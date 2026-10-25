<?php

declare(strict_types=1);

/*
 * Clearing a `config/local.php` fixture a killed Feature run left behind (#303).
 *
 * The directory is a fresh temporary one per test, never the plugin's own
 * `config/`: a test of code that deletes files must not be able to delete a real
 * override.
 */

require_once __DIR__.'/../Support/config-fixture.php';

/**
 * A fresh directory holding only the given `local.php`, or none when null.
 */
function configFixtureDir(?string $contents): string
{
    $dir = sys_get_temp_dir().'/uamswp-config-fixture-'.getmypid().'-'.bin2hex(random_bytes(4));

    mkdir($dir);

    if ($contents !== null) {
        file_put_contents($dir.'/local.php', $contents);
    }

    return $dir;
}

/**
 * Remove a directory made by `configFixtureDir()`, whatever is left in it.
 */
function removeConfigFixtureDir(string $dir): void
{
    if (is_file($dir.'/local.php')) {
        unlink($dir.'/local.php');
    }

    rmdir($dir);
}

it('removes a leftover that carries the fixture marker', function (): void {
    $dir = configFixtureDir(migrationApiConfigFixtureMarker().PHP_EOL."return ['per_page_default' => 13];".PHP_EOL);

    try {
        expect(migrationApiClearStaleConfigFixture($dir.'/local.php'))->toBeTrue()
            ->and(file_exists($dir.'/local.php'))->toBeFalse();
    } finally {
        removeConfigFixtureDir($dir);
    }
});

it('never touches a real override', function (): void {
    $real = "<?php\n\nreturn ['per_page_max' => 100];\n";
    $dir = configFixtureDir($real);

    try {
        expect(migrationApiClearStaleConfigFixture($dir.'/local.php'))->toBeFalse()
            ->and(file_get_contents($dir.'/local.php'))->toBe($real);
    } finally {
        removeConfigFixtureDir($dir);
    }
});

/*
 * The marker identifies the fixture only as its first line. Quoted anywhere
 * else -- a comment explaining this very mechanism, say -- it is somebody's
 * text, and the file is theirs.
 */
it('never touches a file that mentions the marker below its first line', function (): void {
    $mentions = "<?php\n// ".migrationApiConfigFixtureMarker()."\nreturn [];\n";
    $dir = configFixtureDir($mentions);

    try {
        expect(migrationApiClearStaleConfigFixture($dir.'/local.php'))->toBeFalse()
            ->and(file_get_contents($dir.'/local.php'))->toBe($mentions);
    } finally {
        removeConfigFixtureDir($dir);
    }
});

it('does nothing when there is no config/local.php', function (): void {
    $dir = configFixtureDir(null);

    try {
        expect(migrationApiClearStaleConfigFixture($dir.'/local.php'))->toBeFalse();
    } finally {
        removeConfigFixtureDir($dir);
    }
});

/*
 * The half calling the function directly gives up: that the bootstrap runs it,
 * against the plugin's own `config/local.php`, and only after taking the suite
 * lock -- before that point a second suite in the same tree could still be
 * running, and would lose the fixture it is using. Read textually, the idiom
 * `CiParityTest` already uses for the files it cannot execute from here.
 */
it('is run by the bootstrap after the suite lock is taken', function (): void {
    $bootstrap = (string) file_get_contents(__DIR__.'/../Integration/bootstrap.php');

    $lock = strpos($bootstrap, "migrationApiAcquireSuiteLock(\$testDatabase, 'composer test:integration');");
    $clear = strpos($bootstrap, "migrationApiClearStaleConfigFixture(\\dirname(__DIR__, 2).'/config/local.php');");

    expect($lock)->toBeInt()
        ->and($clear)->toBeInt()
        ->and($clear > $lock)->toBeTrue();
});
