<?php

declare(strict_types=1);

use Pest\Mutate\MutationTest;

/*
 * Guards the `pest-plugin-mutate` filter-length patch (#313, #408), which
 * stops a mutation run scoring a mutant it never ran: on Windows when its
 * child's command line is too long to launch, and on every platform when its
 * child's filter is too large for PCRE to compile. Ported from `UAMS-Web/uams-statamic`'s
 * patch for the same defect (its #2578, merged in #2595), with the hunks
 * rewritten for this repository's pinned v3.0.5 and PHP 8.2.
 *
 * On Windows every Symfony Process runs as `cmd.exe /V:ON /E:ON /D /C (...)`,
 * whatever the executable, and `cmd.exe` accepts at most 8,191 characters. The
 * child's `--filter` names every test covering the mutant, so a heavily covered
 * mutant can push the line past that. The child then exits 1 without running a
 * test, and `hasFinished()` reads any non-zero exit as a kill. Measured in #296:
 * a plugin-shaped child ran 15 tests at 8,008 characters and exited 1 with no
 * output at 8,400.
 *
 * `MutationTest::childFilter()` is the patched seam. It takes the platform as an
 * argument, so these cases exercise the Windows branch on any machine. Retire
 * this file with the patch.
 */

$filterLengthMutationTestSource = __DIR__.'/../vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';

/**
 * A command shaped like a mutant child's arguments before its `--filter`.
 *
 * @return array<int, string>
 */
function filterLengthChildCommand(): array
{
    return ['C:\\Herd\\uamswp-migration-api\\vendor\\bin\\pest', '--testsuite=Feature', '--bail'];
}

/**
 * `$count` covering-test patterns across `$classes` classes, each shaped as the
 * plugin builds them.
 *
 * @return array<int, string>
 */
function filterLengthCoveringPatterns(int $count, int $classes = 1): array
{
    $patterns = [];

    foreach (range(1, $count) as $index) {
        $patterns[] = 'CoveringClass'.($index % $classes).'Test::(.*)a.long.descriptive.test.name.number.'.$index.'.that.pads.it';
    }

    return $patterns;
}

it('builds the child filter through the length-aware seam', function () use ($filterLengthMutationTestSource): void {
    $source = file_get_contents($filterLengthMutationTestSource);

    if (! is_string($source)) {
        throw new RuntimeException("Unable to read the vendored source at [{$filterLengthMutationTestSource}].");
    }

    // Absent means the patch is not applied: run `composer patches-repatch` in tests/Integration.
    expect($source)->toContain('$filter = self::childFilter(')
        ->and($source)->toContain("'--filter='.\$filter,");
});

it('leaves a filter that fits unchanged', function (): void {
    $patterns = filterLengthCoveringPatterns(3);

    expect(MutationTest::childFilter($patterns, filterLengthChildCommand(), true))->toBe(implode('|', $patterns));
});

it('widens a Windows filter that does not fit to the covering test classes', function (): void {
    $patterns = filterLengthCoveringPatterns(120, 2);

    // Longer than cmd.exe accepts on its own, so the widening branch is what is exercised.
    expect(strlen(implode('|', $patterns)))->toBeGreaterThan(8191);

    expect(MutationTest::childFilter($patterns, filterLengthChildCommand(), true))
        ->toBe('CoveringClass1Test::|CoveringClass0Test::');
});

it('never widens a filter that compiles off Windows, where no cmd.exe limit applies', function (): void {
    $patterns = filterLengthCoveringPatterns(120, 2);

    expect(MutationTest::childFilter($patterns, filterLengthChildCommand(), false))->toBe(implode('|', $patterns));
});

/*
 * The second limit (#408) applies on every platform. PHPUnit compiles a filter
 * as `/<filter>/i`, and a pattern past PCRE's compiled-size limit does not
 * compile, so it matches no test name: the child prints "No tests found.",
 * exits 0, and is scored untested without having run. Measured on macOS at
 * 089e6ac: 414 covering patterns for `includes/Pagination.php:161`, 38,875
 * characters, "regular expression is too large".
 */

/**
 * `$count` covering-test patterns across `$classes` classes, padded with the
 * `.{1,2}` the plugin writes for each `__` in a Pest test name, which is what
 * makes a real filter expensive to compile.
 *
 * @return array<int, string>
 */
function filterLengthOversizedPatterns(int $count, int $classes): array
{
    $patterns = [];

    foreach (range(1, $count) as $index) {
        $patterns[] = 'CoveringClass'.($index % $classes).'Test::(.*)it.'.str_repeat('word.{1,2}', 12).$index;
    }

    return $patterns;
}

/**
 * Whether PHPUnit could compile `$filter`, the way it compiles a `--filter`,
 * with the compile warning kept away from the runner's own error handler.
 */
function filterLengthCompiles(string $filter): bool
{
    set_error_handler(static fn (): bool => true);

    try {
        return preg_match('/'.$filter.'/i', '') !== false;
    } finally {
        restore_error_handler();
    }
}

it('widens a filter PCRE cannot compile to the covering test classes, on any platform', function (bool $windows): void {
    $patterns = filterLengthOversizedPatterns(1000, 2);

    // The control: the joined pattern really does not compile, the way PHPUnit compiles it.
    expect(filterLengthCompiles(implode('|', $patterns)))->toBeFalse()
        ->and(MutationTest::childFilter($patterns, filterLengthChildCommand(), $windows))
        ->toBe('CoveringClass1Test::|CoveringClass0Test::');
})->with(['off Windows' => false, 'on Windows' => true]);

it('leaves a filter that compiles unchanged off Windows, however long', function (): void {
    $patterns = filterLengthOversizedPatterns(50, 2);

    expect(filterLengthCompiles(implode('|', $patterns)))->toBeTrue()
        ->and(MutationTest::childFilter($patterns, filterLengthChildCommand(), false))->toBe(implode('|', $patterns));
});

it('refuses a mutant whose covering classes alone do not compile, naming it', function (): void {
    // One class per pattern, each long enough that the class-level filter is past the limit too.
    $patterns = array_map(
        static fn (int $index): string => 'CoveringClass'.$index.str_repeat('.{1,2}', 40).'Test::(.*)it',
        range(1, 1000),
    );

    expect(fn (): string => MutationTest::childFilter($patterns, filterLengthChildCommand(), false, 'includes/Config.php:33'))
        ->toThrow(RuntimeException::class, 'Cannot test the mutant at includes/Config.php:33: its 1000 covering tests, even named by class, make a filter of');
});

it('refuses a mutant whose covering classes alone do not fit, naming it', function (): void {
    // Four hundred distinct classes: even one pattern per class is past the limit.
    $patterns = filterLengthCoveringPatterns(400, 400);

    expect(fn (): string => MutationTest::childFilter($patterns, filterLengthChildCommand(), true, 'includes/Auth.php:12'))
        ->toThrow(RuntimeException::class, "passes cmd.exe's limit of 8191 characters");
});

/*
 * The time limit a child gets (#447). The plugin sets every child's limit from
 * the initial run plus max(5 s, 20%) and scores a timeout as caught. A child
 * whose filter was widened to its covering classes can run nearly the whole
 * suite plus its own setup, and overran that limit: measured on Windows 11, a
 * planted no-op at `includes/Auth.php:98` (266 covering tests) reported 5
 * timeouts against a 26.51 s initial run, counted as kills. A widened child
 * now gets at least three times the initial run; any other child keeps the
 * plugin's limit, so a mutant that really loops still times out.
 */

it('gives each child the limit the seam computes, from whether its filter was widened', function () use ($filterLengthMutationTestSource): void {
    $source = file_get_contents($filterLengthMutationTestSource);

    if (! is_string($source)) {
        throw new RuntimeException("Unable to read the vendored source at [{$filterLengthMutationTestSource}].");
    }

    // Absent means the patch is not applied: run `composer patches-repatch` in tests/Integration.
    expect($source)->toContain("timeout: self::childTimeout(\$this->initialTestSuiteDuration(), \$filter !== implode('|', \$filters)),");
});

it("keeps the plugin's limit for a child that was not widened", function (float $initial, int $limit): void {
    expect(MutationTest::childTimeout($initial, false))->toBe($limit);
})->with([
    'a short run, where the 5 s floor applies' => [10.0, 15],
    'the measured Auth pass, where 20% applies' => [26.51, 31],
]);

it('gives a widened child at least three times the initial run', function (float $initial, int $limit): void {
    expect(MutationTest::childTimeout($initial, true))->toBe($limit);
})->with([
    'the measured Auth pass' => [26.51, 79],
    "a run so short that the plugin's limit is already longer" => [1.0, 6],
]);
