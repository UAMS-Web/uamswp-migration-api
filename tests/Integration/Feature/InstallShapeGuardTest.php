<?php

/**
 * Each process runs the install shape it was started for (#14).
 */

declare(strict_types=1);

/*
 * The suite runs once as a network and once as a single site, chosen by
 * `UAMSWP_MIGRATION_API_TESTS_SINGLE_SITE` in tests/Integration/wp-tests-config.php.
 * Tests that mean something on only one shape skip on the other
 * (`networkOnly()` and `singleSiteOnly()` in tests/Support/helpers.php), so a
 * process that silently ran the wrong shape would turn half the suite into
 * skips and still pass. This file never skips: it reds that process instead.
 */

it('runs the install shape the process was started for', function (): void {
    $singleSite = getenv('UAMSWP_MIGRATION_API_TESTS_SINGLE_SITE') === '1';

    expect(is_multisite())->toBe(! $singleSite);
});
