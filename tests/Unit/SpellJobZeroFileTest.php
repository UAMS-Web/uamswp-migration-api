<?php

declare(strict_types=1);

/*
 * Drives the `spell` script's REAL flags against a file set that matches
 * nothing, rather than asserting that `--no-must-find-files` is absent from
 * `package.json`.
 *
 * The distinction is this repository's most expensive lesson about its own
 * tests. `CiWorktreeResidueTest` records three false greens from source-text
 * assertions on the runner: `--untracked-files=all` appears four times there,
 * three of them prose inside comments, so `toContain()` stayed green with the
 * flag deleted from the only place it did anything. A
 * `not->toContain('--no-must-find-files')` here would be the fourth of that
 * kind — green while the flag sat in a comment, green if the script were
 * renamed, and green if cspell changed what the flag means.
 *
 * So the flags are read from the script and then RUN. If the suppressing flag
 * returns, the extracted flags carry it, cspell exits 0 on an empty file set,
 * and the first test fails. That is a behavioral check whose subject is the
 * configuration actually in use.
 *
 * WHY THE DEFECT MATTERS HERE. `cspell` exits non-zero on an empty file set by
 * default; the script was disabling that. A `spell` job examining zero files
 * reported success, and the suppressed and unsuppressed runs print IDENTICAL
 * text — `Files checked: 0, Issues found: 0` either way — so only the exit
 * status distinguishes them and nothing in a chain log does. `.claude/history/AGENTS.md`
 * records cspell sitting red on `main` across two merges because nothing ran
 * it; this is the same outcome reached by the opposite route, wearing green.
 *
 * WHEN THIS SKIPS, recorded rather than left to be discovered. These tests need
 * the cspell PACKAGE installed — not merely the `node` binary — and that is the
 * whole condition. Note the contrast with `CiWorktreeResidueTest`, which needs
 * only `node` and therefore runs anywhere; this distinction is what made an
 * earlier version of this paragraph too narrow (#129).
 *
 * The package is absent, and these tests skip, in TWO situations. The remote
 * case is one instance of the rule rather than the rule itself:
 *
 *   1. A local run in a tree whose `node_modules/` was never installed. In the
 *      runner's slot the `dependencies` job in `local-ci.json` runs `npm ci`,
 *      and the slot keeps it between runs; a run naming only `--jobs pest`
 *      skips that job, so these skip only in a slot no full run has prepared.
 *      The DEFAULT invocation runs every job, so the gate itself always
 *      executes these, which is why a chain summary shows them passing and
 *      why this is a documentation defect rather than a hole in the gate.
 *   2. The remote `pest` job in `.github/workflows/ci.yml`, which runs no
 *      `npm ci` at all. That is case 1 seen from a runner, and it does not bite
 *      today: `RUN_REMOTE_CI` is off, every remote job reports `skipped`, and
 *      the local chain is the whole gate (D-029). It becomes real the moment
 *      that switch is turned on, and the fix then is one `npm ci` step in the
 *      remote job, not a change here.
 *
 * A skip that can never turn into a run is the shape
 * `tests/Integration/Feature/Forms/GravityFormsGuardTest.php` exists to prevent elsewhere,
 * which is why both are named rather than left implicit.
 *
 * WHY THE PACKAGE ENTRY RATHER THAN THE SHIM. The guard and the invocation both
 * go through `node_modules/cspell/bin.mjs` instead of `node_modules/.bin/cspell`
 * deliberately, and there WAS a third skip condition before they did (#129):
 *
 *   - `is_executable()` on PHP 8.2/Windows recognizes `.exe` and `.com` only. It
 *     returns false for all three files npm writes for a bin entry — the shim
 *     with no extension, `.cmd` and `.ps1` — regardless of content or shebang.
 *     So the old guard skipped on a Windows machine that had cspell fully
 *     installed, and pointing it at the `.cmd` would not have helped.
 *   - `./node_modules/.bin/cspell` is a POSIX path that `cmd.exe` does not
 *     resolve, so even past the guard the invocation would have failed there.
 *
 * `bin.mjs` is the same file the shim symlinks to, so nothing is weakened:
 * measured on macOS, both paths exit 0 over `composer.json` and non-zero over a
 * glob matching nothing. `file_exists()` has no extension semantics and `node`
 * needs no `./` prefix, which removes both platform dependencies at once.
 *
 * VERIFIED ON WINDOWS on 2026-09-10, at `8b522abc8`. The gate for this
 * repository runs on Windows and validated that commit; asked whether these
 * three tests RAN or merely did not fail — a skip is green too, which is this
 * file's whole subject — it reported them RUNNING, from the `pest` output
 * rather than from recall. So the guard resolves there, which is what the
 * change was for.
 *
 * DATE A PLATFORM CLAIM, or it expires without saying so (#210). The sentence
 * this replaces asserted the negative of the line above — an unqualified claim
 * that the change was unverified on this platform — and it was false four
 * minutes after it merged — the run that validated the very commit carrying it is what
 * falsified it. It was true of the lane that wrote it and never true of the
 * estate, and being undated it could not age: an unqualified absence silently
 * becomes a present-tense assertion the moment anyone else measures it, and no
 * reader can see that it has. Written as it is now — the reading, the commit,
 * the date — it stays true whatever happens next, and a reader can tell at a
 * glance whether it is worth rechecking.
 *
 * If `node` itself is missing the first test would pass for the WRONG REASON — a
 * command that cannot run at all also exits non-zero. The control test below,
 * which requires exit 0 over `composer.json`, is what catches that, and it is
 * the reason that test exists rather than being decoration.
 */

/**
 * The `spell` script's flags, with its globs removed.
 *
 * @return list<string>
 */
function spellFlags(): array
{
    $manifest = json_decode(
        (string) file_get_contents(\dirname(__DIR__, 2).'/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    \assert(\is_array($manifest));
    \assert(isset($manifest['scripts']) && \is_array($manifest['scripts']));
    \assert(\is_string($manifest['scripts']['spell'] ?? null), 'package.json has no `spell` script');

    $tokens = str_getcsv($manifest['scripts']['spell'], ' ', '"', '\\');

    // Drop the binary and every glob, keeping only the flags.
    return array_values(array_filter(
        \array_slice($tokens, 1),
        static fn (?string $token): bool => \is_string($token) && str_starts_with($token, '--')
    ));
}

/**
 * Run cspell with the script's own flags over one glob, and return the exit code.
 */
function spellExitFor(string $glob): int
{
    $root = \dirname(__DIR__, 2);

    $command = sprintf(
        'cd %s && node node_modules/cspell/bin.mjs %s %s 2>&1',
        escapeshellarg($root),
        implode(' ', array_map(escapeshellarg(...), spellFlags())),
        escapeshellarg($glob)
    );

    exec($command, $output, $exit);

    return $exit;
}

beforeEach(function (): void {
    if (! file_exists(\dirname(__DIR__, 2).'/node_modules/cspell/bin.mjs')) {
        $this->markTestSkipped('cspell is not installed, so the spell job cannot be driven.');
    }
});

it('fails when the configured flags examine no files at all', function (): void {
    // The defect: with `--no-must-find-files` this returns 0 and the job passes
    // having checked nothing.
    expect(spellExitFor('**/*.no-such-extension-exists'))->not->toBe(0);
});

it('still passes over a file set that does match, so the guard did not just break the job', function (): void {
    // The control. Without it, a non-zero above is equally consistent with the
    // guard working and with the command being malformed.
    expect(spellExitFor('composer.json'))->toBe(0);
});

it('reads flags from the script rather than from a copy of them', function (): void {
    // Guards the extraction itself: if `spellFlags()` silently returned nothing,
    // both tests above would still behave correctly for the wrong reason —
    // bare cspell also fails on an empty glob.
    expect(spellFlags())->not->toBeEmpty()
        ->and(spellFlags())->toContain('--no-progress');
});
