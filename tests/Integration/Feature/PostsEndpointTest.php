<?php

/**
 * The posts endpoints — the plugin's core content routes.
 */

declare(strict_types=1);

/*
 * Dispatched through `rest_do_request()` rather than by calling the controller,
 * for the reason AuthTest states: the auth gate and the site switch hang off
 * `rest_pre_dispatch`, which only the server applies. A direct call would test a
 * route nobody can actually reach.
 */

/**
 * Dispatch a GET against a posts route, authenticated.
 *
 * @param  array<string, mixed>  $params  Query parameters.
 */
function postsGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * The record ids in a collection response, in the order served.
 *
 * @return list<int>
 */
function postsIds(WP_REST_Response $response): array
{
    $data = $response->get_data();

    assert(is_array($data), 'a collection response did not return an array');

    $ids = [];

    foreach ($data as $record) {
        assert(is_array($record), 'a collection record was not an array');

        $id = $record['id'] ?? null;

        assert(is_int($id), 'a collection record had no integer id');

        $ids[] = $id;
    }

    return $ids;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves a page of records for a configured post type', function (): void {
    $ids = [makePost(), makePost(), makePost()];

    $response = postsGet('/posts/post');

    expect($response->get_status())->toBe(200)
        ->and(postsIds($response))->toContain(...$ids);
});

it('refuses a post type this deployment does not serve', function (): void {
    // A 404, not a 403: which post types exist is not something to disclose,
    // and the namespace gate has already established the caller is authorized.
    expect(postsGet('/posts/revision')->get_status())->toBe(404)
        ->and(errorCode(postsGet('/posts/revision')))->toBe('uamswp_migration_api_unknown_post_type');
});

it('serves one record, and refuses an id of the wrong type', function (): void {
    // Without the type check `/posts/page/<a post id>` serves the post, so a
    // consumer paging one type could be handed a record of another and never
    // know it happened.
    $id = makePost();

    expect(payload(postsGet("/posts/post/{$id}"), 'id'))->toBe($id)
        ->and(postsGet("/posts/page/{$id}")->get_status())->toBe(404);
});

it('carries both meta shapes on every record', function (): void {
    // D-005. The flat shape is what an existing importer already reads; the
    // structured one is the only shape carrying current values for `location`
    // and `provider`.
    $id = makePost();
    update_post_meta($id, 'plain_key', 'plain value');

    $record = postsGet("/posts/post/{$id}")->get_data();

    expect($record)->toHaveKeys(['meta', 'acf'])
        ->and(payload(postsGet("/posts/post/{$id}"), 'meta', 'plain_key'))->toBe(['plain value'])
        ->and(payload(postsGet("/posts/post/{$id}"), 'acf'))->toHaveKeys(['keys', 'names', 'name_collisions']);
});

it('preserves underscore-prefixed meta keys', function (): void {
    // D-007, and the single most consequential filter this endpoint does NOT
    // apply. Core's own network site-settings screen excludes `\_%`, which
    // would drop ACF field-key references, `_menu_item_*` and `_thumbnail_id`
    // — three unrelated things broken by one line that looks like hygiene.
    $id = makePost();
    update_post_meta($id, '_thumbnail_id', '4242');
    update_post_meta($id, '_menu_item_object_id', '99');
    update_post_meta($id, '_field_key_reference', 'field_abc123');

    $meta = payload(postsGet("/posts/post/{$id}"), 'meta');

    expect($meta)->toHaveKeys(['_thumbnail_id', '_menu_item_object_id', '_field_key_reference']);
});

/*
 * TablePress is paid and absent from CI exactly like Gravity Forms and ACF
 * PRO, but unlike those, `synthesizedMeta()` (#77) depends on no TablePress
 * code at all -- only the post's own `post_type` string and the shape of the
 * `tablepress_tables` option, which any process can fabricate. What these
 * tests DO need registered is the post type itself: `config/defaults.php`'s
 * eligibility list is filtered by `post_type_exists()` at read time (by
 * design -- the same list serves every site in the network), so on a
 * process where TablePress never ran `register_post_type()`, the route
 * 404s before `synthesizedMeta()` is ever reached. Registered here the same
 * way `ConfigTest.php` reproduces a site-registered type it does not ship.
 */
it('synthesizes the export-time TablePress table id `export_wp()` adds and DB rows never carry', function (): void {
    // #77. `_tablepress_export_table_id` is not a postmeta row -- TablePress
    // injects it during export and strips it again on import -- so it can
    // only be reconstructed from `tablepress_tables`' `table_post` map, the
    // same source TablePress itself reads from. `wordpress-importer` matches
    // a `[table id=N /]` shortcode against this value.
    //
    // THE OPTION IS SEEDED AS A JSON STRING, which is the only shape TablePress
    // ever writes (#253): `TablePress_WP_Option` stores through
    // `wp_json_encode()` and reads back through `json_decode()`, so `get_option()`
    // hands this namespace a string on every real install. Three tests here once
    // seeded a PHP array instead, which WordPress serializes and returns as an
    // array -- a shape no production site holds -- and the feature was green for
    // months while emitting the field exactly never.
    register_post_type('tablepress_table', ['public' => true]);

    try {
        $id = makePost(['post_type' => 'tablepress_table']);
        update_option('tablepress_tables', (string) wp_json_encode(['last_id' => 3, 'table_post' => [3 => $id]]));

        // The single route.
        expect(payload(postsGet("/posts/tablepress_table/{$id}"), 'meta'))->toHaveKey('_tablepress_export_table_id')
            ->and(payload(postsGet("/posts/tablepress_table/{$id}"), 'meta', '_tablepress_export_table_id'))->toBe(['3']);

        // And the collection route, which reaches the payload by its own path
        // and is the one a migration actually pages through. Asserted in two
        // steps so a record that is missing from the page is distinguishable
        // from one that is listed without the field.
        $collection = postsGet('/posts/tablepress_table');

        expect(postsIds($collection))->toContain($id);

        $listedValue = null;

        $records = $collection->get_data();

        assert(is_array($records), 'a collection response did not return an array');

        foreach ($records as $record) {
            assert(is_array($record), 'a collection record was not an array');

            if (($record['id'] ?? null) !== $id) {
                continue;
            }

            assert(is_array($record['meta'] ?? null), 'a collection record carried no meta object');

            $listedValue = $record['meta']['_tablepress_export_table_id'] ?? null;
        }

        expect($listedValue)->toBe(['3']);
    } finally {
        delete_option('tablepress_tables');
        unregister_post_type('tablepress_table');
    }
});

it('never synthesizes a TablePress table id for any other post type', function (): void {
    // The type gate itself: an ordinary post sharing no relationship with
    // `tablepress_tables` must never carry the key, however that option is
    // shaped -- it is scoped by post_type, not by whether an id happens to
    // appear somewhere in the option.
    $id = makePost(['post_type' => 'post']);
    update_option('tablepress_tables', (string) wp_json_encode(['last_id' => 1, 'table_post' => [1 => $id]]));

    $meta = payload(postsGet("/posts/post/{$id}"), 'meta');

    expect($meta)->not->toHaveKey('_tablepress_export_table_id');

    delete_option('tablepress_tables');
});

it('synthesizes nothing for a TablePress post with no matching table_post entry', function (): void {
    // A table post whose id was never written into `table_post` -- deleted
    // out from under it, or the option not yet reconciled -- gets no
    // fabricated value rather than a wrong one.
    register_post_type('tablepress_table', ['public' => true]);

    try {
        $id = makePost(['post_type' => 'tablepress_table']);
        update_option('tablepress_tables', (string) wp_json_encode(['last_id' => 1, 'table_post' => [1 => $id + 999999]]));

        $meta = payload(postsGet("/posts/tablepress_table/{$id}"), 'meta');

        expect($meta)->not->toHaveKey('_tablepress_export_table_id');
    } finally {
        delete_option('tablepress_tables');
        unregister_post_type('tablepress_table');
    }
});

it('synthesizes nothing for a TablePress post when the option is absent or genuinely malformed', function (): void {
    // No option at all (TablePress never activated here, or the row was
    // dropped) and malformed shapes all have to fail closed rather than fatal.
    //
    // WHAT COUNTS AS MALFORMED CHANGED WITH #253, and the old value is why this
    // test passed while the feature was broken. It seeded `'not-an-array'` --
    // which is precisely the production shape, a bare string -- and asserted the
    // field was absent, so the defect was pinned here as correct behavior. The
    // cases below are strings that are not valid JSON, and JSON that decodes to
    // something other than a map.
    register_post_type('tablepress_table', ['public' => true]);

    try {
        delete_option('tablepress_tables');
        $withoutOption = makePost(['post_type' => 'tablepress_table']);

        $cases = [
            'not valid JSON at all' => 'not-an-array',
            'JSON encoding a scalar' => '"just-a-string"',
            'JSON encoding a number' => '42',
            'valid JSON, no table_post' => (string) wp_json_encode(['last_id' => 3]),
            'table_post not a map' => (string) wp_json_encode(['last_id' => 3, 'table_post' => 'nope']),
        ];

        expect(payload(postsGet("/posts/tablepress_table/{$withoutOption}"), 'meta'))
            ->not->toHaveKey('_tablepress_export_table_id');

        foreach ($cases as $label => $value) {
            update_option('tablepress_tables', $value);
            $id = makePost(['post_type' => 'tablepress_table']);

            expect(payload(postsGet("/posts/tablepress_table/{$id}"), 'meta'))
                ->not->toHaveKey('_tablepress_export_table_id', "case: {$label}");
        }
    } finally {
        delete_option('tablepress_tables');
        unregister_post_type('tablepress_table');
    }
});

it('keeps a repeater flat in `meta` and nested in `acf`, under key and name', function (): void {
    // The two payloads earning their keep on one record: `meta` carries the row
    // count and the numbered keys exactly as WXR would serialize them, while
    // `acf` carries the assembled rows a new consumer can read without
    // reimplementing ACF's assembly.
    acf_add_local_field_group([
        'key' => 'group_posts_endpoint',
        'title' => 'Posts endpoint fixture',
        'fields' => [[
            'key' => 'field_endpoint_slides',
            'name' => 'endpoint_slides',
            'type' => 'repeater',
            'label' => 'Slides',
            'sub_fields' => [
                ['key' => 'field_endpoint_slide_title', 'name' => 'endpoint_slide_title', 'type' => 'text', 'label' => 'Title'],
            ],
        ]],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    ]);

    $id = makePost();
    update_post_meta($id, 'endpoint_slides', 1);
    update_post_meta($id, '_endpoint_slides', 'field_endpoint_slides');
    update_post_meta($id, 'endpoint_slides_0_endpoint_slide_title', 'First slide');
    update_post_meta($id, '_endpoint_slides_0_endpoint_slide_title', 'field_endpoint_slide_title');

    $record = postsGet("/posts/post/{$id}");

    expect(payload($record, 'meta', 'endpoint_slides_0_endpoint_slide_title'))->toBe(['First slide'])
        ->and(payload($record, 'acf', 'keys', 'field_endpoint_slides'))
        ->toBe([['field_endpoint_slide_title' => 'First slide']])
        ->and(payload($record, 'acf', 'names', 'endpoint_slides'))
        ->toBe([['endpoint_slide_title' => 'First slide']]);

    acf_remove_local_field_group('group_posts_endpoint');
})->skip(
    acfEdition() !== 'pro',
    'repeater is an ACF PRO field type; this run loaded the free copy (see AcfEditionGuardTest).'
);

it('serves non-publish statuses by default, and narrows on request', function (): void {
    // A migration has to carry drafts and private pages — they are content
    // someone wrote. A `publish` default would also serve an empty collection
    // for `attachment`, whose records only ever hold `inherit`.
    $draft = makePost(['post_status' => 'draft']);
    $private = makePost(['post_status' => 'private']);
    $published = makePost(['post_status' => 'publish']);

    expect(postsIds(postsGet('/posts/post')))->toContain($draft, $private, $published)
        ->and(postsIds(postsGet('/posts/post', ['status' => ['draft']])))->toContain($draft)
        ->and(postsIds(postsGet('/posts/post', ['status' => ['draft']])))->not->toContain($published);
});

it('filters on `modified_after` against the GMT column', function (): void {
    // Local time would shift the window by the site's offset, and an
    // incremental re-pull that silently skips an hour of edits is precisely
    // what this parameter exists to prevent.
    $old = makePost(['post_date_gmt' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00']);
    $new = makePost(['post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00']);

    $ids = postsIds(postsGet('/posts/post', ['modified_after' => '2023-06-01 00:00:00']));

    expect($ids)->toContain($new)->and($ids)->not->toContain($old);
});

it('limits to the requested ids', function (): void {
    $wanted = makePost();
    $other = makePost();

    $ids = postsIds(postsGet('/posts/post', ['include' => [$wanted]]));

    expect($ids)->toBe([$wanted])->and($ids)->not->toContain($other);
});

it('orders by ID ascending and never skips or repeats across a page boundary', function (): void {
    // D-006. A migration pages through a live site while editors work, so date
    // ordering lets a record move between pages and be read twice or never —
    // and a skipped record produces no error anywhere.
    $created = [];

    for ($i = 0; $i < 5; $i++) {
        $created[] = makePost();
    }

    sort($created);

    $first = postsIds(postsGet('/posts/post', ['per_page' => 2, 'page' => 1, 'include' => $created]));
    $second = postsIds(postsGet('/posts/post', ['per_page' => 2, 'page' => 2, 'include' => $created]));
    $third = postsIds(postsGet('/posts/post', ['per_page' => 2, 'page' => 3, 'include' => $created]));

    $walked = array_merge($first, $second, $third);

    expect($walked)->toBe($created)
        ->and($walked)->toHaveCount(\count(array_unique($walked)));
});

it('stamps a total that describes the filtered set, not the whole type', function (): void {
    // The trap: `wp_count_posts()` ignores `include` and `modified_after`, so a
    // total taken from it would disagree with the page it is describing — and a
    // consumer paging to that total would request pages that do not exist.
    $created = [makePost(), makePost(), makePost()];

    makePost();
    makePost();

    $response = postsGet('/posts/post', ['per_page' => 2, 'include' => $created]);

    expect(headers($response)['X-WP-Total'])->toBe('3')
        ->and(headers($response)['X-WP-TotalPages'])->toBe('2');
});

it('serves never-published records on every `modified_after` pull', function (): void {
    /*
     * WordPress writes `0000-00-00 00:00:00` into the GMT columns of a post
     * that never had a publish time. `post_modified_gmt > <bound>` is false
     * against that sentinel for every possible bound, so a single `after`
     * clause dropped those records from incremental re-pulls permanently —
     * not late, never. 45 of this development install's 1,453 rows are in
     * that state, and the `wordpress-importer` side had to warn its own
     * operators about it in the CLI help before this was fixed here.
     */
    global $wpdb;

    assert($wpdb instanceof wpdb, 'the WordPress database handle was not available');

    $neverPublished = makePost(['post_status' => 'draft']);
    $wpdb->update(
        $wpdb->posts,
        ['post_date_gmt' => '0000-00-00 00:00:00', 'post_modified_gmt' => '0000-00-00 00:00:00'],
        ['ID' => $neverPublished]
    );
    clean_post_cache($neverPublished);

    $stale = makePost(['post_date_gmt' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00']);

    $ids = postsIds(postsGet('/posts/post', ['modified_after' => '2023-06-01 00:00:00']));

    expect($ids)->toContain($neverPublished);
    // `in_array()` rather than `not->toContain($stale, $message)`: `toContain`
    // is variadic, so a message in that position becomes a second expected
    // value and silently inverts what the assertion checks.
    expect(\in_array($stale, $ids, true))->toBeFalse('a record modified in 2020 came back for a 2023 bound');
});

it('does not skip a day of edits when `modified_after` carries no time', function (): void {
    // `inclusive => false` sets `WP_Date_Query`'s `$default_to_max`, so a bare
    // date compiled to `> '<date> 23:59:59'` and a consumer passing the date of
    // its last pull lost everything edited that day.
    $sameDay = makePost(['post_date_gmt' => '2026-03-05 09:00:00', 'post_modified_gmt' => '2026-03-05 09:00:00']);

    // The bound is the SAME day the record was modified, which is the case a
    // consumer actually hits — it passes the date of its last pull. A previous
    // day's bound would pass with or without the fix and prove nothing.
    $ids = postsIds(postsGet('/posts/post', ['modified_after' => '2026-03-05']));

    expect($ids)->toContain($sameDay);
});

it('does not shift a zone-qualified `modified_after` by the site offset', function (): void {
    /*
     * The site needs a non-zero UTC offset or this proves nothing: the bug is
     * that `WP_Date_Query` re-renders the resolved instant in SITE-LOCAL time
     * and compares it against the GMT column, and at `gmt_offset = 0` the
     * broken path and the correct one agree exactly.
     */
    $originalOffset = get_option('gmt_offset');
    $originalZone = get_option('timezone_string');

    update_option('timezone_string', '');
    update_option('gmt_offset', -5);

    try {
        $earlier = makePost(['post_date_gmt' => '2026-03-05 09:00:00', 'post_modified_gmt' => '2026-03-05 09:00:00']);
        $later = makePost(['post_date_gmt' => '2026-03-05 15:00:00', 'post_modified_gmt' => '2026-03-05 15:00:00']);

        $ids = postsIds(postsGet('/posts/post', ['modified_after' => '2026-03-05T12:00:00+00:00']));

        expect($ids)->toContain($later);
        expect(\in_array($earlier, $ids, true))->toBeFalse('a 12:00 GMT bound served a record modified at 09:00 GMT — the window was shifted by the site offset');
    } finally {
        update_option('gmt_offset', $originalOffset);
        update_option('timezone_string', $originalZone);
    }
});

it('rejects a `modified_after` it cannot read rather than serving everything', function (): void {
    // Dropping an unreadable filter would answer `200` with the whole
    // collection, which is safe for completeness and badly misleading about
    // what was asked for.
    makePost();

    expect(postsGet('/posts/post', ['modified_after' => 'last tuesday-ish'])->get_status())->toBe(400);
});

it('serves nothing for an `include` that resolves to no usable id', function (): void {
    // `intList()` drops anything not a positive integer, so `include[]=0`
    // reduced to `[]` and the filter was not applied at all — the caller asked
    // for one record and got every record, with a `200`.
    $other = makePost();

    $ids = postsIds(postsGet('/posts/post', ['include' => [0]]));

    expect($ids)->toBe([]);
    expect(\in_array($other, $ids, true))->toBeFalse('an unresolvable `include` served the whole post type');
});

it('answers an out-of-range `page` with an empty collection, not a 500', function (): void {
    // `($page - 1) * $perPage` left the integer range and `strict_types` turned
    // the return into a `TypeError`.
    makePost();

    $response = postsGet('/posts/post', ['page' => (string) PHP_INT_MAX]);

    expect($response->get_status())->toBe(200)->and(postsIds($response))->toBe([]);
});

it('counts never-published records in the total it stamps on a `modified_after` page', function (): void {
    /*
     * `countMatching()` runs a SECOND `WP_Query`, so the never-published clause
     * has to reach it too. It does — the count is built from the page's own
     * argument array — and this pins that, because the failure would be a
     * consumer paging to `X-WP-Total` and stopping short of records that were
     * served. Stopping early is indistinguishable from finishing.
     */
    global $wpdb;

    assert($wpdb instanceof wpdb, 'the WordPress database handle was not available');

    for ($i = 0; $i < 2; $i++) {
        $id = makePost(['post_status' => 'draft']);
        $wpdb->update(
            $wpdb->posts,
            ['post_date_gmt' => '0000-00-00 00:00:00', 'post_modified_gmt' => '0000-00-00 00:00:00'],
            ['ID' => $id]
        );
        clean_post_cache($id);
    }

    makePost(['post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00']);
    makePost(['post_date_gmt' => '2026-01-02 00:00:00', 'post_modified_gmt' => '2026-01-02 00:00:00']);
    makePost(['post_date_gmt' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00']);

    $response = postsGet('/posts/post', ['modified_after' => '2023-06-01 00:00:00', 'per_page' => 2]);

    // Two never-published plus two genuinely newer; the 2020 record is excluded.
    expect($response->get_headers()['X-WP-Total'] ?? null)->toBe('4');
    expect(postsIds($response))->toHaveCount(2);

    // And walking to the stamped total really does yield all four.
    $walked = [];

    foreach ([1, 2] as $page) {
        $walked = array_merge($walked, postsIds(postsGet('/posts/post', [
            'modified_after' => '2023-06-01 00:00:00',
            'per_page' => 2,
            'page' => $page,
        ])));
    }

    expect($walked)->toHaveCount(4)->and(array_unique($walked))->toHaveCount(4);
});

it('does not move a `modified_after` bound that lands in a daylight-saving gap', function (): void {
    /*
     * A NAMED zone, not an offset — this is the whole test. `WP_Date_Query`
     * round-trips a string bound through `wp_timezone()`, which is the identity
     * everywhere except inside a spring-forward gap, where the wall clock does
     * not exist and PHP rolls it forward an hour. A fixed `gmt_offset` has no
     * gap, so the offset-based test above cannot see this and stayed green
     * while a bound was silently moved an hour LATER — records dropped, which
     * is the one direction this parameter must never fail in.
     *
     * America/Chicago springs forward on 2026-03-08: local 02:00–02:59 is
     * skipped. This install really is set to that zone.
     */
    $originalZone = get_option('timezone_string');
    $originalOffset = get_option('gmt_offset');

    update_option('timezone_string', 'America/Chicago');

    try {
        $inTheGap = makePost(['post_date_gmt' => '2026-03-08 03:00:00', 'post_modified_gmt' => '2026-03-08 03:00:00']);
        $before = makePost(['post_date_gmt' => '2026-03-08 02:00:00', 'post_modified_gmt' => '2026-03-08 02:00:00']);

        // 02:30 GMT. The record at 03:00 GMT is after it and must be served;
        // with the bound shifted to 03:30 it silently was not.
        $ids = postsIds(postsGet('/posts/post', ['modified_after' => '2026-03-08T02:30:00Z']));

        expect($ids)->toContain($inTheGap);
        expect(\in_array($before, $ids, true))->toBeFalse('a 02:30 GMT bound served a record modified at 02:00 GMT');
    } finally {
        update_option('timezone_string', $originalZone);
        update_option('gmt_offset', $originalOffset);
    }
});

it('refuses timestamp-shaped-but-not strings instead of inventing a bound from them', function (): void {
    /*
     * Every one of these was previously accepted, because the raw string went
     * to `DateTimeImmutable`, which accepts everything `strtotime()` does. Each
     * produced a `200` with a self-consistent `X-WP-Total` and almost no
     * records — well-formed, and wrong.
     *
     * `2026` is the worst of them: a valid ISO 8601 reduced-precision year, a
     * format the parameter description advertises, which PHP reads as the CLOCK
     * TIME 20:26 today.
     */
    makePost();

    $notTimestamps = [
        '2026',                 // ISO year -> read as 20:26 today
        'now',
        'tomorrow',
        '+1 week',
        'Thursday',             // -> next Thursday
        '12:00:00',             // -> today at noon
        '@1700000000',          // unix epoch syntax
        '2026-02-30',           // shaped right, not a real date
        '2026-08-01 25:00:00',  // shaped right, not a real time
        'last tuesday-ish',
    ];

    foreach ($notTimestamps as $value) {
        expect(postsGet('/posts/post', ['modified_after' => $value])->get_status())
            ->toBe(400, sprintf('`%s` was accepted as a timestamp', $value));
    }

    // And the shapes that ARE timestamps still work.
    foreach (['2026-08-01', '2026-08-01 12:00:00', '2026-08-01T12:00:00Z', '2026-08-01T12:00:00-05:00'] as $value) {
        expect(postsGet('/posts/post', ['modified_after' => $value])->get_status())
            ->toBe(200, sprintf('`%s` was rejected but is a timestamp', $value));
    }
});

it('serves nothing for an `include` that WordPress sanitized to an empty list', function (): void {
    /*
     * `?include=`, `?include=,` and `?include=%20` are all sanitized to `[]`
     * against the registered `array` schema BEFORE the controller runs, so a
     * value test against `[]` cannot tell them from "never sent" — which is how
     * the first version of this fix still served the whole post type. Only
     * `has_param()` distinguishes them.
     */
    $other = makePost();

    foreach (['', ',', ' '] as $raw) {
        $ids = postsIds(postsGet('/posts/post', ['include' => $raw]));

        expect(\in_array($other, $ids, true))->toBeFalse(sprintf('`include=%s` served the whole post type', $raw));
    }
});

it('answers an out-of-range `page` at `per_page=1` with an empty collection, not a 500', function (): void {
    // `per_page=1` is the case the first clamp missed: `intdiv(PHP_INT_MAX, 1)
    // + 1` is itself outside the integer range, so the saturation bound became
    // a float and `offset()` threw the TypeError it was rewritten to prevent.
    makePost();

    $response = postsGet('/posts/post', ['page' => (string) PHP_INT_MAX, 'per_page' => 1]);

    expect($response->get_status())->toBe(200)->and(postsIds($response))->toBe([]);
});

it('keeps `include` free of a registered default, which `has_param()` would see', function (): void {
    /*
     * A guard on a coupling that is invisible from the line it protects.
     *
     * The collection uses `$request->has_param('include')` to tell "asked for
     * nothing" from "did not ask". `has_param()` walks
     * `WP_REST_Request::get_parameter_order()`, and that order ENDS WITH
     * `'defaults'` — the bag `WP_REST_Server` fills from every registered arg
     * carrying an explicit `default` key.
     *
     * So giving `include` a `'default' => []` would make `has_param()` true on
     * every request, `intList()` would return `[]`, and `post__in` would become
     * `[0]` — every collection on every post type would serve ZERO records,
     * with a 200 and no error anywhere. It is a one-word edit that looks like
     * tidying up, and this is the only thing that would catch it.
     */
    $routes = rest_get_server()->get_routes();
    $route = $routes['/uamswp-migration/v1/posts/(?P<type>[a-zA-Z0-9_-]+)'] ?? null;

    expect($route)->not->toBeNull();
    assert(is_array($route));

    $handler = $route[0] ?? null;

    assert(is_array($handler), 'the posts collection route has no handler');

    $args = $handler['args'] ?? [];

    assert(is_array($args), 'the posts collection handler registers no args');

    expect($args)->toHaveKey('include');
    expect(array_key_exists('default', (array) $args['include']))->toBeFalse(
        '`include` gained a registered default. `has_param()` reads the defaults bag, so this '
        .'makes every collection request serve zero records. Use a different signal for '
        .'"the caller asked" before adding one.'
    );

    // The behavior that depends on it, asserted directly rather than inferred.
    $post = makePost();

    expect(postsIds(postsGet('/posts/post')))->toContain($post);
});

it('accepts a timestamp that omits seconds or carries fractional ones', function (): void {
    /*
     * Two regressions the enumerated pattern introduced, both of which the
     * previous commit accepted.
     *
     * `2026-08-01T12:34Z` was a 400 while `2026-08-01T12:34` — the same string
     * without the `Z` that says it is GMT — was a 200. PCRE fills a skipped
     * INTERMEDIATE group with `''` rather than leaving it absent, so
     * `$matches[6] ?? '00'` did not fire and `sprintf()` built the malformed
     * `2026-08-01T12:34:Z`.
     *
     * `2026-08-01T12:00:00.000Z` is what `Date.toISOString()`, Carbon and
     * `DATE_RFC3339_EXTENDED` emit, so rejecting it rejects the default output
     * of the most likely callers.
     */
    $accepted = [
        '2026-08-01T12:34Z',
        '2026-08-01T12:34z',
        '2026-08-01T12:34',
        '2026-08-01T12:34+05:00',
        '2026-08-01T12:34-0530',
        '2026-08-01 12:34Z',
        '2026-08-01T12:00:00.000Z',
        '2026-08-01T12:00:00.5-05:00',
    ];

    foreach ($accepted as $value) {
        expect(postsGet('/posts/post', ['modified_after' => $value])->get_status())
            ->toBe(200, sprintf('`%s` is a timestamp and was rejected', $value));
    }

    // And the same instant written two ways selects the same records — the
    // status alone would pass even if the bound were garbage.
    $old = makePost(['post_date_gmt' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00']);
    $new = makePost(['post_date_gmt' => '2026-08-01 13:00:00', 'post_modified_gmt' => '2026-08-01 13:00:00']);

    $minutePrecision = postsIds(postsGet('/posts/post', ['modified_after' => '2026-08-01T12:34Z']));
    $secondPrecision = postsIds(postsGet('/posts/post', ['modified_after' => '2026-08-01T12:34:00Z']));

    expect($minutePrecision)->toBe($secondPrecision)
        ->and($minutePrecision)->toContain($new);
    expect(\in_array($old, $minutePrecision, true))->toBeFalse('a 2026 bound served a 2020 record');
});

it('exposes no post type whose own populated taxonomies are unexposed', function (): void {
    /*
     * `PostPayload::terms()` intersects a record's taxonomies with the
     * CONFIGURED list, so exposing a post type without its taxonomies serves
     * every record with an empty `terms` object and its classification silently
     * gone — a 200 that has quietly dropped data.
     *
     * That is not hypothetical: `logo-list-item` was added to `post_types` one
     * commit before this test, and all three of its records carry a
     * `logo-list-category` term that would have vanished.
     *
     * The exclusions below are taxonomies deliberately not carried. Each is
     * editorial or theme plumbing rather than content, and each is named so the
     * list is a decision rather than an oversight. A NEW pairing that is
     * neither configured nor excluded reds this.
     *
     * **What this test cannot see, stated rather than implied.** It reads
     * REGISTRATION in the running process, so a post type whose plugin is not
     * active here is skipped entirely — on this suite that includes `location`
     * and `provider`, the two Find-a-Doc types with the most taxonomies. A
     * live-install sweep found real assignments in unexposed taxonomies on
     * both (`region`, `location_type`, `gmb_cat_location`, `gmb_cat_provider`),
     * which this test structurally cannot reach. That gap is #12 territory and
     * is tracked separately; do not read a green here as "no terms are being
     * dropped anywhere".
     *
     * It deliberately fires on REGISTRATION rather than on assignments having
     * been made. Counting assignments would be more precise and would pass
     * vacuously — the Feature suite runs against a rebuilt database with no
     * term data at all, so the precise version would be green forever.
     */
    $notContent = [
        'following_users',   // Edit Flow subscription state, not classification
        'ef_usergroup',      // Edit Flow user groups
        'ef_editorial_meta', // Edit Flow editorial metadata
        'post_status',       // Edit Flow custom statuses
        'post_format',       // core, and carried on the record itself
        'content_audit',     // editorial workflow
        'wp_theme',            // block-theme association for wp_global_styles
        'frm_application',     // Formidable's grouping; forms are not API-sourced (D-050)
        'wp_pattern_category', // block-pattern grouping for wp_block
    ];

    /** @var array<string, mixed> $config */
    $config = require \dirname(__DIR__, 3).'/config/defaults.php';

    $postTypes = $config['post_types'] ?? [];
    $taxonomies = $config['taxonomies'] ?? [];

    assert(is_array($postTypes) && is_array($taxonomies));

    $unexposed = [];

    foreach ($postTypes as $type) {
        if (! is_string($type)) {
            continue;
        }

        if (! post_type_exists($type)) {
            continue;
        }

        foreach (get_object_taxonomies($type) as $taxonomy) {
            if (\in_array($taxonomy, $taxonomies, true)) {
                continue;
            }

            if (\in_array($taxonomy, $notContent, true)) {
                continue;
            }

            $unexposed[] = $type.' -> '.$taxonomy;
        }
    }

    expect($unexposed)->toBe([], sprintf(
        'These exposed post types have taxonomies that are neither exposed nor listed as '
        .'deliberately excluded, so any term assigned in them is dropped from the payload '
        ."with no error:\n  %s\nAdd each to `taxonomies` in config/defaults.php, or to the "
        .'exclusion list in this test with a reason.',
        implode("\n  ", $unexposed)
    ));
});

it('keeps the two taxonomy exclusion lists in step with each other', function (): void {
    /*
     * A coupling with nothing else holding it together.
     *
     * The guard above asserts REGISTRATION and runs on CI;
     * `scripts/verify-taxonomy-coverage.php` asserts ASSIGNMENTS and needs a
     * real install. They answer different halves of #73, and each carries its
     * own list of taxonomies deliberately not carried. A taxonomy excused in
     * one but not the other means one of them reports a problem the other
     * calls fine — and since the script is the half nobody runs on every
     * commit, the drift would surface as a confusing failure months later.
     */
    $script = file_get_contents(\dirname(__DIR__, 3).'/scripts/verify-taxonomy-coverage.php');

    assert(is_string($script), 'the taxonomy coverage script could not be read');

    $start = strpos($script, 'const NOT_CONTENT = [');

    expect($start)->not->toBeFalse('the script no longer declares NOT_CONTENT');

    assert(\is_int($start));

    $end = strpos($script, '];', $start);

    assert(\is_int($end));

    preg_match_all("/'([a-z_]+)' =>/", substr($script, $start, $end - $start), $found);

    $inScript = $found[1];

    sort($inScript);

    // Rebuilt from the same source the guard above uses, so this compares the
    // two lists rather than a list against a copy of itself.
    $inTest = [
        'following_users', 'ef_usergroup', 'ef_editorial_meta', 'post_status',
        'post_format', 'content_audit', 'wp_theme', 'frm_application', 'wp_pattern_category',
    ];

    sort($inTest);

    expect($inScript)->toBe(
        $inTest,
        'The exclusion lists in tests/Integration/Feature/PostsEndpointTest.php and '
        .'scripts/verify-taxonomy-coverage.php have drifted. A taxonomy excused in one '
        .'must be excused in the other, or the two halves of #73 disagree.'
    );
});
