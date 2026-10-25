<?php

declare(strict_types=1);

/*
 * The Pest processes the `pest` job in local-ci.json runs, in order.
 *
 * Data rather than code so tests/Unit/CiParityTest.php can hold this list
 * against the processes `composer test` chains, which is what ci.yml runs:
 * scripts/run-pest-suites.php starts these, and nothing else does.
 *
 * Each entry is the Pest binary and its arguments, relative to the repository
 * root, and the environment the process gets on top of the caller's. Every
 * Integration process names both settings it depends on rather than inheriting
 * whatever the caller's shell exported: `MULTISITE` and the shared secret are
 * constants, fixed for the process (#14).
 *
 * @return array<string, array{pest: string, args: list<string>, env: array<string, string>}>
 */

$integration = ['--configuration=tests/Integration/phpunit.xml', '--test-directory=.'];
$secretTest = [...$integration, 'tests/Integration/Feature/SharedSecretTest.php'];

return [
    'Unit' => [
        'pest' => 'vendor/bin/pest',
        'args' => [],
        'env' => [],
    ],
    'Integration' => [
        'pest' => 'tests/Integration/vendor/bin/pest',
        'args' => $integration,
        'env' => ['UAMSWP_MIGRATION_API_TESTS_SINGLE_SITE' => '', 'WP_TESTS_MIGRATION_SECRET' => ''],
    ],
    'shared secret (second process)' => [
        'pest' => 'tests/Integration/vendor/bin/pest',
        'args' => $secretTest,
        'env' => ['UAMSWP_MIGRATION_API_TESTS_SINGLE_SITE' => '', 'WP_TESTS_MIGRATION_SECRET' => 'shared-secret-for-tests'],
    ],
    'Integration, single site' => [
        'pest' => 'tests/Integration/vendor/bin/pest',
        'args' => $integration,
        'env' => ['UAMSWP_MIGRATION_API_TESTS_SINGLE_SITE' => '1', 'WP_TESTS_MIGRATION_SECRET' => ''],
    ],
    'shared secret, single site' => [
        'pest' => 'tests/Integration/vendor/bin/pest',
        'args' => $secretTest,
        'env' => ['UAMSWP_MIGRATION_API_TESTS_SINGLE_SITE' => '1', 'WP_TESTS_MIGRATION_SECRET' => 'shared-secret-for-tests'],
    ],
];
