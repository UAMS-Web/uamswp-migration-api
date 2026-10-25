<?php

/**
 * `rest_authentication_errors`: reporting that a credential was presented.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Auth;
use UAMSWP\MigrationAPI\Token;

/**
 * Put a request's route and credential where the filter reads them.
 *
 * The filter runs inside `check_authentication()`, which hands its callbacks
 * only the running `$result` -- so it reads the route back from where core put
 * it and the header from `$_SERVER`, and a test has to stand both up the same
 * way rather than dispatching a `WP_REST_Request`.
 */
function presentCredential(?string $route, ?string $token): void
{
    $wp = $GLOBALS['wp'] ?? null;

    if ($wp instanceof WP) {
        if ($route === null) {
            unset($wp->query_vars['rest_route']);
        } else {
            $wp->query_vars['rest_route'] = $route;
        }
    }

    if ($token === null) {
        unset($_SERVER['HTTP_X_UAMSWP_MIGRATION_KEY']);
    } else {
        $_SERVER['HTTP_X_UAMSWP_MIGRATION_KEY'] = $token;
    }
}

/**
 * What THIS PLUGIN reports for the state `presentCredential()` just set.
 *
 * The method is called directly rather than through `apply_filters()`, and the
 * reason is measured rather than stylistic: in this suite's environment the
 * chain answers `true` for an unauthenticated request whatever this plugin
 * does. Core's `rest_cookie_check_errors` at priority 100 is what returns it --
 * confirmed by removing that one callback, after which the same chain answers
 * `null`, and by running the chain with this plugin's filter removed, which
 * still answers `true`.
 *
 * So a test written against `apply_filters()` would pass on every assertion
 * here while measuring core's cookie check instead of the subject, and would
 * keep passing if this plugin's filter were deleted. The registration itself is
 * asserted separately below, which is the half that call-the-method-directly
 * gives up.
 */
function authenticationVerdict(mixed $running = null): mixed
{
    return Auth::answerAuthenticationErrors($running);
}

beforeEach(function (): void {
    /*
     * The suite boots WordPress, so `$GLOBALS['wp']` is a real `WP` -- which is
     * what the filter narrows to, and what makes the offset read analyzable.
     * Asserted rather than assumed: if it were not, every route assertion below
     * would silently be testing the `PATH_INFO` fallback instead.
     */
    expect($GLOBALS['wp'] ?? null)->toBeInstanceOf(WP::class);

    presentCredential(null, null);
});

afterEach(function (): void {
    presentCredential(null, null);
    unset($_SERVER['PATH_INFO']);
    wp_set_current_user(0);
});

it('reports true for a valid token on a route in this namespace', function (): void {
    $issued = Token::issue();

    presentCredential('/uamswp-migration/v1/options', $issued['token']);

    expect(authenticationVerdict())->toBeTrue();
});

/*
 * The criterion that keeps the filter honest. A credential this plugin issued
 * must not authenticate somebody else's route -- otherwise answering the filter
 * would hand every core endpoint a second accepted credential.
 */
it('leaves a route outside this namespace alone, even carrying a valid token', function (): void {
    $issued = Token::issue();

    presentCredential('/wp/v2/posts', $issued['token']);

    expect(authenticationVerdict())->toBeNull();
});

it('still reports null when no credential is presented', function (): void {
    presentCredential('/uamswp-migration/v1/options', null);

    expect(authenticationVerdict())->toBeNull();
});

it('still reports null for a credential that does not match', function (): void {
    Token::issue();

    presentCredential('/uamswp-migration/v1/options', 'not-the-issued-token');

    expect(authenticationVerdict())->toBeNull();
});

/*
 * Both directions, because this plugin must never convert another gate's
 * refusal into a pass -- and must not overwrite another gate's success either.
 */
it('returns an earlier verdict untouched, whichever way it went', function (): void {
    $issued = Token::issue();

    presentCredential('/uamswp-migration/v1/options', $issued['token']);

    $refusal = new WP_Error('somebody_elses_gate', 'Refused earlier.', ['status' => 401]);

    expect(authenticationVerdict($refusal))->toBe($refusal)
        ->and(authenticationVerdict(true))->toBeTrue();
});

/*
 * Reporting is not authorizing, and this is the assertion that says so.
 * `guardNamespace()` still decides, on the site actually being read, which the
 * filter cannot know because it runs before the site switch.
 */
it('sets no current user, so the request stays unauthenticated to WordPress', function (): void {
    $issued = Token::issue();

    presentCredential('/uamswp-migration/v1/options', $issued['token']);

    expect(authenticationVerdict())->toBeTrue()
        ->and(is_user_logged_in())->toBeFalse()
        ->and(get_current_user_id())->toBe(0);
});

/*
 * Core resolves the route from the query var OR from `PATH_INFO`, and the
 * filter mirrors both. Reading only the first answers `/` on a deployment
 * serving through `PATH_INFO` -- which does not start with this namespace, so
 * the filter would decline exactly the requests it exists for.
 */
it('resolves the route from PATH_INFO when the query var is absent', function (): void {
    $issued = Token::issue();

    presentCredential(null, $issued['token']);
    $_SERVER['PATH_INFO'] = '/uamswp-migration/v1/options';

    expect(authenticationVerdict())->toBeTrue();

    // And the control: the same fallback must not authenticate a foreign route.
    $_SERVER['PATH_INFO'] = '/wp/v2/posts';

    expect(authenticationVerdict())->toBeNull();
});

/*
 * An EMPTY query var is the case core's own fallback exists for:
 * `serve_request()` reads `PATH_INFO` when it is handed an empty path, not only
 * an absent one. The test above leaves the var unset, so it could not tell the
 * two apart -- and a resolver that took `''` as the route would decline every
 * request on a `PATH_INFO` deployment whose query var is set but empty (#295).
 */
it('falls back to PATH_INFO when the query var is present but empty', function (): void {
    $issued = Token::issue();

    presentCredential('', $issued['token']);
    $_SERVER['PATH_INFO'] = '/uamswp-migration/v1/options';

    expect(authenticationVerdict())->toBeTrue();
});

/*
 * Neither source present: the route is `/`, which is outside this namespace,
 * so a valid token is left unreported. The credential is valid on purpose --
 * a `null` here must come from the route decision, not from a refused token.
 */
it('treats a request with no route from either source as outside this namespace', function (): void {
    $issued = Token::issue();

    presentCredential(null, $issued['token']);
    unset($_SERVER['PATH_INFO']);

    expect(authenticationVerdict())->toBeNull();
});

/*
 * Only core's `WP` object is read for the route. Something else standing in
 * `$GLOBALS['wp']` -- a plugin's stand-in, a stub -- is not where core put the
 * route, so a `query_vars` it happens to carry must not be believed (#295).
 */
it("does not read the route from a global that is not core's WP object", function (): void {
    $issued = Token::issue();
    $original = $GLOBALS['wp'];

    presentCredential(null, $issued['token']);
    unset($_SERVER['PATH_INFO']);

    $impostor = new stdClass;
    $impostor->query_vars = ['rest_route' => '/uamswp-migration/v1/options'];
    $GLOBALS['wp'] = $impostor;

    try {
        expect(authenticationVerdict())->toBeNull();
    } finally {
        $GLOBALS['wp'] = $original;
    }
});

/*
 * The half that calling the method directly gives up: that it is actually WIRED,
 * and at the priority the reasoning depends on. `wp-force-login` registers at 99
 * and yields on a non-null verdict, so answering at 10 lands ahead of it; a
 * silent move to 100 would leave the plugin correct and the defect unfixed.
 */
it('is registered on the filter at the priority the fix depends on', function (): void {
    expect(has_filter('rest_authentication_errors', [Auth::class, 'answerAuthenticationErrors']))->toBe(10);
});

/*
 * Recorded because it bit this test file and would bite the next one: in this
 * environment the whole chain reports `true` for a request carrying nothing at
 * all, and it is core's doing rather than this plugin's.
 */
it('documents why the chain cannot be asserted end to end here', function (): void {
    presentCredential('/wp/v2/posts', null);

    // The chain, with this plugin's filter present and no credential offered.
    expect(apply_filters('rest_authentication_errors', null))->toBeTrue();

    // The same chain with core's cookie check removed answers null, which
    // identifies the source and rules this plugin out as the cause.
    remove_filter('rest_authentication_errors', 'rest_cookie_check_errors', 100);

    try {
        expect(apply_filters('rest_authentication_errors', null))->toBeNull();
    } finally {
        add_filter('rest_authentication_errors', 'rest_cookie_check_errors', 100);
    }
});
