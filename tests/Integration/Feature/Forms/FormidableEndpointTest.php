<?php

/**
 * The Formidable Forms definition endpoints.
 */

declare(strict_types=1);

/*
 * Formidable is the larger of the two form systems the importer handles — about
 * 330 references against Gravity Forms' 74 — and its read API drops data from a
 * migration in four separate ways, each silently. These tests hold every one.
 *
 * The corpus that measures it: 12 committed exports in the importer's tree,
 * 68 forms and 2,412 fields, of which 11 forms (16%) are CHILD forms that
 * `FrmForm::get_published_forms()` hides by default.
 */

/**
 * Dispatch against this plugin's namespace.
 *
 * @param  array<string, mixed>  $params
 */
function frmRequest(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * The records the collection returns.
 *
 * @param  array<string, mixed>  $params
 * @return list<array<string, mixed>>
 */
function frmList(array $params = []): array
{
    $data = frmRequest('/forms/formidable', $params)->get_data();

    assert(is_array($data), 'the Formidable collection did not return a list');

    $records = [];

    foreach ($data as $record) {
        assert(is_array($record), 'a Formidable record was not an array');

        /** @var array<string, mixed> $record */
        $records[] = $record;
    }

    return $records;
}

/**
 * Just the ids the collection returns, in order.
 *
 * @param  array<string, mixed>  $params
 * @return list<int>
 */
function frmIds(array $params = []): array
{
    $ids = [];

    foreach (frmList($params) as $form) {
        $id = $form['id'] ?? null;
        $ids[] = is_numeric($id) ? (int) $id : 0;
    }

    return $ids;
}

/**
 * Create a form and return its id.
 *
 * `description` is passed explicitly because `FrmForm::create()` reads
 * `$values['description']` without a default and warns without it.
 */
function frmMakeForm(string $name, string $key, int $parent = 0): int
{
    $id = FrmForm::create([
        'name' => $name,
        'description' => $name.' description',
        'form_key' => $key,
        'parent_form_id' => $parent,
    ]);

    assert(is_numeric($id), 'Formidable refused to create the fixture form');

    return (int) $id;
}

/**
 * Add one field to a form.
 */
function frmMakeField(int $formId, string $key, string $type = 'text'): int
{
    $id = FrmField::create([
        'form_id' => $formId,
        'field_key' => $key,
        'type' => $type,
        'name' => 'Field '.$key,
        'description' => '',
        // Explicitly empty rather than omitted: FrmField reads default_value
        // through strpos(), which deprecation-warns on the NULL a missing key
        // leaves behind.
        'default_value' => '',
        'field_order' => 1,
        'field_options' => [],
        'options' => [],
    ]);

    assert(is_numeric($id), 'Formidable refused to create the fixture field');

    return (int) $id;
}

/**
 * Attach one action to a form, as Formidable stores it.
 *
 * Through `wp_insert_post()` rather than `FrmFormAction::save_settings()`,
 * which is an instance method on a registered action type and merges that
 * type's defaults into what it writes. A row inserted directly carries exactly
 * the settings given, so a test can tell "decoded" from "resolved".
 *
 * @param  array<string, mixed>  $settings
 */
function frmMakeAction(int $formId, string $type, array $settings, string $status = 'publish'): int
{
    $json = wp_json_encode($settings);
    assert(is_string($json), 'the fixture settings did not encode');

    $id = wp_insert_post([
        'post_type' => 'frm_form_actions',
        'post_status' => $status,
        'post_title' => ucfirst($type).' action',
        'post_name' => $formId.'_'.$type.'_'.wp_rand(1, 999999),
        'post_excerpt' => $type,
        'post_content' => $json,
        'menu_order' => $formId,
    ], true);

    assert(is_int($id), 'WordPress refused to insert the fixture action');

    return $id;
}

/**
 * Set a column directly, for states the model has no setter for.
 */
function frmSetColumn(int $formId, string $column, ?string $value): void
{
    $wpdb = testDatabase();
    $table = $wpdb->prefix.'frm_forms';

    /*
     * Only `status` is ever written here, so the query strings stay literal —
     * `wpdb::prepare()` requires that, and building one by concatenating a
     * column name is the shape that hides an injection even when the value is
     * trusted.
     */
    assert($column === 'status', 'frmSetColumn() only knows how to write `status`');

    $sql = $value === null
        ? $wpdb->prepare('UPDATE %i SET status = NULL WHERE id = %d', $table, $formId)
        : $wpdb->prepare('UPDATE %i SET status = %s WHERE id = %d', $table, $value, $formId);

    assert(is_string($sql), 'wpdb::prepare() did not build the update');

    $wpdb->query($sql);

    wp_cache_flush();
}

beforeEach(function (): void {
    if (! formidableOnDisk()) {
        $this->markTestSkipped('Formidable Forms is not in the surrounding install.');
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

it('serves a definition with its fields, composed from both tables', function (): void {
    $id = frmMakeForm('Contact us', 'contact');
    frmMakeField($id, 'cf1');

    $data = frmRequest('/forms/formidable/'.$id)->get_data();

    assert(is_array($data), 'the single-form route did not return an array');

    expect($data['id'])->toBe($id);
    expect($data['name'])->toBe('Contact us');
    expect($data['form_key'])->toBe('contact');
    expect($data['fields'])->toBeArray();
    expect($data['fields'])->toHaveCount(1);

    $fields = $data['fields'] ?? null;
    assert(is_array($fields), 'the record carried no fields list');

    $field = $fields[0] ?? null;
    assert(is_array($field), 'the field was not an array');

    expect($field['field_key'])->toBe('cf1');
    expect($field['type'])->toBe('text');
});

it('serves CHILD forms, which the obvious call hides', function (): void {
    $parent = frmMakeForm('Parent', 'parent');
    $child = frmMakeForm('Repeating section', 'child', $parent);

    /*
     * The headline regression. Formidable Pro stores a repeating section or an
     * embedded form as a separate row pointing at its parent, and
     * `FrmForm::get_published_forms()` excludes those by default — 11 of the 68
     * forms in the captured corpus, 16%, gone with a 200 and no error.
     */
    expect(FrmForm::get_published_forms())->not->toHaveCount(2); // the plugin really does hide it

    expect(frmIds())->toContain($parent);
    expect(frmIds())->toContain($child);

    // And the edge survives, so a consumer can rebuild the repeater.
    $served = frmRequest('/forms/formidable/'.$child)->get_data();
    assert(is_array($served), 'the child form did not return an array');

    expect($served['parent_form_id'])->toBe($parent);
});

it('serves DRAFT forms, and forms whose status column is NULL', function (): void {
    $published = frmMakeForm('Published', 'pub');
    $draft = frmMakeForm('Draft', 'draft');
    $nullStatus = frmMakeForm('Older row', 'older');

    frmSetColumn($draft, 'status', 'draft');
    frmSetColumn($nullStatus, 'status', null);

    /*
     * Two separate traps in one assertion set.
     *
     * The draft is dropped by `get_published_forms()`, which pins `status` to
     * `array( null, '', 'published' )` — content the destination may still
     * need, the same argument that keeps inactive Gravity forms (D-043).
     *
     * The NULL-status row is the subtler one, and it is why trash is filtered
     * in PHP rather than in SQL: `getAll( array( 'status !' => 'trash' ) )`
     * compiles to `status != 'trash'`, and SQL inequality never matches NULL,
     * so that row would vanish from a query written the obvious way.
     */
    expect(frmIds())->toContain($published);
    expect(frmIds())->toContain($draft);
    expect(frmIds())->toContain($nullStatus);
});

it('withholds a trashed form from BOTH routes, which FrmForm::getOne does not', function (): void {
    $kept = frmMakeForm('Kept', 'kept');
    $trashed = frmMakeForm('Trashed', 'trashed');

    frmSetColumn($trashed, 'status', 'trash');

    /*
     * `FrmForm::getOne()` applies no status filter at all — the same asymmetry
     * Gravity Forms has (D-043), in the same direction. Left alone the single
     * record route serves a form the collection deliberately withheld.
     */
    expect(FrmForm::getOne($trashed))->toBeObject(); // the plugin still returns it

    expect(frmIds())->toContain($kept);
    expect(frmIds())->not->toContain($trashed);
    expect(frmRequest('/forms/formidable/'.$trashed)->get_status())->toBe(404);
});

it('orders by id ascending and pages without dropping or repeating a form', function (): void {
    $ids = [];

    foreach (range(1, 5) as $n) {
        // Names deliberately in reverse order: the plugin's own reader sorts by
        // `name`, and this collection must not.
        $ids[] = frmMakeForm('Form '.(6 - $n), 'k'.$n);
    }

    sort($ids);

    expect(frmIds())->toBe($ids);

    $first = frmIds(['per_page' => 2, 'page' => 1]);
    $second = frmIds(['per_page' => 2, 'page' => 2]);
    $third = frmIds(['per_page' => 2, 'page' => 3]);

    expect(array_merge($first, $second, $third))->toBe($ids);
});

it('reports a total that counts what the collection would serve', function (): void {
    frmMakeForm('One', 'o1');
    frmMakeForm('Two', 'o2');

    $trashed = frmMakeForm('Three', 'o3');
    frmSetColumn($trashed, 'status', 'trash');

    expect(headers(frmRequest('/forms/formidable'))['X-WP-Total'] ?? null)->toBe('2');
});

it('404s for an id that does not exist', function (): void {
    frmMakeForm('Real', 'real');

    $response = frmRequest('/forms/formidable/999999');

    expect($response->get_status())->toBe(404);
    expect(errorCode($response))->toBe('uamswp_migration_api_not_found');
});

it('refuses an unauthenticated caller', function (): void {
    $id = frmMakeForm('Private', 'private');

    wp_set_current_user(0);

    expect(frmRequest('/forms/formidable')->get_status())->toBeGreaterThanOrEqual(400);
    expect(frmRequest('/forms/formidable/'.$id)->get_status())->toBeGreaterThanOrEqual(400);
});

it('advertises the resource in the namespace index, with its availability', function (): void {
    $data = frmRequest('/')->get_data();

    assert(is_array($data), 'the namespace index did not return an array');

    $endpoints = $data['endpoints'] ?? null;
    assert(is_array($endpoints), 'the index carried no endpoints map');

    $forms = $endpoints['forms'] ?? null;
    assert(is_array($forms), 'the index carried no forms block');

    $formidable = $forms['formidable'] ?? null;
    assert(is_array($formidable), 'the index carried no formidable block');

    expect($formidable['collection'])->toBe('/forms/formidable');
    expect($formidable['single'])->toBe('/forms/formidable/<id>');
    expect($formidable['available'])->toBeTrue();
});

it("does not serve a child form's fields inside its parent", function (): void {
    $parent = frmMakeForm('Parent', 'parent-fields');
    $child = frmMakeForm('Repeating section', 'child-fields', $parent);

    frmMakeField($parent, 'p1');
    frmMakeField($parent, 'p2');
    frmMakeField($child, 'c1');
    frmMakeField($child, 'c2');
    frmMakeField($child, 'c3');

    /*
     * `FrmField::get_all_for_form()` defaults `$inc_repeat` to `'include'`, and
     * that is not an inclusion flag in the sense the name suggests — it rewrites
     * the where clause to `( fi.form_id = <id> OR fr.parent_form_id = <id> )`,
     * so a PARENT absorbs its children's fields. Combined with this endpoint
     * serving each child as its own record (D-046), the same rows shipped twice
     * in one page: 5 + 3 for 5 rows in the table.
     *
     * The fixture is built to exhibit exactly that — BOTH forms carry their own
     * fields, and the counts differ (2 against 3) so a swap cannot pass either.
     * The pre-existing child-forms test creates no fields at all, so both its
     * `fields` arrays are empty and it cannot see this.
     */
    $byKey = [];

    foreach (frmList(['per_page' => 100]) as $record) {
        $key = $record['form_key'] ?? '';

        if (is_string($key)) {
            $byKey[$key] = $record;
        }
    }

    expect($byKey['parent-fields']['fields'])->toHaveCount(2);
    expect($byKey['child-fields']['fields'])->toHaveCount(3);

    // And the page as a whole serves each stored row exactly once.
    $served = 0;

    foreach ($byKey as $record) {
        $served += \count((array) $record['fields']);
    }

    expect($served)->toBe(5);
});

it('serves the same record from the collection and the single-record route', function (): void {
    $id = frmMakeForm('Parity', 'parity');
    frmMakeField($id, 'pf1');

    /*
     * Gravity Forms gets this by construction — `GFAPI::get_forms()` is a loop
     * over `GFAPI::get_form()`. Formidable has no such guarantee: `getOne()`
     * funnels through `prepare_form_row_data()`, which `getAll()` never calls,
     * and that function both INVENTS options when the column is not an array
     * and runs `apply_filters( 'frm_form_object', … )` so a plugin can edit the
     * read.
     *
     * The NULL-options case below is the one that made it observable. With a
     * normal row both routes agreed, because `FrmForm::create()` writes
     * defaults — so a fixture that only created forms the ordinary way could
     * not have caught this.
     */
    $wpdb = testDatabase();

    $sql = $wpdb->prepare('UPDATE %i SET options = NULL WHERE id = %d', $wpdb->prefix.'frm_forms', $id);
    assert(is_string($sql), 'wpdb::prepare() did not build the update');

    $wpdb->query($sql);
    wp_cache_flush();

    $fromCollection = null;

    foreach (frmList(['per_page' => 100]) as $record) {
        if (is_numeric($record['id'] ?? null) && (int) $record['id'] === $id) {
            $fromCollection = $record;
        }
    }

    $fromShow = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($fromShow), 'the single-form route did not return an array');

    expect($fromCollection)->not->toBeNull();
    expect($fromShow)->toEqual($fromCollection);

    // Named explicitly, because this is the field that diverged: 18 fabricated
    // keys on one route and the stored NULL on the other.
    // `array_key_exists`, not `??` — the coalesce operator fires on NULL as
    // well as on a missing key, so `$x['options'] ?? 'absent'` yields 'absent'
    // for exactly the value under test and the assertion could never pass.
    expect(array_key_exists('options', $fromShow))->toBeTrue();
    expect($fromShow['options'])->toBeNull();
});

it('serves the actions attached to a form, with their settings decoded and not resolved', function (): void {
    $id = frmMakeForm('With actions', 'with-actions');
    $fieldId = frmMakeField($id, 'wa1');

    $email = frmMakeAction($id, 'email', [
        'email_to' => '[admin_email]',
        'email_subject' => 'New entry from ['.$fieldId.']',
        'conditions' => ['send_stop' => 'send', 'any_all' => 'any'],
    ]);
    $confirm = frmMakeAction($id, 'on_submit', [
        'success_action' => 'message',
        'success_msg' => 'Thanks, [wa1].',
    ]);

    $data = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($data), 'the single-form route did not return an array');

    $actions = $data['actions'] ?? null;
    assert(is_array($actions), 'the record carried no actions list');

    expect($actions)->toHaveCount(2);

    // Ordered by id, so the insertion order above is the served order.
    $first = $actions[0] ?? null;
    $second = $actions[1] ?? null;
    assert(is_array($first) && is_array($second), 'an action was not an array');

    expect($first['id'])->toBe($email);
    expect($first['type'])->toBe('email');
    expect($first['status'])->toBe('publish');
    expect($first['form_id'])->toBe($id);
    expect($first['name'])->toBeString();
    expect($first['created_at'])->toBeString();

    /*
     * Decoded, not resolved. `FrmFormAction::prepare_action()` would have
     * merged the email type's defaults here -- `email_message`, `reply_to`,
     * `plain_text`, a dozen more -- and the record would carry values nobody
     * stored. What was written is exactly what comes back.
     */
    expect($first['settings'])->toBe([
        'email_to' => '[admin_email]',
        'email_subject' => 'New entry from ['.$fieldId.']',
        'conditions' => ['send_stop' => 'send', 'any_all' => 'any'],
    ]);
    expect($first['denied'])->toBe(['total' => 0, 'by_name' => 0, 'by_shape' => 0]);

    expect($second['id'])->toBe($confirm);
    expect($second['type'])->toBe('on_submit');
    expect($second['settings'])->toBe([
        'success_action' => 'message',
        'success_msg' => 'Thanks, [wa1].',
    ]);
});

it('serves a form with no actions as an empty list, not a missing member', function (): void {
    $id = frmMakeForm('Bare', 'bare');

    $data = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($data), 'the single-form route did not return an array');

    expect($data)->toHaveKey('actions');
    expect($data['actions'])->toBe([]);
});

it('serves a DRAFT action, which the runtime reader hides', function (): void {
    $id = frmMakeForm('Drafted', 'drafted');
    frmMakeAction($id, 'email', ['email_to' => '[admin_email]'], 'draft');

    /*
     * The positive control, on the plugin's own reader: `get_action_for_form()`
     * is what the runtime uses and it selects `post_status => publish`, so
     * this action does not exist to it. The exporter applies no status filter
     * and emits it. The endpoint follows the exporter (D-081).
     */
    expect(FrmFormAction::get_action_for_form($id))->toBe([]);

    $data = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($data), 'the single-form route did not return an array');

    $actions = $data['actions'] ?? null;
    assert(is_array($actions), 'the record carried no actions list');

    expect($actions)->toHaveCount(1);

    $draft = $actions[0] ?? null;
    assert(is_array($draft), 'the action was not an array');

    expect($draft['status'])->toBe('draft');
    expect($draft['type'])->toBe('email');
});

it("does not serve another form's actions", function (): void {
    $one = frmMakeForm('One', 'one');
    $two = frmMakeForm('Two', 'two');

    frmMakeAction($one, 'email', ['email_to' => 'one']);
    frmMakeAction($two, 'email', ['email_to' => 'two']);
    frmMakeAction($two, 'on_submit', ['success_action' => 'message']);

    $byKey = [];

    foreach (frmList(['per_page' => 100]) as $record) {
        $key = $record['form_key'] ?? '';

        if (is_string($key)) {
            $byKey[$key] = $record;
        }
    }

    expect($byKey['one']['actions'])->toHaveCount(1);
    expect($byKey['two']['actions'])->toHaveCount(2);

    $onlyOne = array_column((array) $byKey['one']['actions'], 'form_id');
    $onlyTwo = array_column((array) $byKey['two']['actions'], 'form_id');

    expect($onlyOne)->toBe([$one]);
    expect($onlyTwo)->toBe([$two, $two]);
});

it("withholds a credential in an action's settings by name, and exempts a field reference", function (): void {
    $id = frmMakeForm('Guarded', 'guarded');
    $fieldId = frmMakeField($id, 'gd1');

    /*
     * Three settings under credential-shaped names, and only one of them is a
     * credential. `password` holding `[<field id>]` is the Formidable shape of
     * the User Registration case D-056 exempts -- the id of the field the
     * password comes from, not a password -- and `api_secret` holding
     * `[gd1]` is the same thing by key. `api_key` holding a literal is
     * withheld. A fourth, ordinary setting travels untouched.
     */
    frmMakeAction($id, 'register', [
        'password' => '['.$fieldId.']',
        'api_secret' => '[gd1]',
        'api_key' => 'live-key-0001',
        'user_role' => 'subscriber',
    ]);

    $data = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($data), 'the single-form route did not return an array');

    $actions = $data['actions'] ?? null;
    assert(is_array($actions) && isset($actions[0]) && is_array($actions[0]), 'the record carried no action');

    expect($actions[0]['settings'])->toBe([
        'password' => '['.$fieldId.']',
        'api_secret' => '[gd1]',
        'user_role' => 'subscriber',
    ]);
    expect($actions[0]['denied'])->toBe(['total' => 1, 'by_name' => 1, 'by_shape' => 0]);
});

it("withholds a credential-shaped VALUE inside an action's settings, at any depth", function (): void {
    $id = frmMakeForm('Shaped', 'shaped');

    frmMakeAction($id, 'api', [
        'endpoint' => 'https://example.test/hook',
        'headers' => ['authorization' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'],
    ]);

    $data = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($data), 'the single-form route did not return an array');

    $actions = $data['actions'] ?? null;
    assert(is_array($actions) && isset($actions[0]) && is_array($actions[0]), 'the record carried no action');

    expect($actions[0]['settings'])->toBe(['endpoint' => 'https://example.test/hook']);
    expect($actions[0]['denied'])->toBe(['total' => 1, 'by_name' => 0, 'by_shape' => 1]);
});

it('serves an action whose settings are not JSON as an empty settings map', function (): void {
    $id = frmMakeForm('Legacy', 'legacy');

    /*
     * Not a shape the plugin writes today, and a row can still carry it -- a
     * migration from a version that serialized differently, or a hand edit.
     * `FrmAppHelper::maybe_json_decode()` returns the string unchanged when it
     * cannot decode it, and the record must not then carry a string where a
     * consumer expects a map.
     */
    $post = wp_insert_post([
        'post_type' => 'frm_form_actions',
        'post_status' => 'publish',
        'post_title' => 'Odd',
        'post_excerpt' => 'email',
        'post_content' => 'not json at all',
        'menu_order' => $id,
    ], true);
    assert(is_int($post), 'WordPress refused to insert the fixture action');

    $data = frmRequest('/forms/formidable/'.$id)->get_data();
    assert(is_array($data), 'the single-form route did not return an array');

    $actions = $data['actions'] ?? null;
    assert(is_array($actions) && isset($actions[0]) && is_array($actions[0]), 'the record carried no action');

    expect($actions[0]['settings'])->toBe([]);
    expect($actions[0]['type'])->toBe('email');
});
