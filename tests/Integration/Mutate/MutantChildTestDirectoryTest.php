<?php

declare(strict_types=1);

/*
 * Guards the Feature binding in a mutant child (#443).
 *
 * `composer mutate` passes `--test-directory=.`, and Pest removes that flag from
 * argv before any plugin sees it (`vendor/pestphp/pest/bin/pest`, Pest v3.8.7).
 * `pest-plugin-mutate` builds every mutant child from the arguments left over,
 * so a child looks for `tests/Pest.php` under `tests/Integration`, finds nothing, and
 * never runs `uses(WordPressTestCase::class)->in('Feature')`. Its Feature tests
 * then ran without their transaction, rows leaked from one test into the next,
 * and a mutant could be scored killed by a failure that had nothing to do with
 * it. The fix is that `bootstrap.php` loads `Pest.php` itself, so the binding no
 * longer depends on the flag.
 *
 * This starts a child the way the plugin does, without `--test-directory`, and
 * runs `Feature/TestCaseBindingTest.php` in it. The child installs WordPress, so
 * it gets a database of its own: `--mutate` children run while their parent
 * waits, but this one runs in the middle of the suite.
 */

/**
 * Run `$callback` with a database of its own, named after the suite's, created
 * first and dropped afterwards.
 *
 * @template T
 *
 * @param  callable(string): T  $callback  Receives the database name.
 * @return T
 */
function withChildDatabase(callable $callback): mixed
{
    global $wpdb;

    $database = DB_NAME.'_mutant_child';

    $wpdb->query("CREATE DATABASE IF NOT EXISTS `{$database}`");

    try {
        return $callback($database);
    } finally {
        $wpdb->query("DROP DATABASE IF EXISTS `{$database}`");
    }
}

it('binds the WordPress test case to Feature tests in a child started without --test-directory', function (): void {
    [$exitCode, $output] = withChildDatabase(static function (string $database): array {
        $root = dirname(__DIR__, 3);

        $environment = getenv();
        $environment['WP_TESTS_DB_NAME'] = $database;

        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__.'/../vendor/bin/pest',
                '--configuration=tests/Integration/phpunit.xml',
                '--colors=never',
                'tests/Integration/Feature/TestCaseBindingTest.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
            $environment,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a child pest process.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    });

    // The pass line as well as the exit code: a child that ran nothing also exits 0.
    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('1 passed');
});
