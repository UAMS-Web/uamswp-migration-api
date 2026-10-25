<?php

/**
 * Post-author endpoint.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use UAMSWP\MigrationAPI\Content\AuthorPayload;
use UAMSWP\MigrationAPI\Pagination;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * `GET /authors` — the users WXR's author block would carry.
 *
 * **Authors referenced by a post, not every user on the network.** That is
 * core's own rule: `wxr_authors_list()` selects `DISTINCT post_author` from
 * `wp_posts` and resolves each one, so a user who has never authored anything
 * is not in a WXR export and is not here either. On a multisite network with
 * one shared user table that distinction is the difference between the handful
 * of people who wrote a site's content and everyone who has ever logged in.
 *
 * The `auto-draft` exclusion is core's too, and the same one `counts()` makes:
 * a row WordPress creates when an editor opens the "new post" screen and
 * abandons it should not conjure an author into the export.
 */
final class AuthorsController extends Controller
{
    /**
     * How many orphaned author ids the id header will list.
     *
     * A bound on header size, not on the finding: the count header is
     * unbounded and authoritative, so truncation is detectable by comparing
     * the two rather than by noticing a short list.
     */
    private const ORPHAN_ID_LIMIT = 200;

    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute('/authors', [$this, 'index'], Pagination::params());
    }

    /**
     * A page of authors.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $pagination = Pagination::fromRequest($request);
        $ids = self::authorIds();

        /*
         * Primed in one pass, over EVERY referenced author rather than the
         * page. Without this, `get_userdata()` costs a user-row query per
         * author and the first access to `first_name` costs a usermeta prime
         * per author — measured at 2 queries each. `cache_users()` fetches the
         * rows with one `IN (…)` and primes their meta with a second, so the
         * whole collection costs 2 regardless of size.
         *
         * Over the whole list rather than the page because the partition below
         * has to resolve every id to know which ones are orphaned, and doing
         * that unprimed is the same N+1 in a new place. The set is bounded by
         * the number of DISTINCT post authors, not by the size of the posts
         * table: across the 259 captured exports that is a median of 5 and a
         * maximum of 102, so the primed `IN (…)` stays small.
         *
         * One honest cost, measured rather than hand-waved: `cache_users()`
         * primes only users that EXIST, and WordPress does not cache a failed
         * lookup — so each distinct orphan costs a query on every request, not
         * just the first. Measured at 9 queries cold and 5 warm for 3 orphans
         * among 6 authors. It is bounded by the number of distinct orphaned
         * ids, which the corpus survey puts in the low tens at worst, so it is
         * left alone rather than worked around with a negative cache nobody
         * would remember to invalidate.
         */
        cache_users($ids);

        /*
         * Partitioned BEFORE paging, and that ordering is the fix.
         *
         * `array_filter()` in core drops an author whose user row is gone — a
         * post can outlive the account that wrote it — and dropping them after
         * slicing, as this did, produced two separate lies: `X-WP-Total`
         * counted authors the records could never contain, so a consumer
         * paging to the end always came up short; and a page straddling an
         * orphan returned fewer records than `per_page` with nothing to say
         * why. Partitioning first makes every page dense and the total honest.
         */
        $resolvable = [];
        $orphaned = [];

        foreach ($ids as $id) {
            if (get_userdata($id) instanceof WP_User) {
                $resolvable[] = $id;
            } else {
                $orphaned[] = $id;
            }
        }

        $records = [];

        foreach (\array_slice($resolvable, $pagination->offset(), $pagination->perPage) as $id) {
            $user = get_userdata($id);

            if ($user instanceof WP_User) {
                $records[] = AuthorPayload::build($user);
            }
        }

        $response = $pagination->decorate(new WP_REST_Response($records), \count($resolvable));

        return self::reportOrphans($response, $orphaned);
    }

    /**
     * Say how many referenced authors resolved to nothing, and which.
     *
     * This is the whole point of #50. Core drops them and reports nothing, so a
     * consumer holding a post with `author: 41` cannot tell "author 41 is on a
     * page I have not fetched" from "author 41 does not exist" — a 200 that is
     * quietly incomplete, which is this project's defining failure mode.
     *
     * **Measured before it was built.** Across the importer's 259 captured
     * exports, restricted to the 16 post types this API serves: 216,197 items,
     * of which **2,592 (1.20%) carry an author that does not resolve**, spread
     * over 28 exports. Mostly attachments (1,598), but 397 posts and 118 pages
     * too. Small, real, and invisible without this.
     *
     * Two headers rather than one, because they answer different questions and
     * the count stays true even if the list does not: `X-UAMSWP-Orphaned-Authors`
     * is authoritative, and `X-UAMSWP-Orphaned-Author-Ids` is capped so a
     * pathological site cannot push a header past what a proxy will carry.
     * Comparing the two is how a consumer detects the truncation — it is never
     * silent.
     *
     * The ids are not personal data. They already appear in every post payload
     * as `author`, so listing them here reveals nothing a consumer paging
     * `/posts` does not already hold.
     *
     * @param  list<int>  $orphaned  Referenced author ids with no user row.
     */
    private static function reportOrphans(WP_REST_Response $response, array $orphaned): WP_REST_Response
    {
        $response->header('X-UAMSWP-Orphaned-Authors', (string) \count($orphaned));

        $listed = \array_slice($orphaned, 0, self::ORPHAN_ID_LIMIT);

        $response->header('X-UAMSWP-Orphaned-Author-Ids', implode(',', $listed));

        return $response;
    }

    /**
     * Every user id referenced as a post author, ascending.
     *
     * Ordered by id for the reason every collection here is (D-006): a
     * migration pages through a live site, and any ordering that can change
     * puts a record on two pages or none.
     *
     * @return list<int>
     */
    private static function authorIds(): array
    {
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return [];
        }

        $rows = $wpdb->get_col(
            "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status != 'auto-draft' ORDER BY post_author ASC"
        );

        $ids = [];

        foreach ($rows as $row) {
            $id = is_numeric($row) ? (int) $row : 0;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
