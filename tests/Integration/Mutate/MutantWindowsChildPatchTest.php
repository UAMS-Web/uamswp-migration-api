<?php

declare(strict_types=1);

use Pest\Mutate\Support\StreamWrapper;

/*
 * Guards the `pest-plugin-mutate` Windows mutant-child patch (#296), which stops
 * a mutation run on Windows scoring a mutant killed when its child process never
 * ran a test. Ported from `UAMS-Web/uams-statamic`'s patch for the same two
 * defects, with the hunks rewritten for this repository's pinned v3.0.5.
 *
 * 1. `MutationTest::start()` passed `'--filter="'.implode('|', $filters).'"'`.
 *    The literal quotes do not survive `cmd.exe`, which launches the
 *    `vendor\bin\pest` batch file, so the `|` between two or more test names was
 *    read as a pipe and the child exited 255 having run nothing. A long filter
 *    carrying a `"` was also moved into the environment block by Symfony
 *    Process, which aborted the whole run past the 32,767-unit limit.
 * 2. `StreamWrapper::stream_lock()` took `LOCK_SH` whatever was asked. On
 *    Windows a shared lock denies writes even through the handle holding it, so
 *    every `LOCK_EX` write in a mutant child failed.
 *
 * The lock tests exercise the wrapper rather than reading its source. The
 * exclusive-lock conflict holds on macOS and Linux too, where `flock()` locks
 * are per open file, so the first and third tests go red on any platform when
 * the patch is absent. The write test goes red only on Windows, because an
 * advisory shared lock does not stop its own handle writing. Retire this file
 * with the patch.
 */

$mutationTestSource = __DIR__.'/../vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';

/**
 * Run a callback with the plugin's `file` stream wrapper registered, as it is in
 * a mutant child.
 *
 * The wrapper replaces PHP's own `file` protocol process-wide, so it is always
 * restored, and the original and override paths it requires are throwaway files
 * outside the repository.
 *
 * @template T
 *
 * @param  callable(string): T  $callback  Receives a scratch directory to write into.
 * @return T
 */
function withMutantChildStreamWrapper(callable $callback): mixed
{
    $directory = sys_get_temp_dir().'/mutant-child-lock-'.bin2hex(random_bytes(6));
    mkdir($directory, 0777, true);

    $original = $directory.'/Original.php';
    $override = $directory.'/Override.php';
    file_put_contents($original, '<?php');
    file_put_contents($override, '<?php');

    $started = false;

    try {
        StreamWrapper::start($original, $override);
        $started = true;

        return $callback($directory);
    } finally {
        // Restoring a protocol that was never replaced raises a notice, which
        // would mask whatever made start() throw.
        if ($started) {
            StreamWrapper::disable();
        }

        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
}

it('passes the filter unquoted, so cmd.exe cannot split it at the pipe', function () use ($mutationTestSource): void {
    $source = file_get_contents($mutationTestSource);

    if (! is_string($source)) {
        throw new RuntimeException("Unable to read the vendored source at [{$mutationTestSource}].");
    }

    // Absent means the patch is not applied: run `composer patches-repatch` in tests/Integration.
    // Since #313 the unquoted filter comes from childFilter(), which joins the patterns itself;
    // since #447 it is held in $filter, so the child's time limit can see whether it was widened.
    expect($source)->toContain('$filter = self::childFilter($filters')
        ->and($source)->toContain("'--filter='.\$filter,");
    expect($source)->not->toContain("'--filter=\"'.implode('|', \$filters).'\"'");
});

it('writes a file under an exclusive lock through the mutant-child stream wrapper', function (): void {
    $written = withMutantChildStreamWrapper(function (string $directory): array {
        $target = $directory.'/entry';

        $bytes = file_put_contents($target, 'sixteen-byte-val', LOCK_EX);

        return [$bytes, file_get_contents($target)];
    });

    expect($written)->toBe([16, 'sixteen-byte-val']);
});

it('takes the lock that was asked for, so a second exclusive lock is refused', function (): void {
    $secondExclusive = withMutantChildStreamWrapper(function (string $directory): bool {
        $target = $directory.'/locked';
        file_put_contents($target, 'x');

        $first = fopen($target, 'r+');
        $second = fopen($target, 'r+');

        if ($first === false || $second === false) {
            throw new RuntimeException('Unable to open the lock fixture twice.');
        }

        try {
            expect(flock($first, LOCK_EX))->toBeTrue();

            // Unpatched, both requests became LOCK_SH, and two shared locks
            // coexist, so this non-blocking exclusive request succeeded.
            return flock($second, LOCK_EX | LOCK_NB);
        } finally {
            flock($first, LOCK_UN);
            fclose($first);
            fclose($second);
        }
    });

    expect($secondExclusive)->toBeFalse();
});
