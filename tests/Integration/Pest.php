<?php

/**
 * Pest configuration.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Tests\Support\WordPressTestCase;

/*
 * WordPress has to be up before the `uses()` below, because resolving
 * WordPressTestCase loads a class that extends `WP_UnitTestCase`. Pest reads
 * this file ahead of `phpunit.xml`'s bootstrap when it is started with
 * `--test-directory=.`, so waiting for that is too late. The bootstrap guards
 * itself against the second load. Without the flag, as in a `composer mutate`
 * child, the bootstrap loads first and requires this file itself (#443).
 */
require_once __DIR__.'/bootstrap.php';

/*
 * Bind WordPress' test case, and with it the per-test database transaction that
 * is rolled back afterwards, to every Feature test.
 *
 * Bound to the directory rather than declared per file: the suite split is a
 * technical boundary, not a naming convention, and a Feature test that forgot the
 * binding would run without a transaction and leave rows behind for every later
 * test to trip over. Relative to this file, which Pest resolves against the
 * file that calls `uses()`, so it holds whichever route loaded it. `Mutate/` is
 * not bound: those tests read the patched plugin and need no transaction.
 */
uses(WordPressTestCase::class)->in('Feature');
