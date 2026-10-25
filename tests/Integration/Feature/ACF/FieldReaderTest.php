<?php

declare(strict_types=1);

use UAMSWP\MigrationAPI\ACF\FieldReader;

/*
 * Exercises the reader against a real ACF, on a real post, through real
 * postmeta.
 *
 * Values are written as postmeta rather than through `update_field()`, and that
 * is deliberate on two counts. It is the shape ACF actually stores — the same
 * flat rows the `meta` payload carries — so the fixture states the contract
 * rather than trusting ACF's write path to agree with its read path. And a
 * field whose type does not resolve to an `acf_field` has no write path to
 * trust in the first place, which is the whole point of #21.
 */

/**
 * Register a field group for the duration of one test.
 *
 * @param  list<array<string, mixed>>  $fields
 */
function readerFieldGroup(string $key, array $fields): void
{
    acf_add_local_field_group([
        'key' => $key,
        'title' => 'Reader fixture '.$key,
        'fields' => $fields,
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    ]);
}

/**
 * Write one ACF value the way ACF writes it: the value, and the field-key
 * reference behind the underscore-prefixed twin.
 */
function readerStore(int $postId, string $name, string $key, mixed $value): void
{
    update_post_meta($postId, $name, $value);
    update_post_meta($postId, '_'.$name, $key);
}

afterEach(function (): void {
    foreach (['group_reader_flat', 'group_reader_group', 'group_reader_unresolved', 'group_reader_repeater', 'group_reader_collide_a', 'group_reader_collide_b', 'group_reader_stored_vs_default'] as $key) {
        acf_remove_local_field_group($key);
    }

    FieldReader::resetValueStore();
});

it('returns every value under both its field key and its field name', function (): void {
    // D-025. Keys are what `acf_get_value()` returns and are unique network-wide;
    // names are what the flat `meta` payload uses and what an importer maps.
    readerFieldGroup('group_reader_flat', [
        ['key' => 'field_reader_headline', 'name' => 'reader_headline', 'type' => 'text', 'label' => 'Headline'],
    ]);

    $postId = makePost();
    readerStore($postId, 'reader_headline', 'field_reader_headline', 'Neurology Clinic');

    $read = FieldReader::forPost($postId);

    expect($read['keys']['field_reader_headline'])->toBe('Neurology Clinic')
        ->and($read['names']['reader_headline'])->toBe('Neurology Clinic')
        ->and($read['collisions'])->toBe([]);
});

it('rekeys a container from field keys to field names, leaving the key view untouched', function (): void {
    // `acf_get_value()` returns container rows keyed by SUB-FIELD KEY — verified
    // against `location` #3552, whose repeater comes back as
    // `[{"field_location_appointments_text": "Physician Call Center", …}]`.
    // ACF's own `light` REST format does this rewrite and discards the keys.
    readerFieldGroup('group_reader_group', [
        [
            'key' => 'field_reader_contact',
            'name' => 'reader_contact',
            'type' => 'group',
            'label' => 'Contact',
            'sub_fields' => [
                ['key' => 'field_reader_contact_label', 'name' => 'reader_contact_label', 'type' => 'text', 'label' => 'Label'],
                ['key' => 'field_reader_contact_phone', 'name' => 'reader_contact_phone', 'type' => 'text', 'label' => 'Phone'],
            ],
        ],
    ]);

    $postId = makePost();
    readerStore($postId, 'reader_contact_reader_contact_label', 'field_reader_contact_label', 'Physician Call Center');
    readerStore($postId, 'reader_contact_reader_contact_phone', 'field_reader_contact_phone', '501-686-6080');

    $read = FieldReader::forPost($postId);

    expect($read['keys']['field_reader_contact'])->toBe([
        'field_reader_contact_label' => 'Physician Call Center',
        'field_reader_contact_phone' => '501-686-6080',
    ])->and($read['names']['reader_contact'])->toBe([
        'reader_contact_label' => 'Physician Call Center',
        'reader_contact_phone' => '501-686-6080',
    ]);
});

it('serves a field whose type resolves to a `stdClass` placeholder', function (): void {
    // The `maskfield` shape exactly. `acf_field::__construct()` registers a
    // placeholder for every type it constructs; the real instance only replaces
    // it when a plugin calls `acf_register_field_type()`, and `acf-maskfield`
    // never does. Reproduced here rather than depending on that plugin being
    // installed, so CI exercises the same hazard the network carries.
    acf_register_field_type_info(['name' => 'faux_masked', 'label' => 'Faux Masked']);

    // The fixture is worthless if it does not actually reproduce the shape.
    expect(acf_get_field_type('faux_masked'))->toBeInstanceOf(stdClass::class)
        ->and(acf_get_field_type('faux_masked'))->not->toBeInstanceOf(acf_field::class);

    readerFieldGroup('group_reader_unresolved', [
        ['key' => 'field_reader_masked', 'name' => 'reader_masked', 'type' => 'faux_masked', 'label' => 'Masked'],
        // The second shape, and a different one: an entirely unknown type name
        // resolves to `null`, not to a placeholder. `button` does this on the
        // live network. A guard written for either alone misses the other.
        ['key' => 'field_reader_unknown', 'name' => 'reader_unknown', 'type' => 'no_such_type_anywhere', 'label' => 'Unknown'],
    ]);

    expect(acf_get_field_type('no_such_type_anywhere'))->toBeNull();

    $postId = makePost();
    readerStore($postId, 'reader_masked', 'field_reader_masked', '501-686-6080');
    readerStore($postId, 'reader_unknown', 'field_reader_unknown', 'Book now');

    $read = FieldReader::forPost($postId);

    // Served, not skipped. ACF's own REST layer drops a top-level field whose
    // type has no truthy `show_in_rest`, which a placeholder never has — a
    // well-formed response missing a phone number, this project's defining
    // failure mode.
    expect($read['keys']['field_reader_masked'])->toBe('501-686-6080')
        ->and($read['names']['reader_masked'])->toBe('501-686-6080')
        ->and($read['keys']['field_reader_unknown'])->toBe('Book now')
        ->and($read['names']['reader_unknown'])->toBe('Book now');
});

it('names a field whose name collides across field groups rather than dropping it', function (): void {
    // Field names can collide across groups where keys cannot (D-025). A name
    // view that silently overwrites loses a value with nothing to see; the key
    // view stays lossless either way, so the collision is reported and the read
    // stays complete.
    readerFieldGroup('group_reader_collide_a', [
        ['key' => 'field_reader_dup_a', 'name' => 'reader_dup', 'type' => 'text', 'label' => 'Dup A'],
    ]);
    readerFieldGroup('group_reader_collide_b', [
        ['key' => 'field_reader_dup_b', 'name' => 'reader_dup', 'type' => 'text', 'label' => 'Dup B'],
    ]);

    $postId = makePost();
    readerStore($postId, 'reader_dup', 'field_reader_dup_a', 'first');

    $read = FieldReader::forPost($postId);

    expect($read['collisions'])->toBe(['reader_dup'])
        ->and($read['keys'])->toHaveKey('field_reader_dup_a')
        ->and($read['keys'])->toHaveKey('field_reader_dup_b');
});

it('empties the ACF value store after each record', function (): void {
    // Without this the store grows unbounded across a paginated read: measured
    // while building #7 at 42 entries after one post and 614 after twenty. ACF's
    // own REST layer resets per object for the same reason.
    readerFieldGroup('group_reader_flat', [
        ['key' => 'field_reader_headline', 'name' => 'reader_headline', 'type' => 'text', 'label' => 'Headline'],
    ]);

    $postId = makePost();
    readerStore($postId, 'reader_headline', 'field_reader_headline', 'Neurology Clinic');

    FieldReader::forPost($postId);

    expect(acf_get_store('values')->get_data())->toBe([]);
});

it('rekeys a repeater row, the container the fatal recurses through', function (): void {
    // The #21 shape itself: an unresolvable sub-field type inside a repeater.
    // `ACF_Rest_Api::get_fields()` filters TOP-LEVEL fields only, so a
    // placeholder type nested here is not dropped — it reaches
    // `acf_format_value_for_rest()` and raises
    // `Call to undefined method stdClass::format_value_for_rest()`.
    // The reader never asks the type for anything, so it just reads.
    acf_register_field_type_info(['name' => 'faux_masked', 'label' => 'Faux Masked']);

    readerFieldGroup('group_reader_repeater', [
        [
            'key' => 'field_reader_phones',
            'name' => 'reader_phones',
            'type' => 'repeater',
            'label' => 'Phones',
            'sub_fields' => [
                ['key' => 'field_reader_phone_text', 'name' => 'reader_phone_text', 'type' => 'text', 'label' => 'Text'],
                ['key' => 'field_reader_phone_number', 'name' => 'reader_phone_number', 'type' => 'faux_masked', 'label' => 'Number'],
            ],
        ],
    ]);

    $postId = makePost();
    // A repeater stores its ROW COUNT under its own name, with the rows in
    // numbered keys beneath it — which is why the flat `meta` payload alone is
    // not enough for a consumer (D-026).
    readerStore($postId, 'reader_phones', 'field_reader_phones', 1);
    readerStore($postId, 'reader_phones_0_reader_phone_text', 'field_reader_phone_text', 'Physician Call Center');
    readerStore($postId, 'reader_phones_0_reader_phone_number', 'field_reader_phone_number', '501-686-6080');

    $read = FieldReader::forPost($postId);

    expect($read['keys']['field_reader_phones'])->toBe([
        ['field_reader_phone_text' => 'Physician Call Center', 'field_reader_phone_number' => '501-686-6080'],
    ])->and($read['names']['reader_phones'])->toBe([
        ['reader_phone_text' => 'Physician Call Center', 'reader_phone_number' => '501-686-6080'],
    ]);
})->skip(
    acfEdition() !== 'pro',
    'repeater is an ACF PRO field type; this run loaded the free copy (see AcfEditionGuardTest).'
);

it('separates a stored value from a supplied default', function (): void {
    // The behavior `storedMetadata()` exists to preserve: a field with a row
    // lands in the stored view, a field with only a `default_value` lands in
    // the defaulted view. Getting this backwards is the well-formed-response-
    // full-of-wrong-data failure this repository is written against. Formerly
    // in AcfMetadataFallbackTest.php, migrated here by #175 when that file's
    // pre-6.4 fallback comparison it otherwise tested was removed.
    readerFieldGroup('group_reader_stored_vs_default', [
        ['key' => 'field_reader_stored', 'name' => 'reader_stored', 'type' => 'text', 'label' => 'Stored'],
        ['key' => 'field_reader_default', 'name' => 'reader_default', 'type' => 'text', 'label' => 'Default', 'default_value' => 'Supplied'],
    ]);

    $postId = makePost();
    readerStore($postId, 'reader_stored', 'field_reader_stored', 'Neurology Clinic');

    $read = FieldReader::forPost($postId);

    expect($read['names']['reader_stored'])->toBe('Neurology Clinic')
        ->and($read['defaulted_names'])->toHaveKey('reader_default')
        ->and($read['defaulted_names']['reader_default'])->toBe('Supplied')
        ->and($read['defaulted_names'])->not->toHaveKey('reader_stored');
});
