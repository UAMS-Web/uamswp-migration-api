<?php

/**
 * Configuration for the WordPress test bootstrap.
 *
 * Committed, but carries nothing machine-specific: WordPress is located from
 * this checkout's own position inside `wp-content/plugins/`, and every database
 * value falls back to a default that works against a stock Herd MySQL. Override
 * any of them with the matching `WP_TESTS_*` environment variable.
 *
 * The database named here is DROPPED AND REBUILT on every run. `tests/Integration/bootstrap.php`
 * refuses to continue if it matches the one the working install uses.
 */

declare(strict_types=1);

/*
 * Located rather than configured. This file sits at
 * `<wordpress>/wp-content/plugins/uamswp-migration-api/tests/Integration/`, so
 * WordPress is five levels up, true for any checkout in any install, which is
 * what makes the suite runnable by someone who did not write it.
 */
define('ABSPATH', rtrim((string) (getenv('WP_TESTS_ABSPATH') ?: dirname(__DIR__, 5)), '/').'/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'uamswp_migration_api_tests');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

/*
 * Every endpoint in this plugin is site-scoped, and Site::switchForRequest() is
 * switch_to_blog() and its restore. On a single-site install that branch never
 * executes, so a green suite would say nothing about the code path that matters
 * most. Not a preference — the suite has to run against a network.
 */
define('WP_TESTS_MULTISITE', true);

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'UAMSWP Migration API Tests');
define('WP_PHP_BINARY', 'php');

/*
 * Keep the tests off the network. WordPress' HTTP API would otherwise reach out
 * during install (update checks, theme and plugin queries), which makes runs slow
 * and their failures depend on connectivity rather than on the code.
 */
define('WP_HTTP_BLOCK_EXTERNAL', true);

/*
 * The shared secret is configured by a constant, and a constant cannot be undefined
 * once set. Both halves of `Auth::secretMatches()` therefore need their own process:
 * the branch where no secret is configured is only reachable while this stays
 * undefined, and that branch is the security-critical one — an unset secret must
 * authorize nothing, however plausible the header looks.
 *
 * Left undefined by default. `composer test:integration:secret` sets the variable and
 * runs the other half; the tests on each side skip themselves when they are in the
 * wrong process, so neither can pass by never having run.
 */
$secret = getenv('WP_TESTS_MIGRATION_SECRET');

/*
 * `UAMSWP_MIGRATION_API_TESTS_EMPTY_SECRET=1` defines the constant as an empty
 * string, a third state neither process above reaches: defined, and still
 * authorizing nothing (D-098). It is for one invocation of
 * `EmptySecretTest.php`, never for `composer test` or the chain:
 *
 *     UAMSWP_MIGRATION_API_TESTS_EMPTY_SECRET=1 \
 *       tests/Integration/vendor/bin/pest --configuration=tests/Integration/phpunit.xml \
 *       --test-directory=. tests/Integration/Feature/EmptySecretTest.php
 *
 * One `define()` serves all three states, so the analyzer sees the constant as
 * the string it is in production rather than as the literal one branch sets.
 */
$emptySecret = getenv('UAMSWP_MIGRATION_API_TESTS_EMPTY_SECRET') === '1';

if ($emptySecret || (is_string($secret) && $secret !== '')) {
    define('UAMSWP_MIGRATION_API_SECRET', $emptySecret ? '' : $secret);
}
