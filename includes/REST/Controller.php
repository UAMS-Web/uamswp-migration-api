<?php

/**
 * Base REST controller.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use UAMSWP\MigrationAPI\Auth;
use UAMSWP\MigrationAPI\Site;
use WP_Error;
use WP_REST_Request;

use const UAMSWP\MigrationAPI\REST_NAMESPACE;

/**
 * Shared plumbing for every controller in this namespace.
 *
 * Subclasses declare routes; authentication and the `site` parameter are wired
 * here so no route can accidentally ship without them.
 */
abstract class Controller
{
    /**
     * Register this controller's routes.
     */
    abstract public function registerRoutes(): void;

    /**
     * The REST namespace every route lives under.
     *
     * @return non-falsy-string
     */
    protected function namespace(): string
    {
        return REST_NAMESPACE;
    }

    /**
     * Permission callback applied to every route.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return true|WP_Error
     */
    public function authorize(WP_REST_Request $request): bool|WP_Error
    {
        return Auth::authorize($request);
    }

    /**
     * Register a read-only route with auth and the site parameter attached.
     *
     * @param  non-falsy-string  $route  Route pattern, relative to the namespace.
     * @param  callable  $handler  Request handler.
     * @param  array<string, array<string,mixed>>  $args  Additional parameter definitions.
     */
    protected function registerReadRoute(
        string $route,
        callable $handler,
        array $args = [],
    ): void {
        register_rest_route(
            $this->namespace(),
            $route,
            [
                [
                    'methods' => 'GET',
                    'callback' => $handler,
                    'permission_callback' => [$this, 'authorize'],
                    'args' => array_merge(
                        ['site' => Site::param()],
                        $args
                    ),
                ],
            ]
        );
    }

    /*
     * Request narrowing, shared by every controller rather than copied into
     * each. `WP_REST_Request::offsetGet()` hands back `mixed` whatever the
     * route pattern promised, so each of these turns an unusable value into
     * one that fails downstream rather than a plausible one.
     */

    /**
     * A route parameter as a string.
     *
     * The route regex already constrains both of them, but a request object
     * hands back `mixed` and the analyzer is right not to take the pattern's
     * word for it. An unusable value becomes the empty string, which fails
     * every check downstream rather than being coerced into a plausible one.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    protected static function stringParam(WP_REST_Request $request, string $key): string
    {
        $value = $request[$key];

        return \is_string($value) ? $value : '';
    }

    /**
     * A route parameter as an integer.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    protected static function intParam(WP_REST_Request $request, string $key): int
    {
        $value = $request[$key];

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A request parameter as a list of positive integers.
     *
     * @return list<int>
     */
    protected static function intList(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = is_numeric($item) ? (int) $item : 0;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
