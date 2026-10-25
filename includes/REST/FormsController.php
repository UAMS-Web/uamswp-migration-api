<?php

/**
 * Form-definition endpoints.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use FrmForm;
use GFAPI;
use stdClass;
use UAMSWP\MigrationAPI\Content\FormidablePayload;
use UAMSWP\MigrationAPI\Content\GravityFeedPayload;
use UAMSWP\MigrationAPI\Content\NinjaPayload;
use UAMSWP\MigrationAPI\Pagination;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /forms/gravity` and `GET /forms/gravity/<id>`.
 *
 * Gravity Forms never came through WXR. Definitions are sourced today from JSON
 * produced by hand through the plugin's exporter and committed as fixtures —
 * **161 of them** in the importer's tree — with the same defects as WXR capture:
 * a manual step, a snapshot stale on arrival, no incremental re-pull.
 *
 * **Definitions only.** `wp_gf_entry` and its meta hold submitted data for a
 * health system, and the importer references no entry API anywhere — verified
 * against its source, not assumed. Serving submissions nothing reads would put
 * data with a materially different privacy profile into the payload for no
 * benefit. The question is deferred rather than dismissed, in #54.
 *
 * Read through `GFAPI`, never raw SQL: the plugin's own API is the only
 * version-safe reader of its schema, and it is what assembles a form's fields,
 * notifications and confirmations into the shape the fixtures already carry.
 *
 * **The payload is not plain arrays, and does not need to be.** `GFAPI` returns
 * each field as a `GF_Field` subclass object rather than an array, so the
 * records this controller hands to `WP_REST_Response` are objects nested inside
 * arrays. They encode correctly — every public property lands in the JSON, so a
 * consumer receives `type`, `label`, `isRequired`, `choices` and
 * `conditionalLogic` exactly as an export carries them — which is why nothing
 * here converts them. Worth knowing before writing a test: comparing
 * `get_data()` against a decoded fixture compares objects with arrays and fails
 * for a reason that has nothing to do with the data.
 *
 * The two routes serve the same shape by construction rather than by agreement:
 * `GFAPI::get_forms()` is a loop over `GFAPI::get_form()` (`includes/api.php`),
 * so there is no lighter collection representation to drift from the single
 * record. What the two do NOT share is their trash rule, which is why
 * `show()` re-checks it below.
 */
final class FormsController extends Controller
{
    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute('/forms/gravity', [$this, 'index'], Pagination::params());
        $this->registerReadRoute('/forms/gravity/(?P<id>[\d]+)', [$this, 'show']);

        /*
         * Feeds are SIBLING routes rather than a key on the form record, and
         * that is the load-bearing half of #80's shape.
         *
         * `GET /forms/gravity/<id>` serves `GFAPI::get_form()` output verbatim,
         * which is byte-for-byte the native form array a Gravity Forms export
         * file contains. `wordpress-importer#809` is built on exactly that
         * property — its 12.6k-line fieldtype mapper sees identical input from
         * either source — and `GravityFormsFixtureParityTest` is what holds it.
         * Adding a `feeds` key inside the form array would end it for the
         * benefit of saving a consumer one request.
         *
         * `feeds` cannot collide with the id route above: the id pattern is
         * `[\d]+` and this segment is a word.
         */
        $this->registerReadRoute('/forms/gravity/feeds', [$this, 'indexFeeds'], Pagination::params());
        $this->registerReadRoute('/forms/gravity/(?P<id>[\d]+)/feeds', [$this, 'showFormFeeds']);

        $this->registerReadRoute('/forms/formidable', [$this, 'indexFormidable'], Pagination::params());
        $this->registerReadRoute('/forms/formidable/(?P<id>[\d]+)', [$this, 'showFormidable']);

        $this->registerReadRoute('/forms/ninja', [$this, 'indexNinja'], Pagination::params());
        $this->registerReadRoute('/forms/ninja/(?P<id>[\d]+)', [$this, 'showNinja']);
    }

    /**
     * A page of form definitions.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::gravityFormsAvailable()) {
            return self::unavailable($request, 'Gravity Forms');
        }

        $pagination = Pagination::fromRequest($request);
        $forms = self::allForms();

        /*
         * Sliced rather than filtered here: `allForms()` has already dropped
         * the entries `GFAPI` could not resolve, so the page and the total
         * below are computed from one list and cannot disagree.
         */
        $records = \array_slice($forms, $pagination->offset(), $pagination->perPage);

        return $pagination->decorate(new WP_REST_Response($records), \count($forms));
    }

    /**
     * One form definition.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::gravityFormsAvailable()) {
            return self::unavailable($request, 'Gravity Forms');
        }

        $form = GFAPI::get_form(self::intParam($request, 'id'));

        /*
         * `GFAPI::get_form()` returns `false` only for an id that is not there.
         * A TRASHED form it returns normally — it calls
         * `GFFormsModel::get_form( $id, true )`, and that second argument is
         * `$allow_trash`. Read the other way round from the collection, which
         * filters `is_trash = 0` in SQL, so the plugin's own two readers
         * disagree about deleted forms.
         *
         * Left to itself that ships a real defect rather than an inconsistency:
         * the collection withholds a trashed form and this route hands the same
         * id back, so the endpoint that is meant to be the safe one is the one
         * that serves deleted content. Re-checked here so both routes answer
         * the same question, and 404 for the same reason a missing id does —
         * from a consumer's side "deleted" and "not there" are one state.
         */
        if (! \is_array($form) || self::isTrashed($form)) {
            return new WP_Error(
                'uamswp_migration_api_not_found',
                __('No Gravity Forms form with that id.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response($form);
    }

    /**
     * A page of add-on feeds on this site.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function indexFeeds(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::gravityFormsAvailable()) {
            return self::unavailable($request, 'Gravity Forms');
        }

        $pagination = Pagination::fromRequest($request);
        $feeds = self::allFeeds(null, $fieldIdsByForm);

        if ($feeds instanceof WP_Error) {
            return self::feedQueryFailed($feeds);
        }

        $records = [];

        foreach (\array_slice($feeds, $pagination->offset(), $pagination->perPage) as $feed) {
            $records[] = GravityFeedPayload::build($feed, $fieldIdsByForm[GravityFeedPayload::formIdOf($feed)] ?? []);
        }

        return $pagination->decorate(new WP_REST_Response($records), \count($feeds));
    }

    /**
     * Every add-on feed attached to one form.
     *
     * **Not paginated, and 404s for a form the collection withholds.** Both
     * follow from what this route is: the feeds on one form are a handful of
     * records that belong together, and a consumer reading them is already
     * holding the form. The 404 is the parity `show()` had to be given by hand
     * — without it `/forms/gravity/999/feeds` answers `200` with an empty list
     * for a form that does not exist, and a trashed form's feeds outlive the
     * form itself on the one route that is meant to be the safe one.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function showFormFeeds(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::gravityFormsAvailable()) {
            return self::unavailable($request, 'Gravity Forms');
        }

        $id = self::intParam($request, 'id');
        $form = GFAPI::get_form($id);

        if (! \is_array($form) || self::isTrashed($form)) {
            return new WP_Error(
                'uamswp_migration_api_not_found',
                __('No Gravity Forms form with that id.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        $records = [];

        /*
         * Read from the map rather than computed here. `allFeeds()` resolves
         * this form for the trash filter either way, so calling `fieldIdsFor()`
         * separately loaded and walked the same form a second time — which the
         * parameter's own docblock says this arrangement exists to prevent.
         */
        $feeds = self::allFeeds($id, $fieldIdsByForm);

        if ($feeds instanceof WP_Error) {
            return self::feedQueryFailed($feeds);
        }

        foreach ($feeds as $feed) {
            $records[] = GravityFeedPayload::build($feed, $fieldIdsByForm[$id] ?? []);
        }

        /*
         * An empty LIST for a form with no feeds, which is the whole point of
         * serving this rather than letting a consumer infer it. "No feeds" and
         * "feeds are not served here" have to be different answers, or an
         * importer cannot tell a form that creates no user from one whose
         * configuration it simply never received.
         */
        return new WP_REST_Response($records);
    }

    /**
     * Every add-on feed, ordered by id ascending, for one form or the site.
     *
     * **Three corrections to the obvious call, and each one loses data.**
     *
     * 1. **`$is_active` defaults to `true`.** `GFAPI::get_feeds()` is declared
     *    `( $feed_ids = null, $form_ids = null, $addon_slug = null,
     *    $is_active = true )`, so the call everyone writes silently drops every
     *    DISABLED feed. That is `GFAPI::get_forms()`'s defect exactly (D-043),
     *    in the same direction, one method along. A disabled feed is
     *    configuration the destination still needs — `wordpress-importer#821`
     *    requires telling a disabled registration feed from an absent one — so
     *    the argument is widened to `null`, which the plugin documents as "both",
     *    and `is_active` travels on the record instead.
     * 2. **An empty result is a `WP_Error`, not an empty array.** The method
     *    ends `if ( empty( $results ) ) { return new WP_Error( 'not_found', … ) }`.
     *    A form with no feeds is the ordinary case and it arrives looking like
     *    a failure.
     * 3. **A missing TABLE is a different `WP_Error`.** `gf_addon_feed` is
     *    created by `GFFeedAddOn::setup()`, not by Gravity Forms itself, so on
     *    a site that has never had a feed add-on installed the table does not
     *    exist and `get_feeds()` returns `get_missing_table_wp_error()`.
     *    Measured on the development network: 25 `gf_*` tables and no
     *    `gf_addon_feed` among them.
     *
     * All three of the last two collapse to the same answer here, and doing so
     * is a decision rather than laziness: from a consumer's side "this form has
     * no feeds", "nothing on this site has feeds" and "no add-on ever created
     * the table" are one state, and the endpoint should not invent a failure
     * out of a site that simply does not use the feature.
     *
     * **A fourth case does not collapse.** `get_feeds()`'s `$wpdb->get_results()`
     * call returns empty on a genuine query error exactly as it does on zero
     * matching rows, and `GFAPI` reports both as the identical `not_found`
     * error — it cannot tell them apart from its own return value. A dropped
     * connection or a missing `SELECT` grant would otherwise answer `200` with
     * `X-WP-Total: 0`, reporting "this site has no feeds" for a site that was
     * never actually asked. `$wpdb::$last_error` is the one signal `GFAPI`
     * does not surface but does not erase either: WordPress core sets it only
     * on a real failure and leaves it empty on an ordinary empty result, so it
     * is what distinguishes the two cases sharing one error code.
     *
     * `GFAPI` offers no ordering, so ids are sorted here rather than trusted
     * from MySQL, on D-006 like every other collection.
     *
     * @param  array<int, list<string>>|null  $fieldIdsByForm  Filled with each
     *                                                         servable form's
     *                                                         field ids. The
     *                                                         trash filter
     *                                                         below already
     *                                                         loads the form,
     *                                                         so collecting
     *                                                         them here is
     *                                                         what stops the
     *                                                         payload loading
     *                                                         it a second time.
     *
     * @param-out array<int, list<string>>  $fieldIdsByForm
     *
     * @return list<array<string, mixed>>|WP_Error
     */
    private static function allFeeds(?int $formId, ?array &$fieldIdsByForm = null): array|WP_Error
    {
        $fieldIdsByForm = [];

        $feeds = GFAPI::get_feeds(null, $formId, null, null);

        if ($feeds instanceof WP_Error) {
            /*
             * `missing_table` is always ordinary: no feed add-on has ever run
             * on this site, so there is nothing a real failure could look
             * like here.
             */
            if ($feeds->get_error_code() === 'missing_table') {
                return [];
            }

            global $wpdb;

            /*
             * `not_found` is ordinary only when nothing actually went wrong —
             * see the docblock above. A non-empty `$wpdb->last_error` means
             * the query itself failed, and that is not this endpoint's to
             * silently swallow.
             */
            if ($feeds->get_error_code() === 'not_found' && $wpdb instanceof \wpdb && $wpdb->last_error === '') {
                return [];
            }

            return $feeds;
        }

        $records = array_values($feeds);

        /*
         * A feed outlives its form. `GFFormsModel::trash_form()` sets
         * `is_trash=1` and never touches `gf_addon_feed`, and a permanent
         * delete only clears feeds when the add-on is ACTIVE — `delete_feeds()`
         * runs off `gform_after_delete_form`, which `GFFeedAddOn::init()`
         * registers. So a trashed form, and a deleted one whose add-on was off,
         * both leave their feeds in the table.
         *
         * `showFormFeeds()` already refuses those by resolving the form first.
         * Without the same rule here the COLLECTION served what the by-form
         * route withheld, field mapping and all, and counted it in
         * `X-WP-Total` — a consumer crawling the collection would carry
         * registration behavior for a form it never imported.
         *
         * Resolved once per distinct form rather than once per feed: several
         * feeds on one form are the ordinary case, and D-045's flat per-page
         * cost is the reason this caches rather than calling in the loop.
         */
        $servable = [];

        $records = array_values(array_filter($records, static function (array $feed) use (&$servable, &$fieldIdsByForm): bool {
            $formId = GravityFeedPayload::formIdOf($feed);

            if ($formId === 0) {
                return false;
            }

            if (! \array_key_exists($formId, $servable)) {
                $form = GFAPI::get_form($formId);

                $servable[$formId] = \is_array($form) && ! self::isTrashed($form);

                if ($servable[$formId] && \is_array($form)) {
                    $fieldIdsByForm[$formId] = GravityFeedPayload::fieldIdsFor($form);
                }
            }

            return $servable[$formId];
        }));

        usort($records, static function (array $a, array $b): int {
            $left = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
            $right = is_numeric($b['id'] ?? null) ? (int) $b['id'] : 0;

            return $left <=> $right;
        });

        return $records;
    }

    /**
     * The error for a genuine `GFAPI::get_feeds()` query failure.
     *
     * Distinct from `unavailable()`: this is not "the plugin is not active
     * here", it is "the plugin is active and its own query failed" — a
     * dropped connection or a missing grant, not an absent feature. `500`,
     * because the fault is on this side, not the caller's.
     */
    private static function feedQueryFailed(WP_Error $error): WP_Error
    {
        return new WP_Error(
            'uamswp_migration_api_feed_query_failed',
            sprintf(
                /* translators: %s: the underlying GFAPI error message. */
                __('Reading Gravity Forms add-on feeds failed: %s', 'uamswp-migration-api'),
                $error->get_error_message()
            ),
            ['status' => 500]
        );
    }

    /**
     * Whether a form array carries the trash flag.
     *
     * `GFAPI::get_form()` copies `is_trash` across from the form row, so it
     * arrives as the database gave it — the STRING `'1'`, not a boolean. A
     * `=== true` test would pass every trashed form through, which is why this
     * is a loose truth test on a value that is only ever `'0'` or `'1'`.
     *
     * @param  array<mixed>  $form  A form as `GFAPI` returned it.
     */
    private static function isTrashed(array $form): bool
    {
        return (bool) ($form['is_trash'] ?? false);
    }

    /**
     * A page of Formidable form definitions.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function indexFormidable(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::formidableAvailable()) {
            return self::unavailable($request, 'Formidable Forms');
        }

        $pagination = Pagination::fromRequest($request);
        $forms = self::allFormidableForms();

        $records = [];

        foreach (\array_slice($forms, $pagination->offset(), $pagination->perPage) as $form) {
            $records[] = FormidablePayload::build($form);
        }

        return $pagination->decorate(new WP_REST_Response($records), \count($forms));
    }

    /**
     * One Formidable form definition.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function showFormidable(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::formidableAvailable()) {
            return self::unavailable($request, 'Formidable Forms');
        }

        $form = self::formidableForm(self::intParam($request, 'id'));

        /*
         * Trash is already excluded, because this reads through the SAME
         * function the collection does — see `formidableForm()`.
         */
        if (! $form instanceof stdClass) {
            return new WP_Error(
                'uamswp_migration_api_not_found',
                __('No Formidable Forms form with that id.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response(FormidablePayload::build($form));
    }

    /**
     * A page of Ninja Forms definitions.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function indexNinja(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ninjaAvailable()) {
            return self::unavailable($request, 'Ninja Forms');
        }

        $pagination = Pagination::fromRequest($request);
        $forms = self::allNinjaForms();

        $records = [];

        foreach (\array_slice($forms, $pagination->offset(), $pagination->perPage) as $form) {
            $records[] = NinjaPayload::build($form);
        }

        return $pagination->decorate(new WP_REST_Response($records), \count($forms));
    }

    /**
     * One Ninja Forms definition.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function showNinja(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ninjaAvailable()) {
            return self::unavailable($request, 'Ninja Forms');
        }

        $form = self::ninjaForm(self::intParam($request, 'id'));

        if (! \is_object($form)) {
            return new WP_Error(
                'uamswp_migration_api_not_found',
                __('No Ninja Forms form with that id.', 'uamswp-migration-api'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response(NinjaPayload::build($form));
    }

    /**
     * Whether Ninja Forms is loaded in this process.
     *
     * `public` like its two siblings, and for the same reason they are: the
     * namespace index reports it, so a consumer can discover the capability
     * without probing the route. It was `private` while the index omitted the
     * resource entirely — the two defects were the same oversight.
     */
    public static function ninjaAvailable(): bool
    {
        return \function_exists('Ninja_Forms') && class_exists('NF_Database_Models_Form');
    }

    /**
     * Every Ninja Forms form on this site, ordered by id ascending.
     *
     * Read straight off `nf3_forms` rather than through
     * `Ninja_Forms()->form()->get_forms()`, for two reasons found by reading
     * the plugin:
     *
     * 1. **The factory memoizes per id in a `static`.** `Ninja_Forms::form()`
     *    keeps `static $forms` keyed by id and returns the same object for the
     *    life of the process, so a form read earlier in a request — by any
     *    other plugin — is the object this endpoint would serve.
     * 2. **`find()` hydrates settings only when caching is OFF**, and
     *    `WPN_Helper::use_cache()` is `return true;` on its first line with the
     *    real logic dead below it. So the bulk path never runs
     *    `get_object_settings()`, and what a listed object holds depends on
     *    whatever the `nf_form_<id>` cache option happens to contain.
     *
     * Ids from the table and a FRESH model per id sidesteps both: every form
     * is constructed for this request and reads its own row. D-006 ordering by
     * id, like every other collection here.
     *
     * @return list<object>
     */
    private static function allNinjaForms(): array
    {
        global $wpdb;

        if (! $wpdb instanceof \wpdb || ! self::ninjaAvailable()) {
            return [];
        }

        $forms = [];

        foreach (self::ninjaIds($wpdb) as $id) {
            $forms[] = new \NF_Database_Models_Form($wpdb, $id);
        }

        return $forms;
    }

    /**
     * Every Ninja Forms form id on this site, ascending.
     *
     * The single source both Ninja routes read, so they cannot disagree about
     * which forms exist. The handle is passed in by those two callers, which
     * have already checked it is a database handle. Nothing in the query comes
     * from a caller, so no `prepare()` is needed and none is used.
     *
     * @return list<int>
     */
    private static function ninjaIds(\wpdb $wpdb): array
    {
        $table = $wpdb->prefix.'nf3_forms';

        /** @var mixed $ids */
        $ids = $wpdb->get_col("SELECT id FROM {$table} ORDER BY id ASC");

        $found = [];

        foreach (\is_array($ids) ? $ids : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $found[] = (int) $id;
            }
        }

        return $found;
    }

    /**
     * One Ninja Forms form, or null.
     *
     * Reads the SAME way the collection does — a fresh model for an id the
     * table really holds — so the two routes agree by construction, which is
     * the property `formidableForm()` below had to be rewritten to get.
     * Checking the table first is what makes a missing id a 404 rather than an
     * empty form: `new NF_Database_Models_Form( $wpdb, 999 )` constructs
     * happily for an id that does not exist and answers with empty settings,
     * which would serve `200` for a form nobody has.
     */
    private static function ninjaForm(int $id): ?object
    {
        if ($id <= 0 || ! self::ninjaAvailable()) {
            return null;
        }

        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return null;
        }

        /*
         * Membership tested against `ninjaIds()` — the SAME list the collection
         * pages over — rather than with a `WHERE id = %d` of its own. Two
         * routes reading one source is the parity property `formidableForm()`
         * had to be rewritten to get, and here it is free. It also sidesteps
         * `wpdb::prepare()`, which PHPStan requires a literal-string query for
         * and a table name interpolated from `$wpdb` is not.
         */
        if (! \in_array($id, self::ninjaIds($wpdb), true)) {
            return null;
        }

        return new \NF_Database_Models_Form($wpdb, $id);
    }

    /**
     * One Formidable form, read through exactly the reader the collection uses.
     *
     * NOT `FrmForm::getOne()`, and that is a correction rather than a
     * preference. `getOne()` funnels every return through
     * `FrmForm::prepare_form_row_data()`, which does two things `getAll()` does
     * not:
     *
     * 1. **It invents configuration.** `if ( ! is_array( $row->options ) )` it
     *    substitutes `FrmFormsHelper::get_default_opts()` — an 18-key array of
     *    `submit_value`, `success_msg`, `honeypot` and friends that exists
     *    nowhere in `wp_frm_forms`. The `options` column is nullable by
     *    schema, so this is reachable, and it was: measured on a row with a NULL
     *    options column, the collection reported `null` and this route reported
     *    18 fabricated keys. A consumer would have imported them as the form's
     *    real settings.
     * 2. **It lets a plugin edit the read.** It ends in
     *    `apply_filters( 'frm_form_object', $row )`. Formidable's own Stripe
     *    controller registers on that filter unconditionally on
     *    `plugins_loaded`, REST requests included, and writes
     *    `options['ajax_submit'] = '1'`. D-045 set `suppress_filters => true`
     *    on the posts collection precisely to stop plugins editing a migration
     *    read; this was the same hole in a different endpoint.
     *
     * Reading through `allFormidableForms()` makes the two routes the same read
     * **by construction** — the property `FormsController`'s docblock claims for
     * Gravity Forms, where `GFAPI::get_forms()` really is a loop over
     * `GFAPI::get_form()`. Formidable has no such guarantee, so it is built
     * rather than assumed.
     *
     * The cost is reading every form to serve one. It is bounded by forms per
     * site — a median of 4 and a maximum of 225 across the captured corpus —
     * and buying a correctness guarantee with it is the right trade at that
     * size.
     */
    private static function formidableForm(int $id): ?stdClass
    {
        if ($id <= 0) {
            return null;
        }

        foreach (self::allFormidableForms() as $form) {
            $candidate = $form->id ?? null;

            if (is_numeric($candidate) && (int) $candidate === $id) {
                return $form;
            }
        }

        return null;
    }

    /**
     * Every Formidable form definition, ordered by id ascending.
     *
     * **Not `FrmForm::get_published_forms()`, and that is the whole design.**
     * The obvious call is declared
     * `( $query = array(), $limit = 999, $inc_children = 'exclude' )` and each
     * of those three drops data from a migration:
     *
     * - `$inc_children = 'exclude'` hides every child form. Formidable Pro
     *   stores a repeating section or an embedded form as a separate row
     *   pointing at its parent, and **11 of the 68 forms in the captured
     *   corpus are children** — 16% of this network's forms, gone silently.
     * - `$limit = 999` is a cap nobody asked for. A site with a thousand forms
     *   would be truncated with a 200 and no indication.
     * - The query pins `status` to `array( null, '', 'published' )`, so a
     *   DRAFT form is dropped too — content the destination may still need,
     *   the same argument that keeps inactive Gravity forms (D-043) and unused
     *   taxonomy terms (D-037).
     *
     * It also orders by `name`, where every collection in this API orders by id
     * ascending so that a page boundary means the same thing twice (D-006).
     *
     * So the read is `getAll()` with a where clause that matches everything,
     * and trash is filtered **in PHP rather than in SQL**. That is not a style
     * preference: `getAll( array( 'status !' => 'trash' ) )` compiles to
     * `status != 'trash'`, and SQL inequality does not match NULL — so it
     * silently drops every form whose status column is NULL, which older rows
     * on this network have. Verified against a real row, not assumed.
     *
     * `$limit` is the empty string, not `0`. `FrmDb::esc_limit( 0 )` produces a
     * literal `LIMIT 0` and returns nothing at all — also verified, and it is
     * the kind of mistake that reads as "there are no forms".
     *
     * @return list<stdClass>
     */
    private static function allFormidableForms(): array
    {
        $forms = FrmForm::getAll(['id >' => 0], 'id', '');

        $records = [];

        /*
         * `is_array()` is not defensive padding here. `FrmForm::getAll()`
         * `reset()`s its result to a BARE object rather than a list when the
         * limit is 1 — so the return type is genuinely a union, and a caller
         * that assumed a list would iterate an object's properties.
         */
        foreach (\is_array($forms) ? $forms : [] as $form) {
            if (! self::isTrashedFormidable($form)) {
                $records[] = $form;
            }
        }

        return $records;
    }

    /**
     * Whether a Formidable form row is in the trash.
     *
     * Trash stays excluded for the reason D-043 gives on the Gravity Forms
     * side: trash is WordPress' delete, and "raw, not curated" governs how a
     * record is shaped rather than whether a deleted one is resurrected.
     */
    private static function isTrashedFormidable(stdClass $form): bool
    {
        return ($form->status ?? null) === 'trash';
    }

    /**
     * Whether Formidable Forms is loaded here.
     *
     * `FrmForm` and `FrmField` rather than a version constant, because those
     * two classes are the entire read path — if they are present the endpoint
     * works, and if they are not no amount of version detection helps.
     */
    public static function formidableAvailable(): bool
    {
        return class_exists(FrmForm::class) && class_exists('FrmField');
    }

    /**
     * Every form definition, ordered by id ascending.
     *
     * **`$active` is widened deliberately.** `GFAPI::get_forms()` takes
     * `$active = true`, so the call everyone writes silently drops every
     * *inactive* form — and an inactive form is content the destination may
     * still need, exactly as an unused taxonomy term is (D-037).
     * `GFFormsModel::get_form_ids()` skips the filter entirely when the
     * argument is `null`, which is how inactive forms reach the payload.
     *
     * Trashed forms stay excluded, and that is the one place the plugin's own
     * default is right: trash is WordPress' delete, and D-004's "raw" does not
     * extend to resurrecting what somebody deleted.
     *
     * @return list<array<string, mixed>>
     */
    private static function allForms(): array
    {
        $forms = [];

        /*
         * Every form is loaded to serve one page, because `GFAPI` offers no
         * offset: `get_forms()` takes sort arguments and nothing else. The cost
         * is real and bounded — across the importer's 161 captured site
         * exports the median site has 4 forms and the largest has 225, so the
         * worst case is 225 loads per page rather than 50. Left alone rather
         * than reimplemented against `gf_form` directly, which would mean the
         * raw SQL this repo does not write. Batching is #49's subject.
         */

        /*
         * The `false` entries are filtered here rather than in the page loop,
         * so the total counts exactly what the pages would serve.
         * `GFAPI::get_forms()` queries ids and then fetches each one through
         * `GFAPI::get_form()`, which returns `false` — so a form deleted
         * between those two steps leaves a `false` in the middle of the list.
         * Counted rather than filtered, it would report a total one higher
         * than the records, and a consumer would page for a record that is
         * not coming.
         */
        foreach (GFAPI::get_forms(null, false, 'id', 'ASC') as $form) {
            if (\is_array($form)) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * Whether Gravity Forms is loaded here.
     *
     * Public because the namespace index reports it. That index exists so a
     * consumer can discover what a deployment actually serves rather than
     * trusting the documented contract, and a route listed unconditionally
     * while 404ing on most of the network is precisely the assumption it is
     * meant to remove.
     */
    public static function gravityFormsAvailable(): bool
    {
        return class_exists(GFAPI::class);
    }

    /**
     * The error for a site where the plugin is not active.
     *
     * One eligibility list serves every site in the network and not every site
     * runs Gravity Forms, so this is the ordinary case rather than a fault. It
     * says which plugin is missing, because "not served here" sent a reader to
     * check configuration once already (D-041).
     *
     * Under `?site=`, it says something different and more accurate. Whether
     * `GFAPI` exists is a property of the PROCESS, not of the site being read:
     * a plugin loads for the site that booted the request, and
     * `switch_to_blog()` does not re-run that. So on a network where Gravity
     * Forms is activated per-site rather than network-wide, site N can have the
     * plugin while the process answering for it does not — and "not active on
     * this site" would be false. Same shape as `TermsController` and D-034.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function unavailable(WP_REST_Request $request, string $plugin): WP_Error
    {
        $site = self::intParam($request, 'site');

        return new WP_Error(
            'uamswp_migration_api_forms_plugin_not_active',
            $site > 0
                ? sprintf(
                    /* translators: 1: plugin name, 2: site id, 3: site id. */
                    __('%1$s is not loaded in the process handling this request. Whether it is active on site %2$d cannot be answered here, because plugins load for the site that booted the request — ask site %2$d through its own REST root.', 'uamswp-migration-api'),
                    $plugin,
                    $site
                )
                : sprintf(
                    /* translators: %s: plugin name. */
                    __('%s is not active on this site.', 'uamswp-migration-api'),
                    $plugin
                ),
            ['status' => 404]
        );
    }
}
