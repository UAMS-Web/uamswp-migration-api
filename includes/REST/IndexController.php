<?php

/**
 * Namespace index route.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use UAMSWP\MigrationAPI\Config;
use WP_REST_Request;
use WP_REST_Response;

use const UAMSWP\MigrationAPI\VERSION;

/**
 * `GET /` — the namespace index.
 *
 * Deliberately more than a health check: it reports the site actually resolved
 * and the configuration in force there, which is what makes the scaffold's
 * behavior observable before any content endpoint exists. Point it at two
 * different `site` ids and the differing output is the proof that site scoping
 * works.
 */
final class IndexController extends Controller
{
    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute('/', [$this, 'index']);
    }

    /**
     * Describe the API as configured for the resolved site.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $post_types = Config::postTypes();
        $taxonomies = Config::taxonomies();

        return new WP_REST_Response(
            [
                'name' => 'UAMSWP Migration API',
                'version' => VERSION,
                'site' => [
                    'id' => get_current_blog_id(),
                    'name' => get_bloginfo('name'),
                    'url' => home_url(),
                    'is_multisite' => is_multisite(),
                ],
                'exposed' => [
                    'post_types' => $post_types,
                    'taxonomies' => $taxonomies,
                ],
                'pagination' => [
                    'per_page_default' => Config::int('per_page_default', 50),
                    'per_page_max' => Config::int('per_page_max', 200),
                    'order' => 'ID ascending',
                ],
                /*
                 * Populated as each endpoint lands. Listed here so a consumer
                 * can discover what this deployment actually serves rather than
                 * assuming from the documented contract.
                 */
                'endpoints' => [
                    'authors' => [
                        'collection' => '/authors',
                        'params' => ['page', 'per_page'],
                    ],
                    'discovery' => [
                        'sites' => '/sites',
                        'post_types' => '/post-types',
                        'taxonomies' => '/taxonomies',
                    ],
                    'forms' => [
                        /*
                         * `available` rather than omitting the block, because
                         * the two facts differ and a consumer needs both: this
                         * deployment SERVES Gravity Forms definitions, and this
                         * SITE does not have the plugin active. Omission would
                         * collapse them into "not supported" and send someone
                         * to check configuration that is already correct.
                         */
                        'gravity' => [
                            'collection' => '/forms/gravity',
                            'single' => '/forms/gravity/<id>',
                            'available' => FormsController::gravityFormsAvailable(),
                            // No `modified_after`: `gf_form` carries
                            // `date_created` and nothing recording an edit.
                            'params' => ['page', 'per_page'],
                            /*
                             * Advertised here because a consumer cannot infer
                             * it. Feeds are the only part of a form's behavior
                             * that never travels in a Gravity Forms export, so
                             * a reader with an export in hand has no reason to
                             * suspect the endpoint has more than the export
                             * does — and probing a route to find out is what
                             * this index exists to remove.
                             */
                            'feeds' => [
                                'collection' => '/forms/gravity/feeds',
                                'by_form' => '/forms/gravity/<id>/feeds',
                                /*
                                 * On the COLLECTION only. `by_form` is
                                 * deliberately un-paginated — it is registered
                                 * without `Pagination::params()` and returns no
                                 * total header — so advertising these at the
                                 * level of the whole block told a consumer to
                                 * page a route that ignores both and answers
                                 * the identical list every time.
                                 */
                                'collection_params' => ['page', 'per_page'],
                            ],
                        ],
                        'formidable' => [
                            'collection' => '/forms/formidable',
                            'single' => '/forms/formidable/<id>',
                            'available' => FormsController::formidableAvailable(),
                            // No `modified_after`: `frm_forms` carries
                            // `created_at` and nothing recording an edit.
                            'params' => ['page', 'per_page'],
                        ],
                        'ninja' => [
                            'collection' => '/forms/ninja',
                            'single' => '/forms/ninja/<id>',
                            'available' => FormsController::ninjaAvailable(),
                            // No `modified_after`: `nf3_forms` carries
                            // `created_at` and nothing recording an edit.
                            'params' => ['page', 'per_page'],
                        ],
                    ],
                    'options' => [
                        'collection' => '/options',
                        'params' => [],
                    ],
                    'posts' => [
                        'collection' => '/posts/<type>',
                        'single' => '/posts/<type>/<id>',
                        'params' => ['page', 'per_page', 'status', 'modified_after', 'include'],
                    ],
                    'redirects' => [
                        /*
                         * `available` means loaded in this process AND active
                         * on the resolved site; `/sites` reports the second
                         * half for every site at once (#374).
                         */
                        'redirection' => [
                            'collection' => '/redirects/redirection',
                            'available' => RedirectsController::redirectionLoaded()
                                && RedirectsController::activeOnCurrentSite(RedirectsController::REDIRECTION_PLUGIN),
                            'params' => ['page', 'per_page'],
                        ],
                        'pretty_links' => [
                            'collection' => '/redirects/pretty-links',
                            'available' => RedirectsController::prettyLinksLoaded()
                                && RedirectsController::activeOnCurrentSite(RedirectsController::PRETTY_LINKS_PLUGIN),
                            'params' => ['page', 'per_page'],
                        ],
                    ],
                    'terms' => [
                        'collection' => '/terms/<taxonomy>',
                        'single' => '/terms/<taxonomy>/<id>',
                        // No `modified_after`: `wp_terms` and `wp_term_taxonomy`
                        // carry no date column, so there is nothing to filter on.
                        'params' => ['page', 'per_page'],
                    ],
                ],
            ]
        );
    }
}
