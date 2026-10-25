<?php

/**
 * Redirect-rule endpoints.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use PrettyLinks\Repositories\Links;
use Red_Group;
use Red_Item;
use Red_Options;
use UAMSWP\MigrationAPI\Pagination;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /redirects/redirection` and `GET /redirects/pretty-links`.
 *
 * The importer's vanity-domain redirects (`wordpress-importer#1307`). Neither
 * plugin stores its rules as posts, so neither WXR nor `/posts/<type>` can
 * carry them, and the importer's disable pass (`wordpress-importer#1312`)
 * needs a source it can re-read on every run. See #374.
 *
 * **Rules only.** Redirection's `redirection_logs` and `redirection_404`, and
 * Pretty Links' `prli_clicks`, hold visitor addresses and referrers and are
 * never read for output. The per-rule counters derived from them (Redirection's
 * `hits` and `last_access`, Pretty Links' `clicks` and `uniques`) are dropped
 * from every row, so nothing a visitor did reaches the payload.
 *
 * Read through each plugin's own model, never raw SQL: `Red_Item` and
 * `Red_Group` for Redirection, `PrettyLinks\Repositories\Links` for Pretty
 * Links. Measured against Redirection 5.10.0 (schema `4.2`) and Pretty Links
 * 4.0.15, the versions the vanity installs run.
 *
 * **Served only where the plugin is active on the site being read.** A plugin's
 * classes are loaded per process, for the site that booted the request, while
 * `?site=` switches the tables underneath. Without this check a request booted
 * on a site running Redirection, asking for a site that does not, would read a
 * missing table and answer with the empty list a site with no rules
 * legitimately serves; a deactivated plugin's leftover rows would be served as
 * though they were live.
 */
final class RedirectsController extends Controller
{
    /**
     * Plugin basename for Redirection, as WordPress records it when active.
     */
    public const REDIRECTION_PLUGIN = 'redirection/redirection.php';

    /**
     * Plugin basename for Pretty Links, as WordPress records it when active.
     */
    public const PRETTY_LINKS_PLUGIN = 'pretty-link/pretty-link.php';

    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute('/redirects/redirection', [$this, 'indexRedirection'], Pagination::params());
        $this->registerReadRoute('/redirects/pretty-links', [$this, 'indexPrettyLinks'], Pagination::params());
    }

    /**
     * A page of Redirection rules, ordered by id ascending.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function indexRedirection(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::redirectionLoaded()) {
            return self::notLoaded($request, 'Redirection');
        }

        if (! self::activeOnCurrentSite(self::REDIRECTION_PLUGIN)) {
            return self::notActive('Redirection');
        }

        $missing = self::missingTables('Redirection', ['redirection_items', 'redirection_groups']);

        if ($missing instanceof WP_Error) {
            return $missing;
        }

        $pagination = Pagination::fromRequest($request);

        /*
         * `Red_Options` caches the plugin's settings in a static, filled on
         * the site that booted the request, and a `Red_Item` takes every match
         * flag the rule did not set from those settings WHILE IT IS BUILT. So
         * the reset comes before the rules are read: without it a `?site=`
         * read served the booting site's case, trailing-slash and query
         * defaults as the read site's. Reset again afterwards, so the cache is
         * not left holding the read site's settings.
         */
        Red_Options::reset();

        try {
            /*
             * `Red_Item::get_all()` rather than `get_filtered()`: the latter
             * clamps `per_page` to 5..200, pages from zero, and orders by `id`
             * only when no other order is asked for. The whole table is read
             * and sliced here, as the authors and forms endpoints do; the
             * largest install measured carries 175 rules.
             */
            $items = Red_Item::get_all();

            if (self::lastQueryFailed()) {
                return self::readFailed('Redirection', 'redirection_items');
            }

            usort($items, static fn (Red_Item $a, Red_Item $b): int => $a->get_id() <=> $b->get_id());

            $groups = self::redirectionGroups();

            // Read straight after the groups query: a failed read would
            // otherwise serve every rule's `group` as `null`, which reads as
            // a deleted group.
            if (self::lastQueryFailed()) {
                return self::readFailed('Redirection', 'redirection_groups');
            }

            $records = [];

            foreach (\array_slice($items, $pagination->offset(), $pagination->perPage) as $item) {
                $records[] = self::redirectionRule($item, $groups);
            }
        } finally {
            Red_Options::reset();
        }

        return $pagination->decorate(new WP_REST_Response($records), \count($items));
    }

    /**
     * A page of Pretty Links links, ordered by id ascending.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function indexPrettyLinks(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::prettyLinksLoaded()) {
            return self::notLoaded($request, 'Pretty Links');
        }

        if (! self::activeOnCurrentSite(self::PRETTY_LINKS_PLUGIN)) {
            return self::notActive('Pretty Links');
        }

        $missing = self::missingTables('Pretty Links', ['prli_links']);

        if ($missing instanceof WP_Error) {
            return $missing;
        }

        $pagination = Pagination::fromRequest($request);

        /*
         * `status => any` is the plugin's own "All" view: every link not in
         * the trash. A trashed link no longer redirects, so it is not served,
         * and its disappearance is what tells the importer to disable it.
         */
        $result = (new Links)->search([
            'orderby' => 'id',
            'order' => 'asc',
            'per_page' => $pagination->perPage,

            /*
             * From the saturated offset rather than the requested page: the
             * plugin multiplies `($page - 1) * $perPage` itself, a page past
             * the integer range made that a float, and `%d` read the float
             * as `0`, so the first page was served for a page past the end.
             */
            'page' => intdiv($pagination->offset(), $pagination->perPage) + 1,
            'status' => 'any',
        ]);

        // The plugin reads a failed count as `0` and a failed list as `[]`.
        if (self::lastQueryFailed()) {
            return self::readFailed('Pretty Links', 'prli_links');
        }

        $records = [];

        foreach ($result['items'] as $link) {
            unset($link['clicks'], $link['uniques']);
            $records[] = $link;
        }

        return $pagination->decorate(new WP_REST_Response($records), $result['total']);
    }

    /**
     * Refuse when a plugin's tables are missing on the site being read.
     *
     * Activation does not create either plugin's tables: Redirection builds
     * them in a separate setup step, Pretty Links on its first boot. And both
     * plugins read a failed query as an empty result, so behind an active
     * plugin with no tables the routes would answer `200 []` with a total of
     * `0`, which the importer's disable pass reads as every rule removed. A
     * schema check rather than a data read, which is why it is raw SQL; the
     * rules themselves are read only through each plugin's model. It selects
     * no rows rather than asking `SHOW TABLES`, which cannot see a temporary
     * table, so the check holds wherever the plugin's own queries would.
     *
     * @param  list<string>  $tables  Table names without the site prefix.
     */
    private static function missingTables(string $plugin, array $tables): ?WP_Error
    {
        global $wpdb;

        foreach ($tables as $table) {
            if (! $wpdb instanceof \wpdb) {
                return self::readFailed($plugin, $table);
            }

            $name = $wpdb->prefix.$table;

            $suppressed = $wpdb->suppress_errors();
            $found = $wpdb->query("SELECT 1 FROM `{$name}` LIMIT 0") !== false;
            $wpdb->suppress_errors($suppressed);

            if (! $found) {
                return new WP_Error(
                    'uamswp_migration_api_redirect_tables_missing',
                    sprintf(
                        /* translators: 1: plugin name, 2: site id, 3: table name. */
                        __('%1$s is active on site %2$d, but its table %3$s does not exist there, so its rules cannot be read.', 'uamswp-migration-api'),
                        $plugin,
                        get_current_blog_id(),
                        $name
                    ),
                    ['status' => 500]
                );
            }
        }

        return null;
    }

    /**
     * Whether the most recent database query failed.
     *
     * With no database handle there is nothing to have read, so that counts
     * as a failure rather than an empty result.
     *
     * @phpstan-impure
     */
    private static function lastQueryFailed(): bool
    {
        global $wpdb;

        return ! $wpdb instanceof \wpdb || $wpdb->last_error !== '';
    }

    /**
     * A plugin read failed after its tables were found.
     */
    private static function readFailed(string $plugin, string $table): WP_Error
    {
        global $wpdb;

        return new WP_Error(
            'uamswp_migration_api_redirect_read_failed',
            sprintf(
                /* translators: 1: plugin name, 2: table name, 3: site id. */
                __('%1$s could not read %2$s on site %3$d, so its rules are not served rather than served incomplete.', 'uamswp-migration-api'),
                $plugin,
                ($wpdb instanceof \wpdb ? $wpdb->prefix : '').$table,
                get_current_blog_id()
            ),
            ['status' => 500]
        );
    }

    /**
     * Whether Redirection's models are loaded in this process.
     */
    public static function redirectionLoaded(): bool
    {
        return class_exists(Red_Item::class) && class_exists(Red_Group::class);
    }

    /**
     * Whether Pretty Links' repository is loaded in this process.
     */
    public static function prettyLinksLoaded(): bool
    {
        return class_exists(Links::class);
    }

    /**
     * Whether a plugin is active on the current site, network-wide or alone.
     *
     * Read from the two options WordPress records activation in, rather than
     * `is_plugin_active()`, which lives in an admin include a REST request
     * does not load. Both follow `switch_to_blog()`, so after the site switch
     * this answers for the site being read.
     */
    public static function activeOnCurrentSite(string $plugin): bool
    {
        $active = get_option('active_plugins', []);

        if (\is_array($active) && \in_array($plugin, $active, true)) {
            return true;
        }

        if (! is_multisite()) {
            return false;
        }

        $network = get_site_option('active_sitewide_plugins', []);

        return \is_array($network) && \array_key_exists($plugin, $network);
    }

    /**
     * One Redirection rule, with its group's state beside it.
     *
     * @param  array<int, array<string, mixed>>  $groups  Groups keyed by id.
     * @return array<string, mixed>
     */
    private static function redirectionRule(Red_Item $item, array $groups): array
    {
        $json = $item->to_json();

        return [
            'id' => $item->get_id(),
            'url' => $item->get_url(),
            'match_url' => $item->get_match_url(),
            'regex' => $item->is_regex(),
            'match_type' => $item->get_match_type(),

            /*
             * The flags the rule matches with: those it sets itself, and the
             * site's defaults for the rest, as the plugin fills them. The
             * stored column omits any flag equal to the default at save time,
             * so it cannot say how a rule matches without the site's settings.
             */
            'match_data' => $item->get_match_data(),
            'action_type' => $item->get_action_type(),
            'action_code' => $item->get_action_code(),

            /*
             * Both forms of the target and its conditions. `action_data` is the
             * column as stored: a bare URL for a plain rule, a serialized array
             * for a rule conditioned on referrer, login state, user agent or
             * IP. `action_data_parsed` is the plugin's own reading of it, so a
             * consumer listing those conditions never has to unserialize.
             */
            'action_data' => $item->get_action_data(),
            'action_data_parsed' => $json['action_data'] ?? null,

            'title' => $item->get_title(),
            'position' => $item->get_position(),
            'enabled' => $item->is_enabled(),
            'group_id' => $item->get_group_id(),

            /*
             * The group's own state, because a disabled group stops every rule
             * in it. Redirection flips each rule's status too when a group is
             * disabled through its UI, but a rule can be enabled inside a
             * disabled group, and only this says so. `null` when the group no
             * longer exists.
             */
            'group' => $groups[$item->get_group_id()] ?? null,
        ];
    }

    /**
     * Every Redirection group on this site, keyed by id.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function redirectionGroups(): array
    {
        $groups = [];

        foreach (Red_Group::get_all() as $group) {
            // The plugin builds each group from its row with `intval()` over
            // the id, so the id is an int without a check here (D-097).
            $id = $group['id'];

            // `name` is the plugin's reading, which runs
            // `sanitize_text_field()` over the stored name.
            $groups[$id] = [
                'id' => $id,
                'name' => $group['name'],
                'module_id' => $group['module_id'],
                'enabled' => $group['enabled'],
            ];
        }

        return $groups;
    }

    /**
     * The plugin is not loaded in the process handling this request.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function notLoaded(WP_REST_Request $request, string $plugin): WP_Error
    {
        $site = self::intParam($request, 'site');

        return new WP_Error(
            // Its own code: the plugin may well be active on the site asked
            // for, which `/sites` would report, and loading is a property of
            // the process rather than of the site.
            'uamswp_migration_api_redirect_plugin_not_loaded',
            $site > 0
                ? sprintf(
                    /* translators: 1: plugin name, 2: site id. */
                    __('%1$s is not loaded in the process handling this request, so its rules on site %2$d cannot be read here even where `/sites` reports it active, because plugins load for the site that booted the request: ask site %2$d through its own REST root.', 'uamswp-migration-api'),
                    $plugin,
                    $site
                )
                : sprintf(
                    /* translators: %s: plugin name. */
                    __('%s is not loaded in the process handling this request.', 'uamswp-migration-api'),
                    $plugin
                ),
            ['status' => 404]
        );
    }

    /**
     * The plugin is loaded, but not active on the site being read.
     */
    private static function notActive(string $plugin): WP_Error
    {
        return new WP_Error(
            'uamswp_migration_api_redirect_plugin_not_active',
            sprintf(
                /* translators: 1: plugin name, 2: site id. */
                __('%1$s is not active on site %2$d.', 'uamswp-migration-api'),
                $plugin,
                get_current_blog_id()
            ),
            ['status' => 404]
        );
    }
}
