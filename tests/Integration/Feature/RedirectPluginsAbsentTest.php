<?php

/**
 * The redirect routes in a process that loaded neither redirect plugin.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\REST\RedirectsController;

/*
 * Pins the `uamswp_migration_api_redirect_plugin_not_loaded` 404 on both
 * redirect routes, with and without `?site=` (#363).
 *
 * Redirection and Pretty Links are dev dependencies of `tests/Integration`, and
 * the bootstrap refuses to run without them, so this branch runs only in an
 * invocation with `UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS=1`, which
 * loads neither (D-097):
 *
 *     UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS=1 \
 *       tests/Integration/vendor/bin/pest --configuration=tests/Integration/phpunit.xml \
 *       --test-directory=. tests/Integration/Feature/RedirectPluginsAbsentTest.php
 *
 * In every other run, `composer test` and the chain included, both plugins are
 * loaded and every test here skips.
 */

/**
 * Dispatch an authenticated GET against a redirect route.
 *
 * @param  array<string, mixed>  $params  Query parameters.
 */
function redirectsAbsentGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    if (RedirectsController::redirectionLoaded() || RedirectsController::prettyLinksLoaded()) {
        $this->markTestSkipped('A redirect plugin is loaded; run with UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS=1.');
    }

    actAsSuperAdmin();
});

it('answers 404 when the plugin is not loaded, naming the site to ask when one was given', function (string $route, string $plugin): void {
    $site = get_current_blog_id();

    $withoutSite = redirectsAbsentGet($route);
    $withSite = redirectsAbsentGet($route, ['site' => $site]);

    expect($withoutSite->get_status())->toBe(404)
        ->and(errorCode($withoutSite))->toBe('uamswp_migration_api_redirect_plugin_not_loaded')
        ->and(payload($withoutSite, 'message'))->toBe("{$plugin} is not loaded in the process handling this request.")
        ->and($withSite->get_status())->toBe(404)
        ->and(errorCode($withSite))->toBe('uamswp_migration_api_redirect_plugin_not_loaded')
        ->and(payload($withSite, 'message'))->toBe("{$plugin} is not loaded in the process handling this request, so its rules on site {$site} cannot be read here even where `/sites` reports it active, because plugins load for the site that booted the request: ask site {$site} through its own REST root.");
})->with([
    'Redirection' => ['/redirects/redirection', 'Redirection'],
    'Pretty Links' => ['/redirects/pretty-links', 'Pretty Links'],
]);
