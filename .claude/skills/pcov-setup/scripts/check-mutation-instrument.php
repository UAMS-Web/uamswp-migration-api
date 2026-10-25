<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Report whether `pest --mutate` on this machine can actually execute a mutant.
 *
 * A mutation run reports a score whether or not it ran anything, and the two outcomes are
 * byte-identical apart from the numbers. `Pest\Mutate\MutationTest::start()` spawns each
 * mutant in a child process built from `$_SERVER['argv']`, reusing **argv[0] verbatim as the
 * executable**; `hasFinished()` then reads any non-zero exit as "the test failed", i.e. the
 * mutant was killed. A child that could not be launched at all exits non-zero, so it is
 * recorded as a kill. Every mutant is then "killed", the run prints 100.00%, and it exits 0.
 *
 * PHP sets argv[0] to the script path AS TYPED, so on Windows both `vendor/bin/pest` and
 * `php vendor/bin/pest` hand the child a forward-slashed relative path that cmd.exe cannot
 * launch. Measured on Windows 11 Pro 10.0.26200 / PHP 8.4.20 / pest-plugin-mutate v5.0.2,
 * one class, one tree, cache cleared between arms (uams-statamic#2322):
 *
 *   vendor/bin/pest        (forward)   20 tested,  0 untested   100.00%   fabricated
 *   php vendor/bin/pest    (forward)   20 tested,  0 untested   100.00%   fabricated
 *   php vendor\bin\pest    (separator) 14 tested,  6 untested    70.00%   executed
 *   composer mutate        (separator) 14 tested,  6 untested    70.00%   executed
 *
 * THE CRITERION IS DISAGREEMENT, NOT SPEED. A run reporting zero survivors on a class nobody
 * has hardened is a NULL result, not a clean one: a run that executes nothing cannot disagree
 * with itself, so surviving mutants are positive evidence of execution in a way that duration
 * is not. Per-mutant wall time is a weaker secondary signal only -- fast has innocent
 * explanations, which is why a stale result cache had to be excluded separately before speed
 * meant anything. This script supplies the mechanical half of that control: it proves the
 * child can be launched, which is what fabricates the kills when it cannot.
 *
 * WHAT IT CHECKS
 *
 *   1. The launch probe. Spawns each candidate argv[0] with `--version` through the same
 *      Symfony `Process` the plugin uses, and reports which forms can start a child. This
 *      MEASURES whether a child starts rather than branching on the operating system, so
 *      correct on a platform where forward slashes are fine.
 *   2. Whether the installed plugin carries a launch patch that normalizes argv[0] and
 *      aborts when the child still cannot start (uams-statamic#2322's `canLaunchMutantProcess`).
 *      `composer install` does not re-patch an already-installed package, so a `vendor/`
 *      predating the patch is unguarded while reporting the same version.
 *
 * BOUND, stated because it decides how to read a clean run. A child that starts proves the
 * GROSS failure is absent. It does not prove every verdict is honest: a genuine run can still
 * misreport a single mutant as killed, and a false kill leaves NO SURVIVOR TO EXPLAIN, so the
 * "no unexplained survivors" discipline cannot see it. A clean exit here means a score is
 * worth reading, never that it is correct.
 *
 * Delivered under `.claude/skills/pcov-setup/scripts/` (and the `.agents/skills/` copy) to
 * every repository with the `pest` profile. Finds the repository root by walking up for
 * `vendor/autoload.php`.
 *
 * Usage:  php .claude/skills/pcov-setup/scripts/check-mutation-instrument.php [--root=<path>] [--runner=<path>] [argv0 ...]
 *         (default argv0: the repo's pest binary; default runner: the installed plugin)
 * Exit:   0 = a mutant child can be launched, 1 = it cannot and scores would be fabricated,
 *         2 = the instrument could not be located, so nothing was measured.
 *
 * `--runner` exists so this check's OWN failing branch can be exercised against a copy of the
 * unpatched plugin. A guard whose red path nobody has watched fire is the false green this
 * script is about, so the seam is deliberate rather than a convenience.
 */
// cspell:ignore argv0

$arguments = array_slice($argv, 1);
$runnerOverride = null;
$rootOverride = null;

foreach ($arguments as $index => $argument) {
    if (str_starts_with($argument, '--runner=')) {
        $runnerOverride = substr($argument, strlen('--runner='));
        unset($arguments[$index]);
    } elseif (str_starts_with($argument, '--root=')) {
        $rootOverride = substr($argument, strlen('--root='));
        unset($arguments[$index]);
    }
}

$arguments = array_values($arguments);

$root = $rootOverride !== null && is_dir($rootOverride)
    ? (realpath($rootOverride) ?: $rootOverride)
    : findRepositoryRoot(__DIR__);

if ($root === null || $root === false) {
    fwrite(STDERR, "check-mutation-instrument: vendor/autoload.php not found above this script; run composer install in the repository root (or pass --root=).\n");

    exit(2);
}

$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "check-mutation-instrument: vendor/autoload.php is missing under {$root}; run composer install.\n");

    exit(2);
}

require $autoload;

$runnerPath = $runnerOverride ?? $root.'/vendor/pestphp/pest-plugin-mutate/src/Tester/MutationTestRunner.php';

if (! is_file($runnerPath)) {
    fwrite(STDERR, "check-mutation-instrument: pest-plugin-mutate is not installed; nothing measured.\n");

    exit(2);
}

$runnerSource = (string) file_get_contents($runnerPath);
$guarded = str_contains($runnerSource, 'canLaunchMutantProcess');

$candidates = $arguments;

if ($candidates === []) {
    $candidates = ['vendor/bin/pest'];

    if (DIRECTORY_SEPARATOR !== '/') {
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, 'vendor/bin/pest');
    }
}

$started = [];

foreach ($candidates as $candidate) {
    $process = new Process([$candidate, '--version'], cwd: $root, timeout: 60);
    $process->run();

    $ok = $process->isSuccessful();
    $started[$candidate] = $ok;

    printf(
        "%-9s %s%s\n",
        $ok ? 'launches' : 'CANNOT',
        $candidate,
        $ok ? '' : '  -- '.trim(explode("\n", $process->getErrorOutput())[0] ?? 'no error output')
    );
}

$anyFails = in_array(false, $started, true);

echo "\n";
printf("launch patch (canLaunchMutantProcess) in the installed plugin: %s\n", $guarded ? 'present' : 'ABSENT');

if (! $anyFails) {
    echo "check-mutation-instrument: OK -- a mutant child can be launched from every form measured.\n";
    echo "Still read the score itself for SURVIVORS: zero survivors on an unhardened class is a null result.\n";

    exit(0);
}

if ($guarded) {
    echo "check-mutation-instrument: OK -- a form above cannot launch, but the installed plugin\n";
    echo "normalizes argv[0] and aborts rather than scoring a child that never started as a kill.\n";
    echo "Still read the score itself for SURVIVORS: zero survivors on an unhardened class is a null result.\n";

    exit(0);
}

echo "check-mutation-instrument: FABRICATION LIKELY.\n";
echo "A form above cannot launch a child, and the installed plugin has no guard, so every mutant\n";
echo "reached through that form is scored KILLED without being executed and the run exits 0.\n";
echo "Re-apply any launch patch (composer install into a cold vendor/), or invoke via a form that launches.\n";

exit(1);

/**
 * Walk up from `$start` until a directory holding `vendor/autoload.php` is found.
 *
 * @param  string  $start  Directory to begin walking from
 * @return string|null Absolute repository root, or null when none is found
 */
function findRepositoryRoot(string $start): ?string
{
    $dir = $start;

    while (true) {
        if (is_file($dir.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
            return $dir;
        }

        $parent = dirname($dir);

        if ($parent === $dir) {
            return null;
        }

        $dir = $parent;
    }
}
