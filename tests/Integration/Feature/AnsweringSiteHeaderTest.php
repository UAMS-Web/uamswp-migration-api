<?php

/**
 * `X-UAMSWP-Site`: every response says which site answered it.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Site;

/**
 * Dispatch any route in this namespace as a super admin, optionally scoped.
 */
function dispatchScoped(string $route, ?int $site = null): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    if ($site !== null) {
        $request->set_param('site', $site);
    }

    return rest_do_request($request);
}

/**
 * The header under test, or null when the response does not carry it.
 */
function answeringSite(WP_REST_Response $response): ?string
{
    return headers($response)[Site::SITE_HEADER] ?? null;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

/*
 * The case #272 was filed for. An omitted `site` is served from whatever site
 * handled the request -- a deliberate default -- and before this header nothing
 * in the response said which site that was, so a consumer that dropped the
 * parameter could not tell it had.
 */
it('reports the serving site when no site parameter is given', function (): void {
    $serving = get_current_blog_id();
    $response = dispatchScoped('/');

    expect($response->get_status())->toBe(200)
        ->and(answeringSite($response))->toBe((string) $serving)
        /*
         * The load-bearing half: the header agrees with what the payload
         * actually DESCRIBES, not merely with some blog id. Asserted through
         * the same expected value rather than by casting the payload, because
         * `payload()` returns mixed and a cast would be the assertion doing the
         * narrowing instead of the test.
         */
        ->and(payload($response, 'site', 'id'))->toBe($serving);
});

it('reports the requested site when the request is scoped to another one', function (): void {
    $original = get_current_blog_id();
    $second = makeSite();

    expect($second)->not->toBe($original);

    $response = dispatchScoped('/', $second);

    expect($response->get_status())->toBe(200)
        ->and(answeringSite($response))->toBe((string) $second)
        ->and(payload($response, 'site', 'id'))->toBe($second)
        // Read AFTER the restore, which is the whole reason the id is recorded
        // at switch time rather than read from the current blog when stamping:
        // by now the current blog is the original one again.
        ->and(get_current_blog_id())->toBe($original)
        ->and(answeringSite($response))->not->toBe((string) $original);
});

/*
 * Criterion three of #272, decided in the build rather than raised: the two
 * process-global routes DO carry it. The header states which site served the
 * request, which is a fact about the request rather than a claim about the
 * payload's scope, and for these two it is the missing half of D-034 --
 * registration is process-global for whichever site handled the request, and
 * nothing said which one.
 */
it('carries the header on the routes that refuse a cross-site read', function (): void {
    foreach (['/post-types', '/taxonomies'] as $route) {
        $response = dispatchScoped($route);

        expect($response->get_status())->toBe(200)
            ->and(answeringSite($response))->toBe((string) get_current_blog_id());
    }
});

/*
 * A negative control that the stamping is namespace-gated. Without it, an
 * assertion that our routes carry the header is equally consistent with every
 * route on the site carrying it.
 */
it('does not stamp a route outside this namespace', function (): void {
    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'));

    expect($response->get_status())->toBe(200)
        ->and(answeringSite($response))->toBeNull();
});

/*
 * WHAT THIS FILE SPEAKS FOR, and it is narrower than it reads (#276). The
 * Feature suite dispatches through `rest_do_request()`, which is
 * `WP_REST_Server::dispatch()` and nothing else -- so `rest_post_dispatch`,
 * which fires in `serve_request()`, never runs here. Every assertion below is
 * about INTERNAL DISPATCH.
 *
 * Over real HTTP the scope is wider: an error carries the header too, because
 * `rest_post_dispatch` receives the `WP_Error` already converted and stamps it.
 * #272 recorded "successful responses only" on the strength of this file alone,
 * and that was a reading of one dispatch path generalized to both.
 * `scripts/verify-site-scoping.php` is where the HTTP claim is asserted,
 * because that is where this repository keeps claims needing the real install.
 *
 * These stay, and they are not redundant with it: internal dispatch is the path
 * WP-CLI, core's own preloading and this suite take, and a change that started
 * stamping errors there should still have to say so.
 */
it('does not carry the header on an error response, under internal dispatch', function (): void {
    $second = makeSite();

    // A handler-produced 400: the process-global routes refuse a cross-site read.
    $refused = dispatchScoped('/post-types', $second);

    expect($refused->get_status())->toBe(400)
        ->and(answeringSite($refused))->toBeNull();

    // A pre-dispatch 404, returned by the switch itself.
    $unknown = dispatchScoped('/', 999999);

    expect($unknown->get_status())->toBe(404)
        ->and(answeringSite($unknown))->toBeNull();

    // And the commonest exit of all: a 401, which short-circuits after the
    // switch has already happened.
    wp_set_current_user(0);
    $unauthenticated = dispatchScoped('/');

    expect($unauthenticated->get_status())->toBe(401)
        ->and(answeringSite($unauthenticated))->toBeNull();
});

/*
 * The exit that keeps the `shutdown` restore backstop alive, pinned here rather
 * than asserted in a comment (#276). `includes/Plugin.php` used to justify that
 * backstop by saying a `WP_Error` from `rest_pre_dispatch` skips
 * `rest_post_dispatch`; it does not. What actually reaches `shutdown` is the
 * fourth exit `Site::register()` names and leaves alone -- a request into this
 * namespace matching no route, which fires none of the restore hooks.
 *
 * Right conclusion, wrong reason. This test holds the conclusion.
 */
it('leaves the blog switched when a request into the namespace matches no route', function (): void {
    $original = get_current_blog_id();
    $second = makeSite();

    expect($second)->not->toBe($original);

    $response = dispatchScoped('/definitely-no-such-route', $second);

    expect($response->get_status())->toBe(404)
        // Not restored: no hook covers this exit, which is why `shutdown` does.
        ->and(get_current_blog_id())->toBe($second);

    // Leave the suite where it found things, since the backstop only runs at
    // the end of a real request.
    restore_current_blog();

    expect(get_current_blog_id())->toBe($original);
});
