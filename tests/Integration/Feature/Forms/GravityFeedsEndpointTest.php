<?php

/**
 * The Gravity Forms add-on feed endpoints.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Content\GravityFeedPayload;

/*
 * What these tests hold, and why the endpoint exists at all: a feed is the only
 * part of a Gravity Forms form's behavior that never travels in an export. A
 * Gravity Forms export of the largest affected site contains zero occurrences
 * of `userregistration`, `feeds`, or `gravityformsuserregistration`, because
 * feeds live in `gf_addon_feed` and `GFAPI::get_form()` does not read it. Three
 * sites in the captured corpus create real WordPress accounts on submission, so
 * a migration without this leaves three forms that collect a username and a
 * password and create nothing (#80, `wordpress-importer#821`).
 *
 * Gravity Forms is paid and never present on CI, so everything here skips
 * without a real `GFAPI` — D-030's arrangement, and `GravityFormsGuardTest` is
 * what keeps the skip from becoming permanent by accident.
 */

/**
 * Dispatch a feed request against this plugin's namespace.
 *
 * @param  array<string, mixed>  $params
 */
function feedRequest(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * The feed records a route returns.
 *
 * @param  array<string, mixed>  $params
 * @return list<array<string, mixed>>
 */
function feedList(string $route, array $params = []): array
{
    $data = feedRequest($route, $params)->get_data();

    assert(is_array($data), 'a feed route did not return a list');

    $records = [];

    foreach ($data as $record) {
        assert(is_array($record), 'a feed record was not an array');

        /** @var array<string, mixed> $record */
        $records[] = $record;
    }

    return $records;
}

/**
 * The feeds attached to one form.
 *
 * @return list<array<string, mixed>>
 */
function feedsForForm(int $formId): array
{
    return feedList('/forms/gravity/'.$formId.'/feeds');
}

/**
 * A User Registration feed's meta, as the add-on really stores it.
 *
 * **Every mapping value is a FIELD ID**, which is the shape the whole filter
 * had to be designed around: `password` here is the string `'4'`, the id of the
 * form field the password is collected in, not a password. Taken from the
 * add-on's own settings fields — `username`, `email`, `password` and the name
 * parts are field maps, `role` is a WordPress role slug, `displayname` is an
 * enum.
 *
 * @param  array<string, mixed>  $extra  Merged last, so a test can add a key.
 * @return array<string, mixed>
 */
function registrationFeedMeta(array $extra = []): array
{
    return array_merge([
        'feedName' => 'Create an account',
        'feedType' => 'create',
        'username' => '3',
        'email' => '2',
        'password' => '4',
        'firstname' => '5.3',
        'lastname' => '5.6',
        'displayname' => 'nickname',
        'role' => 'subscriber',
        'sendEmail' => '1',
        'userMeta' => [
            ['key' => 'department', 'value' => '6'],
        ],
    ], $extra);
}

/**
 * Create a form and return its id.
 */
function feedMakeForm(string $title): int
{
    /*
     * The fields the fixture feeds actually map to. A one-field form was enough
     * while the exemption counted digits; it is not enough now that a reference
     * is exempt because the field EXISTS, and a feed whose mappings point at
     * fields the form does not have is not a realistic fixture either.
     *
     * `5` and `9` carry inputs because a composite field addresses its parts
     * with an index after a period — `5.3` is a name field's first name.
     */
    $id = GFAPI::add_form([
        'title' => $title,
        'fields' => [
            ['id' => 1, 'type' => 'text', 'label' => 'Name'],
            ['id' => 2, 'type' => 'email', 'label' => 'Email'],
            ['id' => 3, 'type' => 'text', 'label' => 'Username'],
            ['id' => 4, 'type' => 'password', 'label' => 'Password'],
            [
                'id' => 5,
                'type' => 'name',
                'label' => 'Full name',
                'inputs' => [
                    ['id' => '5.3', 'label' => 'First'],
                    ['id' => '5.6', 'label' => 'Last'],
                ],
            ],
            ['id' => 6, 'type' => 'text', 'label' => 'Department'],
            [
                'id' => 9,
                'type' => 'address',
                'label' => 'Address',
                'inputs' => [
                    ['id' => '9.2', 'label' => 'Line 2'],
                ],
            ],
        ],
    ]);

    assert(is_int($id), 'Gravity Forms refused to create the fixture form');

    return $id;
}

/**
 * Attach a feed to a form and return its id.
 *
 * `int|string` rather than `string` alone: a feed's own meta can be
 * numeric-keyed, per `GravityFeedPayload::build()`'s `(object)` cast, and
 * the numeric-keys fixture below relies on this helper accepting one.
 *
 * @param  array<int|string, mixed>  $meta
 */
function feedMake(int $formId, array $meta, string $slug = 'gravityformsuserregistration', bool $active = true): int
{
    $id = GFAPI::add_feed($formId, $meta, $slug);

    assert(is_int($id), 'Gravity Forms refused to create the fixture feed');

    if (! $active) {
        GFAPI::update_feed_property($id, 'is_active', 0);
    }

    return $id;
}

/**
 * The withheld counts on one feed record, narrowed once.
 *
 * `WP_REST_Response::get_data()` is `mixed` all the way down, so without this
 * every count read below is an analyzer error at `level: max` — the same reason
 * `gfArray()` exists in the sibling file.
 *
 * @param  array<string, mixed>  $feed
 * @return array<string, mixed>
 */
function feedDenied(array $feed): array
{
    $denied = $feed['denied'] ?? null;

    assert(is_array($denied), 'the feed record carried no withheld counts');

    /** @var array<string, mixed> $denied */
    return $denied;
}

/**
 * Whether the add-on feed table was installed by the bootstrap.
 *
 * `SHOW TABLES LIKE`, not `GFCommon::table_exists()`, which memoizes: this is
 * asked once per test and the answer is a property of the database, not of
 * the process.
 */
function feedTableExists(): bool
{
    $db = testDatabase();

    $table = $db->prefix.'gf_addon_feed';

    /** @var mixed $found */
    $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $table));

    return $found === $table;
}

beforeEach(function (): void {
    if (! gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is not in the surrounding install.');
    }

    /*
     * The feed table is created by `GFFeedAddOn::setup()`, not by Gravity Forms
     * itself, so `tests/Integration/bootstrap.php` installs it. If that step ever stops
     * working these tests must SKIP rather than pass: `GFAPI::get_feeds()`
     * answers a missing table with a `WP_Error`, the endpoint turns that into
     * the empty list a form with no feeds legitimately has, and every
     * expectation below about an empty result would go green while proving
     * nothing at all.
     */
    if (! feedTableExists()) {
        $this->markTestSkipped('`gf_addon_feed` was not installed by the bootstrap.');
    }

    actAsSuperAdmin();

    foreach (GFAPI::get_forms(null, null, 'id', 'ASC') as $form) {
        $id = is_array($form) ? ($form['id'] ?? null) : null;

        if (is_numeric($id)) {
            GFAPI::delete_form((int) $id);
        }
    }

    // Feeds survive their form's deletion, so the table is emptied on its own
    // account. The totals below are absolute.
    $feeds = GFAPI::get_feeds(null, null, null, null);

    foreach (is_array($feeds) ? $feeds : [] as $feed) {
        $id = $feed['id'] ?? null;

        if (is_numeric($id)) {
            GFAPI::delete_feed((int) $id);
        }
    }
});

it('serves a user-registration feed with its field mapping, role and active flag', function (): void {
    $form = feedMakeForm('Registration');
    feedMake($form, registrationFeedMeta());

    $feeds = feedsForForm($form);

    expect($feeds)->toHaveCount(1);

    $feed = $feeds[0];

    expect($feed['form_id'])->toBe($form);
    expect($feed['addon_slug'])->toBe('gravityformsuserregistration');
    expect($feed['is_active'])->toBeTrue();

    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    // The three `wordpress-importer#821` names explicitly: the field supplying
    // the username, the email and the password must each survive.
    expect($meta['username'])->toBe('3');
    expect($meta['email'])->toBe('2');
    expect($meta['role'])->toBe('subscriber');
});

it('serves the password FIELD MAPPING, which a plain credential denylist withholds', function (): void {
    /*
     * The single most important assertion in this file.
     *
     * `option_denylist_nested_keys` carries `%password`, and the value under
     * that key here is `'4'` — the id of the form field the password is
     * collected in. Filtered by name alone the endpoint would withhold the one
     * mapping the consumer most needs, while withholding nothing sensitive
     * whatever: `'4'` cannot be a credential. That is D-032's own false-positive
     * class, and `wordpress-importer#821` fails outright if it fires.
     */
    $form = feedMakeForm('Registration');
    feedMake($form, registrationFeedMeta());

    $meta = feedsForForm($form)[0]['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('password', $meta))->toBeTrue(
        '`password` maps to a form field id, not to a password. Withholding it breaks the import '
        .'this endpoint exists for — see GravityFeedPayload::fieldIdsFor().'
    );
    expect($meta['password'])->toBe('4');

    // A sub-field reference travels intact. Note this key matches no denylist
    // pattern, so it proves pass-through rather than the exemption — the
    // exemption's sub-field branch is exercised in the bound test below.
    expect($meta['firstname'])->toBe('5.3');

    expect(feedDenied(feedsForForm($form)[0])['total'])->toBe(0);
});

it('does not exempt a LONG numeric value from the name rule', function (): void {
    /*
     * The bound on the field-reference exemption. A field id is one to a few
     * digits; a twelve-digit account number is not a field reference, and under
     * a credential-named key it has to be withheld — otherwise the claim the
     * exemption rests on, that the value cannot be a credential whatever it is
     * called, stops being true. The shape rule's hex branch starts at 32
     * characters, so without this bound the gap runs from about six digits to
     * thirty-one.
     */
    $form = feedMakeForm('Donate');

    feedMake($form, [
        'feedName' => 'Charge the card',
        'billingInformation_email' => '2',
        /*
         * A SUB-FIELD reference under a key the name rule really does match —
         * `%secret`, case-insensitively. This is the branch of the exemption
         * `firstname` above cannot exercise, because no pattern matches that
         * key in the first place.
         */
        'merchantSecret' => '9.2',
        // Not a field reference, however much it looks like a number.
        'merchantPassword' => '481516234255',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('merchantPassword', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
    expect($meta['merchantSecret'])->toBe('9.2');
    expect(feedDenied($feed)['by_name'])->toBe(1);
});

it('withholds a credential-shaped value and reports that it did', function (): void {
    /*
     * The other direction, and the reason the filter exists: a payment add-on
     * feed carries gateway configuration. Gravity Forms says as much itself —
     * a feed settings field can be marked `encrypt`, and `GFAPI::get_feeds()`
     * runs every value it returns through `decrypt_feed_meta()`, so this read
     * path hands back the plaintext of fields the plugin encrypts at rest.
     */
    $form = feedMakeForm('Donate');

    feedMake($form, [
        'feedName' => 'Charge the card',
        'transactionType' => 'product',
        'billingInformation_email' => '2',
        /*
         * Named nothing in particular and holding 64 hex characters: the SHAPE
         * rule's territory, and the half that catches what nobody enumerated.
         * D-032 found `acf_pro_license` on 32 of 33 production sites this way,
         * before any pattern named it.
         */
        'connectedAccount' => str_repeat('a1b2c3d4', 8),
        // Named as a credential and holding one: the NAME rule's territory.
        // Split, so a secret scanner does not read the fixture as a live key.
        'api_key' => 'sk_live_'.'ThisIsNotARealKeyItIsFortyPlusChars',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('connectedAccount', $meta))->toBeFalse();
    expect(array_key_exists('api_key', $meta))->toBeFalse();

    // The mapping around them still travels — withholding is per entry, not
    // per feed, or one gateway key would discard the whole configuration.
    expect($meta['billingInformation_email'])->toBe('2');
    expect($meta['feedName'])->toBe('Charge the card');

    /*
     * Counts, never names. Reporting THAT something was withheld lets a
     * consumer tell "this feed has no such setting" from "we declined to send
     * it"; naming them would hand a reader the map of which credentials exist.
     */
    $denied = feedDenied($feed);

    expect($denied['total'])->toBe(2);
    expect($denied['by_name'])->toBe(1);
    expect($denied['by_shape'])->toBe(1);
});

it('serves a DISABLED feed rather than dropping it, and says that it is disabled', function (): void {
    /*
     * `GFAPI::get_feeds()` is declared with `$is_active = true`, so the obvious
     * call silently drops every disabled feed — `GFAPI::get_forms()`'s defect
     * (D-043) one method along. Both halves matter: the record has to arrive,
     * and it has to arrive marked, or an importer enables a registration feed
     * somebody turned off.
     */
    $form = feedMakeForm('Registration');
    feedMake($form, registrationFeedMeta(['feedName' => 'Live']));
    feedMake($form, registrationFeedMeta(['feedName' => 'Turned off']), active: false);

    $feeds = feedsForForm($form);

    expect($feeds)->toHaveCount(2);

    $flags = [];

    foreach ($feeds as $feed) {
        $meta = $feed['meta'];

        assert($meta instanceof stdClass, 'the feed served no meta');

        $meta = (array) $meta;

        $name = $meta['feedName'];

        assert(is_string($name), 'the feed served no name');

        $flags[$name] = $feed['is_active'];
    }

    expect($flags['Live'])->toBeTrue();
    expect($flags['Turned off'])->toBeFalse();

    /*
     * A REAL boolean, not the `'0'` the column stores. `"0"` is falsy in PHP
     * and truthy in JavaScript, so served raw a disabled feed reads as enabled
     * to any consumer that is not the importer — and the thing being enabled
     * creates user accounts.
     */
    expect($flags['Turned off'])->not->toBe('0');
});

it('serves a feed whose add-on is not installed here', function (): void {
    /*
     * A feed row outlives its add-on's activation, and this endpoint asks no
     * add-on anything — deliberately, because `get_fields_to_encrypt()` derives
     * from `get_feed_settings_fields()` and several add-ons populate that by
     * calling their remote service. A migration read must not make a request to
     * a payment gateway as a side effect. The consequence is this: the record
     * is served on its own terms, with the slug, and the consumer decides.
     */
    $form = feedMakeForm('Registration');
    feedMake($form, registrationFeedMeta(), slug: 'gravityformsuserregistration');

    expect(class_exists('GF_User_Registration'))->toBeFalse();

    $feed = feedsForForm($form)[0];

    expect($feed['addon_slug'])->toBe('gravityformsuserregistration');

    /*
     * `meta` is a `stdClass` now, and PHP's `empty()` — what `toBeEmpty()`
     * runs — is never true for an object regardless of its properties, so
     * asserting directly on the object would pass whether or not it actually
     * carried anything. Cast to array first, where "empty" means what the
     * test name says.
     */
    expect((array) $feed['meta'])->not->toBeEmpty();
});

it('surfaces a genuine query failure rather than an empty list', function (): void {
    /*
     * `$wpdb->get_results()` returns empty on a real query error exactly as
     * it does on zero matching rows, and `GFAPI::get_feeds()` reports both as
     * the identical `not_found` error -- it cannot tell them apart from its
     * own return value. `$wpdb->last_error` is the one signal that survives,
     * but `wpdb::query()` resets it to `''` at the START of every call
     * (`class-wpdb.php`), so setting it directly before the request is
     * overwritten by the first unrelated query WordPress core runs on the
     * way to the controller -- confirmed live: an earlier version of this
     * test set it directly and still asserted `200`.
     *
     * So the failure is made REAL instead of simulated: the `query` filter
     * sabotages the exact SQL `GFAPI::get_feeds()` issues, and MySQL's own
     * rejection is what sets `last_error` in the one query this test cares
     * about.
     */
    $form = feedMakeForm('Query failure');

    $sabotage = static function (string $query): string {
        if (str_contains($query, 'gf_addon_feed') && str_starts_with(trim($query), 'SELECT')) {
            return $query.' AND INVALID SYNTAX HERE';
        }

        return $query;
    };

    add_filter('query', $sabotage);

    try {
        $collection = feedRequest('/forms/gravity/feeds');
        $byForm = feedRequest('/forms/gravity/'.$form.'/feeds');
    } finally {
        remove_filter('query', $sabotage);

        global $wpdb;

        assert($wpdb instanceof wpdb, 'the WordPress database handle was not available');

        $wpdb->last_error = '';
    }

    expect($collection->get_status())->toBe(500);
    expect($byForm->get_status())->toBe(500);
});

it('answers a form with no feeds with an empty list, not a 404 and not a missing key', function (): void {
    /*
     * "No feeds" and "feeds are not served here" have to be different answers,
     * or an importer cannot tell a form that creates no user from one whose
     * configuration it never received. `GFAPI::get_feeds()` returns a
     * `WP_Error` for the empty case, so this is a correction rather than a
     * pass-through.
     */
    $form = feedMakeForm('Contact us');

    $response = feedRequest('/forms/gravity/'.$form.'/feeds');

    expect($response->get_status())->toBe(200);
    expect($response->get_data())->toBe([]);
});

it('404s a feed request for a form that does not exist', function (): void {
    $response = feedRequest('/forms/gravity/999999/feeds');

    expect($response->get_status())->toBe(404);
});

it('404s a feed request for a TRASHED form, which the form route also withholds', function (): void {
    /*
     * The parity `show()` had to be given by hand. Without it a trashed form's
     * feeds outlive the form on the one route meant to be the safe one.
     */
    $form = feedMakeForm('Trashed');
    feedMake($form, registrationFeedMeta());

    /*
     * `trash_form()`, NOT `delete_form()`. `GFFormsModel::delete_forms()` is
     * `DELETE FROM {$form_table}`, so `GFAPI::get_form()` then returns `false`
     * and both assertions pass through the `! is_array($form)` branch that the
     * preceding test already covers — leaving `isTrashed()` unexercised while
     * this test's own name claims it. Trashing is `UPDATE ... SET is_trash=1`,
     * which is the state the guard exists for.
     */
    GFFormsModel::trash_form($form);

    expect(feedRequest('/forms/gravity/'.$form)->get_status())->toBe(404);
    expect(feedRequest('/forms/gravity/'.$form.'/feeds')->get_status())->toBe(404);
});

it('orders the collection by id ascending and pages it without dropping a feed', function (): void {
    /*
     * A plain creation loop cannot exercise the sort. `gf_addon_feed`'s id is
     * the InnoDB clustering key, and `GFAPI::get_feeds()` issues a bare
     * `SELECT *` with no `ORDER BY` at all -- so a simple scan already comes
     * back in id-ascending order regardless of whether anything sorts it a
     * second time. Confirmed directly: removing `allFeeds()`'s `usort()` and
     * running exactly this scenario still passed.
     *
     * So the database's own order is forced to DESCENDING here, through the
     * same `query` filter technique the query-failure test above uses. If
     * `allFeeds()`'s own sort is doing the work, the API still answers
     * ascending regardless of what the database returned; if it is not, the
     * pages come back in the database's order instead.
     */
    $form = feedMakeForm('Registration');

    $created = [];

    for ($i = 1; $i <= 5; $i++) {
        $created[] = feedMake($form, registrationFeedMeta(['feedName' => 'Feed '.$i]));
    }

    sort($created);

    $reverseOrder = static function (string $query): string {
        if (str_contains($query, 'gf_addon_feed') && str_starts_with(trim($query), 'SELECT')) {
            return $query.' ORDER BY id DESC';
        }

        return $query;
    };

    add_filter('query', $reverseOrder);

    try {
        $paged = [];

        foreach ([1, 2, 3] as $page) {
            foreach (feedList('/forms/gravity/feeds', ['page' => $page, 'per_page' => 2]) as $feed) {
                $id = $feed['id'];

                $paged[] = is_numeric($id) ? (int) $id : 0;
            }
        }
    } finally {
        remove_filter('query', $reverseOrder);
    }

    expect($paged)->toBe($created);
});

it('reports a total that counts what the collection would serve', function (): void {
    $form = feedMakeForm('Registration');
    feedMake($form, registrationFeedMeta());
    feedMake($form, registrationFeedMeta(), slug: 'gravityformsstripe');

    $response = feedRequest('/forms/gravity/feeds');

    expect($response->get_headers()['X-WP-Total'])->toBe('2');
});

it('advertises the feed routes in the namespace index', function (): void {
    /*
     * The TRAILING SLASH is load-bearing. `/uamswp-migration/v1` is the index
     * WordPress generates for every namespace; this plugin's own index is
     * registered at `/` inside the namespace and resolves one path along.
     */
    $data = feedRequest('/')->get_data();

    assert(is_array($data), 'the namespace index did not return an array');

    $endpoints = $data['endpoints'] ?? null;

    assert(is_array($endpoints), 'the index carries no endpoints map');

    $forms = $endpoints['forms'] ?? null;

    assert(is_array($forms), 'the index carries no forms block');

    $gravity = $forms['gravity'] ?? null;

    assert(is_array($gravity), 'the index carries no gravity block');

    $feeds = $gravity['feeds'] ?? null;

    assert(is_array($feeds), 'the index does not advertise feeds');

    expect($feeds['collection'])->toBe('/forms/gravity/feeds');
    expect($feeds['by_form'])->toBe('/forms/gravity/<id>/feeds');
});

it('refuses an unauthenticated caller on both feed routes', function (): void {
    $form = feedMakeForm('Registration');
    feedMake($form, registrationFeedMeta());

    wp_set_current_user(0);

    expect(feedRequest('/forms/gravity/feeds')->get_status())->toBe(401);
    expect(feedRequest('/forms/gravity/'.$form.'/feeds')->get_status())->toBe(401);
});

it('withholds a TRASHED form feeds from the COLLECTION, not only from the by-form route', function (): void {
    /*
     * A feed outlives its form: `trash_form()` sets `is_trash=1` and leaves
     * `gf_addon_feed` alone. The by-form route was given the guard by hand and
     * the collection was not, so `/forms/gravity/feeds` served the whole field
     * mapping of a form that `/forms/gravity/<id>` and
     * `/forms/gravity/<id>/feeds` both 404 — and counted it in `X-WP-Total`.
     */
    $live = feedMakeForm('Live');
    feedMake($live, registrationFeedMeta());

    $trashed = feedMakeForm('Trashed');
    feedMake($trashed, registrationFeedMeta());

    GFFormsModel::trash_form($trashed);

    $response = feedRequest('/forms/gravity/feeds');
    $records = $response->get_data();

    assert(is_array($records), 'the collection served no records');

    $formIds = [];

    foreach ($records as $record) {
        $formIds[] = is_array($record) ? ($record['form_id'] ?? null) : null;
    }

    expect($formIds)->toContain($live);
    expect($formIds)->not->toContain($trashed);

    // The total counts what it serves, or a consumer pages past the end.
    expect($response->get_headers()['X-WP-Total'])->toBe('1');
});

it('withholds a NUMERIC credential, which every string-only rule walked past', function (): void {
    /*
     * `json_decode()` leaves a value an add-on wrote as a JSON number typed, so
     * this arrives as an `int`. Every rule in the filter was string-only, so
     * the twelve-digit bound added to stop exactly this value being waived as a
     * field reference never ran — the identical value quoted is withheld.
     */
    $form = feedMakeForm('Numeric');

    feedMake($form, [
        'feedName' => 'Gateway',
        'merchantPassword' => 481516234255,
        'billingInformation_email' => '2',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('merchantPassword', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
    expect(feedDenied($feed)['by_name'])->toBe(1);
});

it('withholds a credential nested inside a JSON string, as the option path does', function (): void {
    /*
     * `OptionsPayload::classify()` runs four rules; this filter ran three. The
     * missing one is `secretIn(decoded($value))`, so a container whose LEAF is
     * the whole JSON string matched nothing: not hex, not base64, not
     * `eyJ`-prefixed, not vendor-prefixed. The same bytes in an option row are
     * withheld, which is what made the divergence a defect rather than a choice.
     */
    $form = feedMakeForm('Connected');

    feedMake($form, [
        'feedName' => 'Connected account',
        'connectedAccount' => '{"client_id":"acct_1","client_secret":"'.str_repeat('a1b2c3d4', 4).'"}',
        'billingInformation_email' => '2',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('connectedAccount', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
    expect(feedDenied($feed)['by_shape'])->toBe(1);
});

it('advertises pagination on the feed COLLECTION only, since by_form ignores it', function (): void {
    /*
     * `showFormFeeds()` is registered without `Pagination::params()` and
     * returns no total header. A blanket `params` at the level of the whole
     * feeds block told a consumer to page it, which answers the identical
     * complete list for every page and offers no total to stop on.
     */
    $data = feedRequest('/')->get_data();

    assert(is_array($data), 'the namespace index did not return an array');

    $endpoints = $data['endpoints'] ?? null;

    assert(is_array($endpoints), 'the index carries no endpoints map');

    $forms = $endpoints['forms'] ?? null;

    assert(is_array($forms), 'the index carries no forms map');

    $gravity = $forms['gravity'] ?? null;

    assert(is_array($gravity), 'the index carries no gravity map');

    $feeds = $gravity['feeds'] ?? null;

    assert(is_array($feeds), 'the index carries no feeds map');

    expect($feeds['collection_params'] ?? null)->toBe(['page', 'per_page']);
    expect(array_key_exists('params', $feeds))->toBeFalse();
});

it('withholds a NESTED numeric credential, which the top-level coercion alone missed', function (): void {
    /*
     * The exemption used to be a wrapper around the shared walk rather than a
     * rule inside it, so one rule had two behaviors: at depth 0 an `int` was
     * coerced and tested, at depth 1 it was skipped entirely. This is that gap
     * one level down — the same value, the same key, nested.
     */
    $form = feedMakeForm('Nested numeric');

    feedMake($form, [
        'feedName' => 'Gateway',
        'gateway' => ['merchantPassword' => 481516234255],
        'billingInformation_email' => '2',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('gateway', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
    expect(feedDenied($feed)['total'])->toBe(1);
});

it('withholds an ARRAY under a credential-named key, which the name rule skipped entirely', function (): void {
    /*
     * `deniedByName()` returned false for any non-string before it reached the
     * name list, so a key ON that list was never tested when its value was an
     * array — and only the children's own keys were. A credential under a
     * benign child key therefore traveled under a key called `credentials`.
     */
    $form = feedMakeForm('Nested array');

    feedMake($form, [
        'feedName' => 'Gateway',
        'credentials' => ['user' => 'admin', 'pass' => 'p@ssw0rd'],
        'billingInformation_email' => '2',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('credentials', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
});

it('withholds a credential inside a NESTED JSON container, not only a top-level one', function (): void {
    /*
     * The decode step was added to the loop's own entries only, so a container
     * one level down was never decoded and its leaf never inspected — the leaf
     * being the whole JSON string, which matches no shape rule.
     */
    $form = feedMakeForm('Nested container');

    feedMake($form, [
        'feedName' => 'Connected account',
        'group' => ['blob' => '{"client_id":"acct_1","client_secret":"'.str_repeat('a1b2c3d4', 4).'"}'],
        'billingInformation_email' => '2',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('group', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
});

it('withholds a reference to a field this form does not have', function (): void {
    /*
     * The property membership buys that a digit count cannot. `'7'` is shaped
     * exactly like a field reference and would have been waived by any
     * heuristic; this form has no field 7, so under a credential-named key it
     * is judged on its name like any other value. The exemption now rests on
     * the form's own data rather than on an assumption about how Gravity Forms
     * numbers fields.
     */
    $form = feedMakeForm('Absent field');

    feedMake($form, [
        'feedName' => 'Gateway',
        'password' => '4',
        'merchantPassword' => '7',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    // `4` IS a field here, so the mapping the endpoint exists for still travels.
    expect($meta['password'])->toBe('4');
    expect(array_key_exists('merchantPassword', $meta))->toBeFalse();
    expect(feedDenied($feed)['by_name'])->toBe(1);
});

it('exempts a nested field reference at depth, not only at the top level', function (): void {
    /*
     * The other direction of the same change, and the one that would break the
     * endpoint if the exemption were simply deleted: a mapping nested inside a
     * settings group is still a mapping. `userMeta` in the registration fixture
     * is exactly this shape.
     */
    $form = feedMakeForm('Nested mapping');

    feedMake($form, [
        'feedName' => 'Create an account',
        'mapping' => ['password' => '4', 'email' => '2'],
    ]);

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;
    assert(is_array($meta['mapping'] ?? null), 'the nested mapping did not travel');

    expect($meta['mapping']['password'])->toBe('4');
    expect(feedDenied($feed)['total'])->toBe(0);
});

it('withholds a credential-named group NESTED inside another, not only at the top', function (): void {
    /*
     * The third round of the same defect, and the reason the rule now lives
     * inside the shared walk rather than in a wrapper around it. `credentials`
     * at the top level was withheld; the identical group one level down was
     * served whole, because the per-child test required a string and an array
     * child never reached the name list.
     */
    $form = feedMakeForm('Nested group');

    feedMake($form, [
        'feedName' => 'Gateway',
        'gateway' => ['credentials' => ['user' => 'admin', 'pass' => 'p@ssw0rd']],
        'billingInformation_email' => '2',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];
    $meta = $feed['meta'];

    assert($meta instanceof stdClass, 'the feed served no meta');

    $meta = (array) $meta;

    expect(array_key_exists('gateway', $meta))->toBeFalse();
    expect($meta['billingInformation_email'])->toBe('2');
    expect(feedDenied($feed)['total'])->toBe(1);
});

it('keeps meta a JSON OBJECT even when every entry was withheld', function (): void {
    /*
     * A PHP array with nothing in it encodes as `[]`, not `{}`. A feed whose
     * only settings were withheld -- the case a consumer most needs to
     * distinguish from "no settings at all" -- used to serve exactly that
     * shape, and a typed or schema-validating consumer reading
     * `feed.meta.password` breaks on precisely the feeds where something was
     * withheld.
     */
    $form = feedMakeForm('Fully withheld');

    feedMake($form, [
        // Split, so a secret scanner does not read the fixture as a live key.
        'api_key' => 'sk_live_'.'ThisIsNotARealKeyItIsFortyPlusChars',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];

    expect($feed['meta'])->toBeInstanceOf(stdClass::class);
    expect((array) $feed['meta'])->toBe([]);
    expect(json_encode($feed['meta']))->toBe('{}');
});

it('keeps meta a JSON OBJECT when its keys are numeric', function (): void {
    /*
     * A PHP array whose keys happen to be numeric and contiguous from zero
     * serializes as a JSON list, silently dropping the keys -- the same
     * failure as the empty case, from the other direction.
     */
    $form = feedMakeForm('Numeric keys');

    feedMake($form, [
        0 => 'first',
        1 => 'second',
    ], slug: 'gravityformsstripe');

    $feed = feedsForForm($form)[0];

    expect($feed['meta'])->toBeInstanceOf(stdClass::class);
    expect(json_encode($feed['meta']))->toBe('{"0":"first","1":"second"}');
});

it('distinguishes a feed with unreadable meta from one with none', function (): void {
    /*
     * `GravityFeedPayload::build()` is called directly here, unit-style,
     * because the only way `meta` genuinely fails to decode -- a NULL,
     * truncated, or wrongly-decrypted column -- is inside
     * `decrypt_feed_meta()`, which this repo does not call and cannot make
     * fail on demand through the real dispatch path.
     */
    $readable = GravityFeedPayload::build(['id' => 1, 'meta' => []]);
    $unreadable = GravityFeedPayload::build(['id' => 2, 'meta' => null]);

    expect($readable['meta_readable'])->toBeTrue();
    expect($unreadable['meta_readable'])->toBeFalse();

    // Both still serve valid, type-stable meta -- the flag is additional
    // information, not a replacement for the meta key itself.
    expect($readable['meta'])->toBeInstanceOf(stdClass::class);
    expect($unreadable['meta'])->toBeInstanceOf(stdClass::class);
});
