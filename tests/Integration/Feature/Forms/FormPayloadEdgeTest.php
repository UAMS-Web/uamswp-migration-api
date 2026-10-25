<?php

/**
 * The form payload builders, called directly with the inputs a route never sends.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Content\FormidablePayload;
use UAMSWP\MigrationAPI\Content\GravityFeedPayload;
use UAMSWP\MigrationAPI\Content\NinjaPayload;

/*
 * Pins the guards in `FormidablePayload`, `GravityFeedPayload` and
 * `NinjaPayload` that the endpoint tests do not reach, because every form a
 * route hands a builder came out of the plugin's own tables (#363). Each test
 * builds the input directly and asserts what the builder returns for it.
 *
 * None of these needs Gravity Forms or Formidable loaded: each guard returns
 * before the builder calls into the plugin. Ninja Forms is always loaded here,
 * from `tests/Integration/vendor/` when the surrounding install lacks it.
 */

/**
 * Create a Ninja form holding one field, returning both ids.
 *
 * @return array{form: int, field: int}
 */
function ninjaFormWithField(): array
{
    global $wpdb;

    assert($wpdb instanceof wpdb, 'no WordPress database handle');

    $form = new NF_Database_Models_Form($wpdb, '');
    $form->update_settings(['title' => 'Edge Form', 'key' => '']);
    $form->save();

    $formId = $form->get_id();

    assert(is_numeric($formId) && (int) $formId > 0, 'the Ninja form model returned no id');

    $field = new NF_Database_Models_Field($wpdb, '', (int) $formId);
    $field->update_settings(['type' => 'textbox', 'label' => 'Full name', 'key' => 'full_name', 'order' => 1]);
    $field->save();

    $fieldId = $field->get_id();

    assert(is_numeric($fieldId) && (int) $fieldId > 0, 'the Ninja field model returned no id');

    return ['form' => (int) $formId, 'field' => (int) $fieldId];
}

it('builds a Formidable form with no usable id without reading fields or actions', function (): void {
    $payload = FormidablePayload::build((object) ['id' => 'not-a-number', 'form_key' => 'orphan']);

    expect($payload['id'])->toBe(0)
        ->and($payload['form_key'])->toBe('orphan')
        ->and($payload['fields'])->toBe([])
        ->and($payload['actions'])->toBe([]);
});

it('serves a boolean or an empty string under a credential-shaped feed key', function (): void {
    // The control: the same key with a string value is withheld by name, so
    // `password` is a name the filter treats as a credential.
    expect(GravityFeedPayload::filterMeta(['password' => 'hunter2'], [])['by_name'])->toBe(1);

    $filtered = GravityFeedPayload::filterMeta(['password' => true, 'apiKey' => ''], []);

    expect($filtered['meta'])->toBe(['password' => true, 'apiKey' => ''])
        ->and($filtered['by_name'])->toBe(0)
        ->and($filtered['by_shape'])->toBe(0);
});

it('reads no field ids from a form whose fields are not a list', function (): void {
    expect(GravityFeedPayload::fieldIdsFor(['fields' => 'not a list']))->toBe([])
        ->and(GravityFeedPayload::fieldIdsFor([]))->toBe([]);
});

it('skips a field entry that is neither an array nor an object', function (): void {
    expect(GravityFeedPayload::fieldIdsFor(['fields' => ['not a field', 7, ['id' => 3]]]))->toBe(['3']);
});

it('builds a Ninja form with no id without reading its children', function (): void {
    $payload = NinjaPayload::build(new class {});

    expect($payload['id'])->toBe(0)
        ->and($payload['key'])->toBe('')
        ->and($payload['title'])->toBe('')
        ->and($payload['settings'])->toBe([])
        ->and($payload['fields'])->toBe([])
        ->and($payload['actions'])->toBe([]);
});

it('reads no key or title from Ninja settings that are not an array', function (): void {
    $form = new class
    {
        public function get_id(): int
        {
            return 0;
        }

        public function get_settings(): string
        {
            return 'not an array';
        }
    };

    $payload = NinjaPayload::build($form);

    expect($payload['key'])->toBe('')
        ->and($payload['title'])->toBe('')
        ->and($payload['settings'])->toBe([]);
});

it('skips a Ninja child id that is not numeric or not positive', function (): void {
    global $wpdb;

    assert($wpdb instanceof wpdb, 'no WordPress database handle');

    $ids = ninjaFormWithField();
    $fieldsTable = $wpdb->prefix.'nf3_fields';

    // `children()` selects ids straight off the table, where neither shape can
    // occur, so the query that reads them is rewritten to return both beside
    // the real field.
    $rewritten = 0;

    add_filter('query', static function (string $query) use ($fieldsTable, $ids, &$rewritten): string {
        if (! str_contains($query, "FROM {$fieldsTable} c")) {
            return $query;
        }

        $rewritten++;

        return "SELECT 'abc' AS id UNION ALL SELECT '0' UNION ALL SELECT '-4' UNION ALL SELECT '{$ids['field']}'";
    });

    $form = new NF_Database_Models_Form($wpdb, $ids['form']);
    $fields = NinjaPayload::build($form)['fields'];

    assert(is_array($fields));

    // The real query returns the one field too, so the rewrite must have run
    // for the count below to say anything about the skipped ids.
    expect($rewritten)->toBe(1)
        ->and($fields)->toHaveCount(1)
        ->and(is_array($fields[0]) ? $fields[0]['id'] : null)->toBe($ids['field'])
        ->and(is_array($fields[0]) ? $fields[0]['key'] : null)->toBe('full_name');
});
