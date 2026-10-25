<?php

declare(strict_types=1);

/*
 * The `pest` job in local-ci.json: the three Pest processes `composer test`
 * chains, against the runner's own database (#366). The unit suite runs from
 * the root setup on Pest 5; the Integration suite runs from the setup in
 * `tests/Integration` on Pest 3, because WordPress' test bootstrap cannot
 * install beside PHPUnit 13 (#375).
 *
 * Three processes rather than one because the shared secret is a CONSTANT, and a
 * constant cannot be undefined once set: the branch where no secret is
 * configured and the branch where one is each need a process of their own. The
 * third process is the one with the secret.
 *
 * The runner gives this job its database through `WP_TESTS_DB_NAME`, takes the
 * suite's lock for it, and says so in `LOCAL_CI_SUITE_LOCK_HELD`; every process
 * started here inherits all three. It does not create the database, so this
 * does, first: without it the run dies later inside the WordPress installer
 * with an error that names neither the database nor the fix.
 *
 * WordPress is located from the PRIMARY checkout, the one inside
 * `wp-content/plugins/`, and passed to every suite as `WP_TESTS_ABSPATH`, so the
 * slot can sit anywhere `--worktree-path` puts it and a value exported in the
 * caller's shell cannot point the suite at another install. Override with
 * `LOCAL_CI_WP_ABSPATH`.
 *
 * Runs under whatever PHP started it, which under the runner is the PHP
 * local-ci.json names, and starts each suite with that same binary.
 *
 * Usage: php scripts/run-pest-suites.php
 */

$root = dirname(__DIR__);
$database = getenv('WP_TESTS_DB_NAME');

if (! is_string($database) || $database === '') {
    fwrite(STDERR, "WP_TESTS_DB_NAME is not set. Run this through the local CI runner, which names the database, or set it.\n");
    exit(1);
}

// `127.0.0.1` rather than the default socket: Herd serves MySQL over TCP and
// creates no socket file. The same host, user and password the suite defaults to.
$host = getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1';
$user = getenv('WP_TESTS_DB_USER') ?: 'root';
$password = getenv('WP_TESTS_DB_PASSWORD') ?: '';

// Derived as tests/Integration/wp-tests-config.php derives it, `dirname(__DIR__, 5)`
// from `tests/Integration/`, but counted from the primary checkout rather than
// this tree.
$wordpress = getenv('LOCAL_CI_WP_ABSPATH');

if (! is_string($wordpress) || $wordpress === '') {
    $common = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse --path-format=absolute --git-common-dir'));

    if ($common === '') {
        fwrite(STDERR, "Could not find this checkout's git directory, so WordPress cannot be located. Set LOCAL_CI_WP_ABSPATH to the WordPress root.\n");
        exit(1);
    }

    $primary = basename($common) === '.git' ? dirname($common) : $root;
    $wordpress = dirname($primary, 3);
}

$wordpress = rtrim($wordpress, '/\\');

if (! is_file($wordpress.'/wp-settings.php')) {
    fwrite(STDERR, "The Integration suite needs a WordPress install, and none was found at {$wordpress}. The primary checkout must sit in wp-content/plugins/, or set LOCAL_CI_WP_ABSPATH to the WordPress root.\n");
    exit(1);
}

echo "WordPress:  {$wordpress}\n";

mysqli_report(MYSQLI_REPORT_OFF);
$link = @mysqli_connect($host, $user, $password);

if ($link === false) {
    fwrite(STDERR, "Could not connect to MySQL at {$host} as {$user}: ".mysqli_connect_error()."\n");
    exit(1);
}

$quoted = '`'.str_replace('`', '``', $database).'`';

if (mysqli_query($link, "CREATE DATABASE IF NOT EXISTS {$quoted} DEFAULT CHARACTER SET utf8mb4") !== true) {
    fwrite(STDERR, "Could not create the '{$database}' database: ".mysqli_error($link)."\n");
    exit(1);
}

mysqli_close($link);

$integration = ['--configuration=tests/Integration/phpunit.xml', '--test-directory=.'];
$suites = [
    'Unit' => [$root.'/vendor/bin/pest', [], []],
    'Integration' => [$root.'/tests/Integration/vendor/bin/pest', $integration, []],
    'shared secret (second process)' => [$root.'/tests/Integration/vendor/bin/pest', [...$integration, 'tests/Integration/Feature/SharedSecretTest.php'], ['WP_TESTS_MIGRATION_SECRET' => 'shared-secret-for-tests']],
];

foreach ($suites as $label => [$pest, $args, $env]) {
    echo "==> pest: {$label}\n";

    $command = array_merge([PHP_BINARY, $pest], $args);
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, array_merge(getenv(), ['WP_TESTS_ABSPATH' => $wordpress], $env));

    if (! is_resource($process)) {
        fwrite(STDERR, "Could not start Pest for {$label}.\n");
        exit(1);
    }

    $status = proc_close($process);

    if ($status !== 0) {
        fwrite(STDERR, "pest: {$label} failed (exit {$status}).\n");
        exit($status);
    }
}
