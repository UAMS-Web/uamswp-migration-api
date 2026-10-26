<?php

declare(strict_types=1);

use UAMSWP\MigrationAPI\ACF\FieldReader;

/*
 * The pre-6.4 fallback behind `FieldReader::storedMetadata()` (#22, D-069,
 * D-107), on real ACF and real fixtures.
 *
 * Below ACF 6.4 the reader calls `acf_get_metadata()` in place of
 * `acf_get_metadata_by_field()`, and the change rests on one claim: the two
 * give the same answer at that call site, which asks only whether a row
 * exists. Every comparison below runs both functions for real on the same data,
 * for each of the three object shapes the reader passes, so a divergence reds
 * this file.
 *
 * Which arm the guard takes cannot be driven from here: ACF is loaded before
 * any test runs, so `function_exists()` is fixed for the whole process. That
 * arm selection is `tests/Unit/AcfMetadataGuardTest.php`, which runs each arm
 * in a child process with no ACF at all.
 *
 * Where this ACF is itself below 6.4 there is nothing to compare against, so
 * the comparisons skip and the end-to-end test is the one that matters: the
 * fallback is then the arm the reader actually takes. The comparison probe
 * calls `acf_get_metadata_by_field()` only from tests that skip without it,
 * because a file testing a version guard must not be unguarded against that
 * version itself.
 *
 * Custom-table storage, the case the fallback most needs to get right, is in
 * `CustomTableDivergenceTest.php`, beside the fixture it needs.
 */

/**
 * Register a field group for the duration of one test.
 *
 * @param  list<array<string, mixed>>  $fields
 * @param  array<string, string>  $location  One location condition: `param`, `operator`, `value`.
 */
function fallbackFieldGroup(string $key, array $fields, array $location): void
{
    acf_add_local_field_group([
        'key' => $key,
        'title' => 'Fallback fixture '.$key,
        'fields' => $fields,
        'location' => [[$location]],
    ]);
}

/**
 * Whether this ACF carries the 6.4 API the fallback stands in for.
 *
 * `acf_get_metadata()` is not checked: it is `@since 5.2.3`, and a skip that
 * could silently disable this file on a missing fallback target would hide the
 * one case worth failing on.
 */
function fallbackHasNativeProbe(): bool
{
    return \function_exists('acf_get_metadata_by_field');
}

/**
 * Both probes, for one field on one object, reduced to the question the call
 * site asks.
 *
 * Only called from tests that skip when the native probe is absent.
 *
 * @param  array<string, mixed>  $field
 * @return array{new: bool, old: bool}
 */
function fallbackProbes(int|string $objectId, array $field): array
{
    $name = $field['name'];

    assert(is_string($name));

    return [
        'new' => acf_get_metadata_by_field($objectId, $field) !== null,
        'old' => acf_get_metadata($objectId, $name) !== null,
    ];
}

afterEach(function (): void {
    foreach (['group_fallback_post', 'group_fallback_term', 'group_fallback_option'] as $key) {
        acf_remove_local_field_group($key);
    }

    FieldReader::resetValueStore();
});

it('has the fallback target on every ACF this plugin supports', function (): void {
    // Without it the guard would fall back to nothing. Whether
    // `acf_get_metadata_by_field()` exists is deliberately not asserted: an
    // ACF without it is a supported install, and the skips below report it.
    expect(\function_exists('acf_get_metadata'))->toBeTrue();
});

it('agrees with the 6.4 probe on a post, stored and unset alike', function (): void {
    $field = ['key' => 'field_fallback_post', 'name' => 'fallback_post', 'type' => 'text', 'label' => 'Post'];
    fallbackFieldGroup('group_fallback_post', [$field], ['param' => 'post_type', 'operator' => '==', 'value' => 'post']);

    $stored = makePost();
    update_post_meta($stored, 'fallback_post', 'Neurology Clinic');
    update_post_meta($stored, '_fallback_post', 'field_fallback_post');

    $unset = makePost();

    $onStored = fallbackProbes($stored, $field);
    $onUnset = fallbackProbes($unset, $field);

    // Both a row and its absence: a fallback answering "stored" everywhere
    // would pass a one-sided check and move every defaulted field.
    expect($onStored['new'])->toBeTrue()
        ->and($onStored['old'])->toBe($onStored['new'])
        ->and($onUnset['new'])->toBeFalse()
        ->and($onUnset['old'])->toBe($onUnset['new']);
})->skip(
    ! fallbackHasNativeProbe(),
    'ACF is below 6.4, so there is no `acf_get_metadata_by_field()` to compare the fallback against; the end-to-end test below covers the arm taken here.'
);

it('agrees with the 6.4 probe on a term, the second shape the reader passes', function (): void {
    // `'term_<id>'` decodes to type `term`, which goes through the base
    // `MetaLocation::get_value()` rather than an override.
    $field = ['key' => 'field_fallback_term', 'name' => 'fallback_term', 'type' => 'text', 'label' => 'Term'];
    fallbackFieldGroup('group_fallback_term', [$field], ['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']);

    $termId = makeTerm(['taxonomy' => 'category']);
    update_term_meta($termId, 'fallback_term', 'Little Rock');
    update_term_meta($termId, '_fallback_term', 'field_fallback_term');

    $unset = makeTerm(['taxonomy' => 'category']);

    $onStored = fallbackProbes('term_'.$termId, $field);
    $onUnset = fallbackProbes('term_'.$unset, $field);

    expect($onStored['new'])->toBeTrue()
        ->and($onStored['old'])->toBe($onStored['new'])
        ->and($onUnset['new'])->toBeFalse()
        ->and($onUnset['old'])->toBe($onUnset['new']);
})->skip(
    ! fallbackHasNativeProbe(),
    'ACF is below 6.4; nothing to compare against, and the end-to-end test below covers the arm taken here.'
);

it('agrees with the 6.4 probe on options, the shape with the one shipped override', function (): void {
    // `'options'` decodes to type `option`, the only location with its own
    // `get_value()`, so the one place the two functions could have drifted.
    $field = ['key' => 'field_fallback_option', 'name' => 'fallback_option', 'type' => 'text', 'label' => 'Option'];
    fallbackFieldGroup('group_fallback_option', [$field], ['param' => 'options_page', 'operator' => '==', 'value' => 'acf-options']);

    $absent = fallbackProbes('options', $field);

    update_option('options_fallback_option', 'Radiology');

    $present = fallbackProbes('options', $field);

    expect($absent['new'])->toBeFalse()
        ->and($absent['old'])->toBe($absent['new'])
        ->and($present['new'])->toBeTrue()
        ->and($present['old'])->toBe($present['new']);

    delete_option('options_fallback_option');
})->skip(
    ! fallbackHasNativeProbe(),
    'ACF is below 6.4; nothing to compare against, and the end-to-end test below covers the arm taken here.'
);

it('still separates a stored value from a supplied default end to end', function (): void {
    // The behavior the guard must not change, through the public reader: a
    // field with a row is in the stored view, a field with only a
    // `default_value` is in the defaulted view.
    fallbackFieldGroup('group_fallback_post', [
        ['key' => 'field_fallback_post', 'name' => 'fallback_post', 'type' => 'text', 'label' => 'Post'],
        ['key' => 'field_fallback_default', 'name' => 'fallback_default', 'type' => 'text', 'label' => 'Default', 'default_value' => 'Supplied'],
    ], ['param' => 'post_type', 'operator' => '==', 'value' => 'post']);

    $postId = makePost();
    update_post_meta($postId, 'fallback_post', 'Neurology Clinic');
    update_post_meta($postId, '_fallback_post', 'field_fallback_post');

    $read = FieldReader::forPost($postId);

    expect($read['names']['fallback_post'])->toBe('Neurology Clinic')
        ->and($read['defaulted_names'])->toHaveKey('fallback_default')
        ->and($read['defaulted_names']['fallback_default'])->toBe('Supplied')
        ->and($read['defaulted_names'])->not->toHaveKey('fallback_post');
});
