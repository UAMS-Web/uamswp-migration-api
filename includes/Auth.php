<?php

/**
 * Authentication boundary.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Gate for every route in this namespace.
 *
 * The API serves unpublished content and raw site settings, so there is no
 * anonymous path — deliberately. Two credentials are accepted:
 *
 * 1. A WordPress user (application password over HTTPS is the intended form)
 *    holding the required capability on the requested site.
 * 2. A shared secret in `UAMSWP_MIGRATION_API_SECRET`, presented in the
 *    `X-UAMSWP-Migration-Key` header, for machine-to-machine use where no WP
 *    user should exist.
 * 3. A per-network token issued from the plugin's admin page (`Token`),
 *    presented in that same header. This is the credential this migration
 *    uses; the shared constant stays for the path that already exists.
 *
 * All three fail identically. Unset, mismatched, mistyped and correctly-refused
 * are one `401` with one message, so the response discriminates nothing an
 * attacker does not already know.
 */
final class Auth
{
    /**
     * Header carrying the shared secret.
     */
    private const SECRET_HEADER = 'X-UAMSWP-Migration-Key';

    /**
     * Constant that, when defined in wp-config.php, enables secret auth.
     */
    private const SECRET_CONSTANT = 'UAMSWP_MIGRATION_API_SECRET';

    /**
     * Gate the entire namespace before dispatch.
     *
     * Per-route permission callbacks cannot cover the namespace index route,
     * which WordPress generates itself for every registered namespace and ships
     * with no permission callback at all. That route discloses the full route
     * table and every argument, so leaving it anonymous would contradict the
     * "no anonymous fallback path" rule even though no content leaks through it.
     *
     * Gating here catches core's route as well as our own, and means no future
     * controller can ship an unauthenticated route by forgetting a callback.
     *
     * Runs at priority 11, after Site::switchForRequest() at 10, so capability
     * checks apply to the site actually being read.
     *
     * @param  mixed  $result  Pre-dispatch short-circuit value.
     * @param  WP_REST_Server  $server  REST server instance.
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return mixed Unchanged $result, or WP_Error when unauthorized.
     */
    public static function guardNamespace(
        mixed $result,
        WP_REST_Server $server,
        WP_REST_Request $request
    ): mixed {
        unset($server);

        if (! str_starts_with(ltrim($request->get_route(), '/'), REST_NAMESPACE)) {
            return $result;
        }

        // Let an existing short-circuit (including a Site error) stand.
        if ($result !== null) {
            return $result;
        }

        $authorized = self::authorize($request);

        return $authorized instanceof WP_Error ? $authorized : $result;
    }

    /**
     * Permission callback for every route in this namespace.
     *
     * Retained alongside the namespace gate above: defense in depth, and it
     * keeps each route self-describing about what it requires.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     * @return true|WP_Error
     */
    public static function authorize(WP_REST_Request $request): bool|WP_Error
    {
        if (self::secretMatches($request)) {
            return true;
        }

        if (self::tokenMatches($request)) {
            return true;
        }

        if (! is_user_logged_in()) {
            return new WP_Error(
                'uamswp_migration_api_unauthorized',
                __('Authentication required.', 'uamswp-migration-api'),
                ['status' => 401]
            );
        }

        if (! current_user_can(self::capability())) {
            return new WP_Error(
                'uamswp_migration_api_forbidden',
                __('You do not have permission to read migration data for this site.', 'uamswp-migration-api'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Capability required for user-authenticated access.
     *
     * Defaults to a network-level capability: this API exposes drafts and raw
     * options across the network, which is a super-admin concern rather than a
     * per-site editor one.
     */
    public static function capability(): string
    {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';

        /**
         * Filters the capability required to read the migration API.
         *
         * @param  string  $capability  Capability name.
         */
        return (string) apply_filters('uamswp_migration_api_capability', $capability);
    }

    /**
     * Whether the request carries a valid shared secret.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function secretMatches(WP_REST_Request $request): bool
    {
        return self::secretAccepts((string) $request->get_header(self::SECRET_HEADER));
    }

    /**
     * Whether a presented credential is the configured shared secret.
     *
     * Split from the request-taking form above so the same comparison can be
     * reached from `rest_authentication_errors`, which fires before any
     * `WP_REST_Request` is available to the filter (#290). The request-taking
     * method is now a header read plus this; nothing about the comparison
     * changed, and `authorize()` still calls the same method it always did.
     *
     * @param  string  $presented  Credential presented in `SECRET_HEADER`.
     */
    private static function secretAccepts(string $presented): bool
    {
        if (! \defined(self::SECRET_CONSTANT)) {
            return false;
        }

        $expected = constant(self::SECRET_CONSTANT);

        // A non-string, empty, or unset constant must never authorize anything.
        if (! \is_string($expected) || $expected === '') {
            return false;
        }

        if ($presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /**
     * Whether the request carries a valid per-network token.
     *
     * Shares `SECRET_HEADER` with the constant path above, so adopting a token
     * needs no consumer change: the same header carries whichever credential
     * the deployment was given.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function tokenMatches(WP_REST_Request $request): bool
    {
        $presented = (string) $request->get_header(self::SECRET_HEADER);

        // Equivalent, not merely hard to kill: an empty presented token that
        // fell through would reach Token::matches(''), and Token::verify()
        // already refuses one at its own layer (Token.php:172) with the
        // identical `return false`. No test can distinguish this early
        // return from the fall-through case, because both produce the same
        // observable result for every input. Kept anyway as defense in
        // depth, so a future change to Token::verify() is not the only thing
        // standing between an empty credential and a match.
        //
        // Both mutators named below target this one guard -- the condition
        // and the early return it guards -- so one annotation covers both;
        // see #133 on why it has to precede the `if`, not the `return`.
        // @pest-mutate-ignore:EmptyStringToNotEmpty,RemoveEarlyReturn
        if ($presented === '') {
            return false;
        }

        return Token::matches($presented);
    }

    /**
     * The route this request is being served for, resolved as core resolves it.
     *
     * `rest_authentication_errors` is applied by `check_authentication()`, which
     * receives only the running `$result` -- no request, no route. So the route
     * is read back from where core put it, and BOTH of core's branches are
     * mirrored rather than one: `rest_api_loaded()` passes
     * `$GLOBALS['wp']->query_vars['rest_route']` to `serve_request()`, and
     * `serve_request()` itself falls back to `PATH_INFO` when it is handed an
     * empty path. Reading only the first would answer `/` for a deployment
     * serving the API through `PATH_INFO`, and `/` does not start with this
     * namespace -- so the filter would decline to authenticate the very
     * requests it exists for, silently and only on those deployments.
     */
    private static function currentRestRoute(): string
    {
        $wp = $GLOBALS['wp'] ?? null;

        // Narrowed to `WP` rather than to `object`: `query_vars` is a declared
        // property there, so the offset read below is analyzable at level max
        // without a cast doing the narrowing the check should be doing.
        // `WP::$query_vars` is declared `array`, so no `is_array()` here: level
        // max reports that check as always-true, and adding it back to look
        // careful would be the redundancy the analyzer is pointing at.
        if ($wp instanceof \WP) {
            $route = $wp->query_vars['rest_route'] ?? null;

            if (\is_string($route) && $route !== '') {
                return $route;
            }
        }

        $pathInfo = $_SERVER['PATH_INFO'] ?? null;

        // Equivalent for EmptyStringToNotEmpty (#295): the mutant answers `''`
        // where this answers `/` for an empty `PATH_INFO`, and the only caller
        // tests the route against this namespace -- which neither starts with.
        // The `&&` itself is not covered by this, and a test kills its mutant.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        return \is_string($pathInfo) && $pathInfo !== '' ? $pathInfo : '/';
    }

    /**
     * Report to WordPress that this request carried a credential (#290).
     *
     * WHY THIS EXISTS. `rest_authentication_errors` has a three-value contract:
     * `WP_Error` for an authentication failure, `true` for success, and `null`
     * for "this authentication method was not used". Answering nothing leaves
     * `null`, which says no method was attempted -- untrue of a request
     * presenting `SECRET_HEADER`, and a lie with consequences: a front-gate
     * plugin reading `null === $result` refuses the request before
     * `rest_pre_dispatch` runs, so `guardNamespace()` never sees it and a valid
     * token is never read. Measured on a real site in #290.
     *
     * IT REPORTS; IT DOES NOT AUTHORIZE. Returning `true` here says a
     * credential was presented and recognized, nothing more.
     * `guardNamespace()` and `authorize()` are untouched and still decide
     * whether the request may proceed, on the site it is actually reading --
     * which this filter cannot know, because it runs before the site switch.
     * No current user is set, so `is_user_logged_in()` stays false throughout.
     *
     * SCOPED TO THIS NAMESPACE, which is the half that keeps it honest. A valid
     * token must not authenticate an unrelated core route, so a request outside
     * `REST_NAMESPACE` is answered with the running `$result` untouched
     * whatever headers it carries.
     *
     * @param  mixed  $result  The running verdict from earlier filters.
     */
    public static function answerAuthenticationErrors(mixed $result): mixed
    {
        // An earlier filter already decided. Never overrule one -- including a
        // refusal, which this plugin has no business converting into a pass.
        if ($result !== null) {
            return $result;
        }

        if (! str_starts_with(ltrim(self::currentRestRoute(), '/'), REST_NAMESPACE)) {
            return $result;
        }

        $presented = $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', self::SECRET_HEADER))] ?? null;

        // Equivalent for EmptyStringToNotEmpty (#295): an empty credential let
        // through here is refused by both readers below, `secretAccepts()` and
        // `Token::verify()` each returning false for `''`, so `$result` comes
        // back either way. Kept so neither reader is the only thing between an
        // empty header and a `true`.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        if (! \is_string($presented) || $presented === '') {
            return $result;
        }

        return self::secretAccepts($presented) || self::tokenAccepts($presented) ? true : $result;
    }

    /**
     * Whether a presented credential is a valid per-network token.
     *
     * The string-taking half of `tokenMatches()`, split for the same reason
     * `secretAccepts()` was: the filter above has no request to read.
     *
     * @param  string  $presented  Credential presented in `SECRET_HEADER`.
     */
    private static function tokenAccepts(string $presented): bool
    {
        // No `''` guard here: the filter's own guard runs first, so an empty
        // credential never arrives, and `Token::verify()` refuses one itself
        // besides (D-098).
        return Token::matches($presented);
    }
}
