<?php

declare(strict_types=1);

/*
 * The `config/local.php` test fixture, and clearing one a killed run left behind
 * (#303).
 *
 * `ConfigTest` proves a `config/local.php` beside the defaults is merged over
 * them by writing one and removing it in a `finally`. A `finally` does not run
 * when the process is killed -- a bound expiring, Ctrl-C, an out-of-memory kill
 * -- and `/config/local.php` is gitignored, so neither `git status` nor the
 * local-CI runner's untracked-source guard sees what is left. Every later run in
 * that tree then reads its override, and in the primary checkout so does the
 * development site.
 *
 * The fixture's first line is a marker the test owns. A deployment's real
 * override never carries it, so only a leftover is ever removed.
 */

/**
 * The first line of every `config/local.php` the suite writes.
 */
function migrationApiConfigFixtureMarker(): string
{
    return '<?php // uamswp-migration-api test fixture';
}

/**
 * Remove a `config/local.php` the suite left behind, and nothing else.
 *
 * Only a file whose FIRST line is the marker is removed; the marker anywhere
 * else is somebody's text, not this suite's fixture.
 *
 * @param  string  $path  The `config/local.php` to inspect.
 * @return bool Whether a leftover fixture was removed.
 */
function migrationApiClearStaleConfigFixture(string $path): bool
{
    if (! is_file($path)) {
        return false;
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return false;
    }

    $firstLine = fgets($handle);
    fclose($handle);

    if ($firstLine === false || rtrim($firstLine, "\r\n") !== migrationApiConfigFixtureMarker()) {
        return false;
    }

    return unlink($path);
}
