<?php

/**
 * Default configuration for the migration API.
 *
 * Override with the `uamswp_migration_api_config` filter, or by dropping a
 * `config/local.php` alongside this file returning a partial array (it is
 * merged over these defaults, top level keys only).
 */

declare(strict_types=1);

return [

    /*
     * Post types eligible for exposure.
     *
     * This is an eligibility list, not an assertion that each exists — types not
     * registered on the current site are filtered out at read time, so one list
     * serves every site in the network.
     */
    'post_types' => [
        // Find-a-Doc.
        'location',
        'provider',
        'condition',
        'treatment',
        'clinical-resource',
        'expertise',
        // Present across the captured WXR exports.
        'post',
        'page',
        'attachment',
        'nav_menu_item',
        'uamspeople',
        'redirect_rule',
        'tablepress_table',
        'wp_global_styles',
        'logo-list-item',
        // Consumed by the importer but absent from every captured export.
        'wp_block',
        'kb',
    ],

    /*
     * What is deliberately NOT above, so this is not re-filed as an oversight.
     *
     * Swept across all three sites of the development network: every post type
     * holding rows is either listed above or named here. `revision` (962 rows)
     * is post history, not content, and the importer creates its own.
     * `acf-field` (22) and `acf-field-group` (3) are ACF's field DEFINITIONS —
     * carried by the ACF reader as structure, not as posts, and serving them
     * here would duplicate that. `oembed_cache` (1) is a cache.
     * `wp_navigation` (1, on sites 2 and 3) is the block-theme menu store; this
     * network's menus live in `nav_menu_item`, which IS listed, and a site that
     * moved to block navigation would need it added.
     *
     * `logo-list-item` was NOT in that category — it was simply missing, found
     * by the `wordpress-importer` side against a real `--source=api` run (3
     * records on site 1, a registered type, with a dedicated entry creator
     * waiting for it). An API-sourced migration omitted them silently, which is
     * the failure mode this project exists to prevent.
     */

    /*
     * Taxonomies eligible for exposure. Same filtering rule as post types.
     *
     * The Find-a-Doc set below STARTED as the 13 taxonomies carrying ACF field
     * groups, and is no longer only those — see the note above `region` at the
     * end of the list. That rule was a reason to include a taxonomy, never a
     * test for whether one is content, and reading it as the latter is what
     * dropped 22 assignments (D-053).
     */
    'taxonomies' => [
        'academic_college',
        'affiliation',
        'association',
        'board',
        'brand_organization',
        'brand_organization_uams',
        'building',
        'certifying_body',
        'clinical_title',
        'degree',
        'language',
        'parking',
        'portal',
        // Core.
        'category',
        'post_tag',
        'nav_menu',
        /*
         * `logo-list-item`'s own taxonomies, added WITH the post type rather
         * than after it.
         *
         * Exposing a post type without its taxonomies is not a partial win — it
         * is the failure mode this project exists to prevent, wearing a `200`.
         * `PostPayload::terms()` intersects the type's taxonomies with THIS
         * list, so all three `logo-list-item` records would have served with an
         * empty `terms` object and their category silently gone. Caught by the
         * pre-merge review one commit after the post type was added.
         *
         * `logo-list-label` carries no assignments on this network today; it is
         * listed anyway, because the reason to list it is that the type uses it.
         */
        'logo-list-category',
        'logo-list-label',
        /*
         * Find-a-Doc classification, added because the DESTINATION says it is
         * content (D-053).
         *
         * These were excluded by the "13 taxonomies carrying ACF field groups"
         * rule above rather than by a judgement about each one, and that rule
         * turned out to be the wrong test. Statamic has populated taxonomies
         * for all four — `regions` (5 terms), `location_types` (12), and
         * `google_business_profile_categories` (5), the last fielded on
         * `clinical_locations/location.yaml` — so excluding them dropped 22
         * real assignments and, for the GMB pair, foreclosed a mapping the
         * destination is visibly built for.
         *
         * The GMB pair is the one worth noting: it looks like integration
         * plumbing from BOTH upstream vantage points — one term each on this
         * install, and no reference anywhere in the importer's `src/` — and
         * the destination contradicts both. "Unused upstream" is not evidence
         * about whether a migration needs something.
         */
        'region',
        'location_type',
        'gmb_cat_location',
        'gmb_cat_provider',
        /*
         * The rest of what `provider`, `location` and `attachment` classify
         * with (D-054).
         *
         * Added as a SET rather than one ticket at a time, because the previous
         * two rounds of this were both "add the one somebody noticed" and both
         * left the rest silently dropping. The line drawn here is a rule
         * instead: a taxonomy attached to an exposed post type is exposed
         * unless it is editorial-workflow plumbing, and the exclusions are
         * enumerated in `scripts/verify-taxonomy-coverage.php` with reasons.
         *
         * Seven of these have open `wordpress-importer` tickets that cannot
         * start without them (#343, #339, #335, #352, #338, #355, #345). The
         * others have no ticket and no confirmed destination yet, and are
         * exposed anyway on the asymmetry D-053 records: withholding wrongly is
         * permanent silent loss and a foreclosed mapping, while carrying
         * wrongly is a handful of small read-only taxonomies riding along.
         */
        'patient_type',
        'service_line',
        'institute_affiliation',
        'academic_department',
        'school',
        'educationtype',
        'academic_position',
        'recognition',
        'residency_year',
        'academic_admin_title',
        'clinical_admin_title',
        'academic_title',
        'attachment_category',
        'attachment_tag',
        /*
         * MinervaKB and People Showcase, added 2026-08-24 (D-054).
         *
         * These were invisible to #75's sweep, and not because the sweep was
         * wrong. `UAMSWP-minervakb` and `UAMSWP-People-Showcase` were
         * DEACTIVATED on the development install when it ran, so their
         * taxonomies were never registered and nothing could enumerate them.
         * Activating the two plugins took registration from 47 to 51 and
         * revealed 34 assignments the API was dropping.
         *
         * `kbtag` carries no terms here and has no destination identified; it
         * is listed with the other two because withholding it would be the
         * same bet #75 already lost twice.
         */
        'kbtopic',
        'uamspeople-categories',
        'kbtag',
    ],

    /*
     * Option-name patterns never served, as SQL LIKE patterns.
     *
     * Deliberately a denylist: the options endpoint serves the whole table so
     * that nothing has to be enumerated, and an allowlist would reintroduce the
     * enumeration problem it exists to avoid. The failure direction is
     * therefore leaking a key rather than dropping a setting.
     *
     * REVIEWED TWICE, and the second review is why this list looks like it
     * does. The first covered the development site — 1,223 options, one site —
     * and produced a list that would have leaked credentials on **32 of the 33
     * production sites**. The second covered all 33 (#6, D-032):
     *
     *   `acf_pro_license`                 32 sites, 128-172 chars of base64
     *   `tidio-access-token`              a JWT, 708 chars
     *   `tidio-refresh-token`             1,504 chars
     *   `ninja-forms-views-secret`        40 chars of base64
     *   `wp-short-pixel-apiKey`           11 sites
     *   `wp-short-pixel-cloudflareToken`  hyphenated and camelCase
     *   `frmpro-credentials`              Formidable Pro
     *
     * The cause was structural rather than a handful of oversights: every
     * pattern assumed `snake_case` (`%\\_token`), so an entire naming convention
     * passed through. Of 58 undenied names, six used hyphens and two used
     * camelCase — and all eight were credential-bearing.
     *
     * The fix is to drop the separator from the pattern rather than enumerate
     * separators. `%token` is anchored at the end and matched case-insensitively,
     * so it covers `gh_token`, `tidio-access-token` and `cloudflareToken` with
     * one reviewable line. Checked against all 751 flagged options across the
     * 33 sites: the bare suffixes catch every credential found and match nothing
     * benign. `%key` is the one worth watching — a future option named
     * `…monkey` would be withheld — and that is the accepted direction.
     *
     * Only the American spelling of `%license` is listed. The British form is
     * unobserved across all 33 sites, and this repo does not carry British
     * spellings; a value behind such a name would still be caught by shape.
     *
     * Note the absence of a blanket `\_%` rule. Core's own network
     * site-settings screen excludes every underscore-prefixed option, which
     * would drop ACF's `_options_<field>` field-key references. Transients are
     * excluded by name instead, and `_options_%` is exempted outright in
     * `OptionsPayload` — those rows hold field-key references by construction.
     *
     * Two patterns were REMOVED by the first review, both false positives that
     * dropped settings while protecting nothing: `%_keys`, which caught core's
     * `disallowed_keys` and `moderation_keys`, and `%user_roles`, which caught
     * the role definitions.
     *
     * Names are only half the guard. `OptionsPayload` also withholds a value
     * whose SHAPE reads as a secret whatever it is called, which is what caught
     * `acf_pro_license` in the audit before any pattern named it.
     */
    'option_denylist' => [
        // Caches, never content.
        '\\_transient\\_%',
        '\\_site\\_transient\\_%',

        // Credentials, by the word the name ends in. Bare suffixes rather than
        // separator variants: matching is case-insensitive and anchored at the
        // end, so one pattern covers `gh_token`, `tidio-access-token` and
        // `cloudflareToken` alike. Verified against all 751 flagged options on
        // the 33-site network — these catch every credential found and produce
        // **no** false positives there.
        '%key',              // frm_api_key, gf_site_key, wp-short-pixel-apiKey, rg_gforms_key
        '%token',            // gh_token, wppusher_token, tidio-access-token, cloudflareToken
        '%secret',           // gf_site_secret, ninja-forms-views-secret
        '%password',
        '%credential',       // frmpro-credentials matches via the plural below
        '%credentials',
        '%license',          // acf_pro_license, facetwp_license
        '%salt',             // nothing in wp_options — the salts live in sitemeta

        // The same words where they are not final.
        '%api\\_key%',
        '%apikey%',
        '%private\\_key%',
        '%access\\_token%',
        '%client\\_secret%',
        '%password%',
    ],

    /*
     * The patterns that are also applied to keys INSIDE a value.
     *
     * A settings row like `wp_mail_smtp` is one option holding a whole
     * configuration, and the credential is a key several levels down rather
     * than the option itself (#33). `OptionsPayload` walks a decoded value and
     * tests each key here — but with a **shorter list than the one above**,
     * because a pattern's precision does not survive the move.
     *
     * Measured against all 1,223 options on this install, applied to nested
     * keys:
     *
     * - `%key` — 22 hits, every one noise: FacetWP's `templates.12.query_obj.orderby.0.key`
     *   is a sort column, `is_valid_key` is a boolean. A top-level option named
     *   `…key` is somebody naming a credential; a *key* named `key` is just a
     *   key. Excluded.
     * - `%password%` — 2 hits, both MinervaKB UI label text
     *   (`restrict_login_password_label_text`). Excluded, while the bare
     *   `%password` suffix stays: a nested key named exactly `password` is a
     *   password.
     * - everything else — 8 hits, of which 2 survive the value rule below, and
     *   both are real: `PMXE_Plugin_Options.zapier_api_key` and
     *   `_asp_media_service_data.license`.
     *
     * A nested match withholds the whole option, so `OptionsPayload` fires this
     * list only on a **non-empty string** — and both halves were bought with a
     * measurement. Keyed on the name alone it also withheld
     * `wp_user_roles.administrator.capabilities.seopress_manage_license`, a
     * capability named after a license screen whose value is boolean `true`,
     * taking all 22 KB of the network's role definitions with it — precisely
     * the false positive D-032 already paid for once with `%user_roles`. Four
     * more of the eight hits are empty strings, WP All Import's unused
     * `license` among them. An empty credential is not a credential, and a
     * boolean is not one either.
     *
     * That is the opposite of the call at the top level, where a blank
     * `wp-short-pixel-apiKey` is still withheld — there the option *is* the
     * credential, and its emptiness is a fact about one site rather than about
     * the name.
     */
    'option_denylist_nested_keys' => [
        '%token',            // access_token, refresh_token, auth_token, server_api_token
        '%secret',           // client_secret, gmp_api_secret
        '%password',
        '%credential',
        '%credentials',
        '%license',
        '%salt',
        '%api\\_key%',
        '%apikey%',
        '%private\\_key%',
        '%access\\_token%',
        '%client\\_secret%',
        // WP All Import's scheduled-import secret, inside `PMXI_Plugin_Options`
        // (#320). Named exactly rather than as `%\_key`: `image_sizer_queue`
        // carries a transient name under `transient_key` in every entry, and
        // a suffix pattern would withhold the whole queue for it.
        'cron\\_job\\_key',
        // The marketplace purchase code the Gravity Forms File Upload add-on
        // (`gravityforms-rcwdupload`) keeps inside
        // `gforms_rcwdupload_verifypurchase` (#455). Named exactly for the
        // reason `cron_job_key` is: `%purchase%` would also match the boolean
        // `purchased` beside it, harmless only because this rule skips
        // non-strings, and not obviously safe on the next plugin.
        'purchase\\_code',
    ],

    /*
     * The plugin set the option denylist was last reviewed against.
     *
     * The denylist is only as current as the plugins it was checked against —
     * a new plugin brings new option names, and #6 proved how that fails: a
     * list reviewed against one site would have leaked credentials on 32 of
     * 33, because five of the plugins holding them were not installed where
     * the review happened.
     *
     * Slugs rather than a hash, deliberately. A hash tells you *that*
     * something changed; a sorted list shows *what* in the commit diff, which
     * is what a reviewer needs. Slugs rather than slug+version, also
     * deliberately: including versions would make this change on every routine
     * update and train everyone to ignore it. The cost is that a plugin
     * introducing a new option name in a new version goes unnoticed — an
     * accepted gap, and the reason the value-shape rule exists.
     *
     * The **union across all 33 production sites**, captured 2026-08-14 from
     * `active_plugins` per site plus network-wide `active_sitewide_plugins`,
     * reduced to directory slugs and sorted. It is the same network and the
     * same moment the denylist audit covered, so the two describe each other.
     *
     * A union rather than a per-site list because plugin sets genuinely vary:
     * measured at min 0, max 27 and a median of 2 per-site activations, with
     * **33 of these 68 slugs active on only one or two sites**. That is why
     * `OptionsPayload` compares by subset rather than equality — no individual
     * site ever equals a union, and an equality test would report `stale` on
     * all 33 forever.
     *
     * `uamswp-migration-api` is in the list although it was not in the capture:
     * the capture predates its deployment, and the moment it is activated it
     * becomes a slug every site reports. Leaving it out would have made `stale`
     * true on all 33 sites from the first production request onward — the
     * always-on signal the subset comparison exists to avoid, arriving by a
     * different door.
     *
     * Twelve slugs were added on 2026-09-28 after `communications.uams.edu/web`
     * reported them as `added` and the denylist was audited against that
     * site's full options table (#273): the six Formidable slugs,
     * `gravityformspolls`, `gravityformssurvey`, `simple-history`,
     * `beyond-multisite`, `safe-redirect-manager` and
     * `UAMSWP-gravityforms-notification`. The one miss that audit found sits
     * in another plugin's settings, and #320 withholds it.
     *
     * Twenty-five slugs were added after #456 reviewed every served option they
     * write on `communications.uams.edu` sites 1 and 6: the Gravity Perks and
     * Gravity Wiz add-ons, `formidable-gravity-forms-importer`,
     * `formidable-signature`, `gravityforms-rcwdupload`,
     * `gravity-forms-remove-entries`, `gravityplus-gfutility`, `blob-mimes` and
     * `query-monitor`. That review found two credentials, which #455 and #457
     * withhold; this list carries the slugs only now that both are withheld,
     * since it is what turns `stale` off for those sites.
     *
     * Refresh it from production when plugins change. #31 carries the SQL for a
     * host with no shell, which is the route to use: `scripts/audit-option-denylist.php`
     * reports on the denylist and reads no plugin list at all.
     */
    'option_denylist_reviewed_against' => [
        'acf-custom-database-tables',
        'acf-extended',
        'acf-input-counter-master',
        'acf-maskfield',
        'admin-columns-pro',
        'advanced-custom-fields-pro',
        'ajax-search-pro',
        'better-image-sizes',
        'beyond-multisite',
        'blob-mimes',
        'bulk-block-converter',
        'content-audit',
        'disable-comments',
        'edit-flow',
        'enable-media-replace',
        'export-all-urls',
        'export-media-urls',
        'facetwp',
        'facetwp-alpha-uams',
        'facetwp-cache',
        'formidable',
        'formidable-api',
        'formidable-bootstrap',
        'formidable-gravity-forms-importer',
        'formidable-pro',
        'formidable-signature',
        'formidable-user-tracking',
        'formidable-views',
        'geot-maxmind',
        'gf-entries-in-excel',
        'gp-advanced-calculations',
        'gp-easy-passthrough',
        'gp-entry-blocks',
        'gp-file-upload-pro',
        'gp-limit-dates',
        'gp-limit-submissions',
        'gp-live-preview',
        'gp-nested-forms',
        'gp-notification-scheduler',
        'gp-populate-anything',
        'gp-qr-code',
        'gravity-forms-remove-entries',
        'gravity-forms-wcag-20-form-fields',
        'gravity-forms-zero-spam',
        'gravityforms',
        'gravityforms-rcwdupload',
        'gravityformsgoogleanalytics',
        'gravityformspolls',
        'gravityformsquiz',
        'gravityformssignature',
        'gravityformssurvey',
        'gravityperks',
        'gravityplus-gfutility',
        'gw-gravity-forms-custom-js',
        'gwconditionallogicdates',
        'gwcopycat',
        'gwlimitcheckboxes',
        'gwlimitchoices',
        'gwtermsofservice',
        'media-library-assistant',
        'multisite-enhancements',
        'my-eyes-are-up-here',
        'ninja-forms',
        'ninja-forms-conditionals',
        'ninja-forms-excel-export',
        'ninja-forms-paypal',
        'query-monitor',
        'regenerate-thumbnails',
        'relevanssi-premium',
        'safe-redirect-manager',
        'shortcodes-finder',
        'shortpixel-image-optimiser',
        'show-modified-date-in-admin-lists',
        'simple-history',
        'tablepress',
        'tablepress-premium',
        'taxonomy-converter',
        'tidio-live-chat',
        'transients-manager',
        'uamswp-batch-processing-fad',
        'uamswp-block-remover',
        'UAMSWP-Content-Syndication',
        'UAMSWP-Find-a-Doc',
        'UAMSWP-gravityforms-notification',
        'UAMSWP-Logo-List',
        'uamswp-migration-api',
        'UAMSWP-minervakb',
        'UAMSWP-News-Syndication-2020',
        'UAMSWP-People-Showcase',
        'uamswp-per-site-wayfinding',
        'UAMSWP-Pusher',
        'UAMSWP-Safe-Redirect-Manager',
        'uamswp-seopress',
        'uamswp-seopress-pro',
        'uamswp-youtube-lyte',
        'user-role-editor',
        'user-switching',
        'velvet-blues-update-urls',
        'widget-css-classes',
        'wordpress-importer',
        'WordPress-Importer',
        'wp-all-export-pro',
        'wp-crontrol',
        'wp-mail-smtp-pro',
        'wps-hide-login',
    ],

    /*
     * Identifiers served in a record's `identifiers` block, by post type (#88,
     * D-104).
     *
     * The one curated shape in the payload, kept beside `meta` and `acf` rather
     * than in place of them: those still carry every row as stored. Each entry
     * names the key it is served under and where it is read from:
     *
     * - `meta`: one postmeta row, read verbatim. People Showcase stores its
     *   fields as plain `_ps`-prefixed postmeta with no ACF layer.
     * - `acf`: one ACF field by name, from the reader's stored view, which is
     *   the only route to the current Find-a-Doc values (D-005).
     * - `acf_list`: one ACF repeater by name, served as one string per row in
     *   row order, taken from the sub-field named in `column`.
     *
     * The served keys match the Statamic `people` identifiers fieldset, so the
     * importer maps both people sources the same way. The source names are the
     * ones the upstream tickets give: UAMS-Web/UAMSWP-People-Showcase#52,
     * UAMS-Web/UAMSWP-Find-a-Doc#815 and #816. When one is renamed upstream,
     * this is the only line that changes.
     *
     * The four `_ps` keys are People Showcase's own meta keys, so they are
     * names rather than prose. cspell:ignore psworkdayuniversalid psworkdayemployeeid psworkdaycontingentworkerid psworkdaystudentid
     */
    'identifiers' => [
        'uamspeople' => [
            'workday_universal_id' => ['meta' => '_psworkdayuniversalid'],
            'workday_employee_id' => ['meta' => '_psworkdayemployeeid'],
            'workday_contingent_worker_id' => ['meta' => '_psworkdaycontingentworkerid'],
            'workday_student_id' => ['meta' => '_psworkdaystudentid'],
        ],
        'provider' => [
            'workday_universal_id' => ['acf' => 'physician_workday_universal_id'],
            'workday_employee_id' => ['acf' => 'physician_workday_employee_id'],
            'workday_contingent_worker_id' => ['acf' => 'physician_workday_contingent_worker_id'],
            'workday_student_id' => ['acf' => 'physician_workday_student_id'],
        ],
        'location' => [
            'epic_dep_ids' => ['acf_list' => 'location_epic_dep_ids', 'column' => 'location_epic_dep_id_item'],
        ],
    ],

    /*
     * Pagination bounds shared by every collection endpoint.
     */
    'per_page_default' => 50,
    'per_page_max' => 200,
];
