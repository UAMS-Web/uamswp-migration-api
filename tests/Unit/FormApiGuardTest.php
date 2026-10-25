<?php

declare(strict_types=1);

/*
 * Holds `includes/` to reading the form plugins through their own APIs.
 *
 * "Read through each plugin's own API, never raw SQL" is a design rule this
 * repo states and, until now, only trusted. It is worth a mechanical guard for
 * the same reason the ACF one is: the failure is a line somebody writes later,
 * under time pressure, because a model is awkward and a `SELECT` is obvious.
 * Nothing about that line reds a build — it works, right up until the plugin
 * changes its schema in a minor version and the payload quietly changes shape.
 *
 * Both form endpoints already earn it. `GFAPI` and `FrmForm` each hide data
 * behind defaults (D-043, D-046), and both were only found by reading their
 * source — a raw query would have looked correct and been wrong in a different
 * way, with no plugin API left to blame.
 *
 * A static scan rather than a behavioral test: there is no request in which
 * "this was raw SQL" is observable from the outside.
 */

/**
 * Every first-party PHP file under `includes/`, keyed by repo-relative path.
 *
 * @return array<string, string>
 */
function formGuardSources(): array
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
 * Identifiers matching a prefix, read through the tokenizer.
 *
 * Comments are excluded because the tokenizer never yields them as an
 * identifier token, and that matters here more than usual: `FormsController`
 * explains at length why it does NOT call `FrmForm::get_published_forms()`, and
 * a guard that punished a file for documenting its own reasoning would be
 * deleted within a month.
 *
 * **`T_STRING` alone was not enough, and the gap was an escape hatch.** PHP 8
 * tokenizes a name by how it is written: `FrmDb::query()` is `T_STRING`, but
 * `\FrmDb::query()` is a single `T_NAME_FULLY_QUALIFIED` whose text is
 * `\FrmDb` — which does not start with `Frm`, so a `T_STRING`-only scan could
 * not see it at all. Both namespaced forms are folded to their last segment
 * before the prefix test.
 *
 * No such call has ever been in `includes/` — verified with `git log -S`. The
 * gap was in what this guard COULD detect, not in what it had missed, and the
 * companion test below demonstrates it on synthetic source rather than on
 * history.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function formGuardIdentifiers(array $sources, string $prefix): array
{
    $nameTokens = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];

    $found = [];

    foreach ($sources as $source) {
        foreach (token_get_all($source) as $token) {
            if (! \is_array($token)) {
                continue;
            }

            if (! \in_array($token[0], $nameTokens, true)) {
                continue;
            }

            // `\Foo\Bar` and `\Bar` both reduce to `Bar`: the guard is about
            // which CLASS is read, not how its name happened to be spelled.
            $segments = explode('\\', $token[1]);
            $name = end($segments);

            if ($name !== '' && str_starts_with($name, $prefix)) {
                $found[$name] = $name;
            }
        }
    }

    ksort($found);

    return array_values($found);
}

/**
 * The files whose source contains a literal string, comments included.
 *
 * Deliberately NOT tokenizer-filtered. A table name inside a comment is fine,
 * but a table name inside a *string* is a query being built, and the whole
 * point is to catch that wherever it hides.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function formGuardTableUsers(array $sources, string $table): array
{
    $files = [];

    foreach ($sources as $path => $source) {
        foreach (token_get_all($source) as $token) {
            $isString = \is_array($token)
                && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && str_contains($token[1], $table);

            if ($isString) {
                $files[] = $path;

                break;
            }
        }
    }

    return $files;
}

it('finds first-party source to scan', function (): void {
    // A guard that scans nothing passes forever.
    expect(\count(formGuardSources()))->toBeGreaterThanOrEqual(8);
});

it('reads Formidable only through `FrmForm`, `FrmField`, `FrmFormAction` and `FrmAppHelper`', function (): void {
    /*
     * An allowlist rather than a ban, so a further `Frm*` entry point is a
     * deliberate edit here instead of an unremarked one elsewhere. `FrmDb` is
     * pointedly absent: it is the plugin's query builder, and reaching for it
     * is raw SQL wearing the plugin's name.
     *
     * `FrmFormAction` is the model behind a form's actions and `FrmAppHelper`
     * carries the decoder for their settings column (D-081). Both are read
     * through one static each -- `action_args()` and `maybe_json_decode()` --
     * and neither is the runtime reader that curates what it returns.
     */
    expect(formGuardIdentifiers(formGuardSources(), 'Frm'))->toBe(['FrmAppHelper', 'FrmField', 'FrmForm', 'FrmFormAction']);
});

it('reads Gravity Forms only through `GFAPI`', function (): void {
    /*
     * `GFFormsModel` is deliberately not allowed. `FormsController` names it in
     * prose — it is where the trash asymmetry lives — but calling it would mean
     * reading the schema behind `GFAPI`'s back, which is the thing D-043 had to
     * reason about rather than inherit.
     */
    expect(formGuardIdentifiers(formGuardSources(), 'GF'))->toBe(['GFAPI']);
});

it('never names a form plugin table anywhere in includes/', function (): void {
    /*
     * The direct statement of the rule. A model call cannot contain its own
     * table name as a string, so any hit here is a query being assembled by
     * hand — which is exactly what #1 and #11 both asked to be prevented.
     */
    $sources = formGuardSources();

    /*
     * `gf_addon_feed` joined this list with #80. Feeds are the one form surface
     * where the raw query is genuinely tempting: `GFAPI::get_feeds()` hides
     * disabled feeds behind a default argument and returns a `WP_Error` for the
     * ordinary empty case, so `SELECT * FROM wp_gf_addon_feed` looks like the
     * simpler and more honest read. It is neither — it would also skip the
     * `decrypt_feed_meta()` step and serve the encrypted value on an install with
     * `GF_ENCRYPTION_KEY` defined, which is a payload that parses and is wrong.
     *
     * `frm_form_actions` is a post type rather than a table, and it joined with
     * #284 for the same reason: a form's actions live in `wp_posts`, and the
     * one fact that makes that read version-safe is which post type and which
     * column key them to the form. `FrmFormAction::action_args()` carries both;
     * a literal here would be that knowledge copied out of the plugin (D-081).
     */
    foreach (['frm_forms', 'frm_fields', 'frm_items', 'frm_item_metas', 'frm_form_actions', 'gf_form', 'gf_entry', 'gf_addon_feed'] as $table) {
        expect(formGuardTableUsers($sources, $table))
            ->toBe([], sprintf('`%s` appears in a string literal — that is raw SQL against a plugin schema.', $table));
    }
});

it('sees a reader class however its name is spelled', function (): void {
    /*
     * The guard's own negative control. Each spelling below is a different PHP
     * 8 token — `T_STRING`, `T_NAME_FULLY_QUALIFIED`, `T_NAME_QUALIFIED` — and
     * a scan that only recognized the first passed while `\FrmDb::query()` sat
     * in `includes/`. Feeding synthetic source keeps the control honest without
     * having to put a forbidden call into the real tree to prove it is caught.
     */
    $spellings = [
        'bare' => '<?php FrmDb::query();',
        'fully-qualified' => '<?php \FrmDb::query();',
        'namespaced' => '<?php Vendor\FrmDb::query();',
    ];

    foreach ($spellings as $label => $source) {
        expect(formGuardIdentifiers([$label => $source], 'Frm'))->toBe(['FrmDb'], $label);
    }

    // And it still reports nothing for source that names no such class.
    expect(formGuardIdentifiers(['clean' => '<?php GFAPI::get_forms();'], 'Frm'))->toBe([]);
});
