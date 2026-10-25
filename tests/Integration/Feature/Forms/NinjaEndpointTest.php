<?php

/**
 * `GET /forms/ninja` and `GET /forms/ninja/<id>`.
 */

declare(strict_types=1);

/**
 * Dispatch a GET against a Ninja route, authenticated.
 *
 * @param  array<string, mixed>  $params  Query parameters.
 */
function ninjaGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * Create a Ninja form with the given settings, returning its id.
 *
 * @param  array<string, mixed>  $settings  Form settings.
 */
function makeNinjaForm(array $settings = []): int
{
    /*
     * `new NF_Database_Models_Form( $wpdb, '' )` rather than
     * `Ninja_Forms()->form()->get()`, and the difference is the whole reason
     * the tests above mean anything.
     *
     * `Ninja_Forms::form()` memoizes factories in a `static` keyed by id, so
     * `form('')` hands back THE SAME factory holding THE SAME model on every
     * call. The second `save()` therefore UPDATEs the first row instead of
     * inserting: this helper created exactly one form per PHP process however
     * many times it was called, and the paging and collection tests were
     * asserting over a single record while appearing to use several.
     *
     * A fresh model per call has no such memory.
     */
    global $wpdb;

    assert($wpdb instanceof wpdb, 'no WordPress database handle');

    $form = new NF_Database_Models_Form($wpdb, '');

    $form->update_settings(array_merge(['title' => 'Test Form', 'key' => ''], $settings));
    $form->save();

    $id = $form->get_id();

    assert(is_numeric($id) && (int) $id > 0, 'the Ninja form model returned no id');

    return (int) $id;
}

/**
 * The ids in a collection response, in the order served.
 *
 * @return list<int>
 */
function ninjaIds(WP_REST_Response $response): array
{
    $data = $response->get_data();

    assert(is_array($data), 'a collection response did not return an array');

    $ids = [];

    foreach ($data as $record) {
        assert(is_array($record), 'a collection record was not an array');

        $id = $record['id'] ?? null;

        assert(is_int($id), 'a record carried no integer id');

        $ids[] = $id;
    }

    return $ids;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves every form, carrying the id a `.nff` export cannot', function (): void {
    /*
     * The whole reason this endpoint exists. A `.nff` is `{settings, fields,
     * actions}` with no id anywhere and an empty `settings.key`, while post
     * content refers to forms by number — `[ninja_form id=3]`. Serving the id
     * deletes the importer's per-form operator prompt (wordpress-importer#10).
     */
    $first = makeNinjaForm(['title' => 'Contact']);
    $second = makeNinjaForm(['title' => 'Referral']);

    $ids = ninjaIds(ninjaGet('/forms/ninja'));

    expect($ids)->toContain($first)->toContain($second);

    $data = ninjaGet('/forms/ninja')->get_data();

    assert(is_array($data));

    foreach ($data as $record) {
        assert(is_array($record));

        expect($record['id'])->toBeInt()->toBeGreaterThan(0);
        expect($record)->toHaveKeys(['id', 'key', 'title', 'settings', 'fields', 'actions']);
    }
});

it('orders by id ascending, so paging cannot skip or repeat a form', function (): void {
    // D-006, in the shape every collection here takes.
    for ($i = 0; $i < 4; $i++) {
        makeNinjaForm(['title' => 'Form '.$i]);
    }

    $ids = ninjaIds(ninjaGet('/forms/ninja'));
    $sorted = $ids;

    sort($sorted);

    expect($ids)->toBe($sorted);

    $firstPage = ninjaIds(ninjaGet('/forms/ninja', ['per_page' => 2, 'page' => 1]));
    $secondPage = ninjaIds(ninjaGet('/forms/ninja', ['per_page' => 2, 'page' => 2]));

    expect(array_intersect($firstPage, $secondPage))->toBe([]);
    expect(\count(array_unique(array_merge($firstPage, $secondPage))))->toBe(\count($firstPage) + \count($secondPage));
});

it('serves the same record from the collection and the single-record route', function (): void {
    // Both routes read `ninjaIds()`, so this holds by construction rather than
    // by coincidence — the property `formidableForm()` had to be rewritten for.
    $id = makeNinjaForm(['title' => 'Parity']);

    $collection = ninjaGet('/forms/ninja')->get_data();

    assert(is_array($collection));

    $fromIndex = null;

    foreach ($collection as $record) {
        assert(is_array($record));

        if (($record['id'] ?? null) === $id) {
            $fromIndex = $record;
        }
    }

    $fromShow = ninjaGet('/forms/ninja/'.$id)->get_data();

    expect($fromIndex)->not->toBeNull();
    expect($fromShow)->toBe($fromIndex);
});

it('answers 404 for an id no form has, rather than an empty form', function (): void {
    /*
     * `new NF_Database_Models_Form( $wpdb, 999999 )` constructs happily for an
     * id nobody has and answers with empty settings, so a route that trusted
     * the model would serve `200` with a blank form — a well-formed response
     * for a record that does not exist.
     */
    makeNinjaForm();

    expect(ninjaGet('/forms/ninja/999999')->get_status())->toBe(404);
});

it('serves fields FLAT, the way a `.nff` carries them', function (): void {
    /*
     * The shape is the contract. A real `.nff` puts a field's settings inline
     * — `label`, `type`, `key`, `order` directly on the element — and Ninja
     * Forms' own `export()` does `$export['fields'][] = $field->get_settings()`
     * with no wrapper. An earlier version of this endpoint wrapped each field
     * as `{id, settings}`, one level deeper, so the importer's reader (which
     * does `$field['type']`, `$field['key']`, `$field['options']`) found null
     * on every field: a 200 with every field present and every field
     * unreadable.
     *
     * `id` is the one addition, because a `.nff` carries it nowhere and it is
     * this endpoint's whole reason.
     */
    global $wpdb;

    assert($wpdb instanceof wpdb);

    $id = makeNinjaForm(['title' => 'With Fields']);

    $field = new NF_Database_Models_Field($wpdb, '', $id);
    $field->update_settings(['type' => 'textbox', 'label' => 'Full name', 'key' => 'full_name', 'order' => 1]);
    $field->save();

    $record = ninjaGet('/forms/ninja/'.$id)->get_data();

    assert(is_array($record));

    $fields = $record['fields'] ?? [];

    assert(is_array($fields));

    expect($fields)->not->toBeEmpty();

    $first = $fields[0];

    assert(is_array($first));

    // Settings INLINE, not nested under a `settings` key.
    expect($first)->toHaveKey('label', 'Full name');
    expect($first)->toHaveKey('type', 'textbox');
    expect($first['id'])->toBeInt()->toBeGreaterThan(0);
    expect(array_key_exists('settings', $first))->toBeFalse(
        'fields are wrapped in a `settings` key; a `.nff` carries them flat and the importer reads them flat'
    );
});

it("orders fields by the form's own order, not by row id", function (): void {
    /*
     * A `.nff` gives every field an explicit `order`, and Ninja Forms renders
     * by it. A form whose fields were reordered after creation has an `order`
     * that disagrees with insertion order, so serving by id alone hands the
     * consumer a scrambled form — which nothing would report.
     */
    global $wpdb;

    assert($wpdb instanceof wpdb);

    $id = makeNinjaForm(['title' => 'Reordered']);

    // Inserted FIRST, rendered LAST.
    $late = new NF_Database_Models_Field($wpdb, '', $id);
    $late->update_settings(['type' => 'textbox', 'label' => 'Second', 'key' => 'second', 'order' => 2]);
    $late->save();

    $early = new NF_Database_Models_Field($wpdb, '', $id);
    $early->update_settings(['type' => 'textbox', 'label' => 'First', 'key' => 'first', 'order' => 1]);
    $early->save();

    $record = ninjaGet('/forms/ninja/'.$id)->get_data();

    assert(is_array($record));

    $fields = $record['fields'] ?? [];

    assert(is_array($fields));

    $labels = [];

    foreach ($fields as $f) {
        assert(is_array($f));

        $labels[] = $f['label'] ?? null;
    }

    expect($labels)->toBe(['First', 'Second']);
});

it('advertises the resource in the namespace index', function (): void {
    $index = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1'))->get_data();

    assert(is_array($index));

    $routes = $index['routes'] ?? [];

    assert(is_array($routes));

    expect(array_keys($routes))->toContain('/uamswp-migration/v1/forms/ninja');
});

it('reports the ninja resource in the discovery payload, not just as a route', function (): void {
    /*
     * The namespace index is how a consumer learns what this deployment can
     * do without probing. Advertising `gravity` and `formidable` while serving
     * `ninja` means a client that trusts discovery never asks for it —
     * the endpoint exists and is invisible.
     */
    // The plugin's own discovery payload lives at the namespace ROOT route
    // it registers ('/'), not at core's auto-generated namespace index.
    $index = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1/'))->get_data();

    assert(is_array($index));

    $endpoints = $index['endpoints'] ?? [];

    assert(is_array($endpoints));

    $forms = $endpoints['forms'] ?? [];

    assert(is_array($forms));

    expect(array_keys($forms))->toContain('gravity', 'formidable', 'ninja');
    expect($forms['ninja'])->toHaveKey('collection', '/forms/ninja');
    expect($forms['ninja'])->toHaveKey('available', true);
});

it('orders fields by `value`, so a legacy row with no `meta_value` still sorts', function (): void {
    /*
     * The ordering fix reads `nf3_field_meta`, and that table carries TWO
     * column pairs: `key`/`value` — what Ninja Forms actually reads settings
     * from — and `meta_key`/`meta_value`, denormalized copies added in 3.3.12.
     * The first version of the fix joined on the copies. Since
     * `_save_setting()` writes all four in sync, the earlier test passed on
     * either choice and could not tell them apart.
     *
     * This nulls the copies, which is the state Ninja Forms' own
     * `get_existing_meta()` guards for on rows predating that migration. With
     * the join on `meta_value` every field collapses to the 999999 default and
     * the sort silently reverts to id order — while each record still carries
     * the correct `order` in its payload, because that is read from `value`.
     * A 200 whose array order contradicts its own contents.
     */
    global $wpdb;

    assert($wpdb instanceof wpdb);

    $id = makeNinjaForm(['title' => 'Legacy meta']);

    // Inserted FIRST, ordered LAST — so id order and form order disagree.
    $late = new NF_Database_Models_Field($wpdb, '', $id);
    $late->update_settings(['type' => 'textbox', 'label' => 'Second', 'key' => 'legacy_b', 'order' => 2]);
    $late->save();

    $early = new NF_Database_Models_Field($wpdb, '', $id);
    $early->update_settings(['type' => 'textbox', 'label' => 'First', 'key' => 'legacy_a', 'order' => 1]);
    $early->save();

    // The legacy shape: the real columns populated, the 3.3.12 copies NULL.
    $meta = $wpdb->prefix.'nf3_field_meta';
    $wpdb->query("UPDATE {$meta} SET meta_key = NULL, meta_value = NULL");

    $record = ninjaGet('/forms/ninja/'.$id)->get_data();

    assert(is_array($record));

    $fields = $record['fields'] ?? [];

    assert(is_array($fields));

    $labels = [];
    $orders = [];

    foreach ($fields as $f) {
        assert(is_array($f));

        $labels[] = $f['label'] ?? null;
        $orders[] = $f['order'] ?? null;
    }

    expect($labels)->toBe(['First', 'Second']);

    // And the served `order` agrees with the position it was served in, which
    // is the invariant the whole fix exists to hold.
    expect($orders)->toBe(['1', '2']);
});
