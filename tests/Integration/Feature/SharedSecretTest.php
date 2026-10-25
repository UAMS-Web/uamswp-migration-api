<?php

/**
 * The shared-secret authentication path.
 */

declare(strict_types=1);

/*
 * `UAMSWP_MIGRATION_API_SECRET` is a constant, so its two states cannot coexist in
 * one process. Each test below declares which process it belongs to and skips in
 * the other — a skip is visible in the output, where a silently-inverted branch is
 * not. `composer test` runs both processes.
 */

const SECRET_HEADER = 'X-UAMSWP-Migration-Key';

/**
 * Dispatch an otherwise-anonymous request carrying a secret header.
 */
function requestWithSecret(?string $secret): WP_REST_Response
{
    wp_set_current_user(0);

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    if ($secret !== null) {
        $request->set_header(SECRET_HEADER, $secret);
    }

    return rest_do_request($request);
}

/**
 * The secret this process was configured with.
 */
function configuredSecret(): string
{
    $secret = constant('UAMSWP_MIGRATION_API_SECRET');

    assert(is_string($secret), 'the test secret must be a string');

    return $secret;
}

// ── Process A: no secret configured ──────────────────────────────────────────

/*
 * The failure this guards against is a presented header being trusted because
 * something upstream went wrong — an unset constant read as an empty string, then
 * compared against an empty header. `Auth::secretMatches()` returns early on
 * `! defined()` for exactly that reason, and this is the only process in which
 * that branch is reachable.
 */
it('authorizes nothing when no secret is configured', function (): void {
    expect(requestWithSecret('any-value-at-all')->get_status())->toBe(401)
        ->and(requestWithSecret('')->get_status())->toBe(401)
        ->and(requestWithSecret(null)->get_status())->toBe(401);
})->skip(
    defined('UAMSWP_MIGRATION_API_SECRET'),
    'Runs in the process where no secret is configured.'
);

// ── Process B: secret configured ─────────────────────────────────────────────

/*
 * A secret defined as `''` is a third process, `EmptySecretTest.php`'s (D-098),
 * and these skip there: an empty secret is not a configured one.
 */

it('admits a request carrying the correct secret, with no WordPress user', function (): void {
    expect(requestWithSecret(configuredSecret())->get_status())->toBe(200);
})->skip(
    ! defined('UAMSWP_MIGRATION_API_SECRET') || constant('UAMSWP_MIGRATION_API_SECRET') === '',
    'Runs in the process where a secret is configured.'
);

it('refuses a wrong, empty or absent secret even when one is configured', function (): void {
    $correct = configuredSecret();

    expect(requestWithSecret('wrong')->get_status())->toBe(401)
        ->and(requestWithSecret('')->get_status())->toBe(401)
        ->and(requestWithSecret(null)->get_status())->toBe(401)
        // A prefix or an extension must not pass. `hash_equals()` is length-aware,
        // but a hand-rolled `str_starts_with` refactor would not be.
        ->and(requestWithSecret(substr($correct, 0, -1))->get_status())->toBe(401)
        ->and(requestWithSecret($correct.'x')->get_status())->toBe(401);
})->skip(
    ! defined('UAMSWP_MIGRATION_API_SECRET') || constant('UAMSWP_MIGRATION_API_SECRET') === '',
    'Runs in the process where a secret is configured.'
);
