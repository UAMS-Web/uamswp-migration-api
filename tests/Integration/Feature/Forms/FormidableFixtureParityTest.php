<?php

/**
 * A committed Formidable export, reproduced through the API.
 */

declare(strict_types=1);

/*
 * The acceptance criterion #1 actually asks for: a definition the importer
 * consumes today, put into a site through Formidable's own importer and read
 * back out through the endpoint, with the same forms and the same fields.
 *
 * The corpus is not vendored — those exports are real production form
 * definitions. Point `UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURES` at
 * `wordpress-importer/src/Services/WordPressImport/Tests/Exports/FormidableForms`
 * to run it; without that it skips, and says so.
 */

/**
 * The smallest committed export, or null when the corpus is not reachable.
 *
 * Smallest rather than first: these run through Formidable's XML importer, and
 * the corpus spans three orders of magnitude in size. Scanned rather than
 * named, because the filenames belong to another repository.
 */
function frmFixtureFile(): ?string
{
    $dir = formidableFixtureDir();

    if ($dir === '') {
        return null;
    }

    $files = glob(rtrim($dir, '/').'/*.xml');

    if (! is_array($files) || $files === []) {
        return null;
    }

    usort($files, static fn (string $a, string $b): int => filesize($a) <=> filesize($b));

    return $files[0];
}

/**
 * The smallest committed export that actually declares a CHILD form.
 *
 * Separate from `frmFixtureFile()` deliberately. The smallest export overall
 * has no children, so a child-form test pointed at it skips — and a test that
 * always skips is worth nothing while looking like coverage. 11 of the 68 forms
 * in the corpus are children; this finds the cheapest export carrying one.
 */
function frmFixtureFileWithChildren(): ?string
{
    $dir = formidableFixtureDir();

    if ($dir === '') {
        return null;
    }

    $files = glob(rtrim($dir, '/').'/*.xml');

    if (! is_array($files) || $files === []) {
        return null;
    }

    usort($files, static fn (string $a, string $b): int => filesize($a) <=> filesize($b));

    foreach ($files as $file) {
        $xml = file_get_contents($file);

        if (is_string($xml) && preg_match('#<parent_form_id>[1-9]\d*</parent_form_id>#', $xml) === 1) {
            return $file;
        }
    }

    return null;
}

/**
 * The `form_key` of every form the export declares.
 *
 * Read from the XML rather than from the database, so the expectation comes
 * from the file the importer consumes and not from whatever the import happened
 * to produce.
 *
 * @return list<string>
 */
function frmFixtureFormKeys(string $file): array
{
    $xml = file_get_contents($file);

    if (! is_string($xml)) {
        return [];
    }

    preg_match_all('#<form>.*?<form_key><!\[CDATA\[(.*?)\]\]></form_key>#s', $xml, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * How many form actions the export declares.
 *
 * Counted from the XML for the same reason `frmFixtureFormKeys()` reads it:
 * the expectation comes from the file, not from what the import produced. An
 * action is a `<view>` whose post type is Formidable's action type; the
 * exporter emits styles and Views through the same element.
 */
function frmFixtureActionCount(string $file): int
{
    $xml = file_get_contents($file);

    if (! is_string($xml)) {
        return 0;
    }

    $count = preg_match_all('#<view>.*?<post_type>frm_form_actions</post_type>.*?</view>#s', $xml);

    return $count === false ? 0 : $count;
}

/**
 * The smallest committed export that declares a DRAFT action.
 *
 * Separate from `frmFixtureFile()` for the reason `frmFixtureFileWithChildren()`
 * is: the smallest export carries only published actions, so a draft-action
 * test pointed at it proves nothing. 4 of the 103 actions in the corpus are
 * drafts, all in one export, and `FrmFormAction::get_action_for_form()` hides
 * every one of them.
 */
function frmFixtureFileWithDraftAction(): ?string
{
    $dir = formidableFixtureDir();

    if ($dir === '') {
        return null;
    }

    $files = glob(rtrim($dir, '/').'/*.xml');

    if (! is_array($files) || $files === []) {
        return null;
    }

    usort($files, static fn (string $a, string $b): int => filesize($a) <=> filesize($b));

    foreach ($files as $file) {
        $xml = file_get_contents($file);

        if (is_string($xml) && preg_match('#<view>(?:(?!</view>).)*<status>draft</status>(?:(?!</view>).)*<post_type>frm_form_actions</post_type>#s', $xml) === 1) {
            return $file;
        }
    }

    return null;
}

/**
 * One comparable line per action: `form_key|type|status`, each part a string.
 */
function frmTriple(mixed $formKey, mixed $type, mixed $status): string
{
    assert(is_string($formKey) && is_string($type) && is_string($status), 'an action triple carried a non-string part');

    return $formKey.'|'.$type.'|'.$status;
}

/**
 * Every action the import produced, as `form_key`, type and status triples.
 *
 * Read from the table rather than from the XML because the importer is
 * allowed to drop an action: it creates one only when the action's type is
 * registered on the importing site, so an add-on's action in an export from a
 * site that had the add-on does not survive an import into one that does not.
 * What this test holds is that the endpoint serves what the import produced,
 * all of it; whether the import produced everything the file declared is the
 * separate assertion `frmFixtureActionCount()` supports.
 *
 * @return list<string>
 */
function frmImportedActionTriples(): array
{
    $wpdb = testDatabase();

    $sql = $wpdb->prepare(
        'SELECT f.form_key, p.post_excerpt, p.post_status FROM %i p INNER JOIN %i f ON f.id = p.menu_order WHERE p.post_type = %s',
        $wpdb->prefix.'posts',
        $wpdb->prefix.'frm_forms',
        'frm_form_actions'
    );
    assert(is_string($sql), 'wpdb::prepare() did not build the query');

    $triples = [];

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $triples[] = frmTriple($row['form_key'], $row['post_excerpt'], $row['post_status']);
    }

    sort($triples);

    return $triples;
}

/**
 * Every action the endpoint served, in the same shape.
 *
 * @return list<string>
 */
function frmServedActionTriples(): array
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/forms/formidable');
    $request->set_param('per_page', 200);

    $data = rest_do_request($request)->get_data();
    assert(is_array($data), 'the Formidable collection did not return a list');

    $triples = [];

    foreach ($data as $record) {
        if (! is_array($record)) {
            continue;
        }

        if (! is_string($record['form_key'] ?? null)) {
            continue;
        }

        foreach ((array) ($record['actions'] ?? []) as $action) {
            assert(is_array($action), 'an action was not an array');
            $triples[] = frmTriple($record['form_key'], $action['type'], $action['status']);
        }
    }

    sort($triples);

    return $triples;
}

/**
 * Import a committed export, tolerating one specific third-party warning.
 *
 * Formidable regenerates its stylesheet whenever an import brings styles with
 * it, and that runs `WP_Filesystem_Direct::mkdir()` over
 * `uploads/formidable`, which the FIRST import in a run has already created.
 * Core suppresses that with `@mkdir()`; PHPUnit promotes suppressed warnings
 * anyway, so the second import in a run reports one.
 *
 * Scoped to this call and to that exact message, and restored immediately —
 * anything else still surfaces. Widening it to all warnings, or turning the
 * promotion off suite-wide, would hide the next real one.
 *
 * @return array<string, mixed>|WP_Error
 */
function frmImportQuietly(string $file)
{
    set_error_handler(

        // Anything that is not the known-benign one goes to PHPUnit.
        static fn (int $errno, string $message): bool => str_contains($message, 'mkdir(): File exists'),
        E_WARNING
    );

    try {
        return FrmXMLHelper::import_xml($file);
    } finally {
        restore_error_handler();
    }
}

beforeEach(function (): void {
    if (! formidableOnDisk()) {
        $this->markTestSkipped('Formidable Forms is not in the surrounding install.');
    }

    if (formidableFixtureDir() === '') {
        $this->markTestSkipped("Set UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURES to the importer's FormidableForms exports directory.");
    }

    actAsSuperAdmin();

    $wpdb = testDatabase();
    $wpdb->query('DELETE FROM '.$wpdb->prefix.'frm_fields');
    $wpdb->query('DELETE FROM '.$wpdb->prefix.'frm_forms');

    $clearActions = $wpdb->prepare('DELETE FROM %i WHERE post_type = %s', $wpdb->prefix.'posts', 'frm_form_actions');
    assert(is_string($clearActions), 'wpdb::prepare() did not build the delete');
    $wpdb->query($clearActions);
    wp_cache_flush();
});

it('reproduces every form in a committed export, fields included', function (): void {
    $file = frmFixtureFile();

    if ($file === null) {
        $this->markTestSkipped('No .xml export in that directory.');
    }

    $expectedKeys = frmFixtureFormKeys($file);

    expect($expectedKeys)->not->toBeEmpty();

    $result = frmImportQuietly($file);

    expect(is_wp_error($result))->toBeFalse('Formidable refused to import the committed export');

    // Read back through the endpoint, not the models.
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/forms/formidable');
    $request->set_param('per_page', 200);

    $data = rest_do_request($request)->get_data();
    assert(is_array($data), 'the Formidable collection did not return a list');

    $served = [];

    foreach ($data as $record) {
        if (is_array($record) && is_string($record['form_key'] ?? null)) {
            $served[$record['form_key']] = $record;
        }
    }

    /*
     * Compared by `form_key`, never by id. Ids are reassigned on import — that
     * is the whole reason the key is load-bearing — so an id comparison would
     * be testing the import's numbering rather than the endpoint's fidelity.
     */
    foreach ($expectedKeys as $key) {
        // `toHaveKey()` takes an expected VALUE as its second argument, not a
        // message, so the message goes on a boolean assertion instead.
        expect(array_key_exists($key, $served))
            ->toBeTrue(sprintf('the export declares form `%s` and the API did not serve it', $key));
    }

    /*
     * And the fields travel. At least one form in every export has them, so an
     * endpoint that served bare form rows — the shape a single-table read
     * produces — would pass every assertion above and fail this one.
     */
    $withFields = 0;

    foreach ($served as $record) {
        $fields = $record['fields'] ?? null;

        if (is_array($fields) && $fields !== []) {
            $withFields++;
        }
    }

    expect($withFields)->toBeGreaterThan(0, 'no served form carried any fields');
});

it('serves the child forms the export declares, not just the parents', function (): void {
    $file = frmFixtureFileWithChildren();

    if ($file === null) {
        $this->markTestSkipped('No export in that directory declares a child form.');
    }

    $result = frmImportQuietly($file);
    expect(is_wp_error($result))->toBeFalse();

    $wpdb = testDatabase();

    $children = (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM '.$wpdb->prefix.'frm_forms WHERE parent_form_id > 0'
    );

    /*
     * Not a skip. The file was chosen BECAUSE its XML declares a child form, so
     * zero rows here means the import lost them — which is a failure, not an
     * absence, and skipping would hide exactly the defect this test exists for.
     */
    expect($children)->toBeGreaterThan(0, 'the chosen export declares a child form and the import produced none');

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/forms/formidable');
    $request->set_param('per_page', 200);

    $data = rest_do_request($request)->get_data();
    assert(is_array($data), 'the Formidable collection did not return a list');

    $servedChildren = 0;

    foreach ($data as $record) {
        if (is_array($record) && is_numeric($record['parent_form_id'] ?? null) && (int) $record['parent_form_id'] > 0) {
            $servedChildren++;
        }
    }

    /*
     * Against real data rather than a synthetic pair. 11 of the 68 forms in the
     * captured corpus are children, and `FrmForm::get_published_forms()` hides
     * every one of them.
     */
    expect($servedChildren)->toBe($children);
});

it('serves every action the import produced, per form, by type and status', function (): void {
    $file = frmFixtureFile();

    if ($file === null) {
        $this->markTestSkipped('No .xml export in that directory.');
    }

    $declared = frmFixtureActionCount($file);
    expect($declared)->toBeGreaterThan(0, 'the smallest export declares no actions, so this proves nothing');

    $result = frmImportQuietly($file);
    expect(is_wp_error($result))->toBeFalse('Formidable refused to import the committed export');

    $imported = frmImportedActionTriples();

    /*
     * The smallest export carries only `email` and `on_submit` actions, both
     * registered by Formidable itself, so the import has no reason to drop
     * one. Equal here is what makes the comparison below a comparison against
     * the FILE and not only against whatever the import kept.
     */
    expect(\count($imported))->toBe($declared, 'the import produced a different number of actions than the export declares');

    expect(frmServedActionTriples())->toBe($imported);
});

it('serves the DRAFT actions the export declares, which the runtime reader hides', function (): void {
    $file = frmFixtureFileWithDraftAction();

    if ($file === null) {
        $this->markTestSkipped('No export in that directory declares a draft action.');
    }

    $result = frmImportQuietly($file);
    expect(is_wp_error($result))->toBeFalse();

    $drafts = array_values(array_filter(
        frmImportedActionTriples(),
        static fn (string $triple): bool => str_ends_with($triple, '|draft')
    ));

    // Not a skip, for the reason the child-form test gives: the file was
    // chosen because it declares one, so none here is a loss, not an absence.
    expect($drafts)->not->toBeEmpty('the chosen export declares a draft action and the import produced none');

    $servedDrafts = array_values(array_filter(
        frmServedActionTriples(),
        static fn (string $triple): bool => str_ends_with($triple, '|draft')
    ));

    expect($servedDrafts)->toBe($drafts);

    /*
     * And the positive control on the reader the endpoint deliberately does
     * not use: for every form carrying a draft, `get_action_for_form()` returns
     * fewer actions than the table holds for it.
     */
    $wpdb = testDatabase();

    foreach (array_unique(array_map(static fn (string $t): string => explode('|', $t)[0], $drafts)) as $key) {
        $formId = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE form_key = %s', $wpdb->prefix.'frm_forms', $key));
        $stored = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s AND menu_order = %d', $wpdb->prefix.'posts', 'frm_form_actions', $formId));

        // A bare object is the reader's shape for exactly one action.
        $runtime = FrmFormAction::get_action_for_form($formId);

        expect(is_array($runtime) ? \count($runtime) : 1)->toBeLessThan($stored);
    }
});
