<?php

/**
 * The per-network API token.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

/**
 * Issues, stores and verifies this network's API token.
 *
 * The credential this migration uses. It replaces the `wp-config.php` shared
 * constant for the deployment described in #141: narrow in breadth (this API's
 * read surface, on one network) and shallow in depth (no WordPress identity at
 * all), where an application password would grant `manage_network_options` in
 * order to perform a read.
 *
 * **Stored hashed, on both branches, and the branch that needs it is the one a
 * multisite check would switch it off for.** `update_network_option()` falls
 * through to `update_option()` when `! is_multisite()`, so on a single-site
 * install this record lands in `wp_options` — the table `GET /options` serves.
 * A plaintext token there would be disclosed by the endpoint it guards, to any
 * holder of a previous token. See #141 and `docs/DECISIONS.md` D-063.
 *
 * Two independent controls keep it out of that payload, because the ticket's
 * own instruction is not to rely on the denylist:
 *
 * 1. Only a hash is ever written. There is no plaintext to serve.
 * 2. `self::OPTION` ends in `token`, which `config/defaults.php`'s
 *    `option_denylist` matches with `%token`, anchored at the end and
 *    case-insensitive.
 *
 * The verification half is deliberately not a password KDF. A token is 256 bits
 * of `random_bytes`, so there is no low-entropy secret for a slow hash to
 * protect, and this credential authenticates a bulk read — every endpoint, for
 * every site — where a per-request bcrypt would be a real cost for no gain.
 */
final class Token
{
    /**
     * Option holding the token record.
     *
     * Network-scoped on multisite, `wp_options` on single-site. See the class
     * docblock for why the name ends in `token`.
     */
    public const OPTION = 'uamswp_migration_api_token';

    /**
     * Seconds a rotated-away token stays valid.
     *
     * Regenerating without an overlap reproduces, at n=1, the defect #140
     * rejected the shared constant for: between regeneration and the operator
     * pasting the new value into the consumer, every request is refused, and it
     * surfaces mid-capture rather than at setup. An hour is long enough to be a
     * convenience and short enough not to be a standing second key.
     */
    public const ROTATION_WINDOW = 3600;

    /**
     * Bytes of entropy in a token.
     */
    private const TOKEN_BYTES = 32;

    /**
     * Bytes of entropy in the public identifier.
     */
    private const IDENTIFIER_BYTES = 4;

    /**
     * Issue a new token, rotating the current one into the overlap window.
     *
     * Returns the plaintext, which is the only time it exists. Everything
     * stored is a hash.
     *
     * @return array{token: string, identifier: string}
     */
    public static function issue(): array
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $identifier = bin2hex(random_bytes(self::IDENTIFIER_BYTES));
        $now = time();

        $record = [
            'current' => [
                'identifier' => $identifier,
                'hash' => self::hash($token),
                'created' => $now,
            ],
            'previous' => self::rotate(self::record(), $now, self::rotationWindow()),
        ];

        update_network_option(null, self::OPTION, $record);

        return ['token' => $token, 'identifier' => $identifier];
    }

    /**
     * Whether a presented token authenticates against this network's record.
     */
    public static function matches(string $presented): bool
    {
        return self::verify(self::record(), $presented, time());
    }

    /**
     * This network's stored record, or null when no token has been issued.
     *
     * @return array<string, mixed>|null
     */
    public static function record(): ?array
    {
        $stored = get_network_option(null, self::OPTION, null);

        if (! \is_array($stored)) {
            return null;
        }

        /** @var array<string, mixed> $stored */
        return $stored;
    }

    /**
     * Hash a token for storage and comparison.
     *
     * See the class docblock for why this is a plain digest rather than a
     * password KDF.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Seconds a rotated-away token stays valid.
     */
    public static function rotationWindow(): int
    {
        /**
         * Filters the rotation overlap window, in seconds.
         *
         * @param  int  $seconds  Overlap window.
         */
        $window = (int) apply_filters('uamswp_migration_api_token_rotation_window', self::ROTATION_WINDOW);

        // A negative window would extend validity rather than shorten it, since
        // the expiry is computed by addition. Zero means no overlap, which is a
        // coherent choice; below zero is not.
        return max(0, $window);
    }

    /**
     * Whether a presented token matches a record at a given moment.
     *
     * Pure: no WordPress, no clock of its own. That is what lets the rotation
     * window be tested at a chosen instant rather than by sleeping.
     *
     * The loop does not return early. Both candidates are compared on every
     * call, so the work done does not depend on which one matched — the
     * property #141's decision names when it says the comparison loops rather
     * than returning on a single `hash_equals`.
     *
     * @param  array<string, mixed>|null  $record  Stored record.
     * @param  string  $presented  Token as presented by the caller.
     * @param  int  $now  Unix timestamp to evaluate the window against.
     */
    public static function verify(?array $record, string $presented, int $now): bool
    {
        // An empty presented token must never authorize anything, including
        // against a record whose hash was somehow computed from one.
        if ($record === null || $presented === '') {
            return false;
        }

        $presentedHash = self::hash($presented);
        $matched = false;

        foreach (self::liveHashes($record, $now) as $hash) {
            $matched = hash_equals($hash, $presentedHash) || $matched;
        }

        return $matched;
    }

    /**
     * Hashes that authenticate at a given moment.
     *
     * The current token always counts. The previous one counts until its
     * expiry passes.
     *
     * @param  array<string, mixed>  $record  Stored record.
     * @param  int  $now  Unix timestamp to evaluate the window against.
     * @return list<string>
     */
    public static function liveHashes(array $record, int $now): array
    {
        $hashes = [];

        $current = $record['current'] ?? null;

        if (\is_array($current) && \is_string($current['hash'] ?? null) && $current['hash'] !== '') {
            $hashes[] = $current['hash'];
        }

        $previous = $record['previous'] ?? null;

        if (\is_array($previous) && \is_string($previous['hash'] ?? null) && $previous['hash'] !== '') {
            $expires = $previous['expires'] ?? null;

            // A missing or non-integer expiry is treated as expired rather than
            // as eternal: the failure direction for a malformed record must be
            // refusing a credential, never honouring one forever.
            if (\is_int($expires) && $now < $expires) {
                $hashes[] = $previous['hash'];
            }
        }

        return $hashes;
    }

    /**
     * The `previous` entry a newly issued token should carry.
     *
     * Pure, for the same reason `verify()` is: the window arrives as an
     * argument rather than being read from a filter, so the rotation can be
     * tested at a chosen instant and a chosen window.
     *
     * @param  array<string, mixed>|null  $record  Record being replaced.
     * @param  int  $now  Unix timestamp of the rotation.
     * @param  int  $window  Overlap window in seconds.
     * @return array{identifier: string, hash: string, expires: int}|null
     */
    public static function rotate(?array $record, int $now, int $window): ?array
    {
        $current = $record['current'] ?? null;

        if (! \is_array($current) || ! \is_string($current['hash'] ?? null) || $current['hash'] === '') {
            return null;
        }

        // No window configured means no overlap: the outgoing token is dropped
        // rather than stored with an already-passed expiry. A negative window is
        // clamped here as well as in `rotationWindow()`, because this method is
        // reachable with a window its caller chose.
        if ($window <= 0) {
            return null;
        }

        return [
            'identifier' => \is_string($current['identifier'] ?? null) ? $current['identifier'] : '',
            'hash' => $current['hash'],
            'expires' => $now + $window,
        ];
    }
}
