<?php

/**
 * The form plugin API surface, re-derived from the installed plugin.
 */

declare(strict_types=1);

require_once \dirname(__DIR__, 3).'/Support/form-api-surface.php';

/*
 * The half of the boundary guard that needs a real plugin to reflect over.
 *
 * `tests/Unit/FormEntryBoundaryTest.php` holds the static scans: nothing under
 * `includes/` calls a submission method, a feed write, or a feed run. Those
 * guards are only ever as good as the lists they read, and a hand-maintained
 * list of another project's API is a list that falls behind — this one already
 * had, by fourteen methods.
 *
 * These two tests are what stops that. They ask the INSTALLED `GFAPI` what it
 * exposes and fail when it grows a method nothing has classified.
 *
 * **They live in Feature because that is the only suite where Gravity Forms is
 * loaded.** In `tests/Unit` the reflection cannot run at all, so the skip fires
 * on every machine on every run rather than only on CI, and the guard reports a
 * reassuring yellow forever. Here it skips exactly where D-030 and D-044 say it
 * should: on a runner that cannot have a paid plugin.
 */

beforeEach(function (): void {
    if (! class_exists('GFAPI')) {
        $this->markTestSkipped('Gravity Forms is not installed here, so there is no API surface to re-derive.');
    }
});

/**
 * Every public `GFAPI` method whose name contains a fragment.
 *
 * @param  list<string>  $fragments
 * @return list<string>
 */
function surfaceMethods(array $fragments): array
{
    $found = [];

    foreach ((new ReflectionClass('GFAPI'))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();

        foreach ($fragments as $fragment) {
            if (str_contains($name, $fragment)) {
                $found[$name] = $name;

                break;
            }
        }
    }

    ksort($found);

    return array_values($found);
}

it('finds a surface to reflect over, so neither guard below passes on an empty set', function (): void {
    // The negative control. A reflection that returns nothing agrees with every
    // list it is compared against.
    expect(surfaceMethods(entryApiMarkers()))->not->toBe([]);
    expect(surfaceMethods(['feed']))->not->toBe([]);
});

it('names every submission method the installed GFAPI actually exposes', function (): void {
    $exposed = surfaceMethods(entryApiMarkers());
    $named = entryApiMethods();

    $missing = array_values(array_diff($exposed, $named));

    sort($missing);

    expect($missing)->toBe([], sprintf(
        'Gravity Forms exposes submission method(s) this guard does not name: %s. '
        .'Add them to entryApiMethods() — an unnamed method is one the caller guard cannot see.',
        implode(', ', $missing)
    ));

    // And the list must not drift the other way into naming methods that do not
    // exist, which would read as coverage while guarding nothing.
    $phantom = array_values(array_diff($named, $exposed));

    sort($phantom);

    expect($phantom)->toBe([], sprintf('entryApiMethods() names method(s) GFAPI does not have: %s.', implode(', ', $phantom)));
});

it('classifies every feed method the installed GFAPI exposes', function (): void {
    /*
     * `entryApiMarkers()` deliberately does NOT carry `feed` — it would sweep
     * up `GFAPI::get_feeds()`, which this repo calls — so the feed surface needs
     * a derivation of its own rather than an entry in that list.
     *
     * Every public method with `feed` in its name has to be named in exactly one
     * of three places: allowed to read, banned as a write or a feed run, or
     * already banned as an entry method. A new one is then a deliberate decision
     * rather than an unremarked capability.
     */
    $exposed = surfaceMethods(['feed']);
    $classified = array_merge(feedReadMethods(), feedWriteMethods(), entryApiMethods());

    $unclassified = array_values(array_diff($exposed, $classified));

    sort($unclassified);

    expect($unclassified)->toBe([], sprintf(
        'Gravity Forms exposes feed method(s) nothing has classified: %s. Decide whether each one reads '
        .'or acts, and add it to feedReadMethods() or feedWriteMethods() — an unclassified method is one '
        .'no guard can see.',
        implode(', ', $unclassified)
    ));

    // The read allowlist must name methods that exist, or it reads as permission
    // for a surface that is not there.
    $phantom = array_values(array_diff(feedReadMethods(), $exposed));

    sort($phantom);

    expect($phantom)->toBe([], sprintf('feedReadMethods() names method(s) GFAPI does not have: %s.', implode(', ', $phantom)));

    /*
     * The write ban is only as good as the names in it. `feedReadMethods()` is
     * checked against the installed surface above; `feedWriteMethods()` was not
     * — so a typo there names a method that does not exist, and
     * `FormEntryBoundaryTest`'s scan for it in `includes/` passes trivially
     * while the REAL method, misspelled or missing from the list entirely,
     * stays unguarded.
     */
    $phantomWrite = array_values(array_diff(feedWriteMethods(), $exposed));

    sort($phantomWrite);

    expect($phantomWrite)->toBe([], sprintf('feedWriteMethods() names method(s) GFAPI does not have: %s.', implode(', ', $phantomWrite)));
});
