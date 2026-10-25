<?php

declare(strict_types=1);

/*
 * Holds the whole of `includes/` to the ACF surface D-024 pinned.
 *
 * A static scan rather than a behavioral test, because the thing being
 * prevented is a *call that is never made today*. There is no run in which a
 * missing guard shows up: the plugin works, the suite passes, and the fatal
 * arrives the first time somebody enables `show_in_rest` on a field group that
 * happens to contain a `maskfield`. The only moment it can be caught is the
 * moment the call is written.
 *
 * Two functions are banned, for different reasons:
 *
 *   `acf_format_value_for_rest()` — calls `$type->format_value_for_rest()` with
 *   no check that `$type` is an object (`acf-rest-api-functions.php:74`). On
 *   this network that is a fatal, not a warning.
 *
 *   `acf_get_field_type()` — the source of the non-object. It is not dangerous
 *   in itself; it is banned because every use of its result is. Banning the
 *   lookup rather than policing what is done with it is what makes the guard
 *   mechanical: there is no judgment call about which method is safe.
 *
 * See `tests/Integration/Feature/ACF/FieldReaderTest.php` for the behavior this protects.
 */

/**
 * Every first-party PHP file under `includes/`, keyed by repo-relative path.
 *
 * @return array<string, string>
 */
function acfGuardSources(): array
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
 * The files naming a function, ignoring occurrences inside comments.
 *
 * Comments are excluded through the tokenizer rather than by a cleverer regex:
 * the class docblock in `FieldReader` names both banned functions, at length,
 * to explain why it does not call them — and a guard that punished a file for
 * documenting its own reasoning would be quietly deleted within a month.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function acfGuardCallers(array $sources, string $function): array
{
    $callers = [];

    foreach ($sources as $path => $source) {
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === T_STRING && $token[1] === $function) {
                $callers[] = $path;

                break;
            }
        }
    }

    return $callers;
}

it('finds first-party source to scan', function (): void {
    // A guard that scans nothing passes forever. The count is deliberately a
    // floor, not an equality — it must survive a new file without a nudge, and
    // still fail if the glob is ever pointed at the wrong directory.
    expect(\count(acfGuardSources()))->toBeGreaterThanOrEqual(8);
});

it('never calls `acf_format_value_for_rest()` anywhere in includes/', function (): void {
    // It is unguarded, and 17 `maskfield` definitions across 7 of 89 field
    // groups on this network reach the unguarded line through a container.
    expect(acfGuardCallers(acfGuardSources(), 'acf_format_value_for_rest'))->toBe([]);
});

it('never resolves a field type through `acf_get_field_type()`', function (): void {
    // `maskfield` resolves to a `stdClass` placeholder and `button` to `null` —
    // two different shapes, so a guard written for either alone misses the
    // other. The reader needs no field-type object at all: it dispatches on the
    // field DEFINITION, which is why it tolerates both without a special case.
    expect(acfGuardCallers(acfGuardSources(), 'acf_get_field_type'))->toBe([]);
});

it('reads ACF only through the five functions it is allowed', function (): void {
    // The complement of the two bans: an allowlist, so a sixth ACF entry point
    // added later is a deliberate edit here rather than an unremarked one
    // elsewhere. `acf_get_store` is included because the value-store reset is
    // load-bearing for pagination, not incidental.
    // `acf_get_metadata_by_field` is the fifth, added deliberately for D-038.
    // It is the only way to tell a stored value from one `acf_get_value()`
    // substituted out of the field definition — the substitution happens
    // before the `acf/load_value` filter, so nothing downstream can. It is
    // also what ACF Custom Database Tables intercepts, which is why it stays
    // correct for values living only in a custom table.
    //
    // A sixth entry, `acf_get_metadata`, lived here from #169/D-069 to #175:
    // the pre-6.4 twin of the fifth, called only where `acf_get_metadata_by_field()`
    // did not exist. #175 removed the fallback once every development machine
    // reached ACF PRO 6.4.2, and this entry went with the code it guarded.
    //
    // This list widening is the point of the guard, not a defeat of it: the
    // test exists to make an addition a decision somebody argued for.
    $allowed = ['acf_get_field_groups', 'acf_get_fields', 'acf_get_value', 'acf_get_store', 'acf_get_metadata_by_field'];
    $used = [];

    foreach (acfGuardSources() as $source) {
        foreach (token_get_all($source) as $token) {
            // Tokens, not a regex, for the same reason as above: the reader's
            // docblock names the banned functions to explain the ban.
            if (\is_array($token) && $token[0] === T_STRING && str_starts_with($token[1], 'acf_')) {
                $used[$token[1]] = true;
            }
        }
    }

    $unexpected = array_values(array_diff(array_keys($used), $allowed));

    sort($unexpected);

    expect($unexpected)->toBe([]);
});
