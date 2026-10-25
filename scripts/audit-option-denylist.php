<?php

/**
 * Audit the option denylist against a real options table.
 *
 * The denylist in `config/defaults.php` decides what `GET /options` withholds,
 * and its failure direction is leaking a credential. It was reviewed against
 * the development network (#6, D-032) and then against all 33 production sites,
 * where it turned out a one-site review would have leaked on 32 of them. This is
 * how it gets reviewed against a table nobody has looked at.
 *
 * **It prints no option values, ever, with one stated exception.** Names, key
 * paths, byte lengths and derived shape labels only. That is not incidental: it
 * is what makes this safe to run on production by someone who is not reviewing
 * the output, and safe to paste into a ticket afterwards. Adding a value to the
 * output would forfeit both.
 *
 * The exception is the per-site header, which prints `home_url()` — the `home`
 * option — because output that does not say which of 33 sites it describes is
 * useless. A site's own URL is public by construction and is in every payload
 * this plugin serves. It is named here rather than left as an unremarked
 * inconsistency, because a guarantee with a silent exception is not one.
 *
 * ## Two questions, and only one of them is shared
 *
 * The script answers two questions that look alike and must not be conflated.
 *
 * **What does the endpoint withhold?** Asked by calling `OptionsPayload::classify()`
 * directly. It used to be answered by a transcription of the rules kept here,
 * and that transcription went stale the moment #33 taught the endpoint to look
 * inside serialized values: for a release this script reported on a denylist
 * that was not shipped, overstating exposure and unable to confirm the new
 * protection worked at all (#38). The hazard was named in this file's own
 * docblock at the time. Sharing the implementation makes the drift impossible
 * rather than merely unlikely.
 *
 * **What might the endpoint be missing?** Answered by the heuristics below,
 * which are **deliberately looser than the shipped rule** and are supposed to
 * be. A detector no broader than the thing it audits can only ever confirm what
 * already happened. These run over the rows the endpoint *serves*, and every
 * hit is a candidate for a human to look at — most will be false positives, and
 * that is the correct trade for the direction of the risk.
 *
 * Usage, from anywhere inside a WordPress install:
 *
 *     php scripts/audit-option-denylist.php [path/to/wordpress] [host]
 *
 * The host is only needed if `DOMAIN_CURRENT_SITE` cannot be read from
 * wp-config.php; on a multisite WordPress dies silently without one.
 *
 * The WordPress root defaults to four levels above this plugin, which holds for
 * any checkout inside `wp-content/plugins/`. On a multisite network every site
 * is audited in turn.
 *
 * What it still cannot do: prove the absence of a credential stored under an
 * innocent name with an innocent-looking value — a short token in `vendor_mode`,
 * say. Nothing detects that. It is the accepted failure direction of a denylist,
 * and this narrows the gap rather than closing it.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Content\OptionsPayload;

$root = rtrim($argv[1] ?? \dirname(__DIR__, 4), '/');

if (! is_readable($root.'/wp-load.php')) {
    fwrite(STDERR, "No WordPress found at {$root}. Pass the root as the first argument.\n");

    exit(1);
}

if (! \defined('WP_USE_THEMES')) {
    \define('WP_USE_THEMES', false);
}

/*
 * A multisite bootstrap resolves the current site from `HTTP_HOST`, which the
 * CLI does not set. Left at a guess, `ms-settings.php` finds no site and dies
 * silently — exit 0, no output, nothing to debug. `DOMAIN_CURRENT_SITE` in
 * wp-config.php is the network's own answer, so read it before loading rather
 * than asking the operator for something WordPress already knows.
 */
$host = $argv[2] ?? null;

if ($host === null && is_readable($root.'/wp-config.php')) {
    $matched = preg_match(
        '/define\(\s*[\'"]DOMAIN_CURRENT_SITE[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
        (string) file_get_contents($root.'/wp-config.php'),
        $domain
    );

    if ($matched === 1) {
        $host = $domain[1];
    }
}

$_SERVER['HTTP_HOST'] = $host ?? 'localhost';
$_SERVER['REQUEST_URI'] ??= '/';

require $root.'/wp-load.php';

/*
 * Required rather than left to the plugin's autoloader, which is registered on
 * activation, and this script has to run on an install where the plugin is
 * merely present — auditing a table before deciding whether to serve it is the
 * normal case, not the exception.
 */
$plugin = \dirname(__DIR__);

require_once $plugin.'/includes/Config.php';
require_once $plugin.'/includes/ACF/FieldReader.php';
require_once $plugin.'/includes/Content/OptionsPayload.php';

require_once __DIR__.'/audit-heuristics.php';

/**
 * Audit one site's options table.
 */
function auditSite(int $blogId): void
{
    global $wpdb;

    if (! $wpdb instanceof wpdb) {
        return;
    }

    $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A);
    $rows = \is_array($rows) ? $rows : [];

    $patterns = Config::optionDenylist();

    $total = 0;
    $exempt = 0;
    $byPattern = [];
    $byNestedKey = [];
    $byShape = [];
    $namedSuspects = [];
    $suspects = [];

    foreach ($rows as $row) {
        $name = $row['option_name'] ?? null;

        if (! \is_string($name)) {
            continue;
        }

        if ($name === '') {
            continue;
        }

        $raw = $row['option_value'] ?? '';
        $value = \is_string($raw) ? $raw : '';

        $total++;

        // The endpoint's own answer, not a reconstruction of it.
        switch (OptionsPayload::classify($name, $value)) {
            case OptionsPayload::VERDICT_EXEMPT:
                $exempt++;

                continue 2;

            case OptionsPayload::VERDICT_NAME:
                foreach ($patterns as $pattern) {
                    if (OptionsPayload::matchesLikePattern($name, $pattern)) {
                        $byPattern[$pattern][] = $name;

                        continue 3;
                    }
                }

                continue 2;

            case OptionsPayload::VERDICT_NESTED_KEY:
                $byNestedKey[] = $name;

                continue 2;

            case OptionsPayload::VERDICT_SHAPE:
                $byShape[] = $name;

                continue 2;
        }

        // Served. Everything below is the looser question: might this be a miss?

        // The option's OWN name, which the walk below cannot see: it is seeded
        // with the value, so the name is never a path segment. Dropping this
        // silenced the whole class where the name is the only signal there is.
        if (auditNameSuspect($name, $value)) {
            $namedSuspects[$name] = \strlen($value).'B';
        }

        $found = auditSuspects(auditDecoded($value) ?? $value, '');

        foreach ($found as $path => $why) {
            $suspects[$name.($path === '' ? '' : ' :: '.$path)] = $why;
        }
    }

    $withheld = \count($byNestedKey) + \count($byShape);

    foreach ($byPattern as $names) {
        $withheld += \count($names);
    }

    printf("\n=== site %d — %s ===\n", $blogId, home_url());
    printf("  options: %d   withheld: %d   `_options_*` exempt: %d\n", $total, $withheld, $exempt);

    printf("\n  withheld because the NAME matched, by pattern:\n");

    foreach ($patterns as $pattern) {
        $names = $byPattern[$pattern] ?? [];
        printf("    %-20s %3d  %s\n", $pattern, \count($names), $names === [] ? '(nothing — speculative, kept)' : implode(', ', \array_slice($names, 0, 4)));
    }

    printf("\n  withheld because a KEY INSIDE the value matched (%d):\n", \count($byNestedKey));

    foreach ($byNestedKey as $name) {
        printf("    %s\n", $name);
    }

    if ($byNestedKey === []) {
        printf("    (none)\n");
    }

    printf("\n  withheld because a VALUE looked like a credential (%d):\n", \count($byShape));

    foreach ($byShape as $name) {
        printf("    %s\n", $name);
    }

    if ($byShape === []) {
        printf("    (none)\n");
    }

    printf("\n  SERVED, but the option NAME reads like a credential (%d) — review each:\n", \count($namedSuspects));

    ksort($namedSuspects);

    foreach ($namedSuspects as $where => $size) {
        printf("    %-64s %s\n", $where, $size);
    }

    if ($namedSuspects === []) {
        printf("    (none)\n");
    }

    printf("\n  SERVED, but something in it reads like a credential (%d) — review each.\n", \count($suspects));
    printf("  These are the possible MISSES: the detector here is deliberately broader\n  than the shipped rule, so expect false positives.\n");

    ksort($suspects);

    foreach ($suspects as $where => $why) {
        printf("    %-64s %s\n", $where, $why);
    }

    if ($suspects === []) {
        printf("    (none)\n");
    }
}

echo "Option denylist audit — names, key paths, lengths and shapes only. No option value is printed.\n";
echo "Withheld/served is decided by OptionsPayload::classify(), the same call the endpoint makes.\n";
printf(
    "Patterns in force: %d name, %d nested-key\n",
    \count(Config::optionDenylist()),
    \count(Config::strings('option_denylist_nested_keys'))
);

if (is_multisite()) {
    // The default object form rather than `fields => 'ids'`: the WordPress
    // stubs type this as `WP_Site[]` whatever `fields` says, so reading the id
    // off the object is both correct and analyzable.
    $sites = get_sites(['number' => 0]);

    printf("Network: %d site(s)\n", \count($sites));

    foreach ($sites as $site) {
        $blogId = (int) $site->blog_id;

        switch_to_blog($blogId);
        auditSite($blogId);
        restore_current_blog();
    }
} else {
    auditSite(get_current_blog_id());
}

echo "\nDone. Paste this whole output into the ticket — it contains no secrets by construction.\n";
