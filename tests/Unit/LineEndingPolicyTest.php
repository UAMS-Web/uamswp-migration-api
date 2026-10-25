<?php

declare(strict_types=1);

/*
 * Holds this repository's line endings to LF, in the object database and in policy.
 *
 * Without `.gitattributes`, every checkout is at the mercy of the checking-out
 * machine's `core.autocrlf`, which Git for Windows defaults to `true`. The
 * resulting corruption does not announce itself as a line-ending problem: on
 * `josh-home`, validating #144 and #149, it produced six Pint fixers against code
 * with no style violations and failed six of seven `CiParityTest` assertions,
 * because that test matches exact strings against lines carrying a `\r` that
 * should never have existed. Both failures described the machine's git
 * configuration and neither described the code.
 *
 * So this guards two different things, and the second is the one that matters:
 * that the policy file exists and says the right thing, and that no committed
 * blob actually carries a carriage return. The first can be satisfied by a file
 * nobody reads; the second is the invariant.
 *
 * See #152 and `docs/DECISIONS.md` D-066.
 */

/**
 * Repository root.
 */
function lineEndingRepoRoot(): string
{
    return \dirname(__DIR__, 2);
}

/**
 * Run a git command in the repository and return its stdout lines.
 *
 * **Deliberately plain, and `git -C` rather than `cd … &&`.** An earlier version
 * prefixed `GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null` and appended
 * `2>/dev/null`, copied from `PreCommitParityHookTest`. Both are POSIX-only:
 * `cmd.exe` does not parse `VAR=value command` as an assignment and has no
 * `/dev/null`, so on Windows every call returned nothing and neither assertion
 * below could run at all — on the one policy this repository most needs proven
 * there. That is #145's defect class, and this test introduced a second instance
 * of it before `josh-home` caught it on #161.
 *
 * The neutralization was also unnecessary, which is the part worth keeping.
 * `core.autocrlf` rewrites the **working tree**; it does not touch what
 * `git show <rev>:<path>` emits. Measured in a clone with `core.autocrlf=true`
 * and no `.gitattributes`: `includes/Auth.php` carried 190 CR bytes on disk and
 * **zero** through `git show`. So the blob read this test depends on was never at
 * risk from the setting it was guarding against.
 *
 * @return list<string>
 */
function lineEndingGit(string $command): array
{
    $full = 'git -C '.escapeshellarg(lineEndingRepoRoot()).' '.$command;

    exec($full, $output, $exitCode);

    return $exitCode === 0 ? $output : [];
}

it('declares a line-ending policy at all', function (): void {
    $path = lineEndingRepoRoot().'/.gitattributes';

    expect(file_exists($path))->toBeTrue(".gitattributes is missing; every checkout falls back to the machine's core.autocrlf");

    $contents = (string) file_get_contents($path);

    // The blanket rule is what makes the policy independent of the checking-out
    // machine. An explicit per-extension list without it leaves any new file type
    // uncovered by omission, which is the failure mode this whole ticket is about.
    expect($contents)->toContain('* text=auto eol=lf');
});

it('covers every text extension the repository actually tracks', function (): void {
    // A per-extension list that drifts behind the tree is worse than none, because
    // it reads as deliberate coverage. The blanket rule above is the real guard;
    // this asserts the explicit list has not silently fallen behind it.
    $tracked = lineEndingGit('ls-files');

    expect($tracked)->not->toBeEmpty('git ls-files returned nothing; the test measured no files');

    $binary = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'lock'];
    $contents = (string) file_get_contents(lineEndingRepoRoot().'/.gitattributes');

    $uncovered = [];

    foreach ($tracked as $file) {
        $basename = basename($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === '') {
            continue;
        }

        if (\in_array($extension, $binary, true)) {
            continue;
        }

        // A dotfile has no extension a glob can match — `pathinfo('.gitignore')`
        // reports `gitignore` — so it is covered by being named literally rather
        // than by a `*.ext` pattern that would never fire.
        $covered = str_starts_with($basename, '.')
            ? str_contains($contents, $basename)
            : str_contains($contents, "*.{$extension}");

        if (! $covered) {
            $uncovered[] = $extension;
        }
    }

    expect(array_values(array_unique($uncovered)))->toBe([]);
});

it('carries no carriage return in any committed text blob', function (): void {
    // The invariant, and the only assertion here that would notice the policy
    // having stopped working. A `.gitattributes` that exists and is ignored looks
    // exactly like one that works, until a blob arrives with CRLF in it.
    $tracked = lineEndingGit('ls-files');

    expect($tracked)->not->toBeEmpty('git ls-files returned nothing; the test measured no files');

    $binary = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2'];
    $offenders = [];

    foreach ($tracked as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (\in_array($extension, $binary, true)) {
            continue;
        }

        // Read the BLOB rather than the working tree. The working copy legitimately
        // differs per machine; the object database is what every checkout derives
        // from, so it is the thing that must be clean.
        $blob = shell_exec(
            'git -C '.escapeshellarg(lineEndingRepoRoot())
            .' show '.escapeshellarg('HEAD:'.$file)
        );

        if (\is_string($blob) && str_contains($blob, "\r")) {
            $offenders[] = $file;
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
