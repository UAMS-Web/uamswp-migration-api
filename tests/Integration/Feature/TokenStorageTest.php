<?php

/**
 * The token's storage half, against a real WordPress.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Token;

/*
 * The pure core — hashing, the overlap window, what authenticates when — is
 * `tests/Unit/TokenTest.php` and runs without WordPress. What needs WordPress is
 * everything about WHERE the record lands and what the API does with it.
 *
 * **This suite runs as multisite, so it cannot take the single-site branch.**
 * `is_multisite()` reads the `MULTISITE` constant, which is fixed for the
 * process. Two of the properties #141 asks about are therefore proved here in
 * the strongest form this install allows, and the residue is named rather than
 * ticked:
 *
 * - *stored hashed on both branches* — the write path is one code path with no
 *   `is_multisite()` branch in it, and the test below asserts the plaintext
 *   appears nowhere in what was stored. What is deferred is inspecting storage
 *   on a genuinely single-site install.
 * - *`GET /options` does not serve it* — asserted by putting the option where
 *   the single-site branch would put it (`wp_options`) and dispatching the real
 *   endpoint, rather than by reading the denylist and agreeing with it.
 */

/**
 * Dispatch a namespace route carrying a token in the shared header.
 */
function tokenRequest(?string $token): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    if ($token !== null) {
        $request->set_header('X-UAMSWP-Migration-Key', $token);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    delete_network_option(null, Token::OPTION);
    delete_option(Token::OPTION);
});

it('stores a hash and never the token itself', function (): void {
    $issued = Token::issue();

    $stored = Token::record();
    $current = \is_array($stored) ? ($stored['current'] ?? null) : null;
    $current = \is_array($current) ? $current : [];

    // Serialize the whole record rather than checking the field that is
    // supposed to hold the hash. A test that reads `current.hash` cannot see a
    // plaintext copy left in a field nobody thought about, which is exactly the
    // way this would go wrong.
    expect(serialize($stored))->not->toContain($issued['token']);
    expect($current)->not->toBeEmpty();
    expect($current['hash'] ?? null)->toBe(Token::hash($issued['token']));
    expect($current['hash'] ?? null)->not->toBe($issued['token']);
    expect($current['identifier'] ?? null)->toBe($issued['identifier']);
});

it('authenticates the issued token and refuses a wrong one', function (): void {
    $issued = Token::issue();

    expect(Token::matches($issued['token']))->toBeTrue();
    expect(Token::matches($issued['token'].'x'))->toBeFalse();
    expect(Token::matches(''))->toBeFalse();
});

it('refuses everything before a token has been issued', function (): void {
    expect(Token::record())->toBeNull();
    expect(Token::matches('anything'))->toBeFalse();

    // The empty string is what `get_header()` yields for a header that was
    // never sent, so this is the anonymous case rather than a curiosity.
    expect(Token::matches(''))->toBeFalse();
});

it('keeps the previous token working through the overlap window', function (): void {
    $first = Token::issue();
    $second = Token::issue();

    expect($first['token'])->not->toBe($second['token']);
    expect(Token::matches($second['token']))->toBeTrue();
    expect(Token::matches($first['token']))->toBeTrue();

    $record = Token::record();
    $previous = \is_array($record) ? ($record['previous'] ?? null) : null;
    $previous = \is_array($previous) ? $previous : [];

    expect($previous)->not->toBeEmpty();
    expect($previous['identifier'] ?? null)->toBe($first['identifier']);
    expect($previous['expires'] ?? null)->toBeGreaterThan(time());
});

it('drops the previous token when the window is closed', function (): void {
    $filter = static fn (): int => 0;
    add_filter('uamswp_migration_api_token_rotation_window', $filter);

    $first = Token::issue();
    $second = Token::issue();

    remove_filter('uamswp_migration_api_token_rotation_window', $filter);

    expect(Token::matches($second['token']))->toBeTrue();
    expect(Token::matches($first['token']))->toBeFalse();

    $record = Token::record();
    expect(\is_array($record) ? ($record['previous'] ?? null) : null)->toBeNull();
});

it('discriminates across the three-request verification', function (): void {
    // The check `README.md` gives an operator, run as dispatch: correct token
    // 200, no header 401, wrong token 401. Logged out, because the point is
    // that the token authenticates on its own.
    $issued = Token::issue();
    wp_set_current_user(0);

    expect(tokenRequest($issued['token'])->get_status())->toBe(200);
    expect(tokenRequest(null)->get_status())->toBe(401);
    expect(tokenRequest($issued['token'].'x')->get_status())->toBe(401);
});

it('refuses a regenerated-away token once its window has closed', function (): void {
    $filter = static fn (): int => 0;
    add_filter('uamswp_migration_api_token_rotation_window', $filter);

    $first = Token::issue();
    $second = Token::issue();

    remove_filter('uamswp_migration_api_token_rotation_window', $filter);
    wp_set_current_user(0);

    expect(tokenRequest($second['token'])->get_status())->toBe(200);
    expect(tokenRequest($first['token'])->get_status())->toBe(401);
});

it('withholds the option by NAME, even carrying a value that looks harmless', function (): void {
    // `update_network_option()` falls through to `update_option()` when
    // `! is_multisite()`, so on a single-site install this record lands in
    // `wp_options` — the table this endpoint serves. This install is multisite,
    // so the value is placed there directly to put the endpoint in front of the
    // shape it would actually meet.
    //
    // **The value here is deliberately not secret-shaped.** A real record holds
    // a 64-character SHA-256 hash, which `OptionsPayload`'s shape heuristic
    // withholds on its own — so a test using a realistic value passes whether
    // or not the NAME is denied, and cannot tell the two mechanisms apart. The
    // first version of this test did exactly that. A plain sentence isolates
    // the name.
    actAsSuperAdmin();
    $benign = ['current' => ['hash' => 'a plain sentence, not a credential']];

    update_option(Token::OPTION, $benign);

    // The positive control, and it is not optional. An absence proves nothing
    // unless a presence is reachable: the same value under a name the denylist
    // does not match must come back, or the endpoint is simply not serving
    // either and the test is measuring nothing.
    update_option('uamswp_migration_api_probe', $benign);

    $options = optionsPayload();

    expect(array_keys($options))->toContain('uamswp_migration_api_probe');
    expect(array_keys($options))->not->toContain(Token::OPTION);
});

it('also withholds a real record by its shape, independently of the name', function (): void {
    // The second control. The stored hash is 64 hex characters, which the shape
    // half of the guard withholds whatever the option is called — so the record
    // is covered twice over. Asserted separately from the name test so that
    // losing either mechanism reds exactly one of them.
    actAsSuperAdmin();
    update_option('uamswp_migration_api_probe', ['current' => ['hash' => str_repeat('a', 64)]]);

    expect(array_keys(optionsPayload()))->not->toContain('uamswp_migration_api_probe');
});

it('keeps the record out of wp_options on the multisite branch', function (): void {
    Token::issue();

    expect(get_option(Token::OPTION, null))->toBeNull();
    expect(get_network_option(null, Token::OPTION, null))->toBeArray();
});
