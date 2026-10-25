<?php

/**
 * Ninja Forms definition payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

/**
 * One Ninja Forms form, as `{id, settings, fields, actions}`.
 *
 * **The `id` is the entire reason this endpoint exists**, and it is the one
 * thing a `.nff` export structurally cannot supply. Every export in the
 * captured corpus is a JSON object of exactly `{settings, fields, actions}`
 * with no id at any level and an empty `settings.key`, while post content
 * refers to forms *by number* — `[ninja_form id=3]` and
 * `<!-- wp:ninja-forms/form {"formID":3} -->`. So the importer has to scrape
 * ids out of content, ask an operator for a file per id, and can only warn on
 * a mismatch it has no way to detect. Serving the id alongside the definition
 * deletes that whole mechanism (`wordpress-importer#10`, `#695`).
 *
 * The rest of the shape deliberately matches a `.nff` so the importer's
 * existing reader needs no second mapper: the same three keys, and fields and
 * actions FLAT with their settings inline, exactly as an export and as the
 * plugin's own `export()` produce them. The only addition is `id`, which a
 * `.nff` carries nowhere.
 *
 * Settings, fields and actions each arrive as the plugin's own arrays, on
 * D-004's "raw, not curated" — this API does not decide what a form means.
 */
final class NinjaPayload
{
    /**
     * Build the payload for one form.
     *
     * @param  object  $form  An `NF_Database_Models_Form`.
     * @return array<string, mixed>
     */
    public static function build(object $form): array
    {
        $id = method_exists($form, 'get_id') ? $form->get_id() : 0;
        $id = is_numeric($id) ? (int) $id : 0;

        $settings = method_exists($form, 'get_settings') ? $form->get_settings() : [];

        return [
            'id' => $id,
            /*
             * Lifted out of settings as well as left inside it. `key` is what a
             * `.nff` uses to identify a form and it is EMPTY in every captured
             * export, so a consumer that keys on it silently collides every
             * form onto one. Surfacing it at the top level makes that visible
             * rather than buried, and costs one string.
             */
            'key' => self::stringSetting($settings, 'key'),
            'title' => self::stringSetting($settings, 'title'),
            'settings' => \is_array($settings) ? $settings : [],
            'fields' => self::children($id, 'nf3_fields', \NF_Database_Models_Field::class),
            'actions' => self::children($id, 'nf3_actions', \NF_Database_Models_Action::class),
        ];
    }

    /**
     * A form's fields or actions, each FLAT: the settings map plus an `id`.
     *
     * **Read off the child tables and rebuilt per id, NOT through
     * `Ninja_Forms()->form( $id )->get_fields()`.** Three reasons, and the
     * first one was a live defect in this file:
     *
     * 1. `get_fields()` and `get_actions()` are methods of
     *    `NF_Abstracts_ModelFactory`, not of `NF_Database_Models_Form`. The
     *    first version of this class called them on the FORM MODEL behind a
     *    `method_exists()` guard, so every form served `fields: []` and
     *    `actions: []` — a well-formed `200` with the definition's contents
     *    missing, which is exactly the failure this project exists to prevent.
     *    The guard is gone: a missing class is now a fatal, not an empty list.
     * 2. `NF_Abstracts_ModelFactory::field()` **mutates the factory**, swapping
     *    its `_object` from the form to a field. Since `Ninja_Forms()->form()`
     *    memoizes factories in a `static`, one call to `->field()` leaves that
     *    memoized factory permanently pointing at a field for the rest of the
     *    process.
     * 3. `NF_Abstracts_Model::find()` only hydrates settings when caching is
     *    OFF, and `WPN_Helper::use_cache()` is `return true;` with the real
     *    logic dead below it — so the bulk path never runs
     *    `get_object_settings()`.
     *
     * A model per id, constructed for this request against the row it names,
     * is immune to all three.
     *
     * @return list<array<string, mixed>>
     */
    private static function children(int $formId, string $table, string $class): array
    {
        global $wpdb;

        if (! $wpdb instanceof \wpdb || ! class_exists($class) || $formId <= 0) {
            return [];
        }

        $name = $wpdb->prefix.$table;

        /*
         * Interpolated rather than prepared, and safe because `$formId` is an
         * `int` by signature — there is no string path into this query. No
         * `esc_sql()` is applied and none is needed; an earlier version of this
         * comment claimed otherwise and credited the safety to escaping that
         * was never there. `prepare()` is avoided because PHPStan requires a
         * literal-string query for it, which a `$wpdb`-interpolated table name
         * cannot be.
         *
         * Ordered by the form's own `order` and only then by id. A `.nff`
         * gives every field an explicit `order` (1..n) and Ninja Forms renders
         * by it, so a form whose fields were reordered after creation has an
         * `order` that disagrees with insertion order — serving by id alone
         * would hand the consumer a scrambled form.
         *
         * **The join is on `key`/`value`, NOT `meta_key`/`meta_value`, and the
         * distinction is the difference between the fix working and silently
         * not.** `nf3_field_meta` carries both pairs: `key`/`value` are the
         * real ones, and `meta_key`/`meta_value` are denormalized copies added
         * in 3.3.12. Ninja Forms reads settings from the FIRST pair —
         * `NF_Abstracts_Model::get_settings()` selects `Meta.key, Meta.value`
         * for a field, and the generic branch has the copies commented out on
         * purpose. So the `order` this endpoint SERVES comes from `value`.
         * Sorting on `meta_value` meant the sort key and the served value
         * could disagree, and on any row where `meta_value` is NULL — a state
         * Ninja Forms' own code guards for — `COALESCE` sent every field to
         * 999999 and the sort collapsed back to id order. A 200 whose array
         * order contradicts the `order` in its own payload.
         *
         * `GROUP BY` because the LEFT JOIN would otherwise emit a field twice
         * if it ever carried two `order` rows; `MIN()` picks one deterministic
         * value. Rows with no `order` at all sort last rather than vanishing.
         */
        $metaTable = $wpdb->prefix.($table === 'nf3_fields' ? 'nf3_field_meta' : 'nf3_action_meta');

        /** @var mixed $ids */
        $ids = $wpdb->get_col(
            "SELECT c.id FROM {$name} c
             LEFT JOIN {$metaTable} m ON m.parent_id = c.id AND m.`key` = 'order'
             WHERE c.parent_id = {$formId}
             GROUP BY c.id
             ORDER BY CAST(COALESCE(MIN(m.`value`), '999999') AS UNSIGNED) ASC, c.id ASC"
        );

        $records = [];

        foreach (\is_array($ids) ? $ids : [] as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            if ((int) $id <= 0) {
                continue;
            }

            $model = new $class($wpdb, (int) $id, $formId);

            $settings = method_exists($model, 'get_settings') ? $model->get_settings() : [];

            /*
             * `['id' => …] + $settings`, NOT `['id' => …, 'settings' => …]`.
             *
             * A real `.nff` carries fields and actions as FLAT objects with
             * their settings inline — `label`, `type`, `key`, `order` sit
             * directly on the element — and Ninja Forms' own
             * `NF_Database_Models_Form::export()` does
             * `$export['fields'][] = $field->get_settings();` with no wrapper.
             * Wrapping them put every field a level deeper than the shape this
             * endpoint promises, so the importer's existing reader — which
             * does `$field['type']`, `$field['key']`, `$field['options']` —
             * would have found `null` on every one. A 200, every field
             * present, every field unreadable.
             *
             * The id still has to be carried: a field row's `id` column is NOT
             * part of `get_settings()`, and the id is this endpoint's whole
             * reason. `+` rather than `array_merge()` so a stray `id` inside
             * settings cannot displace the row id.
             */
            $records[] = ['id' => (int) $id] + (\is_array($settings) ? $settings : []);
        }

        return $records;
    }

    /**
     * One setting as a string, or `''` when it is absent or not scalar.
     *
     * @param  mixed  $settings  The form's settings array.
     */
    private static function stringSetting(mixed $settings, string $key): string
    {
        if (! \is_array($settings)) {
            return '';
        }

        $value = $settings[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
