<?php

/**
 * The audit's own heuristics — what the endpoint might be MISSING.
 *
 * Separated from `audit-option-denylist.php` so the suite can load them, which
 * the script itself cannot offer: requiring that file bootstraps WordPress and
 * audits a network. These four functions are the tuned, load-bearing part, and
 * they were tuned against a real 1,223-option table rather than guessed — a
 * regression here is a review tool that either cries wolf or goes quiet, and
 * neither announces itself.
 *
 * Every one of them is **deliberately looser than the rule the endpoint ships**.
 * That is the point: `OptionsPayload::classify()` answers what *is* withheld,
 * and nothing here may second-guess it (#38). These answer the opposite
 * question, over the rows that are served, and a false positive here costs a
 * reviewer ten seconds while a false negative costs a credential.
 */

declare(strict_types=1);

/**
 * A value's shape, never its content — deliberately looser than the shipped rule.
 *
 * Lower floor (20 rather than 32) and a wider alphabet: the endpoint's rule
 * excludes `-` and `_`, which is right for a filter that withholds and wrong
 * for a detector that only asks a human to look. It catches the GA4
 * `gmp_api_secret` at 22 characters, which the shipped rule cannot.
 *
 * **Tuned against this network's 1,223 options, not guessed.** The first draft
 * allowed `/` in the opaque alphabet and produced 47 hits, 40-odd of them
 * FacetWP facet sources like `cf/uamswp_provider_…` and filesystem paths — and
 * a detector that reports 47 field references buries the one row that matters.
 * Dropping `/` took it to 7; also requiring mixed case and a digit — which is
 * what distinguishes a generated token from a slug — took it to **0 false
 * positives while still catching the probes**. An all-lowercase key like
 * Mailgun's `key-3ax6…` is caught by its prefix instead, which is the more
 * precise instrument for a vendor whose format is known.
 */
function auditLooseShape(string $value): string
{
    $trimmed = trim($value);

    // An empty string is not a shape. Returning a label here made the caller —
    // which treats any non-empty return as a hit — flag 295 ordinary settings
    // as credential-shaped on the first run.
    if (\strlen($trimmed) < 20) {
        return '';
    }

    if (preg_match('/^[a-f0-9]{20,}$/i', $trimmed) === 1) {
        return 'hex-'.\strlen($trimmed);
    }

    if (str_starts_with($trimmed, 'eyJ')) {
        return 'JWT-shaped';
    }

    // A superset of the endpoint's list. `ya29.`/`1//` are Google's OAuth pair,
    // which the endpoint gained in #33 precisely because a list like this one is
    // where a missing prefix shows up first. Measured: zero hits among every
    // value this network currently serves.
    if (preg_match('/^(sk_|pk_|rk_|xox[bp]-|ghp_|gho_|AKIA|SG\.|ya29\.|1\/\/|AIza|glpat-|dop_v1_|shpat_|key-)/', $trimmed) === 1) {
        return 'known-credential-prefix';
    }

    // Mixed case AND a digit, which is what separates a generated token from a
    // long slug. Without it this rule is the noisiest thing in the file.
    $looksGenerated = preg_match('/[a-z]/', $trimmed) === 1
        && preg_match('/[A-Z]/', $trimmed) === 1
        && preg_match('/[0-9]/', $trimmed) === 1;

    if ($looksGenerated && preg_match('/^[A-Za-z0-9+_-]{20,}={0,2}$/', $trimmed) === 1) {
        return 'opaque-'.\strlen($trimmed);
    }

    return '';
}

/**
 * One path segment, with a key that is itself credential-shaped replaced.
 *
 * The audit prints key paths, and an array key is data off the row just as a
 * value is. "Names, key paths, byte lengths and shape labels only" is what makes
 * this output safe to paste into a ticket, and a key that *is* a secret would
 * forfeit that while satisfying the letter of it.
 *
 * Latent rather than observed: 29 served options on this network carry a
 * credential-shaped key — `cron` is keyed by `md5( serialize( $args ) )` — and
 * none of them reaches the output today, because a path only prints when a leaf
 * under it is suspicious. A plugin keying a settings array by its own token
 * would print the token, which is a cheaper thing to prevent than to notice.
 *
 * **The ordinal is not decoration.** `auditSuspects()` keys its findings by
 * path, so two sibling keys redacted to the same placeholder produce the same
 * path and the second finding overwrites the first — a detector quietly
 * reporting one row where there were two. The first version of this function
 * did exactly that, and it is the failure this whole file exists to avoid,
 * committed by the fix for a different one.
 */
function auditRedactKey(string $key, int $ordinal): string
{
    if (auditKeyLooksRandom($key)) {
        return '<key#'.$ordinal.'-'.\strlen($key).'B-redacted>';
    }

    // A literal `.` in a key is indistinguishable from the separator, so two
    // different structures render as the same path and one finding overwrites
    // the other — the same collision the ordinal fixes, by a different route.
    return str_replace('.', '\.', $key);
}

/**
 * Whether a key is opaque enough that printing it could print a secret.
 *
 * **Looser than `auditLooseShape()` on purpose, and looser in a different
 * direction.** That function decides whether to show a reviewer a *value*, so
 * requiring mixed case and a digit is right: it is the rule that took the
 * candidate list from 92 to 8. This one decides whether printing a *key* is
 * safe, where the cost of over-redacting is one unreadable path segment and the
 * cost of under-redacting is a credential in a file whose promise is that it
 * carries none. Applying the value rule here left an all-lowercase 32-character
 * token, and anything containing a `/`, printed verbatim.
 *
 * What it must NOT redact is a readable name — `restrict_login_password_label_text`
 * is 34 characters and has to stay legible. The discriminator is separator
 * structure: a snake_case or hyphenated name is delimited, and a token is one
 * unbroken run.
 */
function auditKeyLooksRandom(string $key): bool
{
    if (\strlen($key) < 20) {
        return false;
    }

    if (preg_match('/^[a-f0-9]{20,}$/i', $key) === 1) {
        return true;
    }

    if (auditLooseShape($key) !== '') {
        return true;
    }

    // One unbroken alphanumeric run, base64 padding allowed. No `_`, no `-`,
    // no `.` — which is what keeps every readable key readable.
    return preg_match('#^[A-Za-z0-9+/]{20,}={0,2}$#', $key) === 1;
}

/**
 * Whether a SERVED option's own name reads like a credential.
 *
 * The single most informative signal a reviewer has, and the rewrite in #38
 * dropped it by accident: `auditSuspects()` is seeded with the decoded value and
 * an empty path, so the option name never becomes a path segment, and
 * `auditNameSmells()` was left with one call site that only ever sees a key
 * *inside* a value. The whole "NOT withheld, but the NAME reads like a
 * credential" class went silent, and the suite stayed green because every test
 * exercised the heuristic through nested keys.
 *
 * The class it covers is the one where the name is the *only* available signal:
 * a credential the denylist does not name, holding a value with punctuation and
 * therefore no detectable shape. `uamswp_slack_webhook_url` holding
 * `https://hooks.slack.com/services/…` is served by the endpoint, matches no
 * pattern, has no shape, and is exactly what a reviewer needs to be shown.
 *
 * Separate from `auditSuspects()` rather than folded into it: seeding the walk
 * with the name would prefix every nested path with it, and the caller already
 * prints the name alongside each path.
 */
function auditNameSuspect(string $name, string $value): bool
{
    // The same rule the nested branch uses — a credential has no whitespace —
    // so a setting whose value is prose is not reported for its name alone.
    return auditNameSmells($name) && preg_match('/\s/', $value) !== 1;
}

/**
 * Names that read like a credential even where the value does not.
 */
function auditNameSmells(string $name): bool
{
    return preg_match(
        '/(secret|token|api[_-]?key|apikey|private[_-]?key|password|passwd|credential|oauth|client[_-]?secret|salt|license|purchase|bearer|signature|webhook|smtp|stripe|paypal|twilio|sendgrid|recaptcha|aws|_key$)/i',
        $name
    ) === 1;
}

/**
 * Decode a stored value far enough to look inside it, for inspection only.
 *
 * `allowed_classes: false` for the same reason `OptionsPayload` uses it: this
 * runs over values nobody has audited, on production, and inspecting one must
 * not be able to construct anything or run its `__wakeup()`.
 */
function auditDecoded(string $stored): mixed
{
    if (is_serialized($stored)) {
        $decoded = @unserialize($stored, ['allowed_classes' => false]);

        /*
         * `false` means the string passed `is_serialized()` and then failed to
         * unserialize — a truncated or length-mismatched row, which a real
         * options table does carry. Returning it made the caller's
         * `auditDecoded( $value ) ?? $value` hand `false` to the walk, because
         * `??` falls back on null and not on false, and the row was scanned by
         * nothing at all. Null puts it back on the raw-string path, where the
         * shape and name rules still see it.
         *
         * A legitimately stored `false` decodes to the same thing, and treating
         * that as "inspect the raw string" is right for the same reason: there
         * is nothing inside it to walk.
         */
        return $decoded === false ? null : $decoded;
    }

    $trimmed = ltrim($stored);

    if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
        return null;
    }

    return json_decode($stored, true);
}

/**
 * Every suspicious string in or under a served value, as `path => reason`.
 *
 * Walks nested structure, which the pre-#38 version did not — so a credential
 * buried in a settings array was invisible to the audit even after the endpoint
 * learned to withhold it. Reports the key path and the byte length, never the
 * value.
 *
 * @param  array<string, string>  $hits
 * @return array<string, string>
 */
function auditSuspects(mixed $node, string $path, array $hits = [], int $depth = 0): array
{
    // The endpoint's own bound, for the same reason: `R:` back-references let a
    // serialized value contain itself.
    if ($depth > 16) {
        return $hits;
    }

    if (\is_object($node)) {
        return auditSuspects((array) $node, $path, $hits, $depth + 1);
    }

    if (\is_array($node)) {
        $ordinal = 0;

        foreach ($node as $key => $child) {
            $segment = auditRedactKey((string) $key, $ordinal);
            $ordinal++;

            $hits = auditSuspects($child, $path === '' ? $segment : $path.'.'.$segment, $hits, $depth + 1);
        }

        return $hits;
    }

    if (! \is_string($node) || $node === '') {
        return $hits;
    }

    /*
     * A string that is itself a container is walked, at every depth (#457).
     * Formidable's license-check caches keep a JSON string inside a serialized
     * array, and the license inside that string under `error.license`; decoding
     * only the top-level value stopped at the string and never reported it. The
     * endpoint decodes at every depth too, so this keeps the audit looking
     * where the filter looks.
     */
    $decoded = auditDecoded($node);

    if (\is_array($decoded) || \is_object($decoded)) {
        return auditSuspects($decoded, $path, $hits, $depth + 1);
    }

    $leaf = str_contains($path, '.') ? substr(strrchr($path, '.') ?: '', 1) : $path;
    $shape = auditLooseShape($node);

    if ($shape !== '') {
        $hits[$path] = $shape.', '.\strlen($node).'B';

        return $hits;
    }

    /*
     * A credential contains no whitespace. General, not tuned to a plugin — and
     * it is what makes the name heuristic usable: MinervaKB names a dozen UI
     * strings `ticket_credentials_*_text`, and reporting `Your credentials were
     * saved` as a possible secret on every run is how a reviewer learns to skim
     * this section. Measured on this network it takes the name-matched hits
     * from 17 to 4 and drops nothing that could be a token.
     *
     * The shape rules above need no such guard: their alphabets exclude the
     * space already.
     */
    if ($path !== '' && auditNameSmells($leaf) && preg_match('/\s/', $node) !== 1) {
        $hits[$path] = 'name reads like a credential, '.\strlen($node).'B';
    }

    return $hits;
}
