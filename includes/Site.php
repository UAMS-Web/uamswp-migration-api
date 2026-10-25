<?php

/**
 * Multisite scoping.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Resolves and applies the site a request targets.
 *
 * Routes are registered on every site in the network, so any site's REST root
 * can serve any other site's data given `?site=<id>`. The switch happens in
 * `rest_pre_dispatch` — before permission callbacks run — so capability checks
 * and handlers both execute in the target site's context.
 */
final class Site
{
    /**
     * Response header naming the blog id that served the request.
     */
    public const SITE_HEADER = 'X-UAMSWP-Site';

    /**
     * Whether this request switched sites and still owes a restore.
     */
    private static bool $switched = false;

    /**
     * The blog id that answered this request, recorded while it is still knowable.
     *
     * `get_current_blog_id()` cannot be asked for this after the fact, and the
     * reason is the same one `isSwitched()` documents from the other direction:
     * by the time a response is stamped, the restore has already run, so the
     * current blog is the one that BOOTED the request rather than the one that
     * served it. A header read from it would name the wrong site on exactly the
     * requests where naming the site matters -- a true value attached to the
     * wrong subject, which is the failure `#272` exists to close rather than to
     * reproduce one layer out.
     *
     * Null until a request into this namespace is seen, so a response outside
     * the namespace is never stamped with a stale value from an earlier one.
     */
    private static ?int $answering = null;

    /**
     * Hook site switching around dispatch.
     *
     * The restore is wired to three hooks because `WP_REST_Server::dispatch()`
     * has three exits, and `rest_post_dispatch` covers none of them.
     *
     * That hook fires in `serve_request()` — the HTTP path — not in `dispatch()`.
     * `rest_do_request()` is nothing but `rest_get_server()->dispatch()`, so every
     * internal sub-request (core's own editor preloading, WP-CLI, another plugin,
     * this plugin's own test suite) returned with the blog still switched. The
     * `shutdown` backstop eventually put it back, but everything in between read
     * the wrong site and said nothing about it.
     *
     * - `rest_pre_dispatch` at the last priority catches a short-circuit. Filters
     *   on that hook all run even after one returns a value, so a non-null result
     *   here means `dispatch()` is about to return early — which is what happens
     *   on every 401 and 403 this plugin issues, since Auth runs at priority 11
     *   and the switch already happened at 10.
     * - `rest_request_after_callbacks` catches a route that ran. It fires inside
     *   `respond_to_request()`, so it reaches internal dispatch as well as HTTP.
     * - `rest_post_dispatch` stays for the HTTP path. By then the restore has
     *   usually happened already; `restore()` is idempotent.
     *
     * The remaining exit — a request into this namespace that matches no route —
     * fires none of them, and is left to the `shutdown` backstop.
     */
    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'switchForRequest'], 10, 3);
        add_filter('rest_pre_dispatch', [self::class, 'restoreIfShortCircuited'], PHP_INT_MAX, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'restoreAfterCallbacks'], PHP_INT_MAX, 3);
        add_filter('rest_post_dispatch', [self::class, 'restoreAfterRequest'], 10, 3);

        /*
         * Stamped on BOTH hooks, and the pair is not redundant. The exits the
         * restore comment above enumerates apply here unchanged:
         * `rest_request_after_callbacks` fires inside `respond_to_request()`, so
         * it reaches internal dispatch -- `rest_do_request()`, WP-CLI, this
         * plugin's own Feature suite -- which `rest_post_dispatch` never sees,
         * because that hook fires in `serve_request()` on the HTTP path alone.
         * A header wired only to the second would be absent from every internal
         * sub-request AND untestable by the suite that has to prove it.
         *
         * Priority 9 on `rest_post_dispatch`, ahead of the restore at 10, is
         * belt-and-braces rather than load-bearing: the value is read from a
         * recorded property and not from the current blog, so the order cannot
         * change what it reports.
         */
        add_filter('rest_request_after_callbacks', [self::class, 'stampAfterCallbacks'], PHP_INT_MAX, 3);
        add_filter('rest_post_dispatch', [self::class, 'stampAfterRequest'], 9, 3);
    }

    /**
     * Restore when something short-circuited dispatch before any route ran.
     *
     * @param  mixed  $result  Pre-dispatch value as filtered so far.
     * @param  WP_REST_Server  $server  REST server instance.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return mixed The unchanged $result.
     */
    public static function restoreIfShortCircuited(
        mixed $result,
        WP_REST_Server $server,
        WP_REST_Request $request,
    ): mixed {
        unset($server, $request);

        if ($result !== null) {
            self::restore();
        }

        return $result;
    }

    /**
     * Restore once the route callback has produced a response.
     *
     * @param  mixed  $response  Response or WP_Error from the callback.
     * @param  array<string, mixed>  $handler  Matched route handler.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return mixed The unchanged $response.
     */
    public static function restoreAfterCallbacks(
        mixed $response,
        array $handler,
        WP_REST_Request $request,
    ): mixed {
        unset($handler, $request);

        self::restore();

        return $response;
    }

    /**
     * Switch to the requested site before the route is dispatched.
     *
     * @param  mixed  $result  Pre-dispatch short-circuit value.
     * @param  WP_REST_Server  $server  REST server instance.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return mixed Unchanged $result, or WP_Error for an unusable site.
     */
    public static function switchForRequest(
        mixed $result,
        WP_REST_Server $server,
        WP_REST_Request $request,
    ): mixed {
        unset($server);

        // Only our namespace.
        if (! str_starts_with(ltrim($request->get_route(), '/'), REST_NAMESPACE)) {
            return $result;
        }

        /*
         * Recorded here, before anything can change it, and reset per request so
         * a response outside this namespace never carries a stale value from an
         * earlier one.
         *
         * WHAT ENDS UP STAMPED IS NARROWER THAN "EVERY RESPONSE", and the reason
         * is the dispatch exits enumerated in `register()` rather than anything
         * chosen here. An error returned from `rest_pre_dispatch` -- every 401
         * and 403, and the 400/404/409 this method itself returns -- short-
         * circuits `dispatch()` and reaches neither stamping hook. An error from
         * a route handler reaches `rest_request_after_callbacks` still a
         * `WP_Error`, which has no headers to set. Measured under internal
         * dispatch: 400, 401 and 404 all come back unstamped.
         *
         * Left that way deliberately. The header exists so a consumer can check
         * which site a PAYLOAD describes, and an error has no payload to
         * mis-attribute -- nothing was served from the wrong site. Widening it
         * to errors would mean converting a `WP_Error` early, which is core's
         * job and not a thing to do for a diagnostic.
         */
        self::$answering = get_current_blog_id();

        // Let an existing short-circuit stand.
        if ($result !== null) {
            return $result;
        }

        $requested = $request->get_param('site');

        if ($requested === null || $requested === '') {
            return $result;
        }

        if (! is_multisite()) {
            return new WP_Error(
                'uamswp_migration_api_not_multisite',
                __('This installation is not a multisite network, so the site parameter is not supported.', 'uamswp-migration-api'),
                ['status' => 400]
            );
        }

        // Mixed off the request even with a declared schema — a caller can
        // always send something the schema does not describe.
        $site_id = is_numeric($requested) ? (int) $requested : 0;

        if ($site_id < 1) {
            return new WP_Error(
                'uamswp_migration_api_invalid_site',
                __('The site parameter must be a positive integer blog id.', 'uamswp-migration-api'),
                ['status' => 400]
            );
        }

        if (get_current_blog_id() === $site_id) {
            return $result;
        }

        $site = get_site($site_id);

        if (! $site instanceof \WP_Site) {
            return new WP_Error(
                'uamswp_migration_api_unknown_site',
                __('No such site in this network.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        // Archived, deleted and spam sites are not migration sources.
        foreach (['archived', 'deleted', 'spam'] as $flag) {
            if ((string) $site->{$flag} === '1') {
                return new WP_Error(
                    'uamswp_migration_api_unavailable_site',
                    sprintf(
                        /* translators: %s: site state, one of archived, deleted, spam. */
                        __('That site is marked %s and cannot be read.', 'uamswp-migration-api'),
                        $flag
                    ),
                    ['status' => 409]
                );
            }
        }

        switch_to_blog($site_id);
        self::$switched = true;
        self::$answering = $site_id;

        // Post types, taxonomies and options are all per-site.
        Config::flush();

        return $result;
    }

    /**
     * Stamp the answering blog id onto a response.
     *
     * WHY THIS EXISTS (#272). Every route here is site-scoped and `?site=` is
     * optional, so an omitted parameter is served from whatever site handled
     * the request -- correct as a default, and impossible to report until now: nothing
     * in a response said which site it described. A consumer that dropped the
     * parameter received a well-formed `200` full of another site's content and
     * had nothing to check it against. This is the thing to check against.
     *
     * ON EVERY ROUTE, INCLUDING THE TWO THAT REFUSE `?site=`. The header states
     * which site served the request, which is a fact about the request rather
     * than a claim about the payload's scope, so it is true everywhere. For
     * `/post-types` and `/taxonomies` it is not merely harmless but the missing
     * half of D-034: registration is process-global *for whichever site handled
     * the request*, and until now nothing said which one that was. Omitting it
     * there would also make an absent header ambiguous between "process-global
     * route" and "deployment too old to send it", which is the distinction a
     * consumer most needs it to draw.
     *
     * IT ENABLES AN ASSERTION AND PERFORMS NONE. No consumer checks it today,
     * and making one check it is a different repository's decision.
     *
     * @param  mixed  $response  Response object, or a WP_Error from a callback.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function stamp(mixed $response, WP_REST_Request $request): mixed
    {
        if (! str_starts_with(ltrim($request->get_route(), '/'), REST_NAMESPACE)) {
            return $response;
        }

        if (self::$answering === null || ! $response instanceof WP_REST_Response) {
            return $response;
        }

        $response->header(self::SITE_HEADER, (string) self::$answering);

        return $response;
    }

    /**
     * Stamp on the hook that reaches internal dispatch as well as HTTP.
     *
     * Two entry points rather than one because the two hooks pass different
     * second arguments -- a handler array here, the server on `rest_post_dispatch`
     * -- exactly as the restore pair above already has to.
     *
     * @param  mixed  $response  Response object, or a WP_Error from a callback.
     * @param  array<string, mixed>  $handler  Route handler.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public static function stampAfterCallbacks(
        mixed $response,
        array $handler,
        WP_REST_Request $request,
    ): mixed {
        unset($handler);

        return self::stamp($response, $request);
    }

    /**
     * Stamp on the HTTP path.
     *
     * @param  WP_REST_Response  $response  Response object.
     * @param  WP_REST_Server  $server  REST server instance.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public static function stampAfterRequest(
        WP_REST_Response $response,
        WP_REST_Server $server,
        WP_REST_Request $request,
    ): WP_REST_Response {
        unset($server);

        $stamped = self::stamp($response, $request);

        return $stamped instanceof WP_REST_Response ? $stamped : $response;
    }

    /**
     * Restore the original site once dispatch completes.
     *
     * @param  WP_REST_Response  $response  Response object.
     * @param  WP_REST_Server  $server  REST server instance.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public static function restoreAfterRequest(
        WP_REST_Response $response,
        WP_REST_Server $server,
        WP_REST_Request $request,
    ): WP_REST_Response {
        unset($server, $request);

        self::restore();

        return $response;
    }

    /**
     * Restore the original site if one was switched away from.
     *
     * Safe to call repeatedly, and called from four places for the reasons in
     * `register()`. `Plugin::boot()` also wires it to `shutdown` as the backstop
     * for the one dispatch exit no hook covers.
     */
    /**
     * Whether this request was sent to a site other than the one that booted it.
     *
     * Exists for the discovery endpoints, which serve registration-scoped data.
     * Post types and taxonomies are registered *per process*, on `init`, for
     * whichever site handled the request — switching does not re-run that. So a
     * handler that would report registrations has to know it is standing
     * somewhere other than where the registry was built, and refuse rather than
     * serve one site's registrations beside another's counts.
     *
     * `get_current_blog_id()` cannot answer this: by the time a handler runs,
     * the switch on `rest_pre_dispatch` has already happened and the current
     * blog *is* the requested one.
     */
    public static function isSwitched(): bool
    {
        return self::$switched;
    }

    public static function restore(): void
    {
        if (! self::$switched) {
            return;
        }

        restore_current_blog();
        self::$switched = false;
        Config::flush();
    }

    /**
     * The `site` query parameter definition, shared by every route.
     *
     * @return array<string, mixed>
     */
    public static function param(): array
    {
        return [
            'description' => __('Blog id to read, for network installs. Defaults to the site handling the request.', 'uamswp-migration-api'),
            'type' => 'integer',
            'minimum' => 1,
            'sanitize_callback' => 'absint',
        ];
    }
}
