<?php

/**
 * The form plugin API surface this repo classifies, shared by two suites.
 */

declare(strict_types=1);

/*
 * WHY THIS FILE EXISTS, AND IT IS A CORRECTION.
 *
 * These lists are read by two tests that cannot live in the same suite. The
 * CALLER guards are static scans of `includes/` and need no WordPress, so they
 * belong in `tests/Unit`. The guards that re-derive the surface by reflection
 * need a loaded `GFAPI`, and Gravity Forms is only ever loaded by the Feature
 * bootstrap — so in `tests/Unit` they skip on EVERY run, on every machine, for
 * the whole life of the file.
 *
 * That is what was happening. `FormEntryBoundaryTest` promised its list "cannot
 * silently fall behind again" because a companion test re-derived it, and that
 * companion had never once executed: `class_exists( 'GFAPI' )` is false in a
 * process with no WordPress, so the skip was unconditional rather than
 * environmental. It is the failure that file's own negative controls exist to
 * catch — a guard that is green because it inspected nothing — reached by a
 * different route, and the reason it survived is that a skip reads like a
 * deliberate accommodation rather than a defect.
 *
 * So the lists move here and the reflection guards move to `tests/Integration`,
 * where the plugin they reflect over is actually present. There they skip only
 * on CI, which is the arrangement D-030 and D-044 describe and pay for.
 */

/**
 * The name fragments that make a `GFAPI` method a submission method.
 *
 * `notification` is here because `GFAPI::send_notification()` emails an
 * entry's contents, which is a disclosure without ever returning the row.
 *
 * Note what is deliberately absent: `feed`. Adding it would sweep up
 * `GFAPI::get_feeds()`, which this repo calls, so the feed surface is
 * classified by its own two lists below rather than by a marker.
 *
 * @return list<string>
 */
function entryApiMarkers(): array
{
    return ['entry', 'entries', 'note', 'notification', 'submit_form'];
}

/**
 * `GFAPI` methods that read or write submissions.
 *
 * Named individually rather than matched on a prefix, because `GFAPI` is an
 * allowed class — the form endpoints read definitions through it — so the line
 * runs between its methods rather than around the class.
 *
 * **This list was short of the real surface and the gap was the whole guard.**
 * It named ten methods; `GFAPI` exposes twenty-four that touch a submission.
 * `count_entries`, `entry_exists`, `submit_form` (which CREATES an entry), the
 * five note methods and both `send_notification*` were all unguarded, so a call
 * to any of them passed a test whose name promises it never calls one.
 *
 * @return list<string>
 */
function entryApiMethods(): array
{
    return [
        'add_entries', 'add_entry', 'count_entries', 'delete_entry', 'entry_exists',
        'get_entries', 'get_entry', 'get_entry_ids',
        'get_entry_feed_status', 'get_entry_feed_status_key', 'update_entry_feed_status',
        'update_entries', 'update_entry', 'update_entry_field', 'update_entry_property',
        'add_note', 'delete_note', 'get_note', 'get_notes', 'update_note',
        'send_notification', 'send_notifications',
        'submit_form', 'submit_form_filter_gform_pre_validation',
    ];
}

/**
 * Post types that are submission storage rather than content.
 *
 * `nf_sub` is Ninja Forms. Gravity Forms and Formidable keep entries in their
 * own tables rather than in `wp_posts`, so they have no entry post type to
 * list — their exposure is the table names and the entry API, guarded
 * elsewhere.
 *
 * @return list<string>
 */
function entryPostTypes(): array
{
    return ['nf_sub', 'frm_entry', 'gf_entry'];
}

/**
 * `GFAPI` feed methods this repo is allowed to call.
 *
 * The read half of the feed surface, and the whole of what #80 needed.
 *
 * @return list<string>
 */
function feedReadMethods(): array
{
    return ['get_feeds', 'get_feed', 'get_feed_name', 'feed_exists'];
}

/**
 * `GFAPI` feed methods that write configuration or run a feed against an entry.
 *
 * **Two different hazards, banned together because both are one word away from
 * the call this repo makes.**
 *
 * The writes — `add_feed`, `update_feed`, `delete_feed`,
 * `update_feed_property`, `encrypt_feed_meta` — would edit a production site
 * from a read-only migration endpoint. Nothing else in this plugin can write at
 * all, which is exactly why nothing else needed saying.
 *
 * `maybe_process_feeds` is the one that matters. It takes an ENTRY and runs the
 * form's feeds against it, which for the add-on #80 exists to serve means
 * **creating a WordPress user account**, and for a payment add-on means charging
 * a card. It reads like a query and it is an action.
 *
 * `get_processed_feeds_meta` and `update_processed_feeds_meta` are entry meta by
 * another name — both are keyed by `$entry_id` — so they cross D-048's line
 * without carrying the word `entry` that `entryApiMarkers()` looks for. All
 * three were unguarded before #80: the marker list catches
 * `get_entry_feed_status` because of where the word `entry` happens to sit in
 * the name, and misses these three because of where it does not.
 *
 * @return list<string>
 */
function feedWriteMethods(): array
{
    return [
        'add_feed', 'update_feed', 'delete_feed', 'update_feed_property', 'encrypt_feed_meta',
        'maybe_process_feeds', 'get_processed_feeds_meta', 'update_processed_feeds_meta',
    ];
}
