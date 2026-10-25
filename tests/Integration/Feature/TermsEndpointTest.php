<?php

/**
 * The term endpoints — taxonomies as a resource, not a footnote on posts.
 */

declare(strict_types=1);

/*
 * These replace the three legacy WXR taxonomy shapes the importer's `XmlParser`
 * reads — `getTerms()`, `getTags()` and `getCategories()`. Parity work rather
 * than gap-filling: WXR does carry `wp:termmeta`, in 103 of the 259 captured
 * exports. What it cannot do is page.
 */

/**
 * Dispatch a term route, authenticated.
 *
 * @param  array<string, mixed>  $params
 */
function termsGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * A term collection as a list of records.
 *
 * @param  array<string, mixed>  $params
 * @return list<array<string, mixed>>
 */
function termsList(string $route, array $params = []): array
{
    $data = termsGet($route, $params)->get_data();

    assert(is_array($data), "the {$route} endpoint did not return a list");

    /** @var list<array<string, mixed>> $data */
    return $data;
}

/**
 * One record, narrowed once.
 *
 * @return array<string, mixed>
 */
function termsRecord(mixed $value): array
{
    assert(is_array($value), 'expected a term record');

    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * The record for a given term id, or null.
 *
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>|null
 */
function termsFind(array $records, int $id): ?array
{
    foreach ($records as $record) {
        if (($record['id'] ?? null) === $id) {
            return $record;
        }
    }

    return null;
}

/**
 * The `X-WP-Total` header as an integer.
 *
 * `get_headers()` is typed as returning `mixed` values, so the narrowing lives
 * here rather than as a cast at each assertion.
 */
function termsHeaderTotal(WP_REST_Response $response): int
{
    $total = $response->get_headers()['X-WP-Total'] ?? null;

    return is_numeric($total) ? (int) $total : -1;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves a flat taxonomy with slugs on every term', function (): void {
    // `post_tag` is the flat case. Slugs are asserted on *every* record rather
    // than on one, because navigation menu selection keys on slug rather than
    // id — a term served without one is a menu the importer cannot rebuild,
    // and ids do not survive a migration.
    $first = makeTerm(['taxonomy' => 'post_tag', 'name' => 'Cardiology', 'slug' => 'cardiology']);
    $second = makeTerm(['taxonomy' => 'post_tag', 'name' => 'Neurology', 'slug' => 'neurology']);

    $records = termsList('/terms/post_tag');

    $slugs = [];

    foreach ($records as $record) {
        $slugs[] = $record['slug'] ?? null;
    }

    expect(termsGet('/terms/post_tag')->get_status())->toBe(200)
        ->and($records)->not->toBe([])
        ->and(termsRecord(termsFind($records, $first))['slug'])->toBe('cardiology')
        ->and(termsRecord(termsFind($records, $second))['slug'])->toBe('neurology')
        ->and($slugs)->not->toContain(null)
        ->and($slugs)->not->toContain('');
});

it('serves a hierarchical taxonomy with the parent relationship intact', function (): void {
    // `category` is the hierarchical case. `parent` is the stored value, 0 for a
    // top-level term — WordPress' own sentinel, left as it is because that is
    // what a consumer rebuilding the tree needs.
    $parent = makeTerm(['taxonomy' => 'category', 'name' => 'Clinical']);
    $child = makeTerm(['taxonomy' => 'category', 'name' => 'Surgery', 'parent' => $parent]);

    $records = termsList('/terms/category');

    expect(termsRecord(termsFind($records, $child))['parent'])->toBe($parent)
        ->and(termsRecord(termsFind($records, $parent))['parent'])->toBe(0);
});

it('serves terms nobody has used yet', function (): void {
    // `get_terms()` hides empty terms unless told otherwise, so the WordPress
    // default would drop every unused term from a migration — the taxonomy
    // arriving at the destination missing exactly the terms nobody had assigned
    // yet, with no error anywhere. The count header has to agree, or a consumer
    // reads a total that says it is finished when it is not.
    $unused = makeTerm(['taxonomy' => 'category', 'name' => 'Never Assigned']);

    $response = termsGet('/terms/category');
    $records = termsList('/terms/category');

    expect(termsFind($records, $unused))->not->toBeNull()
        ->and(termsRecord(termsFind($records, $unused))['count'])->toBe(0)
        ->and(termsHeaderTotal($response))->toBe(\count($records));
});

it('returns raw term meta, underscore-prefixed keys included', function (): void {
    // D-007's rule, which is the same one for termmeta as for postmeta rather
    // than a separate decision: ACF writes a field-key reference at
    // `_<fieldname>` beside every value it stores, so dropping the prefix keeps
    // the values and discards the map back to the fields that produced them.
    $term = makeTerm(['taxonomy' => 'category', 'name' => 'Metadata']);

    add_term_meta($term, 'uamswp_term_probe', 'a value');
    add_term_meta($term, '_uamswp_term_probe', 'field_uamswp_term_probe');

    $record = termsRecord(termsGet('/terms/category/'.$term)->get_data());
    $meta = termsRecord($record['meta'] ?? null);

    expect($meta)->toHaveKey('uamswp_term_probe')
        ->and($meta)->toHaveKey('_uamswp_term_probe')
        // Values arrive as WordPress hands them back — one array of raw strings
        // per key, which nothing here decodes.
        ->and($meta['uamswp_term_probe'])->toBe(['a value'])
        ->and($meta['_uamswp_term_probe'])->toBe(['field_uamswp_term_probe']);
});

it("carries ACF's assembled view beside the raw rows", function (): void {
    // D-005 on posts, applied to terms: Find-a-Doc hangs ACF field groups on
    // thirteen taxonomies, so for those terms the structured shape is the one
    // that reads as the editor entered it. ACF addresses a term as `term_<id>`
    // and matches a field group by the `taxonomy` location rule.
    acf_add_local_field_group([
        'key' => 'group_term_probe',
        'title' => 'Term probe',
        'fields' => [
            ['key' => 'field_term_probe_label', 'name' => 'term_probe_label', 'type' => 'text', 'label' => 'Label'],
        ],
        'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
    ]);

    $term = makeTerm(['taxonomy' => 'category', 'name' => 'ACF bearing']);

    update_field('field_term_probe_label', 'Assembled by ACF', 'term_'.$term);

    $record = termsRecord(termsGet('/terms/category/'.$term)->get_data());
    $acf = termsRecord($record['acf'] ?? null);

    expect($acf)->toHaveKeys(['keys', 'names', 'name_collisions'])
        ->and($acf['keys'])->toBe(['field_term_probe_label' => 'Assembled by ACF'])
        ->and($acf['names'])->toBe(['term_probe_label' => 'Assembled by ACF'])
        // …and the raw row is still there, which is the whole of D-005: an
        // existing importer reads `meta`, and `acf` is what carries the shape.
        ->and(termsRecord($record['meta'] ?? null))->toHaveKey('term_probe_label');

    acf_remove_local_field_group('group_term_probe');
});

it('carries `term_taxonomy_id` beside `term_id`', function (): void {
    // They are different numbers, and WXR's `wp:term_id` is ambiguous about
    // which it means. The relationship table keys on `term_taxonomy_id`, so an
    // importer rebuilding assignments needs it.
    $term = makeTerm(['taxonomy' => 'category', 'name' => 'Both ids']);
    $record = termsRecord(termsGet('/terms/category/'.$term)->get_data());

    $stored = get_term($term, 'category');

    assert($stored instanceof WP_Term);

    expect($record['id'])->toBe($stored->term_id)
        ->and($record['term_taxonomy_id'])->toBe($stored->term_taxonomy_id);
});

it('pages by term id ascending, not by anything that can change', function (): void {
    // D-006. A migration pages through a live site while editors work, so any
    // ordering that can change puts a record on two pages or none.
    //
    // The names run **counter to** creation order deliberately. An earlier
    // version of this test used `Paged 1`..`Paged 5`, whose alphabetical order
    // matches their id order — so ordering by `name` produced an identical page
    // and the test stayed green through the one substitution it exists to
    // catch. A fixture has to be able to tell the orderings apart.
    $created = [];

    foreach (['Zulu', 'Yankee', 'Xray', 'Whiskey', 'Victor'] as $name) {
        $created[] = makeTerm(['taxonomy' => 'post_tag', 'name' => $name]);
    }

    $ids = [];
    $names = [];

    foreach (termsList('/terms/post_tag', ['per_page' => 2]) as $record) {
        $ids[] = $record['id'] ?? null;
        $names[] = $record['name'] ?? null;
    }

    $ascending = $ids;
    sort($ascending);

    expect($ids)->toHaveCount(2)
        ->and($ids)->toBe($ascending)
        // The discriminating assertion: by id the first page starts at the
        // first term created, and by name it would start at `Victor`.
        ->and($ids[0])->toBe($created[0])
        ->and($names[0])->toBe('Zulu');
});

it('refuses a taxonomy this deployment does not serve', function (): void {
    // Both gates are a 404 rather than a 403: which taxonomies exist is not
    // something to let a caller probe, and the namespace gate has already
    // established that this one is authorized.
    register_taxonomy('uamswp_unserved_tax', 'post');

    $notConfigured = termsGet('/terms/uamswp_unserved_tax');
    $unregistered = termsGet('/terms/not_a_taxonomy_at_all');

    expect($notConfigured->get_status())->toBe(404)
        ->and($unregistered->get_status())->toBe(404);

    unregister_taxonomy('uamswp_unserved_tax');
});

it('refuses a term id belonging to another taxonomy', function (): void {
    // Without the taxonomy check `/terms/category/<tag id>` would serve the tag,
    // and a consumer paging one taxonomy could be handed a record of another
    // with nothing going visibly wrong.
    $tag = makeTerm(['taxonomy' => 'post_tag', 'name' => 'Wrong taxonomy']);

    expect(termsGet('/terms/category/'.$tag)->get_status())->toBe(404);
});

it('carries the parent slug, which is what every WXR shape actually holds', function (): void {
    // Core's exporter writes `$terms[$t->parent]->slug` for `wp:term_parent`
    // and the same for `wp:category_parent` (export.php:536, :560). The id
    // alone is not derivable back to a slug without the whole taxonomy in
    // hand, and ids do not survive a migration — the same argument that makes
    // the term's own slug load-bearing.
    $parent = makeTerm(['taxonomy' => 'category', 'name' => 'Parent Term', 'slug' => 'parent-term']);
    $child = makeTerm(['taxonomy' => 'category', 'name' => 'Child Term', 'parent' => $parent]);

    $childRecord = termsRecord(termsGet('/terms/category/'.$child)->get_data());
    $parentRecord = termsRecord(termsGet('/terms/category/'.$parent)->get_data());

    expect($childRecord['parent_slug'])->toBe('parent-term')
        // Empty at the top level, matching what the exporter emits rather than
        // inventing a null.
        ->and($parentRecord['parent_slug'])->toBe('')
        // …and the id is still there, because an importer that has the whole
        // taxonomy in hand may prefer it.
        ->and($childRecord['parent'])->toBe($parent);
});

it('separates a stored value from one the field definition supplied', function (): void {
    // D-038. `acf_get_value()` substitutes `default_value` when nothing is
    // stored, and it does so *before* the `acf/load_value` filter, so no hook
    // can tell them apart afterwards. Measured on this install before the
    // split: 16,626 of 45,249 term fields were served carrying a value no row
    // held — 37%, almost all on `clinical_title`.
    acf_add_local_field_group([
        'key' => 'group_default_probe',
        'title' => 'Default probe',
        'fields' => [
            ['key' => 'field_probe_filled', 'name' => 'probe_filled', 'type' => 'text', 'label' => 'Filled'],
            ['key' => 'field_probe_defaulted', 'name' => 'probe_defaulted', 'type' => 'text', 'label' => 'Defaulted', 'default_value' => 'from the definition'],
            ['key' => 'field_probe_bare', 'name' => 'probe_bare', 'type' => 'text', 'label' => 'Bare'],
        ],
        'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
    ]);

    $term = makeTerm(['taxonomy' => 'category', 'name' => 'Default probe']);

    update_field('field_probe_filled', 'the editor typed this', 'term_'.$term);

    $acf = termsRecord(termsRecord(termsGet('/terms/category/'.$term)->get_data())['acf'] ?? null);
    $stored = termsRecord($acf['keys'] ?? null);
    $defaulted = termsRecord(termsRecord($acf['defaulted'] ?? null)['keys'] ?? null);

    expect($stored)->toHaveKey('field_probe_filled')
        ->and($stored['field_probe_filled'])->toBe('the editor typed this')
        // The manufactured one is in its own map, carrying the value ACF would
        // give a rendering page — a fact worth reporting, not worth mixing in.
        ->and($defaulted)->toHaveKey('field_probe_defaulted')
        ->and($defaulted['field_probe_defaulted'])->toBe('from the definition')
        ->and($stored)->not->toHaveKey('field_probe_defaulted')
        // No row and an EMPTY default is absent, not defaulted.
        // `acf_get_valid_field()` gives most types `default_value => ''`, so
        // without that rule every unset text field on the site would land in
        // the defaulted map carrying an empty string — thousands of rows of
        // noise, and a distinction nobody can act on.
        ->and($stored)->toHaveKey('field_probe_bare')
        ->and($stored['field_probe_bare'])->toBe('')
        ->and($defaulted)->not->toHaveKey('field_probe_bare');

    acf_remove_local_field_group('group_default_probe');
});
