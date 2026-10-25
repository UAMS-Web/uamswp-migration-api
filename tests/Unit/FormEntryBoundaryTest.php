<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/form-api-surface.php';

/*
 * Holds the boundary D-048 draws: this API serves form DEFINITIONS and never
 * submission data.
 *
 * WHY A TEST AND NOT A NOTE. Until this existed, nothing enforced the boundary.
 * `GET /posts/<type>` serves whatever `config/defaults.php` lists, and **Ninja
 * Forms stores every submission as the `nf_sub` post type** — so adding one
 * string to that list would have served submitted data through an endpoint that
 * already exists, with no new code, no new route, and nothing in review that
 * obviously said "this is different". 744 such submissions sit in the
 * importer's captured corpus already, across three exports, with the submitted
 * values in `_field_N` postmeta.
 *
 * The exclusion was an omission from a list. This makes it a decision.
 *
 * WHAT IT DOES NOT COVER, because its sibling does. `FormApiGuardTest` already
 * bans the entry TABLE names in string literals (`frm_items`, `gf_entry`, …)
 * and already allowlists `Frm*` down to `FrmForm` and `FrmField`, which
 * excludes `FrmEntry` and `FrmEntryMeta`. Repeating either here would give two
 * failures for one mistake and two places to update. This covers the two
 * surfaces that guard leaves open: the eligible post-type list, and `GFAPI`'s
 * own entry methods — which are reachable because that guard allows `GFAPI`.
 *
 * WHERE THE OTHER HALF LIVES. The lists these guards read are in
 * `tests/Support/form-api-surface.php`, and the tests that re-derive them from
 * the installed plugin are in `tests/Integration/Feature/Forms/FormApiSurfaceTest.php`.
 * Reflection needs a loaded `GFAPI`, which only the Feature bootstrap provides;
 * kept here they skipped on every run rather than only on CI, which is how a
 * list that promised it could not fall behind went four releases without once
 * being checked.
 */

/**
 * Every first-party PHP file under `includes/`, keyed by repo-relative path.
 *
 * Defined here rather than borrowed from `FormApiGuardTest`, deliberately. A
 * test file that only works when a sibling happened to load first is coupled to
 * Pest's file ordering, and a guard that silently stops scanning is the exact
 * failure this whole family of tests exists to prevent.
 *
 * @return array<string, string>
 */
function entryGuardSources(): array
{
    $root = \dirname(__DIR__, 2);
    $sources = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/includes', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
            $sources[substr($entry->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($entry->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * The files naming an identifier, read through the tokenizer.
 *
 * Comments never yield `T_STRING`, which matters here: the form controller
 * explains in prose why it stops at definitions, and a guard that punished a
 * file for documenting its own boundary would be deleted within a month.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function entryGuardCallers(array $sources, string $identifier): array
{
    $callers = [];

    foreach ($sources as $path => $source) {
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === T_STRING && $token[1] === $identifier) {
                $callers[] = $path;

                break;
            }
        }
    }

    return $callers;
}

/*
 * WHAT THIS GUARD DOES NOT CLAIM. The boundary is the entry TABLES and the
 * submission POST TYPE — not "nothing a member of the public ever submitted".
 * Formidable Pro's file-upload field stamps `_frm_file` on the attachment it
 * creates, and 766 attachments in the captured corpus carry it; `/posts/attachment`
 * serves them today, because attachments are the highest-volume type and a
 * migration that dropped them would be broken in a way nobody asked for.
 * D-048 records that exception rather than letting this file imply a stronger
 * guarantee than it enforces.
 */

it('never lists a submission post type as eligible', function (): void {
    /** @var array<string, mixed> $config */
    $config = require \dirname(__DIR__, 2).'/config/defaults.php';

    $postTypes = $config['post_types'] ?? null;

    // Narrowed with `assert()` rather than only `toBeArray()`, so the analyzer
    // agrees the guard below is reading a list and not `mixed`.
    assert(is_array($postTypes), 'config/defaults.php carries no post_types list');

    expect($postTypes)->not->toBeEmpty();

    foreach (entryPostTypes() as $type) {
        /*
         * `in_array()` plus `toBeFalse()`, NOT `not->toContain($type, $message)`.
         * Pest's `toContain()` is VARIADIC — a second argument is another value
         * to look for, not a message — so `not->toContain('nf_sub', 'why…')`
         * reads as "contains neither of these", which is true whenever the
         * message string is absent. It passes with `nf_sub` sitting in the
         * array. Verified: that exact form let the mutation through, and this
         * form catches it.
         */
        expect(\in_array($type, $postTypes, true))->toBeFalse(sprintf(
            '`%s` is submission storage, not content. Serving it would put submitted data through '
            .'`GET /posts/%s` with no new code at all. Submission data is out of scope per D-048 — '
            .'reopen #54 before widening this.',
            $type,
            $type
        ));
    }
});

it('never calls a GFAPI entry method from includes/', function (): void {
    /*
     * `FormApiGuardTest` allows `GFAPI` as a class, because reading definitions
     * through it is the design. That allowance is exactly why the entry methods
     * need naming: nothing else stands between `GFAPI::get_form()` and
     * `GFAPI::get_entries()`, which sit in the same file and read the same way.
     */
    $sources = entryGuardSources();

    foreach (entryApiMethods() as $method) {
        expect(entryGuardCallers($sources, $method))->toBe([], sprintf(
            '`%s()` reads or writes form submissions. Entries carry an IP address, a user agent, '
            .'a source URL and payment columns, and no consumer reads them — the importer references '
            .'no entry API anywhere. Out of scope per D-048; see #54.',
            $method
        ));
    }
});

it('scans real source, so the guard cannot pass by finding nothing', function (): void {
    // If either helper stopped returning anything, every assertion above would
    // pass vacuously — a guard that scans nothing is green forever.
    expect(\count(entryGuardSources()))->toBeGreaterThanOrEqual(8);
    expect(entryGuardCallers(entryGuardSources(), 'get_form'))->not->toBe([]);
});

it('never calls a GFAPI feed write or feed processing method from includes/', function (): void {
    $sources = entryGuardSources();

    foreach (feedWriteMethods() as $method) {
        expect(entryGuardCallers($sources, $method))->toBe([], sprintf(
            '`%s()` writes feed configuration or runs a feed against an entry. This API is read-only, '
            .'and running a User Registration feed CREATES A USER. Serving feed definitions (#80) is '
            .'the read; nothing here processes one.',
            $method
        ));
    }
});

it('still calls the feed READ method, so the guard above is not passing vacuously', function (): void {
    /*
     * The negative control the sibling guard learned to need. Every assertion
     * above passes if `includes/` stopped mentioning feeds at all — including
     * the case where somebody deletes the endpoint and leaves the guard.
     */
    expect(entryGuardCallers(entryGuardSources(), 'get_feeds'))->not->toBe([]);
});
