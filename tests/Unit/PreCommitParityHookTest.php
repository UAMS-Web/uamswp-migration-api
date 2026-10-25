<?php

declare(strict_types=1);

/*
 * Drives `.githooks/pre-commit` against throwaway git repositories, asserting on
 * the exit code and the names it prints — the contract git actually depends on.
 *
 * The hook is the only thing that notices a one-sided edit to the mirrored CI
 * files at the moment it is made. `tests/Unit/CiParityTest.php` catches the
 * drift that has already landed; this catches it before it does, which is
 * cheaper for everyone.
 *
 * A hook is unusually easy to break without noticing: it fails open. A syntax
 * error, a renamed path, a `grep` whose flags changed meaning — every one of
 * them produces a hook that permits every commit, which is indistinguishable
 * from a hook that has nothing to complain about.
 *
 * Every fixture git call blanks the global and system config, most importantly
 * `core.hooksPath` — without that, this repo's own hook fires during fixture
 * setup and the fixtures test the developer's machine instead.
 */

/**
 * Run a command with git's global and system config blanked, portably.
 *
 * `VAR=value command` is POSIX-shell-only syntax: `exec()` runs the string
 * through `cmd.exe` on Windows, which has no equivalent and reads
 * `GIT_CONFIG_GLOBAL=/dev/null` as the name of a program to run. `exec()`
 * itself has no environment parameter, but a child process inherits the
 * environment `putenv()` sets on the current one, which reaches both
 * platforms — so the two variables travel through the environment rather
 * than the command string. Restored afterward so no other fixture call in
 * the same process inherits a stale value.
 *
 * @return array{0: int, 1: string} Exit code and combined output.
 */
function preCommitExecPortable(string $command): array
{
    $previousGlobal = getenv('GIT_CONFIG_GLOBAL');
    $previousSystem = getenv('GIT_CONFIG_SYSTEM');

    putenv('GIT_CONFIG_GLOBAL=/dev/null');
    putenv('GIT_CONFIG_SYSTEM=/dev/null');

    $output = [];
    $exitCode = 0;

    try {
        exec($command.' 2>&1', $output, $exitCode);
    } finally {
        putenv($previousGlobal === false ? 'GIT_CONFIG_GLOBAL' : "GIT_CONFIG_GLOBAL={$previousGlobal}");
        putenv($previousSystem === false ? 'GIT_CONFIG_SYSTEM' : "GIT_CONFIG_SYSTEM={$previousSystem}");
    }

    return [$exitCode, implode("\n", $output)];
}

/**
 * Run a command in a fixture repository, asserting it succeeded.
 *
 * @return string Combined output.
 */
function preCommitFixtureRun(string $cwd, string $command): string
{
    [$exitCode, $combined] = preCommitExecPortable('cd '.escapeshellarg($cwd).' && '.$command);

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf('Fixture setup failed: %s => %s', $command, $combined));
    }

    return $combined;
}

/**
 * Create a git repository with one commit on `main`, and return its path.
 */
function preCommitFixtureRepo(): string
{
    $dir = sys_get_temp_dir().'/parity-hook-'.bin2hex(random_bytes(6));

    mkdir($dir.'/.github/workflows', 0777, true);
    mkdir($dir.'/scripts', 0777, true);

    preCommitFixtureRun($dir, 'git init --initial-branch=main');
    preCommitFixtureRun($dir, 'git config user.name '.escapeshellarg('CI Fixture'));
    preCommitFixtureRun($dir, 'git config user.email '.escapeshellarg('fixture@example.test'));

    file_put_contents($dir.'/.github/workflows/ci.yml', "jobs: {}\n");
    file_put_contents($dir.'/local-ci.json', "{}\n");
    file_put_contents($dir.'/README.md', "base\n");

    preCommitFixtureRun($dir, 'git add -A');
    preCommitFixtureRun($dir, 'git commit -m base');

    return $dir;
}

/**
 * Drive the real hook exactly as git would: run it with the repo as cwd.
 *
 * @return array{0: int, 1: string} The exit code and combined output.
 */
function preCommitHookRun(string $dir): array
{
    $hook = \dirname(__DIR__, 2).'/.githooks/pre-commit';

    $command = 'cd '.escapeshellarg($dir).' && sh '.escapeshellarg($hook);

    return preCommitExecPortable($command);
}

/**
 * Recursively delete a fixture directory, tolerating read-only git objects.
 */
function preCommitFixtureCleanup(string $dir): void
{
    $remove = static function (string $path) use (&$remove): void {
        foreach (scandir($path) ?: [] as $entry) {
            if (\in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $item = $path.\DIRECTORY_SEPARATOR.$entry;

            if (is_dir($item)) {
                /** @var callable(string): void $remove */
                $remove($item);

                continue;
            }

            @chmod($item, 0666);
            @unlink($item);
        }

        @rmdir($path);
    };

    $remove($dir);
}

/**
 * Run a command in a fixture repository, tolerating a non-zero exit.
 *
 * `git merge` exits non-zero on a conflict, which is the state these fixtures
 * are trying to reach rather than an error.
 */
function preCommitFixtureAttempt(string $cwd, string $command): void
{
    preCommitExecPortable('cd '.escapeshellarg($cwd).' && '.$command);
}

/**
 * Build two branches that conflict, leaving the repo on `main`.
 *
 * Split out so the cherry-pick and revert cases reuse it: the hazard is the
 * same in all three and only the verb differs.
 */
function preCommitFixtureConflictSetup(string $dir): void
{
    file_put_contents($dir.'/conflicted.txt', "base\n");
    file_put_contents($dir.'/auto-merged.txt', "shared helper\n");
    preCommitFixtureRun($dir, 'git add -A');
    preCommitFixtureRun($dir, 'git commit -m fixture-base');

    preCommitFixtureRun($dir, 'git checkout -q -b feature');
    file_put_contents($dir.'/conflicted.txt', "feature\n");
    file_put_contents($dir.'/auto-merged.txt', "shared helper\nfeature line\n");
    preCommitFixtureRun($dir, 'git commit -qam feature');

    preCommitFixtureRun($dir, 'git checkout -q main');
    file_put_contents($dir.'/conflicted.txt', "main\n");
    preCommitFixtureRun($dir, 'git commit -qam main');
}

/**
 * Leave a fixture repository mid-merge with the conflict resolved and staged.
 *
 * The shape that matters is the SECOND file: `auto-merged.txt` merges cleanly,
 * so git never lists it and nothing prompts anyone to stage it. That is the
 * file a hand-ported hunk goes into and then does not ship.
 */
function preCommitFixtureMerge(string $dir): void
{
    preCommitFixtureConflictSetup($dir);

    // Conflicts, deliberately.
    preCommitFixtureAttempt($dir, 'git merge feature');

    // Resolve and stage the conflicted file, exactly as anyone would.
    file_put_contents($dir.'/conflicted.txt', "resolved\n");
    preCommitFixtureRun($dir, 'git add conflicted.txt');
}

it('blocks a merge whose resolution was never staged, and says why it matters', function (): void {
    $dir = preCommitFixtureRepo();

    try {
        preCommitFixtureMerge($dir);

        // The auto-merged file, hand-edited and never staged. Git lists nothing
        // about it and `git commit` accepts the merge without it.
        file_put_contents($dir.'/auto-merged.txt', "shared helper\nfeature line\nhand-ported hunk\n");

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(1);
        expect($output)->toContain('auto-merged.txt');

        // Naming the file is not enough — the message has to say what committing
        // now would actually do, or it reads as pedantry about a dirty tree.
        expect($output)->toContain('working tree');
        expect($output)->toContain('--no-verify');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('permits the same merge once every resolution is staged', function (): void {
    $dir = preCommitFixtureRepo();

    try {
        preCommitFixtureMerge($dir);

        file_put_contents($dir.'/auto-merged.txt', "shared helper\nfeature line\nhand-ported hunk\n");
        preCommitFixtureRun($dir, 'git add auto-merged.txt');

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(0);
        expect($output)->toBe('');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('gates a cherry-pick whose resolution was never staged', function (): void {
    /*
     * `MERGE_HEAD` is not the only marker. A cherry-pick reaches the same state
     * by a different verb — a conflict, a resolution, and a commit that records
     * the index — and sets `CHERRY_PICK_HEAD` instead. Verified against real
     * git: a cherry-pick with a hand-edited auto-merged file commits at exit 0
     * and drops the edit, exactly as a merge does.
     */
    $dir = preCommitFixtureRepo();

    try {
        preCommitFixtureConflictSetup($dir);
        preCommitFixtureAttempt($dir, 'git cherry-pick feature');

        file_put_contents($dir.'/conflicted.txt', "resolved\n");
        preCommitFixtureRun($dir, 'git add conflicted.txt');
        file_put_contents($dir.'/auto-merged.txt', "shared helper\nhand-ported hunk\n");

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(1);
        expect($output)->toContain('cherry-pick');
        expect($output)->toContain('auto-merged.txt');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('gates a revert whose resolution was never staged', function (): void {
    /*
     * The third verb, and it needs its own setup: reverting HEAD applies
     * cleanly and commits immediately, so there is no `REVERT_HEAD` and nothing
     * to gate. A revert only conflicts when a LATER commit touched the same
     * lines — which is the case worth covering anyway, since a clean revert has
     * no resolution to lose.
     */
    $dir = preCommitFixtureRepo();

    try {
        file_put_contents($dir.'/conflicted.txt', "base\n");
        file_put_contents($dir.'/auto-merged.txt', "shared helper\n");
        preCommitFixtureRun($dir, 'git add -A');
        preCommitFixtureRun($dir, 'git commit -m fixture-base');

        file_put_contents($dir.'/conflicted.txt', "second\n");
        preCommitFixtureRun($dir, 'git commit -qam second');

        file_put_contents($dir.'/conflicted.txt', "third\n");
        preCommitFixtureRun($dir, 'git commit -qam third');

        preCommitFixtureAttempt($dir, 'git revert --no-edit HEAD~1');

        file_put_contents($dir.'/conflicted.txt', "resolved\n");
        preCommitFixtureRun($dir, 'git add conflicted.txt');
        file_put_contents($dir.'/auto-merged.txt', "shared helper\nhand-ported hunk\n");

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(1);
        expect($output)->toContain('revert');
        expect($output)->toContain('auto-merged.txt');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('leaves an ordinary commit with a dirty tree alone', function (): void {
    $dir = preCommitFixtureRepo();

    try {
        /*
         * No merge in progress. A partial commit is a normal thing to make, and
         * a hook that complained about one would be turned off within a week —
         * at which point it is not guarding the merge case either.
         */
        file_put_contents($dir.'/README.md', "edited but not staged\n");
        file_put_contents($dir.'/other.txt', "new\n");
        preCommitFixtureRun($dir, 'git add other.txt');

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(0);
        expect($output)->toBe('');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('reports BOTH gates when a commit trips both, and exits once', function (): void {
    $dir = preCommitFixtureRepo();

    try {
        preCommitFixtureMerge($dir);

        // Trip the parity gate too: stage one mirrored file, not the other.
        file_put_contents($dir.'/local-ci.json', "{\"edited\": true}\n");
        preCommitFixtureRun($dir, 'git add local-ci.json');

        // And leave the merge resolution unstaged.
        file_put_contents($dir.'/auto-merged.txt', "shared helper\nfeature line\nhand-ported hunk\n");

        [$exitCode, $output] = preCommitHookRun($dir);

        /*
         * Both, in one run. Bailing on the first would report one problem, and
         * then — after it was fixed — the other, which is two round trips for
         * one commit.
         */
        expect($exitCode)->toBe(1);
        expect($output)->toContain('CI parity gate');
        expect($output)->toContain('Resolution gate');
        expect($output)->toContain('auto-merged.txt');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('blocks a commit staging only the workflow', function (): void {
    $dir = preCommitFixtureRepo();

    try {
        file_put_contents($dir.'/.github/workflows/ci.yml', "jobs: {added: {}}\n");
        preCommitFixtureRun($dir, 'git add .github/workflows/ci.yml');

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('.github/workflows/ci.yml')
            ->and($output)->toContain('local-ci.json')
            // Naming the escape hatch is the difference between a gate people
            // work with and one they disable.
            ->and($output)->toContain('--no-verify');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('blocks a commit staging only local-ci.json', function (): void {
    // The mirror is symmetric, and a gate that only fires one way covers half
    // the drift while reading as if it covered all of it.
    $dir = preCommitFixtureRepo();

    try {
        file_put_contents($dir.'/local-ci.json', "{\"changed\": true}\n");
        preCommitFixtureRun($dir, 'git add local-ci.json');

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('CI parity gate');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('allows a commit staging both mirrored files', function (): void {
    $dir = preCommitFixtureRepo();

    try {
        file_put_contents($dir.'/.github/workflows/ci.yml', "jobs: {added: {}}\n");
        file_put_contents($dir.'/local-ci.json', "{\"changed\": true}\n");
        preCommitFixtureRun($dir, 'git add .github/workflows/ci.yml local-ci.json');

        [$exitCode] = preCommitHookRun($dir);

        expect($exitCode)->toBe(0);
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('leaves an ordinary commit touching neither file alone', function (): void {
    // The everyday case. A gate that fires on unrelated commits is a gate that
    // gets bypassed reflexively, and a reflexive `--no-verify` skips it on the
    // one commit it was written for.
    $dir = preCommitFixtureRepo();

    try {
        file_put_contents($dir.'/README.md', "changed\n");
        preCommitFixtureRun($dir, 'git add README.md');

        [$exitCode, $output] = preCommitHookRun($dir);

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain('CI parity gate');
    } finally {
        preCommitFixtureCleanup($dir);
    }
});

it('is wired by the composer install hook rather than by hand', function (): void {
    // `.git/hooks/` is not tracked, so a committed hook only takes effect
    // through `core.hooksPath`. Leaving that to a README line means it is set on
    // the machine of whoever wrote the README and nowhere else.
    $root = \dirname(__DIR__, 2);
    $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true);

    expect($manifest)->toBeArray();

    /** @var array{scripts?: array<string, list<string>>} $manifest */
    $scripts = $manifest['scripts'] ?? [];

    expect($scripts['post-install-cmd'] ?? [])->toContain('@php scripts/setup-git-hooks.php')
        ->and($scripts['post-update-cmd'] ?? [])->toContain('@php scripts/setup-git-hooks.php')
        ->and($root.'/scripts/setup-git-hooks.php')->toBeFile();
});
