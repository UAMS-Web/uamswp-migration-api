<?php

/**
 * The shared-secret path in a process whose secret is defined and empty.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Token;

/*
 * Pins the refusal when `UAMSWP_MIGRATION_API_SECRET` is defined as `''` (#363).
 * A constant cannot change once set, so this state runs only in an invocation
 * with `UAMSWP_MIGRATION_API_TESTS_EMPTY_SECRET=1` (D-098):
 *
 *     UAMSWP_MIGRATION_API_TESTS_EMPTY_SECRET=1 \
 *       tests/Integration/vendor/bin/pest --configuration=tests/Integration/phpunit.xml \
 *       --test-directory=. tests/Integration/Feature/EmptySecretTest.php
 *
 * In every other run, `composer test` and the chain included, the constant is
 * undefined or holds the test secret, and every test here skips.
 */

/**
 * Dispatch an otherwise-anonymous request carrying a credential header.
 */
function emptySecretRequest(?string $credential): WP_REST_Response
{
    wp_set_current_user(0);

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    if ($credential !== null) {
        $request->set_header('X-UAMSWP-Migration-Key', $credential);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    if (! defined('UAMSWP_MIGRATION_API_SECRET') || constant('UAMSWP_MIGRATION_API_SECRET') !== '') {
        $this->markTestSkipped('The secret is not defined as empty; run with UAMSWP_MIGRATION_API_TESTS_EMPTY_SECRET=1.');
    }
});

it('authorizes nothing with an empty secret, while a valid token still passes', function (): void {
    // The control: the same anonymous request is admitted by a token, so a
    // refusal below is about the secret rather than the request.
    $token = Token::issue()['token'];

    expect(emptySecretRequest($token)->get_status())->toBe(200)
        ->and(emptySecretRequest('any-value-at-all')->get_status())->toBe(401)
        ->and(emptySecretRequest('')->get_status())->toBe(401)
        ->and(emptySecretRequest(null)->get_status())->toBe(401);
});
