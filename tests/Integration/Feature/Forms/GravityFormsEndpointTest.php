<?php

/**
 * The Gravity Forms definition endpoints.
 */

declare(strict_types=1);

/*
 * Gravity Forms is paid and never present on CI, so every test here that needs
 * a real `GFAPI` skips without one. That is the ACF PRO arrangement (D-030)
 * applied to a second plugin, and `GravityFormsGuardTest` is the part that
 * keeps it honest.
 *
 * What these tests are actually holding: `GFAPI`'s two readers disagree about
 * both inactive and trashed forms, in opposite directions, and each disagreement
 * loses or leaks a whole class of form with nothing red anywhere. The captured
 * corpus is the measure of what a definition has to carry — 1,997 forms across
 * the importer's 161 committed fixtures, every one of them with `notifications`
 * and `confirmations`.
 */

/**
 * Dispatch a request against this plugin's namespace.
 *
 * @param  array<string, mixed>  $params
 */
function gfRequest(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * A response body that has to be an array, narrowed once.
 *
 * `WP_REST_Response::get_data()` is `mixed`, so without this every offset read
 * in the file is an analyzer error at `level: max` — the same reason
 * `tests/Support/helpers.php` narrows `$wpdb` in one place.
 *
 * @return array<string, mixed>
 */
function gfArray(WP_REST_Response $response, string $what): array
{
    $data = $response->get_data();

    assert(is_array($data), $what.' did not return an array');

    /** @var array<string, mixed> $data */
    return $data;
}

/**
 * The records the collection returns.
 *
 * @param  array<string, mixed>  $params
 * @return list<array<string, mixed>>
 */
function gfList(array $params = []): array
{
    $data = gfRequest('/forms/gravity', $params)->get_data();

    assert(is_array($data), 'the forms collection did not return a list');

    $records = [];

    foreach ($data as $record) {
        assert(is_array($record), 'a form record was not an array');

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
function gfIds(array $params = []): array
{
    $ids = [];

    foreach (gfList($params) as $form) {
        $id = $form['id'] ?? null;

        $ids[] = is_numeric($id) ? (int) $id : 0;
    }

    return $ids;
}

/**
 * One form, as the single-record route serves it.
 *
 * @return array<string, mixed>
 */
function gfShow(int $id): array
{
    return gfArray(gfRequest('/forms/gravity/'.$id), 'the single-form route');
}

/**
 * Create a form and return its id.
 */
function gfMakeForm(string $title, bool $active = true): int
{
    $id = GFAPI::add_form([
        'title' => $title,
        'description' => $title.' description',
        'fields' => [
            ['id' => 1, 'type' => 'text', 'label' => 'Name'],
        ],
    ]);

    assert(is_int($id), 'Gravity Forms refused to create the fixture form');

    if (! $active) {
        GFFormsModel::update_form_active($id, 0);
    }

    return $id;
}

beforeEach(function (): void {
    if (! gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is not in the surrounding install.');
    }

    actAsSuperAdmin();

    /*
     * Start from an empty forms table rather than trusting the transaction to
     * roll `gf_form` back. The totals below are absolute, and a form left over
     * from another test would move them without failing anything visibly.
     */
    foreach (GFAPI::get_forms(null, null, 'id', 'ASC') as $form) {
        $id = is_array($form) ? ($form['id'] ?? null) : null;

        if (is_numeric($id)) {
            GFAPI::delete_form((int) $id);
        }
    }
});

it('serves a form definition with the blocks every captured fixture carries', function (): void {
    $form = gfShow(gfMakeForm('Contact us'));

    /*
     * `title`, `button`, `fields`, `id`, `confirmations` and `notifications`
     * are present on all 1,997 forms in the committed corpus — they are what a
     * definition IS, not what one deployment happened to set. `notifications`
     * and `confirmations` are the two a read of `gf_form` alone would miss,
     * because they live in `gf_form_meta`.
     */
    foreach (['id', 'title', 'fields', 'button', 'notifications', 'confirmations'] as $key) {
        expect($form)->toHaveKey($key);
    }

    expect($form['title'])->toBe('Contact us');
    expect($form['fields'])->toBeArray();
    expect($form['fields'])->not->toBeEmpty();
});

it('serves inactive forms, which the plugin default would silently drop', function (): void {
    $active = gfMakeForm('Still in use');
    $inactive = gfMakeForm('Retired but not deleted', active: false);

    /*
     * The regression that matters. `GFAPI::get_forms()` defaults `$active` to
     * `true`, so the obvious call returns only the first of these and a
     * migration arrives missing every form somebody had switched off — with a
     * 200 and no error anywhere. Passing `null` skips the filter entirely
     * (`GFFormsModel::get_form_ids()`), which is what the controller does.
     */
    expect(gfIds())->toContain($active);
    expect(gfIds())->toContain($inactive);

    // And the inactive one is reachable on its own route, not just in the page.
    expect(gfRequest('/forms/gravity/'.$inactive)->get_status())->toBe(200);
});

it('withholds a trashed form from BOTH routes, which GFAPI itself does not', function (): void {
    $kept = gfMakeForm('Kept');
    $trashed = gfMakeForm('Trashed');

    GFFormsModel::trash_form($trashed);

    /*
     * `GFAPI`'s two readers disagree here, and in the direction that leaks:
     * the collection filters `is_trash = 0` in SQL, while `GFAPI::get_form()`
     * calls `GFFormsModel::get_form( $id, true )` — `$allow_trash` — and hands
     * a trashed form straight back. Left alone, `/forms/gravity/<id>` serves
     * content the collection deliberately withheld, so the narrower route is
     * the one that resurrects somebody's delete.
     */
    expect(GFAPI::get_form($trashed))->toBeArray(); // The plugin still returns it...

    expect(gfIds())->toContain($kept);
    expect(gfIds())->not->toContain($trashed);
    expect(gfRequest('/forms/gravity/'.$trashed)->get_status())->toBe(404);
});

it('serves the same shape from the collection and the single record', function (): void {
    $id = gfMakeForm('Parity');

    /*
     * True by construction rather than by agreement — `GFAPI::get_forms()` is a
     * loop over `GFAPI::get_form()` — and asserted anyway, because that is an
     * implementation detail of a plugin this repo does not control, and a
     * lighter collection representation is exactly the sort of thing a minor
     * version introduces.
     */
    expect(gfList()[0] ?? null)->toEqual(gfShow($id));
});

it('orders by id ascending and pages without dropping or repeating a form', function (): void {
    $ids = [];

    foreach (range(1, 5) as $n) {
        $ids[] = gfMakeForm('Form '.$n);
    }

    sort($ids);

    expect(gfIds())->toBe($ids);

    $first = gfIds(['per_page' => 2, 'page' => 1]);
    $second = gfIds(['per_page' => 2, 'page' => 2]);
    $third = gfIds(['per_page' => 2, 'page' => 3]);

    expect($first)->toBe(\array_slice($ids, 0, 2));
    expect($second)->toBe(\array_slice($ids, 2, 2));
    expect($third)->toBe(\array_slice($ids, 4, 2));

    // Every form exactly once across the three pages, which is the property
    // ordering by id exists to give.
    expect(array_merge($first, $second, $third))->toBe($ids);
});

it('reports a total that counts what the collection would serve', function (): void {
    gfMakeForm('One');
    gfMakeForm('Two', active: false);

    GFFormsModel::trash_form(gfMakeForm('Three'));

    /*
     * Two, not three: the total has to be counted under the same rules the page
     * is, or a consumer pages to the end and believes it stopped early. The
     * inactive form counts, the trashed one does not.
     */
    expect(headers(gfRequest('/forms/gravity'))['X-WP-Total'] ?? null)->toBe('2');
});

it('404s for an id that does not exist', function (): void {
    gfMakeForm('Real');

    $response = gfRequest('/forms/gravity/999999');

    expect($response->get_status())->toBe(404);
    expect(errorCode($response))->toBe('uamswp_migration_api_not_found');
});

it('refuses an unauthenticated caller', function (): void {
    $id = gfMakeForm('Private');

    wp_set_current_user(0);

    /*
     * Form definitions carry notification routing — recipient addresses, admin
     * labels and confirmation text — so the namespace gate covering this route
     * is a disclosure boundary, not a formality.
     */
    expect(gfRequest('/forms/gravity')->get_status())->toBeGreaterThanOrEqual(400);
    expect(gfRequest('/forms/gravity/'.$id)->get_status())->toBeGreaterThanOrEqual(400);
});

it('advertises the resource in the namespace index, with its availability', function (): void {
    /*
     * The TRAILING SLASH is load-bearing. `/uamswp-migration/v1` is the
     * namespace index WordPress generates for every namespace, and it returns
     * core's `namespace`/`routes` shape; this plugin's own index is registered
     * at `/` inside the namespace, which resolves one path further along.
     */
    $index = gfArray(gfRequest('/'), 'the namespace index');

    $endpoints = $index['endpoints'] ?? null;
    assert(is_array($endpoints), 'the index carried no endpoints map');

    $forms = $endpoints['forms'] ?? null;
    assert(is_array($forms), 'the index carried no forms block');

    $gravity = $forms['gravity'] ?? null;
    assert(is_array($gravity), 'the index carried no gravity block');

    expect($gravity['collection'])->toBe('/forms/gravity');
    expect($gravity['single'])->toBe('/forms/gravity/<id>');

    /*
     * True here because the bootstrap loaded the plugin. The value is the point
     * rather than the key: a consumer reads this to tell "this deployment does
     * not serve forms" apart from "this site has not got the plugin", and those
     * need different actions from whoever is running the migration.
     */
    expect($gravity['available'])->toBeTrue();
});

it('drops a form whose definition has gone, from the records and the total alike', function (): void {
    $intact = gfMakeForm('Intact');
    $orphan = gfMakeForm('Orphan');

    /*
     * `GFAPI::get_forms()` queries ids from `gf_form` and then fetches each one
     * through `GFAPI::get_form()`, which reads `gf_form_meta` and returns
     * `false` when it finds nothing. So a form whose meta row has gone — a
     * delete that failed halfway, or one racing this request — arrives as a
     * literal `false` sitting in the middle of the list.
     *
     * Removing the meta row and leaving the form row is exactly that state, and
     * it is reachable rather than theoretical: the two rows are deleted in
     * separate statements.
     */
    $wpdb = testDatabase();

    /*
     * `%i` for the table name rather than interpolating it: `wpdb::prepare()`
     * takes a literal-string query, and building one by concatenation is the
     * shape that hides an injection even when — as here — the value is
     * trusted. The identifier placeholder has been available since WordPress
     * 6.2, and production is on 6.8.7.
     */
    $sql = $wpdb->prepare(
        'DELETE FROM %i WHERE form_id = %d',
        $wpdb->prefix.'gf_form_meta',
        $orphan
    );

    assert(is_string($sql), 'wpdb::prepare() did not build the delete');

    $wpdb->query($sql);

    // The model caches forms in a process static, which no amount of object
    // cache flushing reaches.
    GFFormsModel::flush_current_forms();

    wp_cache_flush();

    // The plugin really does hand back a `false` here — the guard is not
    // defending against something that cannot happen.
    expect(GFAPI::get_forms(null, false, 'id', 'ASC'))->toContain(false);

    /*
     * Both halves matter. An unfiltered `false` would serialize into the
     * records as a bare `false` where a form object belongs, AND be counted in
     * the total — so a consumer would page toward a record that is never
     * coming and see a JSON literal it has no branch for.
     */
    expect(gfIds())->toBe([$intact]);
    expect(headers(gfRequest('/forms/gravity'))['X-WP-Total'] ?? null)->toBe('1');
});
