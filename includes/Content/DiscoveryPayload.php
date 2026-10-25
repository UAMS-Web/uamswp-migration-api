<?php

/**
 * Describes what a site holds, so a migration can be planned rather than guessed.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\REST\RedirectsController;
use WP_Site;

/**
 * The discovery payloads: sites, post types, taxonomies.
 *
 * These replace WXR's channel header and the importer's
 * `XmlParser::getContentTypes()` / `getBaseBlogUrl()`, so a run can be planned
 * against what a site actually holds instead of a hardcoded list.
 *
 * The channel-header fields are reproduced from `wp-admin/includes/export.php`
 * **function for function**, not merely in spirit. `base_blog_url` is
 * `get_bloginfo_rss( 'url' )` and not `home_url()`: the former strips tags,
 * runs `convert_chars()` and passes through the `get_bloginfo_rss` filter, so
 * the two can differ on any site that filters it — and the importer uses this
 * value for URL rewriting and internal-link resolution, where a near-miss is
 * worse than an obvious failure.
 *
 * **Resolved in D-042: `title` and `description` are decoded here, matching
 * what the WXR path yields.** "Function for function" is one function
 * short. The exporter writes the header with `bloginfo_rss()`, which applies one
 * filter more than `get_bloginfo_rss()` — and core registers `ent2ncr` on it
 * (`default-filters.php:268`), which rewrites `&amp;` as `&#38;` for the XML
 * parser to decode back to `&`. So a site named `Ear, Nose & Throat` is served
 * as `Ear, Nose &amp; Throat` where the importer's WXR read yields
 * `Ear, Nose & Throat`.
 *
 * `link` and `base_blog_url` are unaffected in practice: both derive from the
 * `home` option, which carries no markup and no query string, and the two paths
 * are byte-identical on this network today. The fields that can carry an entity
 * are `title` and `description`, and which value they *should* carry — stored,
 * parser-equivalent, or as-is — is a contract with the importer rather than a
 * defect with one obvious fix, which is why it is an issue rather than a patch.
 */
final class DiscoveryPayload
{
    /**
     * Every site in the network, with its channel header and content counts.
     *
     * @return list<array<string, mixed>>
     */
    public static function sites(): array
    {
        if (! is_multisite()) {
            return [self::site(get_current_blog_id())];
        }

        $sites = [];

        foreach (get_sites(['number' => 0, 'orderby' => 'id', 'order' => 'ASC']) as $site) {
            $sites[] = self::site((int) $site->blog_id, $site);
        }

        return $sites;
    }

    /**
     * One site's channel header and counts.
     *
     * @return array<string, mixed>
     */
    private static function site(int $blogId, ?WP_Site $site = null): array
    {
        $switched = false;
        $hadLocale = \array_key_exists('locale', $GLOBALS);
        $locale = $GLOBALS['locale'] ?? null;

        if (is_multisite() && $blogId !== get_current_blog_id()) {
            switch_to_blog($blogId);
            $switched = true;

            /*
             * `switch_to_blog()` does not clear the memoized locale, and every
             * other field here follows the switch. `wp-settings.php` runs
             * `$locale = get_locale();` at global scope during bootstrap, and
             * `get_locale()` opens with `if ( isset( $locale ) ) return …` — so
             * without this, a Spanish site read through a network booted on an
             * English one reported `en-US` beside its own title, its own URL
             * and its own counts. Core registers exactly one `switch_blog`
             * listener (`wp_switch_roles_and_user`) and it does not touch this.
             *
             * Unset rather than recomputed: `get_locale()` re-derives from the
             * switched site's own `WPLANG` option on the next call, which is
             * the value core's exporter would have written.
             */
            unset($GLOBALS['locale']);
        }

        try {
            $payload = [
                'id' => $blogId,

                /*
                 * The WXR channel header, field for field. `title`, `link` and
                 * `description` are what `<channel>` carries; the `wp:` fields
                 * are what the importer reads to plan a run.
                 */
                'title' => self::decoded(get_bloginfo_rss('name')),
                'link' => get_bloginfo_rss('url'),
                'description' => self::decoded(get_bloginfo_rss('description')),
                'language' => get_bloginfo_rss('language'),
                'wxr_version' => self::wxrVersion(),
                'base_site_url' => self::baseSiteUrl(),
                'base_blog_url' => get_bloginfo_rss('url'),

                'counts' => self::counts(),

                /*
                 * Whether each redirect plugin is active on this site, which is
                 * what decides whether `/redirects/<plugin>?site=<id>` serves
                 * its rules. Activation, not loading: a plugin's classes load
                 * for the site that booted the request, while activation is
                 * recorded per site and follows the switch (#374).
                 */
                'redirect_plugins' => [
                    'redirection' => RedirectsController::activeOnCurrentSite(RedirectsController::REDIRECTION_PLUGIN),
                    'pretty_links' => RedirectsController::activeOnCurrentSite(RedirectsController::PRETTY_LINKS_PLUGIN),
                ],
            ];

            if ($site instanceof WP_Site) {
                // Network facts the switched context does not carry.
                $payload['archived'] = $site->archived === '1';
                $payload['deleted'] = $site->deleted === '1';
                $payload['spam'] = $site->spam === '1';
                $payload['registered'] = $site->registered;
            }

            return $payload;
        } finally {
            if ($switched) {
                restore_current_blog();

                // Put the memo back exactly as it was — including absent, if it
                // was absent. Leaving the switched site's locale behind would
                // hand the next record in the loop the same bug pointing the
                // other way.
                if ($hadLocale) {
                    $GLOBALS['locale'] = $locale;
                } else {
                    unset($GLOBALS['locale']);
                }
            }
        }
    }

    /**
     * The base site URL, exactly as `wxr_site_url()` computes it.
     *
     * Core's rule, quoted: multisite yields `network_home_url()`, single site
     * yields `get_bloginfo_rss( 'url' )`. Worth stating because the two differ
     * in more than value — on this network `network_home_url()` returns
     * `http://…/` where `get_bloginfo_rss( 'url' )` returns `https://…`, so
     * scheme *and* trailing slash diverge. `base_site_url` and `base_blog_url`
     * are not interchangeable, and the importer uses the second for rewriting.
     */
    private static function baseSiteUrl(): string
    {
        return is_multisite() ? network_home_url() : get_bloginfo_rss('url');
    }

    /**
     * Channel text as a consumer of the WXR path would receive it.
     *
     * "Function for function" was one function short (#37). Core writes the
     * channel header with `bloginfo_rss()`, which applies one filter more than
     * `get_bloginfo_rss()` — and core registers `ent2ncr` on it
     * (`default-filters.php:268`), rewriting `&amp;` as `&#38;` for the XML
     * parser to turn back into `&`. So a site named `Ear, Nose & Throat`
     * reached a WXR consumer decoded and reached this payload encoded.
     *
     * Decoding here is what makes the two agree. It is *not* a curation of the
     * stored value under D-004: the entities were never in the row. They are an
     * artifact of `get_bloginfo()`'s display filters, added on the way out, and
     * undoing them returns the text the site actually holds.
     *
     * **Only `title` and `description`.** `link` and `base_blog_url` stay
     * exactly as `get_bloginfo_rss()` returns them, because the importer uses
     * `base_blog_url` verbatim for URL rewriting (D-035) and a URL carries no
     * entities to decode — changing it would risk the one field here that has a
     * live consumer, to fix two that have none.
     */
    private static function decoded(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The WXR version core's exporter would emit.
     *
     * Read out of `wp-admin/includes/export.php` rather than hardcoded, because
     * a hardcoded `1.2` silently becomes wrong the day core bumps it and the
     * importer is told the wrong format version. Parsed rather than required:
     * that file is an admin include which defines `export_wp()` and two dozen
     * helpers, and a REST request has no business loading it for one constant.
     *
     * Null when it cannot be read, which is honest — a guess here would be
     * indistinguishable from a fact.
     */
    private static function wxrVersion(): ?string
    {
        if (\defined('WXR_VERSION')) {
            $version = \constant('WXR_VERSION');

            return \is_string($version) ? $version : null;
        }

        $exporter = ABSPATH.'wp-admin/includes/export.php';

        if (! is_readable($exporter)) {
            return null;
        }

        $matched = preg_match(
            '/define\(\s*[\'"]WXR_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            (string) file_get_contents($exporter),
            $version
        );

        return $matched === 1 ? $version[1] : null;
    }

    /**
     * Content counts for the current site.
     *
     * **Three figures for posts, not one, because one was misleading.** `posts`
     * originally counted every row of `wp_posts` bar `auto-draft`, and on this
     * install that is 1,453 against 462 this deployment is configured to serve — a **3.1x
     * overstatement, 66% of it revisions**, plus post types outside
     * `config/defaults.php`. A consumer sizing a run, or checking a finished one
     * for completeness, was working from a number three times the real one, and
     * production is worse: revision counts scale with editing history, and
     * `uamshealth.com` is the site whose history already defeats core's exporter.
     *
     * The original exclusion carried the argument for the rest of them: an
     * `auto-draft` is a row WordPress creates when an editor opens the "new post"
     * screen and abandons it, and "counting them would inflate every planning
     * figure the importer works from". A revision inflates a planning figure the
     * same way. So does a `redirect_rule` this deployment does not serve.
     *
     * - `rows` — the whole table bar `auto-draft`. Honest, cheap, and what
     *   raw-not-curated (D-004) argues for reporting.
     * - `posts` — only the post types this deployment serves. What a consumer
     *   planning a run actually needs, and the sum of the served entries below.
     * - `by_post_type` — the breakdown, which is what makes the endpoint useful
     *   for *verifying* a run rather than only starting one: a consumer can
     *   reconcile per type instead of against a single aggregate.
     *
     * `terms`, `comments` and `options` stay whole-table figures. There is no
     * equivalent of a revision in `term_taxonomy` or `comments`, and `options`
     * is deliberately the entire table (D-032) — so for those the raw count is
     * the useful one rather than a misleading one.
     *
     * @return array<string, mixed>
     */
    private static function counts(): array
    {
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return [];
        }

        /*
         * `auto-draft` is excluded for the same reason the posts endpoint
         * excludes it: a row WordPress creates when an editor opens the "new
         * post" screen and abandons it. Counting them would inflate every
         * planning figure the importer works from.
         */
        $rows = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != 'auto-draft'");
        $terms = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy}");
        $comments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}");
        $options = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");

        // One grouped query rather than one per type: the set of types present
        // in the table is a fact about the data, and asking the data for it also
        // reports a type nobody registered — a row left behind by a plugin that
        // has been removed is exactly what a migration needs to see.
        $grouped = $wpdb->get_results(
            "SELECT post_type, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_status != 'auto-draft' GROUP BY post_type",
            ARRAY_A
        );

        $byPostType = [];

        foreach (\is_array($grouped) ? $grouped : [] as $row) {
            $type = $row['post_type'] ?? null;
            $total = $row['total'] ?? null;

            if (\is_string($type) && $type !== '' && is_numeric($total)) {
                $byPostType[$type] = (int) $total;
            }
        }

        ksort($byPostType);

        /*
         * Eligibility from CONFIGURATION, not `Config::allowsPostType()`, and
         * the difference is D-034. That method filters the configured list by
         * `post_type_exists()`, which is **process-global**: registration
         * happens on `init` for whichever site booted the request, and
         * `switch_to_blog()` does not re-run it. Using it here judged site N's
         * rows against the booting site's registrations — the mixture D-034
         * refuses on `/post-types`, reintroduced on the one endpoint that
         * decision named as unaffected.
         *
         * The production consequence is under-reporting, silently. Find-a-Doc
         * registers `provider`; a request booted on a site without it would
         * report zero providers for the thirty sites that have them, in a
         * well-formed 200.
         *
         * The configured list is a deployment-level fact and identical for every
         * site, so it mixes nothing. What a given site can actually *register*
         * stays the business of `/post-types`, which refuses a cross-site
         * request precisely so that it can answer honestly.
         */
        $eligible = Config::strings('post_types');
        $served = 0;

        foreach ($byPostType as $type => $total) {
            if (\in_array($type, $eligible, true)) {
                $served += $total;
            }
        }

        return [
            'rows' => is_numeric($rows) ? (int) $rows : 0,
            'posts' => $served,
            'by_post_type' => $byPostType,
            'terms' => is_numeric($terms) ? (int) $terms : 0,
            'comments' => is_numeric($comments) ? (int) $comments : 0,
            'options' => is_numeric($options) ? (int) $options : 0,
        ];
    }

    /**
     * Registered post types, with counts and whether this deployment serves them.
     *
     * Every registered type is listed, not only the exposed ones. A consumer
     * planning a run needs to know that a type exists and is *not* being served
     * — that is the difference between "this site has no events" and "we are
     * not sending you the events".
     *
     * @return list<array<string, mixed>>
     */
    public static function postTypes(): array
    {
        $types = [];

        foreach (get_post_types([], 'objects') as $type) {
            $counts = wp_count_posts($type->name);
            $byStatus = [];

            foreach ((array) $counts as $status => $count) {
                if (\is_string($status) && is_numeric($count) && (int) $count > 0) {
                    $byStatus[$status] = (int) $count;
                }
            }

            $types[] = [
                'name' => $type->name,
                'label' => $type->label,
                'exposed' => Config::allowsPostType($type->name),
                'hierarchical' => $type->hierarchical,
                'public' => $type->public,
                'has_archive' => $type->has_archive,
                'taxonomies' => array_values(get_object_taxonomies($type->name)),
                // Keyed by status, `auto-draft` included where it exists: this
                // is a description of the site, not the payload the posts
                // endpoint serves, and hiding a status here would make the two
                // disagree for no reason.
                'counts' => $byStatus,
                'total' => array_sum($byStatus),
            ];
        }

        usort($types, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $types;
    }

    /**
     * Registered taxonomies, with term counts and whether this deployment serves them.
     *
     * @return list<array<string, mixed>>
     */
    public static function taxonomies(): array
    {
        $taxonomies = [];

        foreach (get_taxonomies([], 'objects') as $taxonomy) {
            $count = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);

            $taxonomies[] = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label,
                'exposed' => Config::allowsTaxonomy($taxonomy->name),
                'hierarchical' => $taxonomy->hierarchical,
                'public' => $taxonomy->public,
                'object_types' => array_values($taxonomy->object_type),
                'terms' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        usort($taxonomies, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $taxonomies;
    }
}
