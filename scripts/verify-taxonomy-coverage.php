<?php

/**
 * Reports every term assignment the API would silently drop (#73).
 *
 * `PostPayload::terms()` intersects a record's taxonomies with the CONFIGURED
 * list, so a post type exposed without one of its taxonomies serves every
 * record with that taxonomy missing from `terms` — a `200`, well formed, with
 * the record's classification gone and nothing anywhere saying so.
 *
 * **Why a script and not a test, the same reason `verify-site-scoping.php`
 * gives, plus a second one.** The Feature suite cannot see this twice over: it
 * runs against a rebuilt database with no term data, so an assignment count is
 * always zero there; and it boots a process where `location` and `provider`
 * are not registered, which are the two types with the most taxonomies. The
 * guard in `tests/Integration/Feature/PostsEndpointTest.php` is the registration-shaped
 * half that CAN run on CI. This is the half that needs a real install.
 *
 * Walks every site on the network, so a taxonomy used on one site and not
 * another is still counted.
 *
 * Read-only. It dispatches nothing and writes nothing.
 *
 * Run with Herd's PHP 8.2 — the global CLI is 8.4 and dies inside
 * `uamswp-seopress` (UAMS-Web/uamswp-seopress#25):
 *
 *   "$HOME/Library/Application Support/Herd/bin/php82" scripts/verify-taxonomy-coverage.php
 *
 * Exits non-zero when any assignment would be dropped, so it can gate a
 * migration run rather than only inform one.
 */

declare(strict_types=1);

$root = getenv('UAMSWP_WP_ROOT') ?: \dirname(__DIR__, 4);

if (! is_readable($root.'/wp-load.php')) {
    fwrite(STDERR, "No WordPress at {$root}. Set UAMSWP_WP_ROOT.\n");
    exit(1);
}

$wpConfig = (string) file_get_contents($root.'/wp-config.php');
preg_match('/define\(\s*[\'"]DOMAIN_CURRENT_SITE[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $wpConfig, $matches);

$_SERVER['HTTP_HOST'] = $matches[1] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';

define('WP_USE_THEMES', false);
require $root.'/wp-load.php';

/**
 * Taxonomies deliberately not carried, and why.
 *
 * Kept in step with the exclusion list in `tests/Integration/Feature/PostsEndpointTest.php`
 * — that test asserts registration, this script asserts assignments, and a
 * taxonomy excused in one must be excused in the other.
 *
 * @var array<string, string>
 */
const NOT_CONTENT = [
    'following_users' => 'Edit Flow subscription state, not classification',
    'ef_usergroup' => 'Edit Flow user groups',
    'ef_editorial_meta' => 'Edit Flow editorial metadata',
    'post_status' => 'Edit Flow custom statuses',
    'post_format' => 'core, and carried on the record itself',
    'content_audit' => 'editorial workflow',
    'wp_theme' => 'block-theme association for wp_global_styles',
    'frm_application' => 'Formidable grouping; forms are not API-sourced (D-050)',
    'wp_pattern_category' => 'block-pattern grouping for wp_block',
];

$pluginConfig = require __DIR__.'/../config/defaults.php';

if (! \is_array($pluginConfig)) {
    fwrite(STDERR, "config/defaults.php did not return an array.\n");
    exit(1);
}

$exposedTypes = \is_array($pluginConfig['post_types'] ?? null) ? $pluginConfig['post_types'] : [];
$exposedTaxonomies = \is_array($pluginConfig['taxonomies'] ?? null) ? $pluginConfig['taxonomies'] : [];

global $wpdb;

if (! $wpdb instanceof wpdb) {
    fwrite(STDERR, "No WordPress database handle.\n");
    exit(1);
}

$dropped = [];
$excused = [];

printf("== Term assignments the API would drop ==\n  network: WordPress %s\n\n", get_bloginfo('version'));

/*
 * COMPLETENESS FIRST, because a clean result from an incomplete sweep is the
 * worst thing this script can print.
 *
 * Everything below reads REGISTRATION. A post type whose plugin is inactive is
 * skipped, and — the part that actually bit — so are all of ITS taxonomies,
 * which nothing else will ever enumerate. On 2026-08-24 two deactivated
 * plugins hid three taxonomies carrying 34 assignments, and this script had
 * reported a clean run. Neither this repo nor the importer could have found
 * them by reading code; it took activating the plugins.
 *
 * So the run announces its own coverage. A clean result on a partial plugin
 * set is reported as UNKNOWN, not as clean.
 */
$notRegistered = [];

foreach ($exposedTypes as $type) {
    if (\is_string($type) && ! post_type_exists($type)) {
        $notRegistered[] = $type;
    }
}

/*
 * Read ONCE, outside the per-site loop below, and that is correct rather than
 * an oversight — but it is also a real limit worth stating.
 *
 * Post-type registration is PROCESS-global (D-034): `switch_to_blog()` does not
 * re-run plugin bootstraps, so `post_type_exists()` answers identically on
 * every iteration and moving this check inside the loop would change nothing.
 * What that means is sharper than it sounds: this run sees only the plugin set
 * of the site that BOOTED it. A plugin active on site 3 and inactive on site 1
 * registers nothing here, so site 3's types and all of their taxonomies are
 * invisible to a run booted as site 1 — and no amount of switching reveals
 * them.
 *
 * So a single run cannot clear a network. Run it once per site, from each
 * site's own root, exactly as D-034 says a caller must ask each site's own
 * REST root about what that site exposes.
 */
printf(
    "  registration view: the plugin set of the booting site only (D-034).\n"
    ."  A network is not cleared by one run — run this from each site's own root.\n\n"
);

if ($notRegistered !== []) {
    printf(
        "  ⚠ INCOMPLETE SWEEP — %d configured post type(s) are not registered in this\n"
        ."    process, so neither they nor their taxonomies could be examined:\n      %s\n"
        ."    Activate the plugins that register them and re-run. A clean result below\n"
        ."    describes only what was visible.\n\n",
        \count($notRegistered),
        implode(', ', $notRegistered)
    );
}

foreach (get_sites(['number' => 200]) as $site) {
    $blogId = (int) $site->blog_id;

    switch_to_blog($blogId);

    /*
     * ONE grouped query per site, with no parameters at all, rather than one
     * per type/taxonomy pair. Two reasons, and the second is the real one:
     * it is a great deal cheaper, and `wpdb::prepare()` requires a
     * literal-string query, which a table name interpolated from `$wpdb`
     * is not. Nothing here comes from a caller, so there is nothing to
     * parameterize — the filtering happens in PHP below.
     *
     * Counted as ASSIGNMENTS, not as terms. A taxonomy with twenty terms and
     * none in use costs a migration nothing; one with a single term on four
     * hundred records is four hundred records arriving unclassified.
     */
    $rows = $wpdb->get_results(
        "SELECT tt.taxonomy AS taxonomy, p.post_type AS post_type, COUNT(*) AS assignments
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         WHERE p.post_status != 'auto-draft'
         GROUP BY tt.taxonomy, p.post_type
         ORDER BY p.post_type ASC, tt.taxonomy ASC"
    );

    foreach ((array) $rows as $row) {
        $taxonomy = \is_scalar($row->taxonomy ?? null) ? (string) $row->taxonomy : '';
        $type = \is_scalar($row->post_type ?? null) ? (string) $row->post_type : '';
        $count = is_numeric($row->assignments ?? null) ? (int) $row->assignments : 0;
        // Only pairs the API actually exposes can drop anything: an unexposed
        // POST TYPE serves no records at all, which is a different problem.
        if ($count === 0) {
            continue;
        }

        if (! \in_array($type, $exposedTypes, true)) {
            continue;
        }

        if (! post_type_exists($type)) {
            continue;
        }

        if (\in_array($taxonomy, $exposedTaxonomies, true)) {
            continue;
        }

        // A taxonomy no longer registered against this type cannot be served
        // even in principle, and is not what this script is about.
        if (! \in_array($taxonomy, get_object_taxonomies($type), true)) {
            continue;
        }

        $where = sprintf('site %d  %s -> %s', $blogId, $type, $taxonomy);

        if (isset(NOT_CONTENT[$taxonomy])) {
            $excused[] = sprintf('%s  (%d) — %s', $where, $count, NOT_CONTENT[$taxonomy]);

            continue;
        }

        $dropped[] = sprintf('%s  (%d assignment%s)', $where, $count, $count === 1 ? '' : 's');
    }

    restore_current_blog();
}

foreach ($dropped as $line) {
    printf("  DROPPED   %s\n", $line);
}

foreach ($excused as $line) {
    printf("  excused   %s\n", $line);
}

/*
 * The SECOND view, and the reason this script grew one.
 *
 * Everything above counts assignments that exist HERE. That is the right
 * precision for "what is being lost today" and the wrong one for "what will be
 * lost in production", and the difference is not academic: this script reported
 * "Nothing dropped" while twenty-two taxonomies attached to exposed post types
 * were unexposed, because none of them happened to carry an assignment on a
 * development network that is a thin slice of the real one. The importer found
 * them by reading their own destination, not from here.
 *
 * So an unexposed taxonomy attached to an exposed type is reported even at zero
 * assignments. It is a WARNING rather than a failure, because a taxonomy that
 * is genuinely unused is not a defect — but it is the thing to look at before
 * pointing a migration at a site whose data this run has never seen.
 */
$atRisk = [];

foreach (get_sites(['number' => 200]) as $site) {
    switch_to_blog((int) $site->blog_id);

    foreach ($exposedTypes as $type) {
        if (! \is_string($type)) {
            continue;
        }

        if (! post_type_exists($type)) {
            continue;
        }

        foreach (get_object_taxonomies($type) as $taxonomy) {
            if (\in_array($taxonomy, $exposedTaxonomies, true)) {
                continue;
            }

            if (isset(NOT_CONTENT[$taxonomy])) {
                continue;
            }

            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            $count = \is_array($terms) ? \count($terms) : 0;

            /*
             * The HIGHEST count across the network, not the last one seen.
             * Keying by taxonomy alone and assigning inside the site loop let
             * the final site overwrite the others, so a taxonomy with four
             * terms on site 1 reported "0 terms here" because sites 2 and 3
             * have none. Caught by the negative control for this very block,
             * which is the argument for running one on a reporting change and
             * not only on a behavioral one.
             */
            $existing = $atRisk[$taxonomy]['terms'] ?? -1;

            if ($count > $existing) {
                $atRisk[$taxonomy] = ['type' => $type, 'terms' => $count, 'site' => (int) $site->blog_id];
            }
        }
    }

    restore_current_blog();
}

ksort($atRisk);

foreach ($atRisk as $taxonomy => $risk) {
    printf(
        "  AT RISK   %-24s attached to %-16s %d term%s (most, site %d)\n",
        $taxonomy,
        $risk['type'],
        $risk['terms'],
        $risk['terms'] === 1 ? '' : 's',
        $risk['site']
    );
}

if ($dropped === []) {
    printf(
        "\n  Nothing dropped. %d excused assignment group(s), %d taxonomy(ies) at risk.\n",
        \count($excused),
        \count($atRisk)
    );

    if ($notRegistered !== []) {
        printf(
            "\n  This run was INCOMPLETE (see the warning above). Not a clean bill of health.\n"
        );

        exit(1);
    }

    if ($atRisk !== []) {
        printf(
            "  AT RISK means unexposed with no assignments HERE. This network is a thin\n"
            ."  slice of production; a taxonomy empty here may be populated there, and the\n"
            ."  loss would be silent. Expose it or excuse it in NOT_CONTENT. See D-054.\n"
        );
    }

    exit(0);
}

printf(
    "\n  %d taxonomy/post-type pair(s) carry assignments the API will not serve.\n"
    ."  Add each to `taxonomies` in config/defaults.php, or to NOT_CONTENT here\n"
    ."  and to the exclusion list in tests/Integration/Feature/PostsEndpointTest.php, with a\n"
    ."  reason. See #73.\n",
    \count($dropped)
);

exit(1);
