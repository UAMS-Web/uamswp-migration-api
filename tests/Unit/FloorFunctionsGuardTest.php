<?php

declare(strict_types=1);

/*
 * Drives `scripts/check-floor-functions.php` against throwaway git repositories,
 * asserting on the exit code and the lines it prints (#399).
 *
 * The guard asks the running PHP whether it has a function natively, and this
 * suite runs on the development PHP, not the floor. So the fixture polyfill
 * defines `uamswp_floor_probe_absent`, which no PHP has, as the function the
 * floor lacks, and `str_contains`, which every PHP since 8.0 has, as the one it
 * does. Each run passes `--any-php`; the refusal without it has its own test.
 *
 * Driven as a subprocess: the script is a CLI entry point that calls `exit()`.
 */

/**
 * Run the guard against a fixture root.
 *
 * @param  list<string>  $options
 * @return array{0: int, 1: string} The exit code and the combined output.
 */
function floorGuardRun(string $root, array $options = ['--any-php']): array
{
    $script = \dirname(__DIR__, 2).'/scripts/check-floor-functions.php';
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);

    foreach ([...$options, $root] as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $exitCode = 0;
    exec($command.' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

/**
 * Build a fixture repository holding the given files, committed to its index.
 *
 * @param  array<string, string>  $files  Contents keyed by path relative to the root.
 */
function floorGuardFixture(array $files): string
{
    $root = sys_get_temp_dir().'/floor-guard-'.bin2hex(random_bytes(6));

    $files += [
        'vendor/acme/polyfill-probe/bootstrap.php' => "<?php\n\nif (! function_exists('uamswp_floor_probe_absent')) {\n    function uamswp_floor_probe_absent(array \$values) { return \$values; }\n}\n\nif (! function_exists('str_contains')) {\n    function str_contains(string \$haystack, string \$needle): bool { return true; }\n}\n",
    ];

    foreach ($files as $path => $contents) {
        $directory = \dirname($root.'/'.$path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($root.'/'.$path, $contents);
    }

    exec('git -C '.escapeshellarg($root).' init -q && git -C '.escapeshellarg($root).' add -A 2>&1', $output, $status);

    expect($status)->toBe(0);

    return $root;
}

/**
 * Recursively delete a fixture directory.
 *
 * In PHP rather than `rm -rf`, which `cmd.exe` does not have (#416). Git
 * writes its object files read-only, and Windows refuses to unlink a read-only
 * file, so each file is made writable first.
 */
function floorGuardCleanup(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        \assert($entry instanceof SplFileInfo);

        if ($entry->isDir()) {
            rmdir($entry->getPathname());

            continue;
        }

        chmod($entry->getPathname(), 0666);
        unlink($entry->getPathname());
    }

    rmdir($root);
}

/**
 * A shipped file whose body is the given statement.
 */
function floorGuardSource(string $statement): string
{
    return "<?php\n\nnamespace Acme\\Widget;\n\nfinal class Thing\n{\n    public function run(array \$values): mixed\n    {\n        {$statement}\n    }\n}\n";
}

it('reports a call to a function only a polyfill defines', function (): void {
    $root = floorGuardFixture(['includes/Thing.php' => floorGuardSource('return uamswp_floor_probe_absent($values);')]);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('includes/Thing.php:9: uamswp_floor_probe_absent()')
        ->and($output)->toContain('1 files checked; 2 functions defined by 1 polyfill files.');
});

it('passes a polyfilled function the running PHP has natively', function (): void {
    $root = floorGuardFixture(['includes/Thing.php' => floorGuardSource("return str_contains('ab', 'a');")]);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No shipped file calls a polyfilled function the floor lacks.');
});

it('reports the fully qualified, imported and string-callable forms', function (string $source): void {
    $root = floorGuardFixture(['includes/Thing.php' => $source]);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('uamswp_floor_probe_absent');
})->with([
    'fully qualified' => [floorGuardSource('return \uamswp_floor_probe_absent($values);')],
    'string callable' => [floorGuardSource("return array_map('uamswp_floor_probe_absent', [\$values]);")],
    'use function' => ["<?php\n\nnamespace Acme\\Widget;\n\nuse function uamswp_floor_probe_absent;\n"],
]);

it('does not mistake a method, a static call or a declaration for a call', function (): void {
    $root = floorGuardFixture(['includes/Thing.php' => floorGuardSource('return [$this->uamswp_floor_probe_absent($values), self::uamswp_floor_probe_absent($values), $this?->uamswp_floor_probe_absent($values)];')
        ."\nfunction uamswp_floor_probe_absent(array \$values): array { return \$values; }\n"]);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(0, $output);
});

it('checks the files lint-floor checks, and not tests/, vendor/ or untracked files', function (): void {
    $call = floorGuardSource('return uamswp_floor_probe_absent($values);');
    $root = floorGuardFixture([
        'tests/Unit/ThingTest.php' => $call,
        'vendor/acme/other/src/Thing.php' => $call,
        'scripts/tool.php' => "<?php\n\nuamswp_floor_probe_absent([]);\n",
    ]);
    file_put_contents($root.'/includes-untracked.php', $call);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    // Only scripts/tool.php is a tracked file outside the exclusions.
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('1 files checked;')
        ->and($output)->toContain('scripts/tool.php:3:')
        ->and($output)->not->toContain('tests/Unit')
        ->and($output)->not->toContain('includes-untracked');
});

it('lists every tracked file on this platform, including a path with a space', function (): void {
    $call = floorGuardSource('return uamswp_floor_probe_absent($values);');
    $root = floorGuardFixture([
        'includes/Thing.php' => $call,
        'includes/Sub Dir/Other.php' => $call,
        'scripts/tool.php' => "<?php\n\nuamswp_floor_probe_absent([]);\n",
    ]);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    // On Windows the listing used to go through cmd.exe, which read the
    // `2>/dev/null` as a path and listed nothing (#416).
    expect($exitCode)->toBe(1, $output)
        ->and($output)->toContain('3 files checked;')
        ->and($output)->toContain('includes/Sub Dir/Other.php:9:')
        ->and($output)->not->toContain('The system cannot find the path specified.');
});

it('names the git failure when the root is not a repository', function (): void {
    $root = floorGuardFixture(['includes/Thing.php' => floorGuardSource('return $values;')]);
    floorGuardCleanup($root.'/.git');

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('could not list the tracked PHP files under')
        ->and($output)->toContain('git ls-files exited 128: fatal: not a git repository')
        ->and($output)->not->toContain('is it a git work tree');
});

it('reads the Integration setup polyfills too', function (): void {
    $root = floorGuardFixture([
        'includes/Thing.php' => floorGuardSource('return uamswp_floor_probe_integration($values);'),
        'tests/Integration/vendor/acme/polyfill-other/bootstrap.php' => "<?php\n\nfunction uamswp_floor_probe_integration(array \$values) { return \$values; }\n",
    ]);

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('3 functions defined by 2 polyfill files.')
        ->and($output)->toContain('uamswp_floor_probe_integration()');
});

it('refuses to report clean with no polyfill to read', function (): void {
    $root = floorGuardFixture(['includes/Thing.php' => floorGuardSource('return $values;')]);
    floorGuardCleanup($root.'/vendor');

    [$exitCode, $output] = floorGuardRun($root);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('no polyfill found');
});

it('refuses to run on a PHP other than the floor without --any-php', function (): void {
    if (PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION === '8.2') {
        $this->markTestSkipped('This process runs on the floor, where the guard runs.');
    }

    $root = floorGuardFixture(['includes/Thing.php' => floorGuardSource('return uamswp_floor_probe_absent($values);')]);

    [$exitCode, $output] = floorGuardRun($root, []);
    floorGuardCleanup($root);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('not the 8.2 floor');
});
