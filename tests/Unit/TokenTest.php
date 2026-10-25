<?php

declare(strict_types=1);

use UAMSWP\MigrationAPI\Token;

/*
 * The token's pure core: hashing, the rotation overlap, and what authenticates
 * at a given instant.
 *
 * Everything here runs without WordPress, which is the point. The overlap
 * window is the part most likely to be got wrong in a way no happy-path test
 * notices — a previous token that never expires, or one that expires
 * immediately — and both of those are a clock comparison rather than anything
 * WordPress owns. Passing the instant in means the boundary is tested at the
 * boundary instead of by sleeping through it.
 *
 * The storage half needs WordPress and lives in
 * `tests/Integration/Feature/TokenStorageTest.php`.
 */

/**
 * A record shaped as `Token::issue()` writes one.
 *
 * @return array<string, mixed>
 */
function tokenRecord(string $current, ?string $previous = null, ?int $expires = null): array
{
    return [
        'current' => [
            'identifier' => 'a1b2c3d4',
            'hash' => Token::hash($current),
            'created' => 1_000_000,
        ],
        'previous' => $previous === null ? null : [
            'identifier' => '11223344',
            'hash' => Token::hash($previous),
            'expires' => $expires,
        ],
    ];
}

it('hashes a token to something that is not the token', function (): void {
    $token = 'a2f5c8d1e4b7';

    expect(Token::hash($token))->not->toBe($token);
    expect(Token::hash($token))->toBe(Token::hash($token));
    expect(Token::hash($token))->not->toBe(Token::hash($token.'x'));
});

it('accepts the current token', function (): void {
    expect(Token::verify(tokenRecord('right'), 'right', 2_000_000))->toBeTrue();
});

it('refuses a token that does not match', function (): void {
    expect(Token::verify(tokenRecord('right'), 'wrong', 2_000_000))->toBeFalse();
});

it('refuses an empty presented token', function (): void {
    // Not hypothetical: `get_header()` returns an empty string for a header
    // that was never sent, so this is the shape an anonymous request takes.
    expect(Token::verify(tokenRecord('right'), '', 2_000_000))->toBeFalse();
});

it('refuses everything when no token has been issued', function (): void {
    expect(Token::verify(null, 'anything', 2_000_000))->toBeFalse();
    expect(Token::verify([], 'anything', 2_000_000))->toBeFalse();
});

it('accepts the previous token while the overlap window is open', function (): void {
    $record = tokenRecord('new', 'old', 2_000_100);

    expect(Token::verify($record, 'old', 2_000_000))->toBeTrue();
    expect(Token::verify($record, 'new', 2_000_000))->toBeTrue();
});

it('refuses the previous token once the window closes', function (): void {
    $record = tokenRecord('new', 'old', 2_000_100);

    // At the expiry itself, not merely after it: the comparison is `now <
    // expires`, so the instant named as the expiry is already outside.
    expect(Token::verify($record, 'old', 2_000_100))->toBeFalse();
    expect(Token::verify($record, 'old', 2_000_101))->toBeFalse();
    expect(Token::verify($record, 'new', 2_000_101))->toBeTrue();
});

it('treats a previous entry with no usable expiry as expired', function (): void {
    // The failure direction for a malformed record has to be refusing a
    // credential. A missing expiry read as "no limit" would make a
    // rotated-away token valid forever, which is the opposite of what
    // rotation is for.
    foreach ([null, 'soon', 2_000_100.5] as $expires) {
        $record = tokenRecord('new', 'old');
        $record['previous'] = ['identifier' => 'x', 'hash' => Token::hash('old'), 'expires' => $expires];

        expect(Token::verify($record, 'old', 2_000_000))->toBeFalse();
        expect(Token::verify($record, 'new', 2_000_000))->toBeTrue();
    }
});

it('ignores an entry carrying no hash', function (): void {
    $record = ['current' => ['identifier' => 'x', 'hash' => '', 'created' => 1], 'previous' => null];

    expect(Token::liveHashes($record, 2_000_000))->toBe([]);
    expect(Token::verify($record, '', 2_000_000))->toBeFalse();
});

it('reports only the current hash when nothing has been rotated', function (): void {
    expect(Token::liveHashes(tokenRecord('only'), 2_000_000))->toBe([Token::hash('only')]);
});

it('rotates nothing when no token has been issued yet', function (): void {
    expect(Token::rotate(null, 2_000_000, 3600))->toBeNull();
    expect(Token::rotate([], 2_000_000, 3600))->toBeNull();
    expect(Token::rotate(['current' => ['hash' => '']], 2_000_000, 3600))->toBeNull();
});

it('carries the outgoing token into the window with an expiry', function (): void {
    $rotated = Token::rotate(tokenRecord('old'), 2_000_000, 3600);

    expect($rotated)->toBeArray();
    expect($rotated['hash'] ?? null)->toBe(Token::hash('old'));
    expect($rotated['identifier'] ?? null)->toBe('a1b2c3d4');
    expect($rotated['expires'] ?? null)->toBe(2_003_600);
});

it('drops the outgoing token when there is no window', function (): void {
    // Zero is a coherent choice — no overlap — and must not be stored as an
    // entry that already expired, which would read as a rotation in progress.
    expect(Token::rotate(tokenRecord('old'), 2_000_000, 0))->toBeNull();
    expect(Token::rotate(tokenRecord('old'), 2_000_000, -60))->toBeNull();
});

it('compares every live candidate rather than stopping at the first', function (): void {
    // The decision on #141 asks for a looping comparison. A `verify()` that
    // returned on the first `hash_equals` would still pass every test above,
    // because the current token is checked first and the previous one only
    // matters when the current has already failed. This asserts the property
    // that distinguishes them: with the current token live, the previous one
    // still authenticates.
    $record = tokenRecord('new', 'old', 2_000_100);

    expect(Token::liveHashes($record, 2_000_000))->toBe([Token::hash('new'), Token::hash('old')]);
    expect(Token::verify($record, 'old', 2_000_000))->toBeTrue();
});

it('refuses an empty presented token even against a record holding its hash', function (): void {
    // The early refusal is the only thing standing between an anonymous request
    // and a record whose hash was computed from an empty string. Without it the
    // comparison below would match.
    $record = ['current' => ['identifier' => 'x', 'hash' => Token::hash(''), 'created' => 1], 'previous' => null];

    expect(Token::verify($record, '', 2_000_000))->toBeFalse();
});

it('ignores an entry whose hash is not a string', function (): void {
    // A non-string hash reaching `hash_equals()` is a TypeError, so each guard
    // has to hold on its own rather than as one half of an `or`.
    $record = [
        'current' => ['identifier' => 'x', 'hash' => 5, 'created' => 1],
        'previous' => ['identifier' => 'y', 'hash' => 6, 'expires' => 2_000_100],
    ];

    expect(Token::liveHashes($record, 2_000_000))->toBe([]);
    expect(Token::rotate($record, 2_000_000, 3600))->toBeNull();
});

it('ignores a previous entry carrying no hash while its window is open', function (): void {
    $record = tokenRecord('new');
    $record['previous'] = ['identifier' => 'y', 'hash' => '', 'expires' => 2_000_100];

    expect(Token::liveHashes($record, 2_000_000))->toBe([Token::hash('new')]);
});

it('keeps the outgoing token for a one-second window', function (): void {
    // The smallest window that is a window at all.
    expect(Token::rotate(tokenRecord('old'), 2_000_000, 1)['expires'] ?? null)->toBe(2_000_001);
});

it('carries an outgoing token with no identifier as an empty identifier', function (): void {
    $record = ['current' => ['hash' => Token::hash('old'), 'created' => 1], 'previous' => null];

    expect(Token::rotate($record, 2_000_000, 3600)['identifier'] ?? null)->toBe('');
});
