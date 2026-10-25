<?php

/**
 * Post-type content endpoints.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Content\AttachmentFiles;
use UAMSWP\MigrationAPI\Content\PostPayload;
use UAMSWP\MigrationAPI\Pagination;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /posts/<type>` and `GET /posts/<type>/<id>`.
 *
 * The core content endpoint, and the one the whole plugin exists for: the
 * WordPress exporter builds its entire XML in a single request and does not
 * complete on `uamshealth.com`, which pagination solves by construction.
 */
final class PostsController extends Controller
{
    /**
     * The bound that identifies a never-published post's zeroed GMT column.
     *
     * `0000-00-00 00:00:00` compares below every real datetime, and no genuine
     * WordPress post predates the epoch, so `<= 1970-01-02` selects the
     * sentinel and nothing else. A literal `= '0000-00-00 00:00:00'` would say
     * it more directly, but `WP_Date_Query` cannot express an equality against
     * an invalid date and `strict` SQL modes reject the literal outright.
     */
    private const NEVER_PUBLISHED_BEFORE = [
        'year' => 1970, 'month' => 1, 'day' => 2,
        'hour' => 0, 'minute' => 0, 'second' => 0,
    ];

    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute(
            '/posts/(?P<type>[a-zA-Z0-9_-]+)',
            [$this, 'index'],
            array_merge(Pagination::params(), self::collectionParams())
        );

        $this->registerReadRoute(
            '/posts/(?P<type>[a-zA-Z0-9_-]+)/(?P<id>[\d]+)',
            [$this, 'show']
        );
    }

    /**
     * A page of records of one post type.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $type = self::resolveType($request);

        if ($type instanceof WP_Error) {
            return $type;
        }

        /*
         * Refused rather than ignored. Ignoring it would serve the whole post
         * type to a caller who asked for a few files, `200`, which is the
         * dropped-filter shape `include` already had to be closed against.
         */
        if ($request->has_param('file') && $type !== 'attachment') {
            return new WP_Error(
                'uamswp_migration_api_file_not_attachment',
                __('`file` selects attachments and is only accepted on `/posts/attachment`.', 'uamswp-migration-api'),
                ['status' => 400]
            );
        }

        $pagination = Pagination::fromRequest($request);
        $args = $pagination->applyToQueryArgs(self::queryArgs($type, $request));

        $query = new WP_Query($args);

        self::primeParents($query->posts);

        $records = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $records[] = PostPayload::build($post);
            }
        }

        /*
         * `applyToQueryArgs()` sets `no_found_rows`, because pagination here is
         * driven by an explicit offset rather than by WP_Query's own paging — so
         * the total has to be counted separately. `wp_count_posts()` cannot do
         * it: it ignores `include` and `modified_after` entirely, and would
         * report a total that disagrees with the page it is describing.
         */
        $response = new WP_REST_Response($records);

        return $pagination->decorate($response, self::countMatching($args));
    }

    /**
     * One record.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $type = self::resolveType($request);

        if ($type instanceof WP_Error) {
            return $type;
        }

        $post = get_post(self::intParam($request, 'id'));

        /*
         * The type has to match the route as well as exist. Without that check
         * `/posts/page/12` happily serves a `post`, so a consumer paging one
         * type could be handed a record of another and never know.
         */
        if (! $post instanceof WP_Post || $post->post_type !== $type) {
            return new WP_Error(
                'uamswp_migration_api_not_found',
                __('No record of that type with that id.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response(PostPayload::build($post));
    }

    /**
     * The post type named in the route, if this deployment serves it.
     *
     * Two separate gates. Configuration decides what this *deployment* exposes;
     * `post_type_exists()` decides what this *process* has — and that word is
     * exact. Registration happens on `init` for whichever site booted the
     * request and `switch_to_blog()` does not re-run it (D-034), so under
     * `?site=N` this gate answers about the booting site while everything
     * beneath it reads site N. A type only the target site registers therefore
     * 404s; tracked in #43.
     *
     * Both failures are a 404 rather than a 403: which post types exist is not
     * something an unauthorized caller should be able to probe, and the
     * namespace gate has already established that the caller is authorized.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function resolveType(WP_REST_Request $request): string|WP_Error
    {
        $type = self::stringParam($request, 'type');

        /*
         * The CONFIGURED list, not `Config::allowsPostType()`. That method
         * filters the configured list through `post_type_exists()`, so it folds
         * the two failures into one and cannot tell them apart — which is the
         * whole point of this method now.
         */
        if (! \in_array($type, Config::strings('post_types'), true)) {
            return new WP_Error(
                'uamswp_migration_api_unknown_post_type',
                __('That post type is not served here.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        if (! post_type_exists($type)) {
            return self::notRegisteredHere($request, $type);
        }

        return $type;
    }

    /**
     * The error for a type this deployment serves but this process cannot.
     *
     * Registration runs on `init` for whichever site booted the request and
     * `switch_to_blog()` does not re-run it (D-034), so a cross-site request for
     * a type only the target site registers **cannot be made to work** — no
     * amount of switching re-runs another site's plugin set in one process.
     * What was wrong was not the 404 but what it said: "that post type is not
     * served here" is false, and sends a consumer to check its configuration
     * when the fix is to ask a different REST root (D-041).
     *
     * Serving it anyway was measured and rejected: `WP_Query` returns the rows,
     * but `get_object_taxonomies()` yields nothing for an unregistered type and
     * no ACF location rule matches, so the payload arrives with an empty `terms`
     * block, zero ACF values and a `?p=` fallback permalink. A well-formed 200
     * missing half the record is worse than a loud 404.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function notRegisteredHere(WP_REST_Request $request, string $type): WP_Error
    {
        $site = self::intParam($request, 'site');

        return new WP_Error(
            'uamswp_migration_api_type_not_registered_here',
            $site > 0
                ? sprintf(
                    /* translators: 1: post type name, 2: site id. */
                    __('`%1$s` is served by this deployment but is not registered in the process handling this request. Request it from site %2$d\'s own REST root.', 'uamswp-migration-api'),
                    $type,
                    $site
                )
                : sprintf(
                    /* translators: %s: post type name. */
                    __('`%s` is served by this deployment but is not registered on this site.', 'uamswp-migration-api'),
                    $type
                ),
            ['status' => 404]
        );
    }

    /**
     * Load every parent of the page in one query instead of one each.
     *
     * `WP_Query` primes the caches for the ids it returned and cannot know
     * about anything they point at. `PostPayload` then reads each record's
     * parent — `get_permalink()` on an attachment resolves its parent to build
     * the URL — and every one of those is a cache miss, so the page pays a
     * `SELECT * FROM wp_posts WHERE ID = N` and a single-id postmeta prime per
     * record. Measured on the development install before this: a page of 27
     * attachments cost 15 of each.
     *
     * The scale is the argument. Attachments are the highest-volume type in the
     * captured exports — 2,011 of them, more than every other type combined —
     * and this API paginates precisely because `uamshealth.com` cannot complete
     * a single-request export. A per-record multiplier on the largest set works
     * against the thing the endpoint exists for.
     *
     * `_prime_post_caches()` rather than `get_post()` in a loop, because that is
     * the batching: one `WHERE ID IN (…)` for the rows and one for their meta.
     * Not `update_post_term_cache` though — nothing reads a parent's terms, and
     * priming them would trade one saved query for another spent.
     *
     * @param  array<int|WP_Post>  $posts  The page, as `WP_Query` returned it.
     */
    private static function primeParents(array $posts): void
    {
        $ids = [];

        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $parent = (int) $post->post_parent;

            /*
             * Zero is WordPress' "no parent" sentinel rather than an id, and
             * the page's own ids are already primed — re-priming one would be a
             * second query for a row that is in the cache. A post that is its
             * own parent is not a shape core creates, but the id check covers
             * it for free.
             */
            if ($parent > 0 && $parent !== (int) $post->ID) {
                $ids[$parent] = $parent;
            }
        }

        if ($ids === []) {
            return;
        }

        _prime_post_caches(array_values($ids), false, true);
    }

    /**
     * Query arguments for a collection request.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return array<string, mixed>
     */
    private static function queryArgs(string $type, WP_REST_Request $request): array
    {
        $statuses = self::statuses($request);

        $args = [
            'post_type' => $type,
            'post_status' => $statuses,
            /*
             * Filters OFF, and this is a correctness setting before it is a
             * cost one (D-045).
             *
             * `suppress_filters` gates the `posts_*` clause filters, and every
             * one of them is a plugin's chance to rewrite what a query returns
             * — which is precisely what D-004's "raw, not curated" forbids. On
             * this network the one that matters is GeoTargeting WP, which
             * hooks `posts_where` at `PHP_INT_MAX` to REMOVE posts from the
             * loop based on where it thinks the visitor is. A migration read
             * that silently omits records is this repo's defining failure mode,
             * and geotargeting has no opinion worth honoring about what a
             * migration carries.
             *
             * Enumerated rather than assumed — the eight callbacks this
             * silences on the development install are geo exclusion, three
             * search rewrites (Ajax Search Pro, Relevanssi, TablePress), two
             * ACF admin-query filters and core's `_close_comments_for_old_posts`.
             * None is something a migration read wants, and the payload was
             * byte-identical with the flag either way.
             *
             * Note this is NOT total protection, and there are TWO holes, not
             * one. `pre_get_posts` is an action rather than one of these
             * filters, and still runs. So does `posts_pre_query`, which is a
             * filter but sits outside the suppression branch —
             * `class-wp-query.php` closes the `if ( ! $q['suppress_filters'] )`
             * block at 3209 and applies `posts_pre_query` at 3227. That one is
             * the more dangerous of the pair: it can REPLACE the result set
             * wholesale, which is exactly what this flag is set to prevent.
             */
            'suppress_filters' => true,
            // The payload reads meta and terms per record anyway, so priming
            // both caches for the page is strictly cheaper than not.
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
            'ignore_sticky_posts' => true,
        ];

        /*
         * The test is whether the caller ASKED, not whether the ask resolved,
         * and `has_param()` is the only thing that answers that question.
         *
         * `intList()` drops anything not a positive integer, so `include[]=0`
         * reduces to `[]` — and an earlier `if ($include !== [])` applied no
         * filter at all and served the entire post type. A caller who asked for
         * one record got every record, `200`, with nothing saying the filter
         * had been dropped.
         *
         * Testing the VALUE against `[]` does not fix that, which is how the
         * first attempt at this went wrong. WordPress sanitizes `include`
         * against its registered `array` schema BEFORE the controller runs, so
         * `?include=`, `?include=,` and `?include=%20` all arrive as an empty
         * array — indistinguishable, by value, from never having been sent.
         * Verified against `rest_sanitize_value_from_schema()` on the install.
         */
        $selected = null;

        if ($request->has_param('include')) {
            $selected = self::intList($request['include'] ?? null);
        }

        /*
         * `file` and `include` are a UNION, not an intersection: each names
         * records, and a consumer sends ids for the references it can trust and
         * files for the ones it cannot. The same `has_param()` rule applies — a
         * `file` that resolves to nothing serves nothing, never everything.
         */
        if ($request->has_param('file')) {
            $selected = array_values(array_unique(array_merge(
                $selected ?? [],
                AttachmentFiles::resolve(self::fileList($request['file'] ?? null), $statuses)
            )));
        }

        if ($selected !== null) {
            // `[0]` rather than `[]`: `WP_Query` skips `post__in` when it is
            // empty, which is the fallthrough being closed here. No post has
            // id 0, so `ID IN (0)` is the empty collection, expressed.
            $args['post__in'] = $selected === [] ? [0] : $selected;
        }

        $modifiedAfter = $request['modified_after'] ?? null;

        if (\is_string($modifiedAfter) && $modifiedAfter !== '') {
            $after = self::gmtParts($modifiedAfter);
        } else {
            $after = null;
        }

        if ($after !== null) {
            /*
             * Two clauses, OR'd, because `post_modified_gmt` has to answer for
             * rows where it holds no answer.
             *
             * WordPress writes `0000-00-00 00:00:00` into the GMT columns of a
             * post that was never published — it has no GMT time because it
             * never had a publish time. `>` is false against that sentinel for
             * every bound, so a single `after` clause drops those rows from
             * every incremental re-pull, permanently: not late, never. On this
             * development install that is 45 drafts of 1,453 rows, and the
             * destination would simply never receive them.
             *
             * The second clause takes them back. A real WordPress post cannot
             * have been modified before 1970, so the epoch bound is a test for
             * the sentinel and nothing else. Re-sending them on every pull is
             * the deliberate trade: cheap, and in the safe direction.
             *
             * `date_query` rather than a `posts_where` filter because
             * `suppress_filters` is on for this query and would discard one.
             */
            $args['date_query'] = [
                'relation' => 'OR',
                [
                    'column' => 'post_modified_gmt',
                    'after' => $after,
                    'inclusive' => false,
                ],
                // Both bounds are PART ARRAYS rather than strings, and that is
                // load-bearing — see `gmtParts()`. A string bound is re-parsed
                // in the SITE timezone and can be silently moved.
                [
                    'column' => 'post_modified_gmt',
                    'before' => self::NEVER_PUBLISHED_BEFORE,
                    'inclusive' => true,
                ],
            ];
        }

        return $args;
    }

    /**
     * A `file` parameter as a list of normalized, distinct paths.
     *
     * @return list<string>
     */
    private static function fileList(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $item) {
            $path = AttachmentFiles::normalize($item);

            if ($path !== null) {
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }

    /**
     * The statuses a request asks for.
     *
     * Defaults to every registered status rather than to `publish`. A migration
     * has to carry drafts, pending review, scheduled posts and private pages —
     * they are content someone wrote — and `attachment` records only ever hold
     * `inherit`, so a `publish` default would serve an empty collection for the
     * one post type that always has records.
     *
     * `auto-draft` is the single exclusion: it is a row WordPress creates when
     * an editor opens the "new post" screen and abandons it, holding no content
     * anyone authored.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return list<string>
     */
    private static function statuses(WP_REST_Request $request): array
    {
        $all = array_values(array_diff(array_keys(get_post_stati()), ['auto-draft']));

        $requested = $request['status'] ?? null;

        if (! \is_array($requested)) {
            return $all;
        }

        // Non-string members are dropped rather than coerced: a caller sending
        // `status[]=1` means nothing, and turning it into the string "1" would
        // silently match no status while looking like a filter that applied.
        $wanted = array_values(array_intersect(array_filter($requested, \is_string(...)), $all));

        return $wanted === [] ? $all : $wanted;
    }

    /**
     * How many records match, ignoring pagination.
     *
     * A second query rather than `found_posts`, because `no_found_rows` is set
     * for the page itself — and `fields => ids` with no meta or term caches
     * makes the count cheap enough that the trade is worth taking. Counting
     * from the same argument array is what keeps the total describing the same
     * result set the page came from.
     *
     * @param  array<string, mixed>  $args  The page's own query arguments.
     */
    private static function countMatching(array $args): int
    {
        $count = new WP_Query(array_merge(
            $args,
            [
                'fields' => 'ids',
                'posts_per_page' => -1,
                'offset' => 0,
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        ));

        return \count($count->posts);
    }

    /**
     * One two-digit component of a matched timestamp, defaulting to `00`.
     *
     * Exists because `$matches[$i] ?? '00'` is wrong here: PCRE fills a skipped
     * INTERMEDIATE group with `''` rather than leaving it absent, so `??` never
     * fires for an omitted group that has a participating group after it.
     *
     * @param  array<int, string>  $matches  Groups from the timestamp pattern.
     */
    private static function part(array $matches, int $index): string
    {
        $value = $matches[$index] ?? '';

        return $value === '' ? '00' : $value;
    }

    /**
     * A caller's timestamp as GMT date PARTS, or null if it is not one.
     *
     * Returns `['year' => …, 'month' => …, 'day' => …, 'hour' => …,
     * 'minute' => …, 'second' => …]` for `WP_Date_Query`.
     *
     * **Parts rather than a string, because a string bound gets moved.**
     * `WP_Date_Query` runs a `Y-m-d H:i:s` string through
     * `build_mysql_datetime()`, which re-parses it in the SITE timezone and
     * formats it back — `date_create( $s, wp_timezone() )->setTimezone(
     * wp_timezone() )`. That is the identity everywhere except inside a
     * daylight-saving spring-forward gap, where the wall clock does not exist
     * and PHP rolls it forward an hour. This install's `timezone_string` is
     * `America/Chicago`, so `modified_after=2026-03-08T02:30:00Z` compiled to
     * `> '2026-03-08 03:30:00'` — an hour TOO NARROW, silently dropping an
     * hour of edits, which is the one direction this endpoint must never fail
     * in. The part-array branch of `build_mysql_datetime()` is a plain
     * `sprintf()` and touches no timezone at all.
     *
     * Note the trap in the shape of that bug: before this parameter was
     * reworked the bound was too WIDE (over-serving, harmless), and the first
     * version of the fix made it too NARROW for that hour. A regression test
     * cannot see it with a fixed `gmt_offset` — only a named zone has a gap.
     *
     * **The accepted shapes are enumerated, not delegated.** Handing the raw
     * string to `DateTimeImmutable` accepted everything `strtotime()` does, and
     * the failures were silent bounds rather than errors: `now`, `tomorrow`,
     * `+1 week`, `Thursday` (next Thursday), `@1700000000`, `12:00:00` (today
     * at noon) — and worst, `2026`, which is a valid ISO 8601 reduced-precision
     * year and which PHP reads as the CLOCK TIME 20:26 today. Each produced a
     * `200` with a self-consistent `X-WP-Total` and almost no records.
     *
     * A bare timestamp is GMT, matching what the parameter is documented to
     * mean. Assuming the site's zone for it would reintroduce an offset shift.
     *
     * Seconds are optional and fractional seconds are tolerated, because
     * `Date.toISOString()`, Carbon and `DATE_RFC3339_EXTENDED` all emit
     * them. Components are read with `part()` rather than `??` — see there.
     *
     * @return array<string, int>|null
     */
    private static function gmtParts(string $raw): ?array
    {
        $raw = trim($raw);

        // Date, optionally a time, optionally a zone. Nothing else — see above.
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})'
            .'(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(Z|z|[+-]\d{2}:?\d{2})?)?$/';

        if (preg_match($pattern, $raw, $matches) !== 1) {
            return null;
        }

        [, $year, $month, $day] = $matches;

        /*
         * `?? '00'` is WRONG for an optional group with a later group after it,
         * and that was a real regression rather than a nicety.
         *
         * PCRE fills a skipped INTERMEDIATE group with `''`, not with nothing —
         * so for `2026-08-01T12:34Z` the seconds group is present-and-empty,
         * `??` does not fire, and `sprintf()` built `2026-08-01T12:34:Z`, which
         * `DateTimeImmutable` rejects. The endpoint answered `400` to a
         * minute-precision ISO 8601 timestamp while accepting the identical
         * string with the `Z` removed, and while its own error message told the
         * caller to use ISO 8601. The previous commit accepted all of them.
         *
         * Test the VALUE, not whether the offset exists.
         */
        $hour = self::part($matches, 4);
        $minute = self::part($matches, 5);
        $second = self::part($matches, 6);
        $zone = $matches[7] ?? '';

        // `preg_match` proved the SHAPE; this proves the DATE. Without it
        // `2026-02-30` parses and rolls over into March.
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        if ((int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        try {
            $parsed = new DateTimeImmutable(
                sprintf('%s-%s-%sT%s:%s:%s%s', $year, $month, $day, $hour, $minute, $second, $zone === '' ? 'Z' : $zone),
                $utc
            );
        } catch (Throwable) {
            return null;
        }

        $parsed = $parsed->setTimezone($utc);

        return [
            'year' => (int) $parsed->format('Y'),
            'month' => (int) $parsed->format('n'),
            'day' => (int) $parsed->format('j'),
            'hour' => (int) $parsed->format('G'),
            'minute' => (int) $parsed->format('i'),
            'second' => (int) $parsed->format('s'),
        ];
    }

    /**
     * Reject a `modified_after` this endpoint cannot read, rather than ignore it.
     *
     * An unreadable bound would otherwise drop the filter and serve the whole
     * collection with a `200` — safe for completeness, and badly misleading
     * about what was asked for. A `400` says so.
     */
    public static function validateModifiedAfter(mixed $value): bool|WP_Error
    {
        if (! \is_string($value) || $value === '' || self::gmtParts($value) !== null) {
            return true;
        }

        return new WP_Error(
            'uamswp_migration_api_bad_modified_after',
            __('`modified_after` is not a timestamp this endpoint can read. Use `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS` (GMT), or an ISO 8601 timestamp.', 'uamswp-migration-api'),
            ['status' => 400]
        );
    }

    /**
     * Parameters shared by the collection route.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function collectionParams(): array
    {
        return [
            'status' => [
                'description' => __('Post statuses to include. Defaults to every status except auto-draft.', 'uamswp-migration-api'),
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            'modified_after' => [
                'description' => __('Only records modified strictly after this GMT timestamp. Never-published records, whose GMT column WordPress zeroes, are always included.', 'uamswp-migration-api'),
                'type' => 'string',
                'validate_callback' => [self::class, 'validateModifiedAfter'],
            ],
            'include' => [
                'description' => __('Limit to these record ids.', 'uamswp-migration-api'),
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
            'file' => [
                'description' => sprintf(
                    /* translators: %d: maximum number of paths per request. */
                    __("Attachments only. Limit to the attachments these file paths belong to, relative to the site's own uploads directory, such as `2020/09/photo.png`. A generated size, such as `2020/09/photo-750x629.png`, resolves to its attachment. Combined with `include` as a union. Unmatched paths are omitted. Send as `file[]`: a single comma- or space-separated `file` value is split on those characters. At most %d paths per request, which bounds the paths and not the records, so a selection can span more than one page.", 'uamswp-migration-api'),
                    AttachmentFiles::MAX_PATHS
                ),
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => AttachmentFiles::MAX_PATHS,
            ],
        ];
    }
}
