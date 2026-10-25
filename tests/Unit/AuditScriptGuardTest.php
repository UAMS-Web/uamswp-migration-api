<?php

declare(strict_types=1);

/*
 * Holds `scripts/audit-option-denylist.php` to asking the endpoint rather than
 * answering for it.
 *
 * The script reviews the option denylist against a production table nobody has
 * read — the review that found seven credential leaks a one-site pass had
 * missed. For that to mean anything it has to classify exactly as the endpoint
 * filters, which its own docblock said and its code then stopped doing: it kept
 * a transcription of the rules, #33 taught the endpoint to look inside
 * serialized values, and the transcription did not follow. For a release the
 * audit reported on a denylist that was not shipped (#38).
 *
 * The fix was to delete the copy, so this guard exists to notice the copy coming
 * back. It is a tripwire rather than a proof: it cannot tell that a
 * second copy of the rules is wrong, only that the script has stopped delegating. That
 * is the failure that actually happened, and it is cheap to catch.
 */

/**
 * The audit script's source.
 */
function auditScriptSource(): string
{
    return (string) file_get_contents(\dirname(__DIR__, 2).'/scripts/audit-option-denylist.php');
}

/**
 * The audit runner and its heuristics together.
 *
 * The heuristics moved into their own file so the suite could load them, and a
 * guard that scans only the runner would stop seeing half of what it guards.
 */
function auditAllSource(): string
{
    return auditScriptSource()
        ."\n"
        .file_get_contents(\dirname(__DIR__, 2).'/scripts/audit-heuristics.php');
}

/**
 * The audit script with every comment and string literal removed.
 *
 * Scanning raw text was not enough, and the negative control is what showed it:
 * replacing the real `OptionsPayload::classify()` call with a local copy of
 * the rules left the delegation assertion **green**, because the file's
 * own docblock says the words `OptionsPayload::classify()`. A guard that a
 * comment can satisfy is the false-green class this repo keeps finding, in a
 * test written to catch a different one.
 *
 * Echoed text is stripped for the same reason — the script prints a line naming
 * the call it makes.
 */
function auditScriptCode(): string
{
    $code = '';

    foreach (token_get_all(auditScriptSource()) as $token) {
        if (! \is_array($token)) {
            $code .= $token;

            continue;
        }

        if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $code .= $token[1];
    }

    return $code;
}

it('decides withheld-or-served by calling the endpoint, not by its own rules', function (): void {
    // `classify()` is public for this caller and no other. If the script stops
    // calling it, the audit has started having opinions of its own.
    expect(auditScriptCode())->toContain('OptionsPayload::classify');
});

it("attributes a name match with the endpoint's own matcher", function (): void {
    // The per-pattern tally is the part of the output that shows which patterns
    // are speculative. Deriving it needs LIKE matching, and a second
    // implementation of LIKE matching is exactly what drifted last time —
    // `auditMatchesLike()` sat here for a release captioned "Mirrors
    // OptionsPayload::matchesLikePattern()".
    expect(auditScriptCode())->toContain('OptionsPayload::matchesLikePattern');
});

it('keeps no denylist pattern of its own', function (): void {
    // The moment a pattern is written here, the list that ships and the list
    // that is audited have started to differ.
    //
    // Detected by shape rather than by stripping `printf` specifiers first,
    // which is how the previous version of this test failed at its job: the
    // stripper matched `%` followed by any letter, so `%key` became `ey` and
    // `%token` lost its first two characters — and **eight of the sixteen shipped patterns were
    // invisible to the guard**. It arrived that way as collateral from widening
    // the stripper to satisfy cspell, with no negative control re-run after.
    //
    // A LIKE pattern is `%` followed by a word of two or more letters, or
    // carries LIKE's `\_` escape. A `printf` conversion is `%` and a single
    // letter, optionally behind flags, a width and a precision — so `%-20s`,
    // `%3d` and `%s` are all quiet here.
    $patterns = [];

    foreach (token_get_all(auditAllSource()) as $token) {
        if (! \is_array($token)) {
            continue;
        }

        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        if (preg_match('/%[A-Za-z]{2,}/', $token[1]) === 1 || str_contains($token[1], '\_')) {
            $patterns[] = $token[1];
        }
    }

    expect($patterns)->toBe([]);
});

it('detects every pattern the denylist actually ships', function (): void {
    // The guard above is only worth having if it sees a real pattern. Every
    // entry of the shipped list is run through its own detector, so a pattern
    // style the denylist adds later cannot be one the guard is blind to.
    $undetected = [];

    $config = require \dirname(__DIR__, 2).'/config/defaults.php';

    assert(\is_array($config), 'config/defaults.php did not return an array');

    foreach ($config as $key => $value) {
        if ($key !== 'option_denylist' && $key !== 'option_denylist_nested_keys') {
            continue;
        }

        foreach ((array) $value as $pattern) {
            $literal = var_export($pattern, true);

            if (preg_match('/%[A-Za-z]{2,}/', $literal) !== 1 && ! str_contains($literal, '\_')) {
                $undetected[] = $pattern;
            }
        }
    }

    expect($undetected)->toBe([]);
});

it('never prints an option value', function (): void {
    // The guarantee that makes this safe to run on production by someone who is
    // not reading the output, and safe to paste into a ticket afterwards. The
    // script prints names, key paths, byte lengths and shape labels; a `$value`
    // or `$node` reaching a print call would forfeit both.
    // The negative lookahead this used to carry — `(?!\s*\))` — exempted
    // `$value)`, which is the file's own `printf` idiom and the single most
    // likely way a value would ever reach the output. A guard that skips the
    // canonical form of the thing it guards against is worse than none, because
    // it reads as coverage.
    $offenders = [];

    foreach (explode("\n", auditAllSource()) as $number => $line) {
        if (preg_match('/\b(printf|vprintf|print|echo|var_dump|print_r|fwrite)\b/', $line) !== 1) {
            continue;
        }

        if (preg_match('/\$(value|node|raw|stored|child|decoded)\b/', $line) === 1) {
            $offenders[] = 'line '.($number + 1).': '.trim($line);
        }
    }

    expect($offenders)->toBe([]);
});
