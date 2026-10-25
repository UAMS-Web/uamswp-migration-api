<?php

/**
 * Proves site scoping against the REAL development network (#12).
 *
 * The Feature suite already covers every branch of `Site::switchForRequest()`
 * against a network the test factory builds. What it cannot say is whether the
 * mechanism holds on the actual development install — a different WordPress
 * version, a real plugin set, real site rows. That is the whole of this
 * script's job, and it is why it lives in `scripts/` rather than in `tests/`:
 * it needs an install, so it can never run on CI.
 *
 * Requests are dispatched IN-PROCESS through `rest_do_request()` rather than
 * over HTTP. The distinction #12 draws is the NETWORK, not the transport — and
 * an in-process run needs no credentials, so verifying costs nothing and can be
 * repeated by anyone with a checkout.
 *
 * Read-only except for two things it undoes, both in a `finally`: the
 * archived/deleted/spam branch needs a genuinely flagged site, so it flags the
 * throwaway site and clears it again; and the `Config::flush()` check registers
 * a `uamswp_migration_api_config` filter for the duration of two requests and
 * removes it. Nothing it does survives the run.
 *
 * Run with Herd's PHP 8.2 — the global CLI is 8.4 and dies inside
 * `uamswp-seopress` (UAMS-Web/uamswp-seopress#25):
 *
 *   "$HOME/Library/Application Support/Herd/bin/php82" scripts/verify-site-scoping.php
 */

declare(strict_types=1);

/*
 * Derived from this file's location, not hardcoded: the plugin lives at
 * `wp-content/plugins/<plugin>/scripts/`, so four levels up is the WordPress
 * root for any checkout placed the normal way. Same count the test bootstrap
 * uses. `UAMSWP_WP_ROOT` overrides it for a checkout kept somewhere else.
 */
$root = getenv('UAMSWP_WP_ROOT') ?: \dirname(__DIR__, 4);

if (! is_readable($root.'/wp-load.php')) {
    fwrite(STDERR, "No WordPress at {$root}. Set UAMSWP_WP_ROOT.\n");
    exit(1);
}

$config = (string) file_get_contents($root.'/wp-config.php');
preg_match('/define\(\s*[\'"]DOMAIN_CURRENT_SITE[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $config, $matches);

$_SERVER['HTTP_HOST'] = $matches[1] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';

define('WP_USE_THEMES', false);
require $root.'/wp-load.php';

/**
 * Running tallies.
 *
 * A static holder rather than `global`, so the analyzer can type them — this
 * file is analyzed at `level: max` alongside `includes/`.
 */
final class ScopingTally
{
    public static int $failures = 0;

    public static int $skips = 0;
}

/**
 * Report one assertion.
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    if (! $ok) {
        ScopingTally::$failures++;
    }

    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : "  — {$detail}");
}

/**
 * Report something that could not be verified. Any of these fails the run.
 */
function skip(string $label, string $why): void
{
    ScopingTally::$skips++;

    printf("  SKIP  %s  — %s\n", $label, $why);
}

/**
 * The namespace index for a site, as a super admin.
 *
 * @return array<string, mixed>
 */
function index(?int $site = null): array
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    if ($site !== null) {
        $request->set_param('site', $site);
    }

    $data = rest_do_request($request)->get_data();

    if (! is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $data */
    return $data;
}

/**
 * One value out of an index payload, narrowed.
 *
 * @param  array<string, mixed>  $payload
 */
function field(array $payload, string $block, string $key): mixed
{
    $section = $payload[$block] ?? null;

    return is_array($section) ? ($section[$key] ?? null) : null;
}

/**
 * The status code for a site request.
 */
function status(int $site): int
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');
    $request->set_param('site', $site);

    return rest_do_request($request)->get_status();
}

if (! is_multisite()) {
    fwrite(STDERR, "Not a multisite network; #12 does not apply.\n");
    exit(1);
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$first = $admins[0] ?? 1;
$uid = is_numeric($first) ? (int) $first : 1;
wp_set_current_user($uid);

if (function_exists('grant_super_admin')) {
    grant_super_admin($uid);
}

rest_get_server();

printf("\n== AC: requests are site-scoped ==\n");
printf("  network: WordPress %s, %d site(s)\n", get_bloginfo('version'), count(get_sites(['number' => 100])));

$sites = get_sites(['number' => 100, 'archived' => 0, 'deleted' => 0, 'spam' => 0]);

if (count($sites) < 2) {
    skip('at least two live sites', 'only '.count($sites).' in this network');
    printf("\n%d failed, %d skipped\n", ScopingTally::$failures, ScopingTally::$skips);
    exit(1);
}

check('the network has at least two live sites', true, count($sites).' sites');

$origin = get_current_blog_id();
$target = 0;

foreach ($sites as $site) {
    if ((int) $site->blog_id !== $origin) {
        $target = (int) $site->blog_id;

        break;
    }
}

$here = index();
$there = index($target);

check(
    "?site=N returns that site's id",
    field($there, 'site', 'id') === $target,
    'got '.var_export(field($there, 'site', 'id'), true)
);

check(
    "it returns that site's name and url",
    field($there, 'site', 'name') === get_blog_option($target, 'blogname')
        && is_string(field($there, 'site', 'url'))
        && field($there, 'site', 'url') !== field($here, 'site', 'url'),
    (string) (is_scalar(field($there, 'site', 'name')) ? field($there, 'site', 'name') : '?')
);

check(
    'two site ids return different payloads',
    field($here, 'site', 'id') !== field($there, 'site', 'id')
        && field($here, 'site', 'url') !== field($there, 'site', 'url')
);

check(
    'the original blog is restored after a SUCCESSFUL switch',
    get_current_blog_id() === $origin,
    'current blog '.get_current_blog_id()
);

$backHere = index();

check(
    'a later request still describes the origin site',
    field($backHere, 'site', 'id') === field($here, 'site', 'id')
);

/*
 * The case that proves `Config::flush()` actually runs on a switch — and the
 * one that needs setting up rather than merely observing.
 *
 * `exposed.post_types` is the only field in the payload that can differ per
 * site. `site.id` cannot serve: it comes from `get_current_blog_id()` and
 * differs whether or not configuration was re-resolved. And `Config::postTypes()`
 * filters the configured list by `post_type_exists()`, which is process-global
 * (D-034) — so with identical configuration every site in a process exposes an
 * identical list, and a flush that silently stopped running would be invisible
 * in the response.
 *
 * The per-site lever is the `uamswp_migration_api_config` filter, which
 * re-runs on every `Config::flush()`. This registers it HERE, for the duration
 * of two requests, rather than relying on anything installed on the machine.
 *
 * That placement is the whole point, and it is a correction. This script used
 * to depend on a `mu-plugins/` file carrying the same filter — a committed
 * script whose passing relied on an uncommitted side-effect present on exactly
 * one developer's install, and which permanently narrowed a real site's
 * `exposed` list as a side effect. A consumer treating `exposed` as an
 * allow-list would have captured that site and written a valid-looking export
 * containing a fraction of it, exit 0 (`wordpress-importer#669`). Setting it up
 * in-process is self-contained, leaves nothing behind, and works on any machine
 * with two sites rather than only the one that was hand-prepared.
 *
 * NARROWING, never widening: the endpoint intersects the configured list with
 * what is actually registered, so adding a type would prove nothing.
 */
$narrowed = ['post'];

$filter = static function (array $config) use ($target, $narrowed): array {
    if (get_current_blog_id() === $target) {
        $config['post_types'] = $narrowed;
    }

    return $config;
};

add_filter('uamswp_migration_api_config', $filter);

try {
    // Re-read both sides WITH the filter in place. The earlier reads above
    // deliberately ran without it, so they describe the network as it really is.
    $hereTypes = field(index(), 'exposed', 'post_types');
    $thereTypes = field(index($target), 'exposed', 'post_types');
} finally {
    remove_filter('uamswp_migration_api_config', $filter);
}

check(
    'exposed.post_types reflects the TARGET site, not the requester',
    $thereTypes === $narrowed && $hereTypes !== $thereTypes,
    sprintf(
        'origin %d types, target %s',
        \count((array) $hereTypes),
        json_encode($thereTypes)
    )
);

check(
    'and the filter is gone again afterwards',
    field(index($target), 'exposed', 'post_types') !== $narrowed
);

// Archived / deleted / spam, against a genuinely flagged site.
$throwaway = 0;

foreach ($sites as $site) {
    $id = (int) $site->blog_id;

    if ($id !== $origin && $id !== $target) {
        $throwaway = $id;

        break;
    }
}

if ($throwaway === 0) {
    skip('archived / deleted / spam are refused with 409', 'no third site to flag without disturbing the other two');
} else {
    foreach (['archived', 'deleted', 'spam'] as $flag) {
        try {
            update_blog_details($throwaway, [$flag => '1']);
            clean_blog_cache($throwaway);

            check(
                sprintf('%s site is refused with 409', $flag === 'archived' ? 'an archived' : 'a '.$flag),
                status($throwaway) === 409,
                'got '.status($throwaway)
            );
        } finally {
            update_blog_details($throwaway, [$flag => '0']);
            clean_blog_cache($throwaway);
        }
    }

    $details = get_blog_details($throwaway);

    check(
        'the throwaway site is left unflagged',
        $details instanceof WP_Site
            && (int) $details->archived === 0
            && (int) $details->deleted === 0
            && (int) $details->spam === 0
    );
}

/*
 * `X-UAMSWP-Site` OVER REAL HTTP, which is the only place this claim can be
 * made (#276). Everything above -- and the whole Feature suite -- goes through
 * `rest_do_request()`, which is `dispatch()` and never fires
 * `rest_post_dispatch`. D-079 recorded the header as covering successful
 * responses only, on the strength of exactly that path, and an HTTP error
 * carries it: `rest_post_dispatch` runs in `serve_request()` whatever
 * `dispatch()` returned, and receives the `WP_Error` already converted.
 *
 * Unauthenticated deliberately. A 401 is the case that proves the point and it
 * needs no credential, so this arm runs anywhere the site is reachable.
 */
$httpHeader = static function (string $url): array {
    /*
     * TLS verification off, and the reason is local rather than general. Herd
     * serves this development network under a locally-generated certificate
     * that PHP's CA bundle does not trust, so a verified request fails with
     * `cURL error 60` and this whole arm skips -- measured, not assumed, by
     * running it both ways. The request never leaves the machine and is
     * addressed to the site executing it.
     *
     * Do not copy this into anything that talks to a host it did not just boot.
     */
    $response = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);

    if ($response instanceof WP_Error) {
        return ['status' => 0, 'site' => null, 'error' => $response->get_error_message()];
    }

    return [
        'status' => wp_remote_retrieve_response_code($response),
        'site' => wp_remote_retrieve_header($response, 'x-uamswp-site'),
        'error' => '',
    ];
};

$root = rest_url('uamswp-migration/v1/');
$unauthenticated = $httpHeader($root);

if ($unauthenticated['status'] === 0) {
    skip('the site header over HTTP', 'the REST root is not reachable from here: '.$unauthenticated['error']);
} else {
    check(
        'an unauthenticated HTTP request is refused and STILL reports the answering site',
        $unauthenticated['status'] === 401 && $unauthenticated['site'] === (string) $origin,
        sprintf('status %d, header %s', $unauthenticated['status'], var_export($unauthenticated['site'], true))
    );

    /*
     * Scoped to a DIFFERENT site, which is the arm that carries the finding.
     * The switch runs on `rest_pre_dispatch` at priority 10 and the auth gate
     * at 11, so a refusal names the site that was being READ rather than the
     * one that booted the request. Scoping to the origin would pass whether or
     * not the switch had happened, and would prove nothing.
     */
    $scoped = $httpHeader(add_query_arg('site', (string) $target, $root));

    check(
        'a refused HTTP request names the site being read, not the requester',
        $scoped['status'] === 401 && $scoped['site'] === (string) $target,
        sprintf('status %d, header %s, requester %d', $scoped['status'], var_export($scoped['site'], true), $origin)
    );

    /*
     * The control, and it is what makes the two readings above mean anything: a
     * route outside this namespace must carry NO such header, or "the header is
     * present" is equally consistent with every response on the site carrying
     * one for reasons nothing here controls.
     */
    $core = $httpHeader(rest_url());

    check(
        'a core route carries no site header, so the stamping is namespace-gated',
        $core['status'] === 200 && ($core['site'] === '' || $core['site'] === null),
        sprintf('status %d, header %s', $core['status'], var_export($core['site'], true))
    );
}

check('an unknown site id is refused', status(999999) === 404, 'got '.status(999999));
check('the original blog survives every path above', get_current_blog_id() === $origin);

printf("\n  %d failed, %d skipped\n\n", ScopingTally::$failures, ScopingTally::$skips);

exit(ScopingTally::$failures > 0 || ScopingTally::$skips > 0 ? 1 : 0);
