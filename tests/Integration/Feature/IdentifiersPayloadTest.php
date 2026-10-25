<?php

declare(strict_types=1);

use UAMSWP\MigrationAPI\ACF\FieldReader;

/*
 * The `identifiers` block (#88, D-104), dispatched through `rest_do_request()`
 * so the record is the one a consumer receives.
 *
 * Neither People Showcase nor Find-a-Doc is loaded by the suite, so each test
 * registers the post type it needs, and the ACF tests register the upstream
 * field definitions themselves. Values are written as postmeta, the shape ACF
 * and People Showcase actually store, for the reason `FieldReaderTest` gives.
 *
 * The `_ps` keys are People Showcase's own meta keys, so they are names
 * rather than prose. cspell:ignore psworkdayuniversalid psworkdayemployeeid psworkdaycontingentworkerid psworkdaystudentid
 */

/**
 * Dispatch a GET for one record, authenticated.
 */
function identifiersRecord(string $type, int $id): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request('GET', "/uamswp-migration/v1/posts/{$type}/{$id}"));
}

/**
 * The post types this file registered, so only those are unregistered.
 *
 * @param  string|null  $add  A post type to add to the list.
 * @param  bool  $reset  Empty the list after returning what it held.
 * @return list<string>
 */
function identifiersRegistered(?string $add = null, bool $reset = false): array
{
    static $registered = [];

    if ($add !== null) {
        $registered[] = $add;
    }

    $held = $registered;

    if ($reset) {
        $registered = [];
    }

    return $held;
}

/**
 * Register a post type for one test, unless something already registered it.
 *
 * @param  lowercase-string&non-empty-string  $type
 */
function identifiersPostType(string $type): void
{
    if (! post_type_exists($type)) {
        register_post_type($type, ['public' => true]);
        identifiersRegistered($type);
    }
}

/**
 * Register a field group on one post type for the duration of one test.
 *
 * @param  list<array<string, mixed>>  $fields
 */
function identifiersFieldGroup(string $key, string $type, array $fields): void
{
    acf_add_local_field_group([
        'key' => $key,
        'title' => 'Identifiers fixture '.$key,
        'fields' => $fields,
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => $type]]],
    ]);
}

/**
 * Write one ACF value the way ACF writes it.
 */
function identifiersStore(int $postId, string $name, string $key, mixed $value): void
{
    update_post_meta($postId, $name, $value);
    update_post_meta($postId, '_'.$name, $key);
}

/**
 * The Find-a-Doc `provider` Workday fields, as `group_uamswp_physicians.json` on
 * `UAMS-Web/UAMSWP-Find-a-Doc` `main` defines them (read 2026-10-25; the copy
 * installed on the development network predates them).
 */
function identifiersProviderGroup(): void
{
    $fields = [];

    foreach (['universal', 'employee', 'contingent_worker', 'student'] as $kind) {
        $fields[] = [
            'key' => "field_physician_workday_{$kind}_id",
            'name' => "physician_workday_{$kind}_id",
            'type' => 'text',
            'label' => $kind,
        ];
    }

    identifiersFieldGroup('group_identifiers_provider', 'provider', $fields);
}

/**
 * The Find-a-Doc `location` DEP ID repeater, as `group_uamswp_locations.json` on
 * `UAMS-Web/UAMSWP-Find-a-Doc` `main` defines it (read 2026-10-25).
 */
function identifiersLocationGroup(): void
{
    identifiersFieldGroup('group_identifiers_location', 'location', [
        [
            'key' => 'field_location_epic_dep_ids',
            'name' => 'location_epic_dep_ids',
            'type' => 'repeater',
            'label' => 'Epic DEP IDs',
            'sub_fields' => [
                ['key' => 'field_location_epic_dep_id_item', 'name' => 'location_epic_dep_id_item', 'type' => 'text', 'label' => 'DEP ID'],
            ],
        ],
    ]);
}

/**
 * Store a location's DEP ID rows, the way ACF stores a repeater.
 *
 * @param  list<string>  $ids
 */
function identifiersStoreDepIds(int $postId, array $ids): void
{
    identifiersStore($postId, 'location_epic_dep_ids', 'field_location_epic_dep_ids', (string) count($ids));

    foreach ($ids as $index => $id) {
        identifiersStore($postId, "location_epic_dep_ids_{$index}_location_epic_dep_id_item", 'field_location_epic_dep_id_item', $id);
    }
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

afterEach(function (): void {
    foreach (['group_identifiers_provider', 'group_identifiers_location'] as $key) {
        acf_remove_local_field_group($key);
    }

    foreach (identifiersRegistered(reset: true) as $type) {
        unregister_post_type($type);
    }

    FieldReader::resetValueStore();
});

it('serves the four People Showcase Workday identifiers verbatim from postmeta', function (): void {
    identifiersPostType('uamspeople');
    $id = makePost(['post_type' => 'uamspeople']);

    update_post_meta($id, '_psworkdayuniversalid', '000123');
    update_post_meta($id, '_psworkdayemployeeid', 'E-00042');
    update_post_meta($id, '_psworkdaycontingentworkerid', ' 77 ');
    update_post_meta($id, '_psworkdaystudentid', '0');

    expect(payload(identifiersRecord('uamspeople', $id), 'identifiers'))->toBe([
        'workday_universal_id' => '000123',
        'workday_employee_id' => 'E-00042',
        'workday_contingent_worker_id' => ' 77 ',
        'workday_student_id' => '0',
    ]);
});

it('serves a person with no Workday identifiers, with every key present and empty', function (): void {
    identifiersPostType('uamspeople');
    $id = makePost(['post_type' => 'uamspeople']);
    update_post_meta($id, '_psworkdayemployeeid', '');

    $response = identifiersRecord('uamspeople', $id);

    expect($response->get_status())->toBe(200)
        ->and(payload($response, 'id'))->toBe($id)
        ->and(payload($response, 'identifiers'))->toBe([
            'workday_universal_id' => '',
            'workday_employee_id' => '',
            'workday_contingent_worker_id' => '',
            'workday_student_id' => '',
        ]);
});

it('serves the four Find-a-Doc provider Workday identifiers through ACF', function (): void {
    identifiersPostType('provider');
    identifiersProviderGroup();
    $id = makePost(['post_type' => 'provider']);

    identifiersStore($id, 'physician_workday_universal_id', 'field_physician_workday_universal_id', '0099');
    identifiersStore($id, 'physician_workday_employee_id', 'field_physician_workday_employee_id', 'EMP-7');
    identifiersStore($id, 'physician_workday_student_id', 'field_physician_workday_student_id', '1e3');

    expect(payload(identifiersRecord('provider', $id), 'identifiers'))->toBe([
        'workday_universal_id' => '0099',
        'workday_employee_id' => 'EMP-7',
        'workday_contingent_worker_id' => '',
        'workday_student_id' => '1e3',
    ]);
});

it('serves an integer a read filter returns as its digits, and a float as empty', function (): void {
    identifiersPostType('provider');
    identifiersProviderGroup();
    $id = makePost(['post_type' => 'provider']);

    identifiersStore($id, 'physician_workday_universal_id', 'field_physician_workday_universal_id', 'stored');
    identifiersStore($id, 'physician_workday_employee_id', 'field_physician_workday_employee_id', 'stored');

    $filter = static fn (mixed $value, mixed $postId, array $field): mixed => match ($field['name'] ?? null) {
        'physician_workday_universal_id' => 4021,
        'physician_workday_employee_id' => 1.5,
        default => $value,
    };
    add_filter('acf/load_value', $filter, 10, 3);

    try {
        expect(payload(identifiersRecord('provider', $id), 'identifiers'))->toBe([
            'workday_universal_id' => '4021',
            'workday_employee_id' => '',
            'workday_contingent_worker_id' => '',
            'workday_student_id' => '',
        ]);
    } finally {
        remove_filter('acf/load_value', $filter, 10);
    }
});

it('serves a provider with no Workday fields registered, with every key present and empty', function (): void {
    identifiersPostType('provider');
    $id = makePost(['post_type' => 'provider']);

    expect(payload(identifiersRecord('provider', $id), 'identifiers'))->toBe([
        'workday_universal_id' => '',
        'workday_employee_id' => '',
        'workday_contingent_worker_id' => '',
        'workday_student_id' => '',
    ]);
});

it("serves a location's DEP IDs as strings in repeater row order", function (): void {
    identifiersPostType('location');
    identifiersLocationGroup();
    $id = makePost(['post_type' => 'location']);
    identifiersStoreDepIds($id, ['00321', 'B-9', '17']);

    $record = identifiersRecord('location', $id);

    expect(payload($record, 'identifiers'))->toBe(['epic_dep_ids' => ['00321', 'B-9', '17']])
        // The raw view is untouched beside it.
        ->and(payload($record, 'acf', 'names', 'location_epic_dep_ids'))->toBe([
            ['location_epic_dep_id_item' => '00321'],
            ['location_epic_dep_id_item' => 'B-9'],
            ['location_epic_dep_id_item' => '17'],
        ]);
})->skip(
    acfEdition() !== 'pro',
    'repeater is an ACF PRO field type; this run loaded the free copy (see AcfEditionGuardTest).'
);

it('keeps a DEP ID row whose value is empty, so the list has one element per row', function (): void {
    identifiersPostType('location');
    identifiersLocationGroup();
    $id = makePost(['post_type' => 'location']);
    identifiersStoreDepIds($id, ['501', '', '502']);

    expect(payload(identifiersRecord('location', $id), 'identifiers'))->toBe(['epic_dep_ids' => ['501', '', '502']]);
})->skip(
    acfEdition() !== 'pro',
    'repeater is an ACF PRO field type; this run loaded the free copy (see AcfEditionGuardTest).'
);

it('serves a location with zero DEP rows as an empty list', function (): void {
    identifiersPostType('location');
    identifiersLocationGroup();
    $id = makePost(['post_type' => 'location']);
    identifiersStoreDepIds($id, []);

    $record = identifiersRecord('location', $id);

    expect(payload($record, 'identifiers'))->toBe(['epic_dep_ids' => []])
        // What the reader holds for zero rows, and why the block exists: not a list.
        ->and(payload($record, 'acf', 'names', 'location_epic_dep_ids'))->toBeFalse();
})->skip(
    acfEdition() !== 'pro',
    'repeater is an ACF PRO field type; this run loaded the free copy (see AcfEditionGuardTest).'
);

it('serves a location whose repeater was never saved as an empty list', function (): void {
    identifiersPostType('location');
    identifiersLocationGroup();
    $id = makePost(['post_type' => 'location']);

    expect(payload(identifiersRecord('location', $id), 'identifiers'))->toBe(['epic_dep_ids' => []]);
});

it('serves null for a post type with no identifiers configured', function (): void {
    $id = makePost();

    $record = identifiersRecord('post', $id)->get_data();

    expect($record)->toBeArray()->toHaveKey('identifiers')
        ->and(payload(identifiersRecord('post', $id), 'identifiers'))->toBeNull();
});
