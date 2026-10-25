<?php

declare(strict_types=1);

require_once \dirname(__DIR__, 3).'/scripts/audit-heuristics.php';

/*
 * The audit's miss-detection, which is the half of `audit-option-denylist.php`
 * that is NOT shared with the endpoint and must not be.
 *
 * `OptionsPayload::classify()` answers what is withheld; these answer what might
 * have been missed, over the rows that are served, and they are deliberately
 * looser. That looseness was tuned against a real 1,223-option table — the first
 * draft reported 92 candidates, 75 of them FacetWP field references — and until
 * these tests it was a tuning nothing held in place. A regression here produces
 * a review tool that either cries wolf or goes quiet, and neither announces
 * itself.
 *
 * In `tests/Integration` rather than `tests/Unit` although the heuristics are pure:
 * `auditDecoded()` calls WordPress' `is_serialized()`, and the Unit suite runs
 * with no WordPress at all.
 */

it('catches the credential shapes the shipped rule is too narrow for', function (): void {
    // The endpoint's rule excludes `-` and `_` and floors at 32, which is right
    // for a filter that withholds. A 22-character GA4 `gmp_api_secret` walks
    // straight through it and is exactly what this is for.
    expect(auditLooseShape('Xy3kQ8vRt-Zp1LmNoPqRsT'))->toStartWith('opaque-')
        // Split, so a secret scanner does not read the fixture as a live key.
        ->and(auditLooseShape('key-'.'0123456789abcdef0123456789abcdef'))->toBe('known-credential-prefix')
        ->and(auditLooseShape('eyJhbGciOiJIUzI1NiJ9.abcdefghij'))->toBe('JWT-shaped')
        ->and(auditLooseShape(str_repeat('a1b2c3d4', 3)))->toStartWith('hex-');
});

it('stays quiet on the values that made the first draft unreadable', function (): void {
    // Every one of these was a false positive in a real run. FacetWP facet
    // sources and filesystem paths came in through `/` in the alphabet; the
    // slug-like ones through not requiring mixed case and a digit.
    $quiet = [
        'cf/uamswp_provider_specialty_group',
        '/Users/site/wp-content/uploads/asp_upload',
        'uamswp-people-showcase-layout-wide',
        'post_modified_gmt_descending_order',
    ];

    $noisy = [];

    foreach ($quiet as $value) {
        if (auditLooseShape($value) !== '') {
            $noisy[] = $value.' → '.auditLooseShape($value);
        }
    }

    expect($noisy)->toBe([]);
});

it('reports a suspicious leaf with its path, its shape and its length — never its value', function (): void {
    $secret = 'SG.'.str_repeat('a1b2c3d4', 8);
    $hits = auditSuspects(['integration' => ['handle' => $secret]], '');

    expect($hits)->toHaveKey('integration.handle')
        ->and($hits['integration.handle'])->toContain('known-credential-prefix')
        ->and(implode(' ', array_merge(array_keys($hits), array_values($hits))))->not->toContain($secret);
});

it('redacts a path segment that is itself credential-shaped', function (): void {
    // An array key is data off the row exactly as a value is. `cron` is keyed by
    // `md5( serialize( $args ) )`, and a plugin keying a settings array by its
    // own token would otherwise print the token into a file whose whole promise
    // is that it is safe to paste into a ticket.
    $token = 'AKIAIOSFODNN7EXAMPLE123456789';
    $hits = auditSuspects([$token => ['api_key' => 'x'.str_repeat('a1b2c3d4', 8)]], '');

    $rendered = implode(' ', array_keys($hits));

    expect($hits)->not->toBe([])
        ->and($rendered)->not->toContain($token)
        ->and($rendered)->toContain('-redacted>')
        ->and($rendered)->toContain('<key#');
});

it('does not flag prose that merely sits under a credential-ish name', function (): void {
    // MinervaKB names a dozen UI strings `ticket_credentials_*_text`. A
    // credential contains no whitespace, and that one rule took the name-matched
    // hits on this network from 17 to 4.
    $hits = auditSuspects([
        'ticket_credentials_saved_text' => 'Your credentials were saved',
        'api_key' => 'kQ8vRtZp1LmNoPqRsT2u',
    ], '');

    expect($hits)->not->toHaveKey('ticket_credentials_saved_text')
        ->and($hits)->toHaveKey('api_key');
});

it('lists a license inside a nested JSON string among the possible misses', function (): void {
    // #457. `auditDecoded()` decodes the top-level value only, so the walk
    // reached `value`, found a JSON string, and never looked inside it.
    $license = 'q27fd-41b8c-amber-h3k59';
    $stored = serialize(['timeout' => 1792900000, 'value' => json_encode(['error' => ['license' => $license]])]);

    $hits = auditSuspects(auditDecoded($stored) ?? $stored, '');

    expect($hits)->toHaveKey('value.error.license')
        ->and(implode(' ', array_merge(array_keys($hits), array_values($hits))))->not->toContain($license);
});

it('lists a served purchase code among the possible misses', function (): void {
    // #455. A purchase code is a hyphenated UUID, which no shape rule reports,
    // so the key name is the only signal the audit has.
    $code = '3f2a9c1e-7b4d-4e8a-9c21-5d6e7f8a9b0c';
    $hits = auditSuspects(['username' => 'shopper001', 'purchase_code' => $code, 'purchased' => true], '');

    expect(auditNameSmells('purchase_code'))->toBeTrue()
        ->and(auditLooseShape($code))->toBe('')
        ->and($hits)->toHaveKey('purchase_code')
        ->and($hits)->not->toHaveKey('username')
        ->and(implode(' ', $hits))->not->toContain($code);
});

it('terminates on a self-referential structure', function (): void {
    // `R:` back-references let a serialized value contain itself, and this walks
    // decoded values off a production table.
    $recursive = ['name' => 'ok'];
    $recursive['self'] = &$recursive;

    expect(auditSuspects($recursive, ''))->toBeArray();
});

it('decodes for inspection without constructing anything', function (): void {
    // Same reason `OptionsPayload` passes `allowed_classes: false`: this runs
    // over values nobody has audited, on production.
    $decoded = auditDecoded('O:8:"stdClass":1:{s:1:"a";s:1:"b";}');

    expect($decoded)->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and(auditDecoded('{"a":"b"}'))->toBe(['a' => 'b'])
        ->and(auditDecoded('plain string'))->toBeNull();
});

it('keeps two redacted siblings distinct', function (): void {
    // Findings are keyed by path, so redacting two sibling keys to the same
    // placeholder made the second overwrite the first — a detector reporting
    // one row where there were two. Committed by the fix for the leak above,
    // which is why the redaction carries an ordinal.
    $hits = auditSuspects([
        'AKIAIOSFODNN7EXAMPLE111111111' => ['api_key' => 'x'.str_repeat('a1b2c3d4', 8)],
        'AKIAIOSFODNN7EXAMPLE222222222' => ['api_key' => 'y'.str_repeat('d4c3b2a1', 8)],
    ], '');

    $rendered = implode(' ', array_keys($hits));

    expect($hits)->toHaveCount(2)
        ->and($rendered)->not->toContain('AKIAIOSFODNN7EXAMPLE1')
        ->and($rendered)->not->toContain('AKIAIOSFODNN7EXAMPLE2');
});

it('redacts an opaque key the value rule would have let through', function (): void {
    // The key rule has to be looser than the value rule and looser in a
    // different direction: the value rule requires mixed case and a digit,
    // which is what keeps the candidate list readable, and which left an
    // all-lowercase token and anything carrying a `/` printed verbatim.
    $lower = str_repeat('x', 24);
    $slashed = 'AB/CD'.str_repeat('x', 20);

    $hits = auditSuspects([
        $lower => ['api_key' => 'x'.str_repeat('a1b2c3d4', 8)],
        $slashed => ['api_key' => 'y'.str_repeat('d4c3b2a1', 8)],
    ], '');

    $rendered = implode(' ', array_keys($hits));

    expect($hits)->toHaveCount(2)
        ->and($rendered)->not->toContain($lower)
        ->and($rendered)->not->toContain($slashed);
});

it('keeps a readable key readable', function (): void {
    // The other half of that trade. `restrict_login_password_label_text` is 34
    // characters and has to stay legible, or the redaction has bought safety
    // with the output's whole purpose. Separator structure is the discriminator:
    // a name is delimited, a token is one unbroken run.
    $hits = auditSuspects(['restrict_login_password_label_text' => 'Password'], '');

    expect(array_keys($hits))->toBe(['restrict_login_password_label_text']);
});

it('does not let a literal dot in a key collapse two findings', function (): void {
    // A `.` in a key is indistinguishable from the path separator, so two
    // different structures render as one path and one finding overwrites the
    // other — the sibling collision by another route.
    $hits = auditSuspects([
        'a.b' => ['api_key' => 'x'.str_repeat('a1b2c3d4', 8)],
        'a' => ['b' => ['api_key' => 'y'.str_repeat('d4c3b2a1', 8)]],
    ], '');

    expect($hits)->toHaveCount(2);
});

it('still inspects a row whose serialized value will not unserialize', function (): void {
    // `is_serialized()` accepts it and `unserialize()` rejects it — a truncated
    // or length-mismatched row, which a real options table does carry. Returning
    // `false` made the caller's `?? $value` fall through to nothing, because
    // `??` catches null and not false, and the row was scanned by no rule at all.
    $malformed = 'a:1:{s:6:"secret";s:99:"short";}';

    // `unserialize()` emits an E_WARNING that the `@` in `auditDecoded()`
    // suppresses on the command line — where this script runs — but PHPUnit's
    // error handler surfaces regardless of `@`. Scoped here so the suite stays
    // quiet without weakening the assertion or the production call.
    set_error_handler(static fn (): bool => true);

    $decoded = auditDecoded($malformed);

    restore_error_handler();

    expect(is_serialized($malformed))->toBeTrue()
        ->and($decoded)->toBeNull();
});
