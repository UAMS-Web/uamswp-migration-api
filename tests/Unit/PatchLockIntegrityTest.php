<?php

declare(strict_types=1);

/*
 * Holds every patch file under `patches/` to the `sha256` that
 * `tests/Integration/patches.lock.json` records for it (#349). The lock sits in
 * the Integration setup because the patched package is installed there (#375),
 * so each `url` it records is relative to that directory.
 *
 * `cweagans/composer-patches` 2 serves a patch from its cache by that hash
 * once it has seen it, and reads the file only on a cold cache. So a patch
 * edited without regenerating the lock is applied from the stale cached copy
 * on any machine that installed it before, even under `--fresh`, and fails
 * with a hash mismatch on a machine that never did. Neither outcome names the
 * edit. This test does, on every machine, before anything installs.
 *
 * The lock is regenerated with `composer patches-relock` in `tests/Integration`. `.gitattributes`
 * pins `*.patch` to LF, so the bytes hashed here are the bytes on every
 * checkout.
 */

/**
 * The patch entries `patches.lock.json` records, keyed by the file they name.
 *
 * @return array<string, array{0: string, 1: string}> path relative to tests/Integration, recorded sha256
 */
function patchLockEntries(): array
{
    $path = __DIR__.'/../Integration/patches.lock.json';
    $lock = json_decode((string) file_get_contents($path), true);

    // Fail with the reason rather than on an offset read
    if (! is_array($lock) || ! is_array($lock['patches'] ?? null)) {
        throw new RuntimeException('patches.lock.json has no patches map');
    }

    $entries = [];

    foreach ($lock['patches'] as $patches) {
        foreach (is_array($patches) ? $patches : [] as $patch) {
            $url = is_array($patch) ? ($patch['url'] ?? null) : null;
            $sha256 = is_array($patch) ? ($patch['sha256'] ?? null) : null;

            if (! is_string($url)) {
                continue;
            }

            // A remote patch is verified by the plugin when it downloads; only a file in this repository can drift here
            if (str_contains($url, '://')) {
                continue;
            }

            $entries[$url] = [$url, is_string($sha256) ? $sha256 : ''];
        }
    }

    return $entries;
}

it('records at least one patch file, so the check below is not vacuous', function (): void {
    expect(patchLockEntries())->not->toBeEmpty();
});

it('matches the sha256 the lock records for each patch file', function (string $url, string $recorded): void {
    $path = __DIR__.'/../Integration/'.$url;

    expect(is_file($path))->toBeTrue("{$url} is in patches.lock.json but not on disk");

    expect(hash_file('sha256', $path))->toBe(
        $recorded,
        "{$url} no longer matches the sha256 in patches.lock.json: regenerate it with `composer patches-relock` in tests/Integration",
    );
})->with(patchLockEntries());
