<?php

/**
 * Taxonomy term endpoints.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Content\TermPayload;
use UAMSWP\MigrationAPI\Pagination;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * `GET /terms/<taxonomy>` and `GET /terms/<taxonomy>/<id>`.
 *
 * Terms as a first-class resource rather than a sub-resource of posts. A term
 * carries its own meta — 103 of the 259 captured WXR exports contain
 * `wp:termmeta` — and Find-a-Doc hangs ACF field groups on thirteen taxonomies,
 * so a term read only through the post that references it arrives stripped of
 * everything except its name.
 *
 * No `modified_after`. Terms have no modified timestamp: `wp_terms` and
 * `wp_term_taxonomy` carry no date column at all, so there is nothing to filter
 * on and a parameter that silently matched everything would be worse than its
 * absence. An incremental re-pull of terms is a full re-pull, which is cheap —
 * this network's largest taxonomy is three orders of magnitude smaller than its
 * post table.
 */
final class TermsController extends Controller
{
    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute(
            '/terms/(?P<taxonomy>[a-zA-Z0-9_-]+)',
            [$this, 'index'],
            Pagination::params()
        );

        $this->registerReadRoute(
            '/terms/(?P<taxonomy>[a-zA-Z0-9_-]+)/(?P<id>[\d]+)',
            [$this, 'show']
        );
    }

    /**
     * A page of terms in one taxonomy.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $taxonomy = self::resolveTaxonomy($request);

        if ($taxonomy instanceof WP_Error) {
            return $taxonomy;
        }

        $pagination = Pagination::fromRequest($request);

        /*
         * Ordered by `term_id` ascending, which is D-006 in the shape this API
         * takes: a migration pages through a live site while editors work, and
         * any ordering that can change puts a record on two pages or none.
         *
         * `hide_empty` is forced off, and the WordPress default is the trap.
         * `get_terms()` hides terms with no assigned posts unless told
         * otherwise — so the default would silently drop every unused term from
         * a migration, and the taxonomy would arrive missing exactly the terms
         * nobody had used yet, with no error anywhere.
         */
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'number' => $pagination->perPage,
            'offset' => $pagination->offset(),
            'orderby' => 'term_id',
            'order' => 'ASC',
            'hide_empty' => false,
        ]);

        /*
         * `get_terms()` returns `WP_Term[]` on success and a `WP_Error` when
         * the taxonomy is unregistered — which `resolveTaxonomy()` has
         * already ruled out, so this narrows the union rather than guarding
         * against a case that can still happen.
         */
        $records = [];

        foreach (\is_array($terms) ? $terms : [] as $term) {
            /*
             * Re-resolved against the route's taxonomy rather than trusting the
             * object `get_terms()` returned. `WP_Term_Query::populate_terms()`
             * calls `get_term( $id )` with no taxonomy, and `_prime_term_caches()`
             * keys the cache by `term_id` alone — so for a term that exists in
             * two taxonomies the cached row can be the other one's, and the
             * collection would serve a `post_tag` record under `/terms/category`
             * while `show()`, which does pass the taxonomy, served the right one.
             *
             * Not reachable on this install — WordPress 4.4 stopped creating
             * shared terms, 4.3's splitter cleared the legacy ones, and
             * `finished_splitting_shared_terms` is `1` with zero shared ids. The
             * 33 production databases were not inspected, and one line that
             * makes the two endpoints agree by construction is cheaper than
             * finding out.
             */
            $resolved = get_term($term->term_id, $taxonomy);

            $records[] = TermPayload::build($resolved instanceof WP_Term ? $resolved : $term);
        }

        $response = new WP_REST_Response($records);

        return $pagination->decorate($response, self::countMatching($taxonomy));
    }

    /**
     * One term.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $taxonomy = self::resolveTaxonomy($request);

        if ($taxonomy instanceof WP_Error) {
            return $taxonomy;
        }

        $term = get_term(self::intParam($request, 'id'), $taxonomy);

        /*
         * The taxonomy has to match the route as well as the id existing, for
         * the reason the posts endpoint gives: without it `/terms/category/12`
         * would serve a `post_tag`, and a consumer paging one taxonomy could be
         * handed a record of another without anything going wrong visibly.
         *
         * `get_term()` returns null for a missing id and a `WP_Error` when the
         * taxonomy does not match, so neither is a `WP_Term` and one check
         * covers both.
         */
        if (! $term instanceof WP_Term) {
            return new WP_Error(
                'uamswp_migration_api_not_found',
                __('No term of that taxonomy with that id.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response(TermPayload::build($term));
    }

    /**
     * The taxonomy named in the route, if this deployment serves it.
     *
     * Two gates, exactly as the posts endpoint has: configuration decides what
     * this deployment exposes, and `taxonomy_exists()` decides what this
     * *process* has. `$wp_taxonomies` is a process global that `switch_to_blog()`
     * never touches — verified, not assumed: `ms-blogs.php` does not mention it
     * and core's only `switch_blog` listener is `wp_switch_roles_and_user`. So
     * under `?site=N` this gate answers about the booting site, exactly as the
     * posts endpoint does, and a taxonomy only the target site registers 404s.
     * Inherited deliberately rather than diverged from; #43 covers both.
     *
     * Both failures are a 404 rather than a 403 — which taxonomies exist is not
     * something to let a caller probe, and the namespace gate has already
     * established that this one is authorized.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function resolveTaxonomy(WP_REST_Request $request): string|WP_Error
    {
        $taxonomy = self::stringParam($request, 'taxonomy');

        // The CONFIGURED list, for the reason `PostsController` gives:
        // `Config::allowsTaxonomy()` folds registration into the same answer and
        // so cannot tell the two failures apart.
        if (! \in_array($taxonomy, Config::strings('taxonomies'), true)) {
            return new WP_Error(
                'uamswp_migration_api_unknown_taxonomy',
                __('That taxonomy is not served here.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        if (! taxonomy_exists($taxonomy)) {
            $site = self::intParam($request, 'site');

            return new WP_Error(
                'uamswp_migration_api_taxonomy_not_registered_here',
                $site > 0
                    ? sprintf(
                        /* translators: 1: taxonomy name, 2: site id. */
                        __('`%1$s` is served by this deployment but is not registered in the process handling this request. Request it from site %2$d\'s own REST root.', 'uamswp-migration-api'),
                        $taxonomy,
                        $site
                    )
                    : sprintf(
                        /* translators: %s: taxonomy name. */
                        __('`%s` is served by this deployment but is not registered on this site.', 'uamswp-migration-api'),
                        $taxonomy
                    ),
                ['status' => 404]
            );
        }

        return $taxonomy;
    }

    /**
     * How many terms the collection holds, ignoring the page bounds.
     *
     * Counted with the same `hide_empty` rule the page uses. Letting the two
     * differ would report a total that disagrees with the records beside it —
     * and since `hide_empty` defaults to true, the disagreement would be
     * silent and in the direction of a consumer believing it had finished.
     */
    private static function countMatching(string $taxonomy): int
    {
        $total = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        return is_numeric($total) ? (int) $total : 0;
    }
}
