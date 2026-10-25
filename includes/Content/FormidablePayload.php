<?php

/**
 * Serializes one Formidable Forms definition into the migration payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use FrmAppHelper;
use FrmField;
use FrmFormAction;
use stdClass;

/**
 * One Formidable form, with its fields, exactly as the `Frm*` models return them.
 *
 * Formidable never came through WXR; definitions are sourced today from XML the
 * plugin's own exporter produces by hand — 12 of them committed in the
 * importer's tree, carrying 68 forms and 2,412 fields. Same defects as WXR
 * capture: a manual step, a snapshot stale on arrival, no incremental re-pull.
 *
 * **Composed, not curated.** The `frm_forms` row and the `frm_fields` rows are
 * two tables and the endpoint serves both together, because a form definition
 * without its fields is not a definition. Nothing here renders, resolves or
 * reorders — the columns are handed over as the models return them, which is
 * what D-004 asks for and what makes the payload comparable to an export.
 */
final class FormidablePayload
{
    /**
     * Serialize one form.
     *
     * @param  stdClass  $form  A row as `FrmForm::getOne()` or `getAll()` returned it.
     * @return array<string, mixed>
     */
    public static function build(stdClass $form): array
    {
        $id = isset($form->id) && is_numeric($form->id) ? (int) $form->id : 0;
        $fields = self::fields($id);

        return [
            'id' => $id,

            /*
             * `form_key` beside the id, and it is the load-bearing one. Ids do
             * not survive a migration; the key does, and it is what Formidable's
             * own shortcodes and its exporter reference a form by. The same
             * argument that makes a term's slug load-bearing on the taxonomy
             * endpoint.
             */
            'form_key' => $form->form_key ?? '',
            'name' => $form->name ?? '',
            'description' => $form->description ?? '',

            /*
             * `parent_form_id` is not decoration. Formidable Pro stores a
             * repeating section and an embedded form as a SEPARATE form row
             * pointing back at its parent — 11 of the 68 forms in the captured
             * corpus are children. A consumer rebuilding a repeater needs the
             * edge, and a collection that hides them (as
             * `FrmForm::get_published_forms()` does by default) loses 16% of
             * this network's forms with nothing to show for it.
             */
            'parent_form_id' => isset($form->parent_form_id) && is_numeric($form->parent_form_id)
                ? (int) $form->parent_form_id
                : 0,

            'status' => $form->status ?? null,
            'is_template' => isset($form->is_template) && is_numeric($form->is_template)
                ? (int) $form->is_template
                : 0,
            'default_template' => isset($form->default_template) && is_numeric($form->default_template)
                ? (int) $form->default_template
                : 0,
            'logged_in' => $form->logged_in ?? null,
            'editable' => $form->editable ?? null,
            'created_at' => $form->created_at ?? null,

            /*
             * `options` arrives already decoded — `FrmForm::getAll()` runs
             * `FrmAppHelper::unserialize_or_decode()` over it on the way out,
             * and `getOne()` does the same. Handed on as the model gave it
             * rather than re-encoded, so a consumer reads a structure instead
             * of a serialized string it would have to know how to parse.
             */
            'options' => $form->options ?? null,

            'fields' => $fields,

            /*
             * Every action attached to the form -- its email notifications, its
             * on-submit confirmations, and whatever else an add-on registered --
             * which the form row does not carry and the fields do not either.
             * The consumer's XML loader reads exactly these off the export's
             * `<view>` elements, and without them an import lands zero
             * notifications and reports success (D-081).
             */
            'actions' => self::actions($id, self::fieldReferences($fields)),
        ];
    }

    /**
     * Every field on the form, in the order the editor arranged them.
     *
     * **One flag is widened and one is NARROWED**, and getting that backwards
     * duplicates data. `FrmField::get_all_for_form()` is declared
     * `( $form_id, $limit = '', $inc_embed = 'exclude', $inc_repeat = 'include' )`.
     *
     * `$inc_embed` is widened to `include`, because the default silently omits
     * the fields of an embedded form and a definition with a hole in it says
     * nothing about the hole.
     *
     * `$inc_repeat` is narrowed to `exclude`, and `include` — the plugin's own
     * default — is the trap. It is not an inclusion flag in the sense the name
     * suggests: `FrmField::maybe_include_repeating_fields()` rewrites the where
     * clause to `( fi.form_id = <id> OR fr.parent_form_id = <id> )`, so a
     * PARENT's field list absorbs every field belonging to its children. And
     * this endpoint already serves each child as its own top-level record with
     * its own fields (D-046) — so the two together served the same
     * `frm_fields` rows twice in one page. Measured: a parent with 2 fields and
     * a child with 3 produced 5 + 3 = **8 field records for 5 rows in the
     * table**, the parent's list interleaved by `field_order` across both forms
     * so it matched neither.
     *
     * A consumer importing `record.fields` per form would have built the parent
     * with the repeating section's inner fields as its own top-level fields,
     * and created the child's fields a second time. The edge is carried by
     * `parent_form_id`, which is what a consumer rebuilding a repeater needs.
     *
     * `$limit` is left empty deliberately. It is not a page size here: passing
     * `0` yields a literal `LIMIT 0` and returns nothing at all — verified, not
     * assumed — so the "no limit" value is the empty string. Pagination happens
     * over forms, never over one form's fields.
     *
     * @return list<array<string, mixed>>
     */
    private static function fields(int $formId): array
    {
        if ($formId <= 0) {
            return [];
        }

        $fields = FrmField::get_all_for_form($formId, '', 'include', 'exclude');

        $records = [];

        foreach ($fields as $field) {
            $records[] = [
                'id' => isset($field->id) && is_numeric($field->id) ? (int) $field->id : 0,
                'field_key' => $field->field_key ?? '',
                'name' => $field->name ?? '',
                'description' => $field->description ?? '',
                'type' => $field->type ?? '',
                'default_value' => $field->default_value ?? null,
                'field_order' => isset($field->field_order) && is_numeric($field->field_order)
                    ? (int) $field->field_order
                    : 0,
                'required' => isset($field->required) && is_numeric($field->required)
                    ? (int) $field->required
                    : 0,
                'form_id' => isset($field->form_id) && is_numeric($field->form_id)
                    ? (int) $field->form_id
                    : 0,
                'created_at' => $field->created_at ?? null,

                /*
                 * `options` holds a choice list and `field_options` holds
                 * everything else the editor set — the calculation, the
                 * conditional logic, the CSS class, the custom HTML. Both are
                 * served as the model returns them, which is the only shape
                 * that survives a field type this deployment has never heard
                 * of. Resolving a field type is exactly what D-031 forbids for
                 * ACF, and the argument carries over unchanged.
                 */
                'options' => $field->options ?? null,
                'field_options' => $field->field_options ?? null,
            ];
        }

        return $records;
    }

    /**
     * Every action attached to the form, as Formidable stores it.
     *
     * Formidable keeps a form's actions -- email notifications, on-submit
     * confirmations, and any action an add-on registers -- as posts of type
     * `frm_form_actions`, keyed to the form by `menu_order`, with the action
     * type in `post_excerpt` and the settings as JSON in `post_content`. Its
     * own exporter selects them by exactly those two columns and emits each as
     * a `<view>` sibling of the `<form>`; this mirrors that selection so the API
     * carries what the export carries (D-081).
     *
     * **Selected through the model's own query, read through WordPress.**
     * `FrmFormAction::action_args()` is the public builder the plugin uses for
     * this query, and it is what makes the post type and the key column the
     * plugin's knowledge rather than a string copied here -- the guard in
     * `tests/Unit/FormApiGuardTest.php` holds that no literal names them. Two
     * of its defaults are widened, deliberately: `post_status` to `any`,
     * because the exporter applies no status filter and a draft notification
     * is still part of the definition -- 4 of the 103 actions across the
     * importer's 12 captured exports are drafts; and the limit to none, because
     * the exporter has none and the plugin's runtime cap of 99
     * (`frm_form_action_limit`) is a display bound, not a data one. `any`
     * reaches both statuses the plugin ever writes; it deletes an action
     * outright rather than trashing it.
     *
     * **Not `FrmFormAction::get_action_for_form()`, and that is a choice.** It
     * is the runtime reader, and read at 6.26.1 it is this same `get_posts()`
     * over `action_args()` behind a cache, plus three things on top: it pins
     * `publish` unless told otherwise, it drops any action whose type is not
     * registered on the serving site -- an add-on that is inactive here makes
     * its actions vanish -- and `prepare_action()` merges the type's global
     * defaults into the settings before returning them. The first is a
     * parameter; the other two are curation. Both are right for rendering a
     * form and wrong for describing one: one loses data the export keeps, the
     * other serves values nobody stored. Raw, not curated (D-004) means the
     * row, so the read stops at the query the model builds.
     *
     * **Settings are decoded, not resolved.** `FrmAppHelper::maybe_json_decode()`
     * is the plugin's own decoder for this column -- what `prepare_action()`
     * calls -- and it returns the input unchanged when it is not JSON, so a
     * row that is not a map is served as an empty one rather than as a string
     * where a consumer expects a structure. Decoding is the same choice
     * `options` and `field_options` already make; resolving -- merging
     * defaults, expanding shortcodes -- is what is refused. A consumer whose
     * reader expects the stored string normalizes at its own seam.
     *
     * **The value machinery applies, per setting.** An action's settings carry
     * recipient addresses and can carry credentials an add-on stored, so each
     * top-level setting goes through the same filter feed meta does (D-032,
     * D-056): withheld by name unless the value is a bare reference to a field
     * on this form, withheld by shape at any depth. The `[123]` and
     * `[field_key]` shortcode forms Formidable writes into a setting are field
     * references too, and are exempt the same way a Gravity field id is.
     *
     * @param  list<string>  $fieldReferences  Every form a field reference can take on this form.
     * @return list<array<string, mixed>>
     */
    private static function actions(int $formId, array $fieldReferences): array
    {
        if ($formId <= 0) {
            return [];
        }

        $args = array_merge(FrmFormAction::action_args($formId, -1), [
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',

            // `get_posts()` already defaults this on; stated so a migration read
            // sees the table and not a plugin's view of it (D-045).
            'suppress_filters' => true,

            // An action carries no meta and no terms, and the collection route
            // builds one form per record, so two cache-priming queries per form
            // would be the whole cost of this member on a page.
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $records = [];

        foreach (get_posts($args) as $post) {
            $decoded = FrmAppHelper::maybe_json_decode($post->post_content);
            $settings = \is_array($decoded) ? $decoded : [];
            $filtered = GravityFeedPayload::filterMeta($settings, $fieldReferences);

            $records[] = [
                'id' => (int) $post->ID,
                'type' => (string) $post->post_excerpt,
                'title' => (string) $post->post_title,
                'name' => (string) $post->post_name,
                'status' => (string) $post->post_status,
                'created_at' => (string) $post->post_date,
                'form_id' => (int) $post->menu_order,
                'settings' => $filtered['meta'],
                'denied' => [
                    'total' => $filtered['by_name'] + $filtered['by_shape'],
                    'by_name' => $filtered['by_name'],
                    'by_shape' => $filtered['by_shape'],
                ],
            ];
        }

        return $records;
    }

    /**
     * Every form a reference to one of this form's fields can take inside an
     * action's settings: the id, the `[id]` shortcode, and the `[field_key]`
     * shortcode. A bare field key is deliberately not on the list -- a key can
     * be any slug, and exempting `password` because a field happens to be
     * called that would be exempting by name.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<string>
     */
    private static function fieldReferences(array $fields): array
    {
        $references = [];

        foreach ($fields as $field) {
            $id = $field['id'] ?? 0;
            $key = $field['field_key'] ?? '';

            if (\is_int($id) && $id > 0) {
                $references[] = (string) $id;
                $references[] = '['.$id.']';
            }

            if (\is_string($key) && $key !== '') {
                $references[] = '['.$key.']';
            }
        }

        return $references;
    }
}
