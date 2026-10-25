<?php

/**
 * Gravity Forms add-on feed payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

/**
 * One Gravity Forms add-on feed, with its meta filtered for credentials.
 *
 * A feed is the configuration that says what an add-on does when a form is
 * submitted. The one with a known consumer is User Registration, which creates
 * a real WordPress account: three sites in the captured corpus run it, and
 * imported without their feeds each of those forms lands as an ordinary form
 * that collects a username and a password and creates nothing, so a registrant
 * cannot log in (#80, `wordpress-importer#821`).
 *
 * **This is the only place the information exists.** A Gravity Forms export
 * carries the form *definition*; feeds live in `gf_addon_feed` and
 * `GFAPI::get_form()` does not read that table. A Gravity Forms export of the
 * largest affected site contains zero occurrences of `userregistration`,
 * `feeds`, or `gravityformsuserregistration`. So a migration that does not
 * carry this loses it rather than deferring it.
 *
 * ## Why the meta is filtered at all
 *
 * A feed is a generic add-on record and its `meta` is whatever that add-on
 * stores, which for a payment add-on is gateway configuration. Gravity Forms
 * says so itself, in the strongest way a plugin can: since 2.7.16 a feed
 * settings field can be marked `encrypt`, and `GFAPI::get_feeds()` runs every
 * value it returns through `decrypt_feed_meta()`. **The read path this endpoint
 * uses hands back the plaintext of fields the plugin encrypts at rest.**
 *
 * That encryption is off unless `GF_ENCRYPTION_KEY` is defined, so on most
 * installs the values were never encrypted and nothing is being decrypted. It
 * is the *marking* that matters rather than the ciphering: an add-on author
 * writing `'encrypt' => true` on a settings field is naming that field a
 * credential, and this endpoint would otherwise serve it.
 *
 * ## Why the add-on's own list is not used
 *
 * The obvious move is to ask each add-on which of its fields are marked, via
 * `GFAddon::get_addon_by_slug()` and `get_fields_to_encrypt()`. It is not done,
 * for a reason that outweighs the accuracy: `get_fields_to_encrypt()` derives
 * its answer from `get_feed_settings_fields()`, which is the add-on's settings
 * *UI* definition, and several add-ons populate that by calling their remote
 * service to list accounts, audiences or products. A read endpoint for a
 * migration must not make a request to a payment gateway as a side effect of
 * serving a page. So the filter is this repo's own, and the plugin's marking is
 * the evidence that one is needed rather than the mechanism.
 *
 * ## The rule, and the one exemption that carries the ticket
 *
 * D-032's machinery is reused rather than transcribed —
 * `OptionsPayload::isDeniedNestedKey()` for the name and
 * `OptionsPayload::secretIn()` for the value, over both the raw value and
 * `OptionsPayload::decoded()` of it — which together are the same nested walk
 * with the same depth bound. Feed meta arrives from `GFAPI` already
 * JSON-decoded, so it is the decoded structure that walk expects.
 *
 * **A feed's meta is mostly a field MAPPING, and that breaks a plain name
 * rule.** User Registration stores `password` as the id of the form field the
 * password comes from — the string `"4"`, not a password. The nested-key list
 * carries `%password`, so applied naively it withholds the single mapping the
 * consumer most needs while withholding nothing sensitive at all: the exact
 * false positive D-032 has already paid for twice, and a direct contradiction
 * of `wordpress-importer#821`'s requirement that the field supplying the
 * password survive.
 *
 * So a value that is a bare field reference — an integer, or `4.3` for a
 * sub-field — is never withheld by name. The exemption is safe by construction
 * rather than by judgment: it is not a decision that some *key* is harmless, it
 * is the observation that the string `"4"` cannot be a credential whatever it
 * is called. The shape rule still runs on it, and every non-numeric value under
 * a credential-named key is still withheld.
 */
final class GravityFeedPayload
{
    /**
     * Build the payload for one feed.
     *
     * @param  array<string, mixed>  $feed  A feed row as `GFAPI::get_feeds()` returned it.
     * @param  list<string>  $fieldIds  Every field and input id on the feed's
     *                                  own form, from `fieldIdsFor()`. Empty
     *                                  withholds every reference rather than
     *                                  serving it, which is the safe direction
     *                                  and does not arise for a served feed:
     *                                  `allFeeds()` drops a feed whose form
     *                                  cannot be resolved.
     * @return array<string, mixed>
     */
    public static function build(array $feed, array $fieldIds = []): array
    {
        $meta = $feed['meta'] ?? null;

        /*
         * `is_array($meta)` is the one signal this branch has for a column
         * that did not decode — a NULL, truncated, or wrongly-decrypted value
         * from `decrypt_feed_meta()` arrives here identically to a feed that
         * genuinely has no settings, and both would otherwise serve
         * `meta: {}` with `denied.total: 0`. Recorded on the payload as
         * `meta_readable`, so a consumer can tell "this feed has no settings"
         * from "this feed's settings could not be read" rather than reading a
         * User Registration feed as a configuration-free no-op.
         */
        $metaReadable = \is_array($meta);

        $filtered = self::filterMeta($metaReadable ? $meta : [], $fieldIds);

        return [
            'id' => self::int($feed, 'id'),
            'form_id' => self::int($feed, 'form_id'),
            'addon_slug' => self::string($feed, 'addon_slug'),
            'meta_readable' => $metaReadable,
            /*
             * A REAL boolean, and this is the one place the payload leaves a
             * stored value behind on purpose.
             *
             * `is_active` is a `tinyint(1)` that arrives from `$wpdb` as the
             * STRING `'0'` or `'1'`, the same way `is_trash` does on a form.
             * `"0"` is falsy in PHP and TRUTHY in JavaScript, so served raw it
             * reads as "enabled" to any consumer that is not the importer —
             * and the thing being enabled is a feed that creates user accounts.
             * `wordpress-importer#821` states the requirement directly: a
             * disabled feed must import without the registration configuration
             * rather than with it silently on. D-004 governs how a content
             * value is shaped, not whether a storage artifact is allowed to
             * invert a flag on the way out.
             */
            'is_active' => (bool) ($feed['is_active'] ?? false),
            'feed_order' => self::int($feed, 'feed_order'),
            'event_type' => self::string($feed, 'event_type'),
            /*
             * Cast to an object, not left as the array `filterMeta()` built.
             * A PHP array with no entries — every one withheld, or a feed with
             * no settings at all — encodes as JSON `[]`, and a top-level array
             * whose keys are numeric (`GFAPI`'s own array-in-array feed shapes
             * can produce these) serializes as a bare list rather than a
             * keyed object, losing the keys entirely. `wordpress-importer#821`
             * reads `feed.meta.password` and breaks on exactly the feeds where
             * something was withheld, which is the case this endpoint exists
             * to handle correctly. The cast is type-stable regardless of
             * emptiness or key shape; it does not touch any VALUE nested
             * inside `meta`, which still encodes on its own terms.
             */
            'meta' => (object) $filtered['meta'],
            /*
             * Counts split by reason, never the names — D-032's convention, and
             * for its reason: reporting THAT something was withheld lets a
             * consumer tell "this feed has no such setting" from "we declined
             * to send it", while naming them would hand a reader the map of
             * which credentials this site holds and where.
             *
             * The split is worth more here than it is on an option row. Feed
             * meta is a mapping, so `by_name` firing at all is the signal that
             * a credential-named key held something other than a field
             * reference, and `by_shape` is the signal that an add-on nobody
             * enumerated is storing a token.
             */
            'denied' => [
                'total' => $filtered['by_name'] + $filtered['by_shape'],
                'by_name' => $filtered['by_name'],
                'by_shape' => $filtered['by_shape'],
            ],
        ];
    }

    /**
     * The served meta, and how much was withheld from it.
     *
     * Per ENTRY rather than per feed, which is the one place this diverges from
     * `OptionsPayload` and does so deliberately. An option row is atomic — a
     * credential anywhere inside it withholds the whole row, because the row is
     * the unit somebody configured. A feed is a record of many independent
     * settings, and withholding the whole thing over one gateway key would
     * discard the field mapping that is the entire reason the endpoint exists.
     *
     * Public because a Formidable form action is the same shape -- a record of
     * many independent settings, several of them field references -- and
     * `FormidablePayload` filters each action's settings through this rather
     * than carrying a second copy of the rule (D-081). The per-entry variant of
     * the value machinery lives here because it was written here first; the
     * name says where it lives, not what it is for.
     *
     * @param  array<mixed>  $meta
     * @param  list<string>  $fieldIds  Every form a field reference can take in this record.
     * @return array{meta: array<string, mixed>, by_name: int, by_shape: int}
     */
    public static function filterMeta(array $meta, array $fieldIds): array
    {
        $served = [];
        $byName = 0;
        $byShape = 0;

        foreach ($meta as $key => $value) {
            $key = (string) $key;

            if (self::deniedByName($key, $value, $fieldIds)) {
                $byName++;

                continue;
            }

            /*
             * One call, and it covers what used to take two. `classify()` runs
             * four rules for an option — exemption, name, the raw leaf shape,
             * then the decoded container — and this filter ran the first three
             * at the top level only. Passing the exemption and the decode flag
             * into the shared walk applies all of them at every depth instead,
             * which is what closed three nested disclosure paths at once.
             */
            if (OptionsPayload::secretIn($value, 0, $fieldIds, true) !== null) {
                $byShape++;

                continue;
            }

            $served[$key] = $value;
        }

        return ['meta' => $served, 'by_name' => $byName, 'by_shape' => $byShape];
    }

    /**
     * Whether this entry is withheld because of what its key is called.
     *
     * The three conditions are the same three `OptionsPayload::secretIn()`
     * applies one level down, plus the field-reference exemption this file's
     * docblock argues for. A non-string or empty value is not withheld for the
     * reason recorded there: a boolean under a credential-shaped name is a flag,
     * not a credential, and withholding a whole configuration over a blank field
     * is the same mistake in miniature.
     *
     * An ARRAY under a matching key is not waived here either — it falls
     * through to the shape rule, which walks into it and tests its own keys.
     * That is how a nested `auth_token` pair is caught.
     *
     * @param  list<string>  $fieldIds  Every field and input id on this feed's form.
     */
    private static function deniedByName(string $key, mixed $value, array $fieldIds): bool
    {
        /*
         * A NUMBER is a candidate, a flag is not. `json_decode()` leaves a
         * value an add-on wrote as a JSON number as an `int`, so
         * `['merchantPassword' => 481516234255]` arrives here typed and every
         * string-only rule would walk past it. A boolean under a
         * credential-shaped name is still a flag.
         */
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }

        /*
         * An ARRAY is judged by its key here, and that is the one place this
         * diverges from the nested rule deliberately. `OptionsPayload` withholds
         * an option named `credentials` by name whatever its value is; this
         * filter returned false before reaching the list, so only the CHILDREN's
         * keys were ever tested and a credential under a benign child key
         * traveled under a key called `credentials`.
         *
         * The cost is a settings group named like a credential and containing
         * only mappings, withheld whole. That is the safe direction and it is
         * visible — D-056 makes a non-zero `denied.by_name` the signal that a
         * mapping was withheld, and this is exactly the case it describes.
         */
        if (\is_array($value)) {
            return OptionsPayload::isDeniedNestedKey($key);
        }

        /*
         * A boolean is a flag and an empty string is a blank field, and neither
         * becomes a credential by sitting under a credential-shaped name.
         */
        if (! \is_string($value) || $value === '') {
            return false;
        }

        /*
         * Exempt because it IS a field on this form, not because it looks like
         * one. `OptionsPayload::secretIn()` applies the identical test at every
         * depth below this one, so the rule has a single behavior rather than
         * one at the top level and another underneath.
         */
        if (\in_array($value, $fieldIds, true)) {
            return false;
        }

        return OptionsPayload::isDeniedNestedKey($key);
    }

    /**
     * A feed's `form_id` as an integer, from the one place that decides it.
     *
     * `$wpdb` returns every column as a string. This was written out three
     * times across two files, and two of those copies had to AGREE or the
     * field-id exemption silently fell back to an empty list and withheld every
     * mapping on the collection route — `indexFeeds()` looks the ids up by this
     * value and `allFeeds()` stored them under it.
     *
     * @param  array<string, mixed>  $feed
     */
    public static function formIdOf(array $feed): int
    {
        return is_numeric($feed['form_id'] ?? null) ? (int) $feed['form_id'] : 0;
    }

    /**
     * Every field and input id on a form, as the strings feed meta stores.
     *
     * **This replaces a digit heuristic, and the difference is the whole
     * point.** The exemption exists because a User Registration feed stores
     * `password` as the ID OF THE FIELD the password comes from — withholding
     * it by name discards exactly the mapping the endpoint is for. Deciding
     * that by counting digits both over-exempted (a five-digit PIN under
     * `%password` was served) and rested on an assumption about how Gravity
     * Forms numbers fields rather than on this form's own data.
     *
     * `4` is a reference because field 4 exists here, and `4.3` because that
     * input exists — a composite field addresses its parts with an input index
     * after a period. Anything else is judged on its name and shape like any
     * other value, whatever it looks like.
     *
     * @param  array<mixed>  $form  A form as `GFAPI::get_form()` returned it.
     * @return list<string>
     */
    public static function fieldIdsFor(array $form): array
    {
        $fields = $form['fields'] ?? null;

        if (! \is_array($fields)) {
            return [];
        }

        $ids = [];

        foreach ($fields as $field) {
            $field = \is_object($field) ? get_object_vars($field) : $field;

            if (! \is_array($field)) {
                continue;
            }

            if (isset($field['id']) && (\is_string($field['id']) || \is_int($field['id']))) {
                $ids[] = (string) $field['id'];
            }

            $inputs = $field['inputs'] ?? null;

            if (! \is_array($inputs)) {
                continue;
            }

            foreach ($inputs as $input) {
                $input = \is_object($input) ? get_object_vars($input) : $input;

                if (\is_array($input) && isset($input['id']) && (\is_string($input['id']) || \is_int($input['id']))) {
                    $ids[] = (string) $input['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * One column as an integer.
     *
     * `$wpdb` returns every column as a string, ids included, so a consumer
     * comparing a feed's `form_id` against a form's `id` would be comparing a
     * string to an integer unless this cast happens somewhere.
     *
     * @param  array<string, mixed>  $feed
     */
    private static function int(array $feed, string $column): int
    {
        $value = $feed[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * One column as a string.
     *
     * `addon_slug` and `event_type` are both nullable by schema, so a row
     * written before an add-on set them arrives as `null` rather than absent.
     * An empty string keeps the key present and the type stable, which is the
     * same choice the endpoint makes for a form with no feeds.
     *
     * @param  array<string, mixed>  $feed
     */
    private static function string(array $feed, string $column): string
    {
        $value = $feed[$column] ?? null;

        return \is_string($value) ? $value : '';
    }
}
