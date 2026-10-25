<?php

/**
 * Multisite scoping: resolution, refusal, and restore.
 */

declare(strict_types=1);

/**
 * Dispatch the index route as a super admin, optionally scoped to a site.
 */
function indexAsSuperAdmin(?int $site = null): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    if ($site !== null) {
        $request->set_param('site', $site);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves the current site when no site parameter is given', function (): void {
    $response = indexAsSuperAdmin();

    expect($response->get_status())->toBe(200)
        ->and(payload($response, 'site', 'id'))->toBe(get_current_blog_id())
        ->and(payload($response, 'site', 'is_multisite'))->toBeTrue();
})->skip(! is_multisite(), networkOnly('it asserts a network; SingleSiteTest.php covers the single site'));

it('refuses a site that does not exist in the network', function (): void {
    $response = indexAsSuperAdmin(999999);

    expect($response->get_status())->toBe(404)
        ->and(errorCode($response))->toBe('uamswp_migration_api_unknown_site');
})->skip(! is_multisite(), networkOnly('a single site refuses every `?site=` before looking the site up'));

/*
 * The switch branch itself, and the restore that goes with it.
 *
 * The development network has a single site, so this path has never executed there
 * — the gap #12 tracks. The test factory can create a second blog, so the suite
 * exercises it even where the dev install cannot.
 *
 * The restore assertion is the load-bearing half. `rest_post_dispatch` fires only
 * in `serve_request()`, never in `dispatch()`, so before `Site::register()` also
 * hooked `rest_request_after_callbacks` this left the blog switched for every
 * internal `rest_do_request()` — and everything afterwards silently read the wrong
 * site.
 */
it('switches to another site in the network and restores afterwards', function (): void {
    $original = get_current_blog_id();
    $second = makeSite();

    expect($second)->not->toBe($original);

    $response = indexAsSuperAdmin($second);

    expect($response->get_status())->toBe(200)
        ->and(payload($response, 'site', 'id'))->toBe($second)
        ->and(get_current_blog_id())->toBe($original);
});

it('restores the original site after an error response', function (): void {
    $original = get_current_blog_id();

    expect(indexAsSuperAdmin(999999)->get_status())->toBe(404)
        ->and(get_current_blog_id())->toBe($original);
})->skip(! is_multisite(), networkOnly('a single site refuses every `?site=` before looking the site up'));

/*
 * A 401 or 403 is the commonest way a request into this namespace ends, and it
 * short-circuits `rest_pre_dispatch` AFTER the switch has already happened —
 * Site runs at priority 10, Auth at 11. That exit reaches neither
 * `rest_request_after_callbacks` nor `rest_post_dispatch`.
 */
it('restores the original site when authorization short-circuits after a switch', function (): void {
    $original = get_current_blog_id();
    $second = makeSite();

    wp_set_current_user(0);

    expect(indexAsSuperAdmin($second)->get_status())->toBe(401)
        ->and(get_current_blog_id())->toBe($original);
});

it('refuses an archived site', function (): void {
    $second = makeSite();
    update_blog_details($second, ['archived' => '1']);

    $response = indexAsSuperAdmin($second);

    expect($response->get_status())->toBe(409)
        ->and(errorCode($response))->toBe('uamswp_migration_api_unavailable_site');
});

it('refuses a deleted site', function (): void {
    $second = makeSite();
    update_blog_details($second, ['deleted' => '1']);

    expect(indexAsSuperAdmin($second)->get_status())->toBe(409);
});

it('refuses a spam site', function (): void {
    $second = makeSite();
    update_blog_details($second, ['spam' => '1']);

    expect(indexAsSuperAdmin($second)->get_status())->toBe(409);
});

/*
 * Configuration is per-site — post types and taxonomies are registered per site,
 * and options live in per-site tables. `Site::switchForRequest()` flushes the
 * memoized config on switch and `Site::restore()` flushes it again on the way back;
 * without the second flush the original site keeps reading the switched site's values.
 */
it('re-resolves configuration on each side of a switch', function (): void {
    $second = makeSite();

    $here = indexAsSuperAdmin();
    $there = indexAsSuperAdmin($second);
    $backHere = indexAsSuperAdmin();

    expect(payload($there, 'site', 'id'))->toBe($second)
        ->and(payload($backHere, 'site', 'id'))->toBe(payload($here, 'site', 'id'))
        ->and(payload($backHere, 'site', 'url'))->toBe(payload($here, 'site', 'url'));
});

it('re-resolves configuration for the TARGET site, so a switch is visible in the payload', function (): void {
    $second = makeSite();

    /*
     * The case `re-resolves configuration on each side of a switch` above does
     * NOT cover: that one asserts ids and urls differ, which they would even if
     * `Config::flush()` never ran, because `site.id` comes from
     * `get_current_blog_id()` rather than from configuration.
     *
     * `exposed` is the field that can only differ if configuration was
     * re-resolved. Registration is process-global (D-034), so the configured
     * list is the sole per-site lever — and `uamswp_migration_api_config` is
     * where a real deployment pulls it. Narrowing rather than widening,
     * because the endpoint intersects the configured list with what is
     * actually registered, so an added type would prove nothing.
     */
    $filter = static function (array $config) use ($second): array {
        if (get_current_blog_id() === $second) {
            $config['post_types'] = ['post'];
        }

        return $config;
    };

    add_filter('uamswp_migration_api_config', $filter);

    try {
        $here = indexAsSuperAdmin();
        $there = indexAsSuperAdmin($second);

        $hereTypes = payload($here, 'exposed', 'post_types');
        $thereTypes = payload($there, 'exposed', 'post_types');

        expect($thereTypes)->toBe(['post']);
        expect($hereTypes)->not->toBe($thereTypes);

        // And the origin is described the same way afterwards, so the flush
        // restores as well as switches.
        expect(payload(indexAsSuperAdmin(), 'exposed', 'post_types'))->toBe($hereTypes);
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
    }
});
