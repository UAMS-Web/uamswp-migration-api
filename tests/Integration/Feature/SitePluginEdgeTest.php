<?php

/**
 * The site switch and the plugin bootstrap, past the paths the endpoint tests take.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Auth;
use UAMSWP\MigrationAPI\Plugin;
use UAMSWP\MigrationAPI\REST\IndexController;
use UAMSWP\MigrationAPI\Site;

/*
 * Pins the branches of `Site` and `Plugin` that the endpoint tests never reach
 * (#363): a site parameter that is not a positive integer, a request another
 * filter has already answered, the `rest_post_dispatch` callbacks, and the
 * bootstrap itself.
 *
 * `Plugin::boot()` and `Site::register()` run while the suite boots, before
 * coverage collection starts, so a test that measures them boots a second
 * instance. Every callback that boot adds is a static method except the new
 * instance's `registerRoutes`, so adding them again changes nothing, and that
 * one is removed afterwards.
 */

/**
 * Dispatch the index route as a super admin with a raw site parameter.
 */
function siteEdgeIndex(mixed $site = null): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    if ($site !== null) {
        $request->set_param('site', $site);
    }

    return rest_do_request($request);
}

/**
 * How many callbacks a hook holds, across every priority.
 */
function siteEdgeCallbackCount(string $hook): int
{
    $count = 0;

    foreach (siteEdgeHook($hook) as $callbacks) {
        $count += is_array($callbacks) ? count($callbacks) : 0;
    }

    return $count;
}

/**
 * A hook's callbacks, keyed by priority.
 *
 * @return array<mixed>
 */
function siteEdgeHook(string $hook): array
{
    $hooks = $GLOBALS['wp_filter'] ?? null;
    $registered = is_array($hooks) ? ($hooks[$hook] ?? null) : null;

    return $registered instanceof WP_Hook ? $registered->callbacks : [];
}

/**
 * The ACF object whose `initialize()` is hooked on `rest_pre_dispatch`, if any.
 */
function siteEdgeAcfRestApi(): ?ACF_Rest_Api
{
    $callbacks = siteEdgeHook('rest_pre_dispatch')[10] ?? null;

    foreach (is_array($callbacks) ? $callbacks : [] as $callback) {
        $function = is_array($callback) ? ($callback['function'] ?? null) : null;

        if (is_array($function) && ($function[0] ?? null) instanceof ACF_Rest_Api) {
            return $function[0];
        }
    }

    return null;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

describe('Site', function (): void {
    it('refuses a site parameter that is not a positive integer', function (string|int $site): void {
        // The control: a real blog id is served.
        expect(siteEdgeIndex(get_current_blog_id())->get_status())->toBe(200);

        $response = siteEdgeIndex($site);

        expect($response->get_status())->toBe(400)
            ->and(errorCode($response))->toBe('uamswp_migration_api_invalid_site');
    })->with([
        'not a number' => ['abc'],
        'negative' => [-3],
        'zero as a string' => ['0'],
    ]);

    it('passes on an answer another filter gave first, without switching', function (): void {
        $original = get_current_blog_id();
        $second = makeSite();
        $ran = 0;

        $answer = static function () use (&$ran): WP_Error {
            $ran++;

            return new WP_Error('earlier_filter', 'Answered before the site switch.', ['status' => 418]);
        };

        // The control: the same request without the earlier answer is served by
        // the second site.
        expect(payload(siteEdgeIndex($second), 'site', 'id'))->toBe($second);

        // ACF PRO's `ACF_Rest_Api::initialize()` is hooked here at priority 10,
        // ahead of the switch, and returns null whatever it was given, so it
        // discards an earlier answer before the switch sees one. It is taken
        // off for this request and put back.
        $acf = siteEdgeAcfRestApi();

        if ($acf instanceof ACF_Rest_Api) {
            remove_filter('rest_pre_dispatch', [$acf, 'initialize'], 10);
        }

        add_filter('rest_pre_dispatch', $answer, 5);

        try {
            $response = siteEdgeIndex($second);
        } finally {
            remove_filter('rest_pre_dispatch', $answer, 5);

            if ($acf instanceof ACF_Rest_Api) {
                add_filter('rest_pre_dispatch', [$acf, 'initialize'], 10, 3);
            }
        }

        expect($ran)->toBe(1)
            ->and(errorCode($response))->toBe('earlier_filter')
            ->and(Site::isSwitched())->toBeFalse()
            ->and(get_current_blog_id())->toBe($original);
    });

    it('stamps the answering site and restores on rest_post_dispatch', function (): void {
        $original = get_current_blog_id();
        $second = makeSite();

        $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');
        $request->set_param('site', $second);

        // `rest_do_request()` never applies `rest_post_dispatch`, so the filter
        // is applied here as `WP_REST_Server::serve_request()` applies it, with
        // the switch made by the request still in place.
        rest_get_server()->dispatch($request);

        $switchedBefore = Site::isSwitched() || get_current_blog_id() !== $original;

        switch_to_blog($second);
        $reflection = new ReflectionProperty(Site::class, 'switched');
        $reflection->setValue(null, true);

        $response = apply_filters('rest_post_dispatch', new WP_REST_Response([]), rest_get_server(), $request);

        // The control: a route outside this namespace is not stamped.
        $outside = apply_filters('rest_post_dispatch', new WP_REST_Response([]), rest_get_server(), new WP_REST_Request('GET', '/wp/v2/posts'));

        assert($response instanceof WP_REST_Response && $outside instanceof WP_REST_Response);

        expect($switchedBefore)->toBeFalse()
            ->and($response->get_headers()[Site::SITE_HEADER] ?? null)->toBe((string) $second)
            ->and($outside->get_headers())->not->toHaveKey(Site::SITE_HEADER)
            ->and(Site::isSwitched())->toBeFalse()
            ->and(get_current_blog_id())->toBe($original);
    });
});

describe('Plugin', function (): void {
    it('returns one shared instance, and booting it again adds nothing', function (): void {
        $instance = Plugin::instance();

        expect(Plugin::instance())->toBe($instance);

        $before = siteEdgeCallbackCount('rest_api_init');
        $instance->boot();

        expect(siteEdgeCallbackCount('rest_api_init'))->toBe($before);
    });

    it('wires the site switch ahead of the auth gate when it boots', function (): void {
        $fresh = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $before = siteEdgeCallbackCount('rest_api_init');

        $fresh->boot();

        try {
            // The control for the test above: a fresh instance does add its own
            // route registration, so the count can move.
            expect(siteEdgeCallbackCount('rest_api_init'))->toBe($before + 1)
                ->and(has_filter('rest_pre_dispatch', [Site::class, 'switchForRequest']))->toBe(10)
                ->and(has_filter('rest_pre_dispatch', [Auth::class, 'guardNamespace']))->toBe(11)
                ->and(has_filter('rest_pre_dispatch', [Site::class, 'restoreIfShortCircuited']))->toBe(PHP_INT_MAX)
                ->and(has_filter('rest_post_dispatch', [Site::class, 'stampAfterRequest']))->toBe(9)
                ->and(has_filter('rest_post_dispatch', [Site::class, 'restoreAfterRequest']))->toBe(10)
                ->and(has_filter('rest_authentication_errors', [Auth::class, 'answerAuthenticationErrors']))->toBe(10)
                ->and(has_action('shutdown', [Site::class, 'restore']))->toBe(0);
        } finally {
            remove_action('rest_api_init', [$fresh, 'registerRoutes']);
        }

        expect(siteEdgeCallbackCount('rest_api_init'))->toBe($before);
    });

    it('keeps no controllers when the controllers filter returns something other than a list', function (): void {
        $only = new ReflectionMethod(Plugin::class, 'onlyControllers');
        $index = new IndexController;

        // The control: a list keeps its controllers and drops the rest.
        expect($only->invoke(null, [$index, 'not a controller']))->toBe([$index])
            ->and($only->invoke(null, 'not a list'))->toBe([]);
    });
});
