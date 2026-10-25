<?php

/**
 * The authorization boundary, exercised through real dispatch.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Auth;

use const UAMSWP\MigrationAPI\REST_NAMESPACE;

/*
 * Every test here dispatches. Calling a controller method directly would prove
 * almost nothing: `Auth::guardNamespace()` hangs off `rest_pre_dispatch`, which
 * only `WP_REST_Server::dispatch()` applies, so a direct call runs unauthenticated
 * against whatever site is current and passes.
 */

/**
 * Dispatch a GET against a route in this plugin's namespace.
 *
 * Named for the namespace rather than the verb: helpers declared in a test file
 * are global, shared with every other file in the same process, and a function
 * called `get()` would be a collision waiting for the second person to want one.
 */
function migrationApiGet(string $route): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request('GET', $route));
}

it('refuses an anonymous request to a namespace route', function (): void {
    $response = migrationApiGet('/uamswp-migration/v1/');

    expect($response->get_status())->toBe(401)
        ->and(errorCode($response))->toBe('uamswp_migration_api_unauthorized');
});

/*
 * The regression test for the bug this scaffold shipped with.
 *
 * WordPress generates a namespace index route for every registered namespace and
 * ships it with NO permission callback, so `/uamswp-migration/v1` answered 200 to
 * anonymous callers and disclosed the full route table with every argument. Reading
 * the diff did not catch it and static analysis could not; it took a dispatch
 * against a booted WordPress.
 *
 * Per-route permission callbacks structurally cannot cover this route, so if the
 * namespace-wide gate is ever removed in favour of them, this is what fails.
 */
it('gates the namespace index route WordPress generates for itself', function (): void {
    /*
     * Two distinct routes, one character apart. `/uamswp-migration/v1/` is
     * IndexController, registered with a permission callback. `/uamswp-migration/v1`
     * — no trailing slash — is core's generated index, which has none. Assert both
     * exist, so this cannot quietly become a test of the same route twice.
     */
    $routes = array_keys(rest_get_server()->get_routes());

    expect($routes)->toContain('/uamswp-migration/v1')
        ->and($routes)->toContain('/uamswp-migration/v1/')
        ->and(migrationApiGet('/uamswp-migration/v1')->get_status())->toBe(401);
});

it('refuses a logged-in user without the capability', function (): void {
    actAs('subscriber');

    $response = migrationApiGet('/uamswp-migration/v1/');

    expect($response->get_status())->toBe(403)
        ->and(errorCode($response))->toBe('uamswp_migration_api_forbidden');
});

/*
 * A site administrator is deliberately NOT enough. The API exposes drafts and raw
 * options across the whole network, which is a super-admin concern; `Auth::capability()`
 * resolves to `manage_network_options` on multisite for exactly that reason.
 */
it('refuses a site administrator who is not a super admin', function (): void {
    actAs('administrator');

    expect(migrationApiGet('/uamswp-migration/v1/')->get_status())->toBe(403);
})->skip(! is_multisite(), networkOnly('only a network separates a site administrator from a super admin'));

it('admits a super admin', function (): void {
    actAsSuperAdmin();

    expect(migrationApiGet('/uamswp-migration/v1/')->get_status())->toBe(200);
});

it('resolves the required capability to a network capability on multisite', function (): void {
    expect(Auth::capability())->toBe('manage_network_options');
})->skip(! is_multisite(), networkOnly('the capability is a network capability only on a network'));

it('lets the capability filter change what is required', function (): void {
    $filter = static fn (): string => 'read';

    add_filter('uamswp_migration_api_capability', $filter);

    try {
        actAs('subscriber');

        expect(Auth::capability())->toBe('read')
            ->and(migrationApiGet('/uamswp-migration/v1/')->get_status())->toBe(200);
    } finally {
        // Same callable identity, or the filter outlives this test — WordPress
        // matches by identity, and a stale filter makes later tests assert against
        // a capability nothing configured.
        remove_filter('uamswp_migration_api_capability', $filter);
    }
});

/*
 * The namespace gate's one unique job (#155): catching a controller that ships
 * a route in this namespace without `permission_callback`. Every route this
 * plugin actually registers goes through `Controller::registerReadRoute()`,
 * which always attaches one — so nothing already in the route table can
 * demonstrate the gap. Registering a bare route directly is the only way to
 * reproduce the shape the gate exists to catch, matching core's own generated
 * index route (which also carries no permission callback) rather than
 * inventing a hypothetical.
 */
it('refuses a route in this namespace that forgot its own permission callback', function (): void {
    // Core itself flags this via _doing_it_wrong() since WP 5.5.0 — expected
    // here, since reproducing the omission is the point of the test.
    $this->setExpectedIncorrectUsage('register_rest_route');

    register_rest_route(REST_NAMESPACE, '/__no-callback-test', [
        [
            'methods' => 'GET',
            'callback' => static fn (): WP_REST_Response => new WP_REST_Response(['reached' => true]),
        ],
    ]);

    expect(migrationApiGet('/uamswp-migration/v1/__no-callback-test')->get_status())->toBe(401);
});

/*
 * Direct call rather than dispatch, deliberately — this test isolates
 * `guardNamespace()`'s own early-return branch given a controlled `$result`,
 * not whether the hook is wired (the file's other tests already prove that
 * through dispatch). `Site::switchForRequest()` runs at priority 10, before
 * this gate at 11, and can short-circuit with its own error (e.g. a 409 for
 * a flagged site) — that error must survive even when auth would separately
 * have failed, or a site-availability response quietly turns into an auth one.
 */
it('lets an existing short-circuit (a Site error) stand instead of the auth result', function (): void {
    $siteError = new WP_Error('uamswp_migration_api_site_unavailable', 'stand-in for Site::switchForRequest()', ['status' => 409]);

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    // Anonymous request: authorize() would fail if it ran, which is the case
    // that makes RemoveEarlyReturn observable — a passing auth returns $result
    // either way, so only the failing-auth branch distinguishes the mutant.
    $result = Auth::guardNamespace($siteError, rest_get_server(), $request);

    expect($result)->toBe($siteError);
});

/*
 * The gate is scoped to this namespace, and this is the only test that says so
 * for a request the gate would REFUSE. Every other dispatch of a foreign route
 * runs as a super admin, where `authorize()` passes and returns `$result` --
 * so removing the non-namespace early return changed nothing any of them could
 * see (#295). Anonymous is the case that makes the scope observable: without
 * the early return, core's own public route answers 401 with this plugin's
 * error code, and every anonymous REST request on the site with it.
 */
it('leaves an anonymous request outside this namespace to core', function (): void {
    wp_set_current_user(0);

    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'));

    expect($response->get_status())->toBe(200)
        ->and(errorCode($response))->toBeNull();
});

/*
 * `Auth::capability(): string` is declared under `declare(strict_types=1)`,
 * so the `(string)` cast is load-bearing rather than decorative: without it, a
 * filter returning a non-string return type-errors instead of coercing.
 */
it('casts the capability filter result to a string', function (): void {
    $filter = static fn (): int => 42;

    add_filter('uamswp_migration_api_capability', $filter);

    try {
        expect(Auth::capability())->toBe('42');
    } finally {
        remove_filter('uamswp_migration_api_capability', $filter);
    }
});
