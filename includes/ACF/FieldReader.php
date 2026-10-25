<?php

/**
 * Reads ACF values for an object, keyed by field key and by field name.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\ACF;

/**
 * The plugin's only route to ACF.
 *
 * Five functions, and nothing else: `acf_get_field_groups()` to discover,
 * `acf_get_fields()` to enumerate, `acf_get_value()` to read,
 * `acf_get_store()` to drop what reading memoized, and
 * `acf_get_metadata_by_field()` to tell a stored value from one the
 * definition supplied (D-038). Nothing in ACF's `includes/rest-api/` is
 * reachable from this namespace — it matches `/wp/v2/…` routes exclusively —
 * and `acf_format_value_for_rest()` raises a fatal on this network. See
 * `docs/DECISIONS.md` D-024.
 *
 * **No field-type object is ever obtained, let alone called.** `acf_get_field_type()`
 * does not always return an `acf_field`: on this network `maskfield` resolves to
 * a `stdClass` placeholder and `button` resolves to `null` — two different
 * shapes, so a guard written for either alone misses the other. This class
 * needs neither, because every transformation below reads the field
 * **definition array** and never asks the type for behavior. That is what makes
 * it structurally tolerant rather than defensively patched.
 * `tests/Unit/AcfApiGuardTest.php` holds the whole of `includes/` to it.
 */
final class FieldReader
{
    /**
     * Every ACF value on a post, under both its field key and its field name.
     *
     * @return array{keys: array<string, mixed>, names: array<string, mixed>, defaulted_keys: array<string, mixed>, defaulted_names: array<string, mixed>, collisions: list<string>, identities: list<array{key: string, name: string}>}
     */
    public static function forPost(int $postId): array
    {
        return self::read(['post_id' => $postId], $postId);
    }

    /**
     * Every ACF value for one object.
     *
     * `identities` pairs each field's key with its name. The two value maps are
     * keyed differently on purpose, so nothing else can tell which entry of one
     * corresponds to which entry of the other — and a caller that has to
     * withhold a field needs to remove it from *both* or the value ships anyway
     * under the other spelling. `OptionsPayload::acf()` is that caller.
     *
     * @param  array<string, mixed>  $location  Location args for `acf_get_field_groups()`.
     * @param  int|string  $objectId  The `post_id` argument `acf_get_value()` takes — a post ID, or a string like `options`.
     * @return array{keys: array<string, mixed>, names: array<string, mixed>, defaulted_keys: array<string, mixed>, defaulted_names: array<string, mixed>, collisions: list<string>, identities: list<array{key: string, name: string}>}
     */
    public static function read(array $location, int|string $objectId): array
    {
        $keys = [];
        $names = [];
        $defaultedKeys = [];
        $defaultedNames = [];
        $collisions = [];
        $identities = [];

        foreach (self::fieldGroups($location) as $group) {
            foreach (self::fields($group) as $field) {
                $identity = self::identity($field);

                if ($identity === null) {
                    continue;
                }

                [$key, $name] = $identity;

                // Raw, un-formatted, exactly as stored (D-004). This is also
                // what routes around the ACF Custom Database Tables staleness:
                // `acf/pre_load_value` is the first statement of
                // `acf_get_value()`, so a value living only in a custom table is
                // returned here and is absent from postmeta entirely.
                $value = acf_get_value($objectId, $field);

                /*
                 * Stored, or supplied by the field definition — and the two are
                 * kept apart (D-038). `acf_get_value()` substitutes
                 * `$field['default_value']` when nothing is stored, and it does
                 * so *before* the `acf/load_value` filter, so no hook can tell
                 * them apart afterwards. Measured on this install: 16,626 of
                 * 45,249 term fields and 8% of sampled post fields were served
                 * carrying a value no row held.
                 *
                 * `acf_get_metadata_by_field()` is the clean signal — null when
                 * there is no row — and it is the function ACF Custom Database
                 * Tables intercepts through `acf/pre_load_metadata`, so it stays
                 * right for the `location` and `provider` values that live only
                 * in custom tables. A plain `get_post_meta()` check would report
                 * "no stored row" for exactly the fields this project exists to
                 * rescue.
                 */
                $isStored = self::isStoredRatherThanSupplied($objectId, $field, $value);

                $identities[] = ['key' => $key, 'name' => $name];

                /*
                 * A field with no row AND no default is absent rather than
                 * defaulted: nothing was manufactured, so it belongs with the
                 * stored view carrying null. Only a value the definition
                 * supplied moves.
                 */
                if ($isStored) {
                    $keys[$key] = $value;
                } else {
                    $defaultedKeys[$key] = $value;
                }

                // First occurrence wins, and the loser is NAMED rather than
                // dropped in silence. Field names can collide across field
                // groups where keys cannot (D-025), and a name view that
                // quietly overwrites is this project's defining failure mode
                // wearing a different hat — a well-formed payload missing a
                // value. The key view above is lossless either way.
                // Collision detection spans BOTH name views. Splitting the maps
                // must not let one name occupy each and look uncontested.
                if (\array_key_exists($name, $names) || \array_key_exists($name, $defaultedNames)) {
                    $collisions[] = $name;

                    continue;
                }

                if ($isStored) {
                    $names[$name] = self::rekey($value, $field);
                } else {
                    $defaultedNames[$name] = self::rekey($value, $field);
                }
            }
        }

        self::resetValueStore();

        return [
            'keys' => $keys,
            'names' => $names,
            'defaulted_keys' => $defaultedKeys,
            'defaulted_names' => $defaultedNames,
            'collisions' => array_values(array_unique($collisions)),
            'identities' => $identities,
        ];
    }

    /**
     * Whether a value came from the row rather than from the field definition.
     *
     * Three cases, and two of them were wrong in the first draft — both caught
     * by tests rather than by reading, which is the argument for having them.
     *
     * **A container has no row of its own.** A `group` stores nothing; its
     * children do, and `acf_get_value()` assembles them. So
     * `acf_get_metadata_by_field()` returns null for a fully populated group,
     * and treating that as "supplied by the definition" moved every repeater
     * and group in the payload into the wrong map. Detected by shape rather
     * than by type, per D-031: a field carrying `sub_fields` — or `layouts` on
     * flexible content — is assembled from children and is never a default.
     *
     * **An empty default is not a supplied value.** `acf_get_valid_field()`
     * gives most field types `default_value => ''`, so without this every unset
     * text field on the site would land in the defaulted map carrying an empty
     * string — thousands of rows of noise, and a distinction nobody can act on.
     * Empty is absent, and absent belongs with the stored view.
     *
     * Otherwise: no row, and the definition supplied something. That is the
     * 16,626 term fields D-038 is about.
     *
     * @param  array<string, mixed>  $field
     */
    private static function isStoredRatherThanSupplied(int|string $objectId, array $field, mixed $value): bool
    {
        if (self::subFieldMap($field) !== []) {
            return true;
        }

        if (in_array($value, [null, '', []], true)) {
            return true;
        }

        return self::storedMetadata($objectId, $field) !== null;
    }

    /**
     * Whether ACF holds a row for this field.
     *
     * Consults `acf/pre_load_metadata`, which is the filter ACF Custom
     * Database Tables intercepts, so this stays right for exactly the
     * `location` and `provider` values this project exists to rescue. See
     * `docs/DECISIONS.md` D-069 for the pre-6.4 fallback this used to carry
     * and #175, which removed it once every development machine was on ACF
     * PRO 6.4.2.
     *
     * @param  array<string, mixed>  $field
     */
    private static function storedMetadata(int|string $objectId, array $field): mixed
    {
        return acf_get_metadata_by_field($objectId, $field);
    }

    /**
     * A field's key and name, or null when it has neither usable.
     *
     * Both come off a filtered array, so neither is guaranteed to be a string —
     * and a field missing either is not addressable in the payload at all.
     *
     * @param  array<string, mixed>  $field
     * @return array{string, string}|null
     */
    private static function identity(array $field): ?array
    {
        $key = $field['key'] ?? null;
        $name = $field['name'] ?? null;

        if (\is_string($key) && \is_string($name) && $key !== '' && $name !== '') {
            return [$key, $name];
        }

        return null;
    }

    /**
     * Drop ACF's memoized values.
     *
     * `acf_get_value()` memoizes into the `values` store and never evicts, so a
     * paginated read accumulates every value of every record it has passed.
     * Measured while building #7: 42 entries after one post, **614 after 20**,
     * unbounded across a page. ACF's own REST layer resets per object for the
     * same reason (`class-acf-rest-api.php:245`).
     */
    public static function resetValueStore(): void
    {
        acf_get_store('values')->reset();
    }

    /**
     * Field groups matching a location, as plain arrays.
     *
     * @param  array<string, mixed>  $location
     * @return list<array<string, mixed>>
     */
    private static function fieldGroups(array $location): array
    {
        $groups = acf_get_field_groups($location);

        return self::arraysOnly($groups);
    }

    /**
     * The fields of one group.
     *
     * @param  array<string, mixed>  $group
     * @return list<array<string, mixed>>
     */
    private static function fields(array $group): array
    {
        return self::arraysOnly(acf_get_fields($group));
    }

    /**
     * Keep only the array members of an untrusted result.
     *
     * `acf_get_fields()` returns `false` for a group with no fields, and both
     * functions run through filters any plugin can hook.
     *
     * @return list<array<string, mixed>>
     */
    private static function arraysOnly(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> $arrays */
        $arrays = array_values(array_filter($value, \is_array(...)));

        return $arrays;
    }

    /**
     * Rewrite a container value's field keys to field names, recursively.
     *
     * `acf_get_value()` returns container rows keyed by the sub-field **key** —
     * verified against `location` #3552, whose `location_phone_numbers` repeater
     * comes back as `[{"field_location_appointments_text": "Physician Call
     * Center", …}]`. ACF's own `light` REST format performs this same rewrite
     * and *discards* the keys; both views are kept here instead (D-025).
     *
     * Dispatched on the **shape of the definition**, never on `$field['type']`.
     * A field with sub-fields is a container whatever its type is called, which
     * is why an unregistered type nests correctly rather than needing a special
     * case — and why a field type added to ACF tomorrow needs no change here.
     *
     * @param  array<string, mixed>  $field
     */
    private static function rekey(mixed $value, array $field): mixed
    {
        $map = self::subFieldMap($field);

        if ($map === [] || ! \is_array($value)) {
            return $value;
        }

        if (self::isRow($value, $map)) {
            return self::rekeyRow($value, $map);
        }

        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $row): mixed => \is_array($row) && self::isRow($row, $map)
                    ? self::rekeyRow($row, $map)
                    : $row,
                $value
            );
        }

        return $value;
    }

    /**
     * Whether an array is one container row rather than a list of them.
     *
     * Decided by membership, not by `array_is_list()`: a repeater row is a
     * key-addressed map, so a single row and a list of rows are told apart by
     * whether the array's own keys name sub-fields.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, array<string, mixed>>  $map
     */
    private static function isRow(array $value, array $map): bool
    {
        foreach (array_keys($value) as $key) {
            if (\is_string($key) && isset($map[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * One row, rekeyed.
     *
     * A key with no matching sub-field passes through untouched — that is how
     * `acf_fc_layout`, which flexible content adds to every row and which names
     * no field, survives rather than being dropped.
     *
     * @param  array<array-key, mixed>  $row
     * @param  array<string, array<string, mixed>>  $map
     * @return array<array-key, mixed>
     */
    private static function rekeyRow(array $row, array $map): array
    {
        $rekeyed = [];

        foreach ($row as $key => $value) {
            $sub = \is_string($key) ? ($map[$key] ?? null) : null;

            if ($sub === null || ! isset($sub['name']) || ! \is_string($sub['name'])) {
                $rekeyed[$key] = $value;

                continue;
            }

            $rekeyed[$sub['name']] = self::rekey($value, $sub);
        }

        return $rekeyed;
    }

    /**
     * A field's sub-fields, indexed by key.
     *
     * Covers both places ACF puts them: `sub_fields` on repeater, group and
     * clone, and `layouts[*]['sub_fields']` on flexible content. Merged into one
     * map because a row names its layout separately (`acf_fc_layout`), so keys
     * from every layout can be resolved without first deciding which layout a
     * row belongs to.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, array<string, mixed>>
     */
    private static function subFieldMap(array $field): array
    {
        $subFields = self::arraysOnly($field['sub_fields'] ?? null);

        foreach (self::arraysOnly($field['layouts'] ?? null) as $layout) {
            foreach (self::arraysOnly($layout['sub_fields'] ?? null) as $subField) {
                $subFields[] = $subField;
            }
        }

        $map = [];

        foreach ($subFields as $subField) {
            if (isset($subField['key']) && \is_string($subField['key'])) {
                $map[$subField['key']] = $subField;
            }
        }

        return $map;
    }
}
