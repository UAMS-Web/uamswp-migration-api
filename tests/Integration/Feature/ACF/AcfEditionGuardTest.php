<?php

declare(strict_types=1);

/*
 * Keeps the two ACF layers honest about which one is running.
 *
 * The suite loads ACF PRO when the surrounding install has it and the free
 * package otherwise, so a developer gets `repeater`, `flexible_content` and
 * `clone` while CI gets a portable subset. That arrangement has one failure
 * mode and it is silent: a run that *could* have loaded PRO but did not still
 * passes, having skipped exactly the container tests #21 exists for.
 *
 * So the choice is asserted rather than trusted. If PRO is on disk, PRO is what
 * ran — anything else is a red, not a skip.
 */

it('loaded ACF', function (): void {
    // The floor. Everything else in tests/Integration/Feature/ACF asserts against a real
    // ACF, and without one they would each fail with an undefined-function
    // error that names the test rather than the cause.
    expect(acfEdition())->toBeIn(['pro', 'free'])
        ->and(\function_exists('acf_get_field_groups'))->toBeTrue()
        ->and(\function_exists('acf_get_value'))->toBeTrue()
        ->and(\defined('ACF_VERSION'))->toBeTrue();
});

it('runs against ACF PRO whenever the install has it', function (): void {
    // The loud half of the "loud skip". A PRO-only test that skips because PRO
    // is genuinely absent is fine and expected — that is CI. A PRO-only test
    // that skips while PRO sits in the sibling plugin directory means the
    // bootstrap stopped finding it, and every container assertion in this
    // directory has quietly stopped running.
    if (! acfProOnDisk()) {
        expect(acfEdition())->toBe('free');

        return;
    }

    expect(acfEdition())->toBe('pro')
        // The containers the whole fidelity layer exists for. Their absence
        // under a 'pro' edition would mean PRO loaded but did not initialize.
        ->and(acf_get_field_type('repeater'))->toBeInstanceOf(acf_field::class)
        ->and(acf_get_field_type('flexible_content'))->toBeInstanceOf(acf_field::class);
});

it('pins the free copy to the same minor as the PRO the network runs', function (): void {
    // A stand-in three minors adrift from production is a green that does not
    // transfer. Composer holds the constraint; this states the reason, so a
    // future bump moves both together or fails here.
    // Both manifests: the Integration setup's copy is the one this suite
    // loads, and the root's is the one PHPStan scans (#375).
    foreach ([\dirname(__DIR__, 2), \dirname(__DIR__, 4)] as $directory) {
        $manifest = json_decode((string) file_get_contents($directory.'/composer.json'), true);

        expect($manifest)->toBeArray();

        /** @var array{require-dev?: array<string, string>} $manifest */
        expect($manifest['require-dev']['wpackagist-plugin/advanced-custom-fields'] ?? '')->toBe('~6.4.0');
    }
});
