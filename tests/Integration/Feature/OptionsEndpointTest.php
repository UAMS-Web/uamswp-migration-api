<?php

/**
 * The options endpoint — the gap WXR cannot express at all.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Tests\Support\WakeupProbe;

/*
 * Zero of 259 captured WXR exports contain a `<wp:option>` element, so every
 * site migrated before this endpoint silently dropped its settings. The tests
 * below are mostly about the filter rather than the read: the read is one
 * query, and the filter is a denylist whose failure direction is leaking a
 * credential.
 */

/**
 * Dispatch the options route, authenticated, optionally against another site.
 */
function optionsGet(?int $site = null): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/options');

    if ($site !== null) {
        $request->set_param('site', $site);
    }

    return rest_do_request($request);
}

/**
 * The `options` block of the response.
 *
 * @return array<string, mixed>
 */
function optionsPayload(): array
{
    $options = payload(optionsGet(), 'options');

    assert(\is_array($options), 'the options endpoint did not return an options block');

    /** @var array<string, mixed> $options */
    return $options;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves the whole table rather than an enumerated list', function (): void {
    // Enumeration is what loses the setting nobody thought to name. A stock
    // install carries a few hundred options; the exact count is not the point,
    // the absence of a curated list is.
    update_option('uamswp_options_probe', 'present');

    $response = optionsGet();

    expect($response->get_status())->toBe(200)
        ->and(optionsPayload())->toHaveKey('uamswp_options_probe')
        ->and(\count(optionsPayload()))->toBeGreaterThan(50);
});

it('serves `page_for_posts`, which unblocks the importer', function (): void {
    // Named explicitly because a specific downstream ticket waits on it:
    // UAMS-Web/wordpress-importer#452.
    update_option('page_for_posts', '42');

    expect(optionsPayload())->toHaveKey('page_for_posts')
        ->and(optionsPayload()['page_for_posts'])->toBe('42');
});

it('withholds credential-shaped options and reports how many, never which', function (): void {
    // Naming the withheld options would hand a reader the map of which
    // credentials exist. A count still lets a consumer tell "this site has no
    // such setting" from "we declined to send it".
    update_option('some_vendor_api_key', 'a-real-looking-secret');
    update_option('some_vendor_access_token', 'another-one');

    $data = optionsGet()->get_data();

    assert(\is_array($data));

    $denied = $data['denied'] ?? [];

    assert(\is_array($denied));

    expect(optionsPayload())->not->toHaveKey('some_vendor_api_key')
        ->and(optionsPayload())->not->toHaveKey('some_vendor_access_token')
        ->and($denied)->toHaveKeys(['total', 'by_name', 'by_shape'])
        ->and($denied['by_name'])->toBeGreaterThanOrEqual(2)
        ->and(json_encode($data))->not->toContain('some_vendor_api_key');
});

it('never withholds an `_options_*` row, whatever the patterns say', function (): void {
    // JC1 on #6. These hold ACF field-key references by construction — the real
    // `_options_fad_google_key` on this network contains the literal string
    // `field_fad_google_key` — so no credential can live in one, and denying it
    // breaks the pairing D-007 exists to protect.
    update_option('options_probe_api_key', 'the actual secret');
    update_option('_options_probe_api_key', 'field_probe_api_key');

    expect(optionsPayload())->not->toHaveKey('options_probe_api_key')
        ->and(optionsPayload())->toHaveKey('_options_probe_api_key')
        ->and(optionsPayload()['_options_probe_api_key'])->toBe('field_probe_api_key');
});

it('serves the core settings the old denylist dropped', function (): void {
    // JC2 and JC3 on #6, as a regression: `%_keys` caught core's comment
    // moderation lists and `%user_roles` caught the role definitions. Both are
    // configuration, neither is a credential, and dropping them was the silent
    // loss this project exists to prevent.
    update_option('disallowed_keys', "spam\nphishing");
    update_option('moderation_keys', 'held');

    // Derived, not spelled: the roles option is prefixed per site, so it is
    // `wp_user_roles` on the network and `wptests_user_roles` here. Hard-coding
    // either would make this pass for the wrong reason somewhere.
    $roles = testDatabase()->get_blog_prefix().'user_roles';

    expect(optionsPayload())->toHaveKey('disallowed_keys')
        ->and(optionsPayload())->toHaveKey('moderation_keys')
        ->and(optionsPayload())->toHaveKey($roles);
});

it('unpacks what WordPress serialized, and leaves JSON alone', function (): void {
    // The distinction that matters. A PHP-serialized array is an encoding
    // WordPress applied and can undo; a JSON string is a *value*, and unpacking
    // it would be a judgment about its type the stored row does not make.
    // MinervaKB stores its configuration as JSON — 9 such options on this
    // network.
    update_option('uamswp_serialized_probe', ['a' => 1, 'b' => ['c' => 2]]);
    update_option('uamswp_json_probe', '{"kb":"config","n":3}');

    $options = optionsPayload();

    expect($options['uamswp_serialized_probe'])->toBe(['a' => 1, 'b' => ['c' => 2]])
        ->and($options['uamswp_json_probe'])->toBe('{"kb":"config","n":3}')
        ->and($options['uamswp_json_probe'])->toBeString();
});

it("honors a LIKE pattern's escaped underscore", function (): void {
    // `%api\_key%` means a literal underscore. Treated as LIKE's single-character
    // wildcard it would also match `apiXkey`, and a denylist that silently
    // matches more than it reads as is one nobody can review.
    update_option('vendor_apiXkey_thing', 'not a secret');
    update_option('vendor_api_key_thing', 'a secret');

    expect(optionsPayload())->toHaveKey('vendor_apiXkey_thing')
        ->and(optionsPayload())->not->toHaveKey('vendor_api_key_thing');
});

it('carries a structured ACF view keyed by options page', function (): void {
    // D-026. The flat table is not lossy but it is flat in the way WXR is: a
    // repeater is stored as its row count with the rows in numbered keys, while
    // `acf_get_value( 'options', $field )` returns them assembled.
    //
    // The page is discovered from the field group's own location rule, which is
    // why this needs no `acf_add_options_page()` — and why the plugin stays
    // inside the four ACF functions D-024 pinned.
    acf_add_local_field_group([
        'key' => 'group_options_probe',
        'title' => 'Options probe',
        'fields' => [
            ['key' => 'field_options_probe_title', 'name' => 'options_probe_title', 'type' => 'text', 'label' => 'Title'],
        ],
        'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'uamswp-probe-page']]],
    ]);

    update_option('options_options_probe_title', 'Assembled by ACF');
    update_option('_options_options_probe_title', 'field_options_probe_title');

    $acf = payload(optionsGet(), 'acf');

    assert(\is_array($acf));

    expect($acf)->toHaveKey('uamswp-probe-page');

    $page = $acf['uamswp-probe-page'];

    assert(\is_array($page));

    expect($page)->toHaveKeys(['keys', 'names', 'name_collisions'])
        ->and($page['names'])->toBe(['options_probe_title' => 'Assembled by ACF'])
        ->and($page['keys'])->toBe(['field_options_probe_title' => 'Assembled by ACF']);

    acf_remove_local_field_group('group_options_probe');
});

it("serves the requested site's options, not the current site's", function (): void {
    // Site scoping is the mechanism every endpoint inherits, and its failure is
    // silent: a well-formed response full of another site's settings. The
    // options table is where that is most visible, because `$wpdb->options`
    // is literally a different table after `switch_to_blog()` — so this asserts
    // the read followed the switch, not merely that the switch happened.
    //
    // Not blocked on #12: that ticket is about the *development network* having
    // one site. The test suite builds its own.
    $original = get_current_blog_id();
    $second = makeSite();

    expect($second)->not->toBe($original);

    update_option('uamswp_scoping_probe', 'first site');

    switch_to_blog($second);
    update_option('uamswp_scoping_probe', 'second site');
    restore_current_blog();

    $here = payload(optionsGet(), 'options');
    $there = payload(optionsGet($second), 'options');

    assert(\is_array($here) && \is_array($there));

    expect($here['uamswp_scoping_probe'])->toBe('first site')
        ->and($there['uamswp_scoping_probe'])->toBe('second site')
        // And the switch was given back, so nothing after this reads the wrong site.
        ->and(get_current_blog_id())->toBe($original);
});

it('withholds the credentials the one-site review would have leaked', function (): void {
    // Every one of these is a real option name from the 33-site production
    // network, and every one was served by the denylist reviewed against the
    // single development site (#6, D-032). Six used a hyphen or camelCase where
    // the patterns assumed `snake_case`; `acf_pro_license` was on 32 of 33
    // sites and no pattern came close to it.
    $leaked = [
        'acf_pro_license',
        'tidio-access-token',
        'tidio-refresh-token',
        'ninja-forms-views-secret',
        'wp-short-pixel-apiKey',
        'wp-short-pixel-cloudflareToken',
        'frmpro-credentials',
    ];

    foreach ($leaked as $name) {
        update_option($name, 'a-value-that-must-not-travel');
    }

    $options = optionsPayload();

    foreach ($leaked as $name) {
        expect($options)->not->toHaveKey($name);
    }
});

it('serves the status and version fields that merely read like credentials', function (): void {
    // The other half of the same review. `acf_pro_license_status` is 273
    // characters on 32 sites and reads alarming — but it is a serialized status
    // array, and a query comparing it against `acf_pro_license` confirmed it
    // does not contain the key. Withholding it would drop configuration for no
    // gain, which is the failure the first review actually committed.
    $benign = [
        'acf_pro_license_status',
        'seopress_pro_license_status',
        'acp_periodic_license_check',
        'gform_recaptcha_keys_status',
        'wp_mail_smtp_version',
    ];

    foreach ($benign as $name) {
        update_option($name, 'a:1:{s:6:"status";s:5:"valid";}');
    }

    $options = optionsPayload();

    foreach ($benign as $name) {
        expect($options)->toHaveKey($name);
    }
});

it('withholds a value that looks like a secret whatever it is called', function (): void {
    // The half a name-based denylist structurally cannot do. `acf_pro_license`
    // was found in the production audit by its SHAPE — 128 characters of base64
    // — before any pattern named it. The next paid plugin will arrive with a
    // name nobody predicted, and this is what stands between it and the wire.
    update_option('vendor_setting_three', str_repeat('a1b2c3d4', 8));              // 64 hex
    update_option('vendor_setting_four', base64_encode(str_repeat('abcdefghij', 5))); // base64
    update_option('vendor_setting_five', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc'); // JWT

    // …while leaving alone the things that merely happen to be long.
    update_option('vendor_prose', str_repeat('a sentence of ordinary settings ', 8));
    update_option('vendor_serialized', serialize(['a' => 1, 'b' => 2]));

    $options = optionsPayload();
    $data = optionsGet()->get_data();

    assert(\is_array($data));

    $denied = $data['denied'];

    assert(\is_array($denied));

    expect($options)->not->toHaveKey('vendor_setting_three')
        ->and($options)->not->toHaveKey('vendor_setting_four')
        ->and($options)->not->toHaveKey('vendor_setting_five')
        ->and($options)->toHaveKey('vendor_prose')
        ->and($options)->toHaveKey('vendor_serialized')
        // Reported separately, because a shape match is a guess about an option
        // nobody enumerated while a name match is a reviewed decision.
        ->and($denied['by_shape'])->toBeGreaterThanOrEqual(3);
});

it('reports that the denylist has never been pinned to a plugin set', function (): void {
    // An empty baseline is not "unchanged", it is "never reviewed" — and the
    // difference matters, because a denylist that has never been checked
    // against the plugins actually running is exactly what #6 shipped the first
    // time. The endpoint reports it rather than refusing, and names the plugins
    // so a reviewer knows what arrived rather than only that a count moved.
    //
    // `active_plugins` is set explicitly: the test database is installed fresh,
    // so nothing is active in it and the plugin under test is required by the
    // bootstrap rather than activated. Depending on the environment here would
    // make the assertion vacuous.
    //
    // The baseline is emptied for this case rather than assumed empty — it
    // ships populated from production, and a test that only passed while it was
    // blank would have quietly stopped covering anything the day it was filled.
    update_option('active_plugins', ['some-plugin/some-plugin.php', 'another-plugin/another.php']);

    add_filter('uamswp_migration_api_config', static function (mixed $config): mixed {
        if (\is_array($config)) {
            $config['option_denylist_reviewed_against'] = [];
        }

        return $config;
    });

    Config::flush();

    $denylist = payload(optionsGet(), 'denylist');

    assert(\is_array($denylist));

    expect($denylist)->toHaveKeys(['reviewed_against', 'current', 'stale', 'added', 'removed'])
        ->and($denylist['reviewed_against'])->toBe(0)
        ->and($denylist['current'])->toBe(2)
        ->and($denylist['stale'])->toBeTrue()
        // Named, not counted: a reviewer needs to know *what* arrived.
        // Sorted, so the payload is stable between requests rather than
        // reflecting whatever order WordPress happened to store.
        ->and($denylist['added'])->toBe(['another-plugin', 'some-plugin'])
        ->and($denylist['removed'])->toBe([]);

    remove_all_filters('uamswp_migration_api_config');
    Config::flush();
});

it('reports the denylist as current once the baseline matches the plugin set', function (): void {
    // The other side of the signal, and the one that makes it usable: pinned
    // and matching must read `stale: false`, or the field is noise everyone
    // learns to skip.
    update_option('active_plugins', ['some-plugin/some-plugin.php', 'another-plugin/another.php']);

    add_filter('uamswp_migration_api_config', static function (mixed $config): mixed {
        if (\is_array($config)) {
            $config['option_denylist_reviewed_against'] = ['another-plugin', 'some-plugin'];
        }

        return $config;
    });

    Config::flush();

    $denylist = payload(optionsGet(), 'denylist');

    assert(\is_array($denylist));

    expect($denylist['stale'])->toBeFalse()
        ->and($denylist['reviewed_against'])->toBe(2)
        ->and($denylist['added'])->toBe([])
        ->and($denylist['removed'])->toBe([]);

    remove_all_filters('uamswp_migration_api_config');
    Config::flush();
});

it('names a plugin that has appeared since the denylist was reviewed', function (): void {
    // The case the whole signal exists for: a plugin arrives, brings option
    // names nobody has checked, and the payload says so by name.
    update_option('active_plugins', ['some-plugin/some-plugin.php', 'brand-new-plugin/new.php']);

    add_filter('uamswp_migration_api_config', static function (mixed $config): mixed {
        if (\is_array($config)) {
            $config['option_denylist_reviewed_against'] = ['some-plugin'];
        }

        return $config;
    });

    Config::flush();

    $denylist = payload(optionsGet(), 'denylist');

    assert(\is_array($denylist));

    expect($denylist['stale'])->toBeTrue()
        ->and($denylist['added'])->toBe(['brand-new-plugin'])
        ->and($denylist['removed'])->toBe([]);

    remove_all_filters('uamswp_migration_api_config');
    Config::flush();
});

it('is not stale when a site runs only a subset of the reviewed plugins', function (): void {
    // The case every site in the network is in, and the one an equality test
    // gets wrong. The baseline is the **union** of plugins across all sites,
    // because every site can activate a different set — so no individual site
    // ever equals the baseline. Requiring an exact match would report `stale`
    // on all 33 sites forever, which is a signal nobody reads.
    //
    // What matters is whether this site runs anything the denylist has
    // never been checked against. A plugin in
    // the baseline that this site does not run is another site's plugin, not a
    // risk.
    update_option('active_plugins', ['some-plugin/some-plugin.php']);

    add_filter('uamswp_migration_api_config', static function (mixed $config): mixed {
        if (\is_array($config)) {
            // A union covering sites this one is not.
            $config['option_denylist_reviewed_against'] = [
                'another-plugin', 'some-plugin', 'a-third-plugin', 'yet-another',
            ];
        }

        return $config;
    });

    Config::flush();

    $denylist = payload(optionsGet(), 'denylist');

    assert(\is_array($denylist));

    expect($denylist['stale'])->toBeFalse()
        ->and($denylist['added'])->toBe([])
        // Plugins this site does not run are reported, because they are worth
        // seeing when the baseline is next refreshed — but they do not make this
        // payload suspect.
        ->and($denylist['removed'])->toHaveCount(3)
        ->and($denylist['reviewed_against'])->toBe(4)
        ->and($denylist['current'])->toBe(1);

    remove_all_filters('uamswp_migration_api_config');
    Config::flush();
});

it('ships a baseline captured from the production network', function (): void {
    // The pinning is only meaningful if the shipped baseline is the real one.
    // These three are the tell: each is active on one or two sites out of 33,
    // and each is a plugin the denylist audit found a credential in — so their
    // presence proves the baseline came from the network the audit covered
    // rather than from a development install.
    $reviewed = Config::strings('option_denylist_reviewed_against');

    expect($reviewed)->toContain('tidio-live-chat')
        ->and($reviewed)->toContain('ninja-forms')
        ->and($reviewed)->toContain('shortpixel-image-optimiser')
        ->and($reviewed)->toContain('advanced-custom-fields-pro')
        // A development install carries nothing like this many.
        ->and(\count($reviewed))->toBeGreaterThanOrEqual(60);
});

/*
 * #33 — the denylist used to judge the wrapper and never the contents.
 *
 * A settings row like `wp_mail_smtp` is one option holding a whole
 * configuration, and the credential is a key several levels down. The shape
 * rules never matched a serialized string, which the docblock described as
 * narrowness and was actually the hole: the identical secret was withheld as a
 * bare value and served one level in.
 */

it('withholds a secret nested inside a serialized value', function (): void {
    // The negative control is the point. Both rows are the same shape of
    // settings array; only one carries a credential, and only one goes.
    //
    // The arrays are passed as arrays rather than pre-serialized. `update_option()`
    // runs `maybe_serialize()`, which serializes a *string* that already looks
    // serialized a second time — so a hand-serialized fixture lands in the
    // table as `s:48:"a:2:{…}";` and reads back as an inert string. That mistake
    // made an earlier draft of this test pass while the endpoint did nothing.
    update_option('vendor_mailer_paid', [
        'mail' => ['mailer' => 'sendgrid'],
        'sendgrid' => ['api_key' => 'SG.'.str_repeat('a1b2c3d4', 8)],
    ]);
    update_option('vendor_mailer_plain', [
        'mail' => ['mailer' => 'smtp'],
        'smtp' => ['host' => 'smtp.uams.edu', 'port' => 587],
    ]);

    $options = optionsPayload();

    expect($options)->not->toHaveKey('vendor_mailer_paid')
        ->and($options)->toHaveKey('vendor_mailer_plain');
});

it('withholds a secret nested inside a JSON value, and still serves JSON as the string it is', function (): void {
    // D-009 is unchanged by looking inside: a JSON string is a value rather
    // than an encoding WordPress applied, so what ships is still the string.
    // Decoding decides only whether to withhold the row.
    $clean = json_encode(['theme' => 'uams', 'columns' => 3]);

    assert(\is_string($clean));

    update_option('vendor_json_integration', (string) json_encode([
        'integration' => ['access_token' => 'ya29.'.str_repeat('x', 200)],
    ]));
    update_option('vendor_json_clean', $clean);

    $options = optionsPayload();

    expect($options)->not->toHaveKey('vendor_json_integration')
        ->and($options['vendor_json_clean'])->toBe($clean);
});

it('catches Google OAuth tokens, which matched none of the shape rules', function (): void {
    // `ya29.` and `1//` are not hex, and the `.` and `-` they carry keep them
    // out of the base64 run — so before #33 a Google access token and the
    // refresh token that keeps minting them were served in full even as bare
    // top-level values.
    update_option('vendor_google_access', 'ya29.'.str_repeat('x', 200));
    update_option('vendor_google_refresh', '1//0g'.str_repeat('z', 98));

    $options = optionsPayload();

    expect($options)->not->toHaveKey('vendor_google_access')
        ->and($options)->not->toHaveKey('vendor_google_refresh');
});

it('withholds on a nested credential key only when it carries a non-empty string', function (): void {
    // The false positive this rule cost before it was narrowed: WordPress maps
    // `administrator.capabilities.seopress_manage_license` to boolean `true`,
    // and testing the key alone took all 22 KB of the network's role
    // definitions with it. A capability is not a license; a blank field is not
    // a credential.
    update_option('vendor_nested_named', ['integration' => ['api_key' => 'AKIAIOSFODNN7EXAMPLE']]);
    update_option('vendor_nested_boolean', ['capabilities' => ['plugin_manage_license' => true]]);
    update_option('vendor_nested_empty', ['scheduling_license' => '', 'rows_per_page' => 25]);

    $options = optionsPayload();

    expect($options)->not->toHaveKey('vendor_nested_named')
        ->and($options)->toHaveKey('vendor_nested_boolean')
        ->and($options)->toHaveKey('vendor_nested_empty');
});

it('never constructs a class while inspecting a value', function (): void {
    // Inspection reads values that a denylist exists because nobody has
    // audited. `unserialize()` without `allowed_classes: false` instantiates
    // whatever class the string names and runs its `__wakeup()`; this asserts
    // the flag is actually set, by giving a real class a side effect and
    // showing it never fires.
    //
    // The option is withheld, so `value()` — which uses `maybe_unserialize()`
    // and does construct — never runs on it either. That is what makes the
    // assertion about the inspection path specifically.
    WakeupProbe::$woke = false;

    $probe = new WakeupProbe;
    $probe->api_secret = str_repeat('a1b2c3d4', 8);

    // The object, not `serialize($probe)` — `update_option()` serializes it
    // once, which is how a plugin storing an object stores it.
    update_option('vendor_serialized_object', $probe);

    $options = optionsPayload();

    expect($options)->not->toHaveKey('vendor_serialized_object')
        ->and(WakeupProbe::$woke)->toBeFalse();
});

it('terminates on a self-referential serialized value', function (): void {
    // `R:` back-references are part of the serialization format, so a value can
    // contain itself and a plain recursive walk over one never returns. The
    // depth bound fails closed, which is why this asserts a withholding rather
    // than a serving — and, mostly, asserts that the request comes back at all.
    // Written straight to the table. A self-referential value cannot be built
    // in PHP and handed to `update_option()` — `serialize()` unrolls a
    // reference cycle into `R:` itself, but `maybe_serialize()` would then
    // serialize the resulting string a second time and the row would arrive
    // here inert.
    global $wpdb;

    assert($wpdb instanceof wpdb);

    $wpdb->insert(
        $wpdb->options,
        [
            'option_name' => 'vendor_recursive',
            'option_value' => 'a:2:{s:6:"secret";s:32:"'.str_repeat('ab', 16).'";s:4:"self";R:1;}',
            'autoload' => 'no',
        ],
        ['%s', '%s', '%s']
    );

    wp_cache_delete('alloptions', 'options');

    $response = optionsGet();
    $options = optionsPayload();

    expect($response->get_status())->toBe(200)
        ->and($options)->not->toHaveKey('vendor_recursive');
});

it('does not re-serve a denied value under the ACF view', function (): void {
    // The bypass K1 found. `acf_get_value( 'options', $field )` resolves to
    // `get_option( "options_{$name}" )`, so a row the flat map withheld was read
    // straight back out and shipped one key later — with `denied.by_name`
    // reporting a withholding that had not happened, next to a dangling
    // `_options_*` reference pointing at an absent value.
    acf_add_local_field_group([
        'key' => 'group_options_secret_probe',
        'title' => 'Options secret probe',
        'fields' => [
            ['key' => 'field_probe_api_key', 'name' => 'probe_api_key', 'type' => 'text', 'label' => 'API key'],
            ['key' => 'field_probe_heading', 'name' => 'probe_heading', 'type' => 'text', 'label' => 'Heading'],
        ],
        'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'uamswp-secret-page']]],
    ]);

    update_option('options_probe_api_key', 'SG.'.str_repeat('a1b2c3d4', 8));
    update_option('_options_probe_api_key', 'field_probe_api_key');
    update_option('options_probe_heading', 'Contact us');
    update_option('_options_probe_heading', 'field_probe_heading');

    $options = optionsPayload();
    $acf = payload(optionsGet(), 'acf');

    assert(\is_array($acf));
    assert(\is_array($acf['uamswp-secret-page']));

    $page = $acf['uamswp-secret-page'];

    assert(\is_array($page['keys']));
    assert(\is_array($page['names']));

    // Gone from the flat map, and gone from BOTH spellings of the ACF map —
    // they are keyed differently, so removing it from one alone would ship it
    // under the other.
    expect($options)->not->toHaveKey('options_probe_api_key')
        ->and($page['keys'])->not->toHaveKey('field_probe_api_key')
        ->and($page['names'])->not->toHaveKey('probe_api_key')
        // …while the field beside it is untouched, and the `_options_*`
        // references still ship: they hold field keys, never values.
        ->and($page['names'])->toHaveKey('probe_heading')
        ->and($options)->toHaveKey('_options_probe_api_key');

    acf_remove_local_field_group('group_options_secret_probe');
});

it('reports a never-pinned denylist as stale even on a site running no plugins', function (): void {
    // The `$reviewed === []` disjunct, which the suite could not previously
    // distinguish from the one beside it: every other case pairs an empty
    // baseline with a non-empty plugin set, so `added` was non-empty and
    // decided `stale` on its own. Delete the disjunct and they all stay green
    // while a never-pinned credential denylist reports itself as reviewed
    // against this site — which is the flag D-032 hands the consumer to gate
    // the production pull on.
    //
    // A fresh test database is in exactly this state, which is why every other
    // denylist test has to set `active_plugins` explicitly.
    update_option('active_plugins', []);

    add_filter('uamswp_migration_api_config', function (array $config): array {
        $config['option_denylist_reviewed_against'] = [];

        return $config;
    });

    Config::flush();

    $denylist = payload(optionsGet(), 'denylist');

    assert(\is_array($denylist));

    expect($denylist['current'])->toBe(0)
        ->and($denylist['added'])->toBe([])
        ->and($denylist['stale'])->toBeTrue();

    remove_all_filters('uamswp_migration_api_config');
    Config::flush();
});

it('pins a baseline that includes this plugin and stays sorted', function (): void {
    // Two mistakes this catches, both made while writing the pinning.
    //
    // The capture predates this plugin's deployment, so its own slug was
    // missing — and the moment it is network-activated it becomes a slug every
    // site reports, making `stale` true on all 33 sites forever. That is the
    // always-on signal the subset comparison exists to avoid, arriving by a
    // different door.
    //
    // And the list is read in commit diffs, which is the whole argument for
    // slugs over a hash (D-032). An entry in the wrong place makes the next
    // refresh's diff unreadable; `uamswp-migration-api` was first added between
    // `uamswp-block-remover` and `UAMSWP-Content-Syndication`, which is wrong
    // because `migration` sorts after `logo-list` and before `minervakb`.
    $reviewed = Config::strings('option_denylist_reviewed_against');
    $sorted = $reviewed;

    usort($sorted, strcasecmp(...));

    expect($reviewed)->toContain('uamswp-migration-api')
        ->and($reviewed)->toBe($sorted);
});

/**
 * The path of a fixture plugin, relative to the plugins directory.
 *
 * The header is read from the file itself, so a test needs a real file. The
 * fixtures live in this repository rather than the install's plugins
 * directory, and a checkout can sit anywhere relative to it, so the path is
 * computed rather than written down; it may climb out with `../`.
 */
function fixturePlugin(string $file): string
{
    $root = realpath(WP_PLUGIN_DIR);
    $target = realpath(__DIR__.'/../../Support/fixtures/plugins/'.$file);

    assert(\is_string($root) && \is_string($target), "fixture plugin '{$file}' or the plugins directory does not exist");

    // realpath() answers with backslashes on Windows, where a split on `/`
    // alone finds no common prefix and climbs out of a drive-letter path.
    $from = explode('/', trim(str_replace('\\', '/', $root), '/'));
    $to = explode('/', trim(str_replace('\\', '/', $target), '/'));

    while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
        array_shift($from);
        array_shift($to);
    }

    return str_repeat('../', \count($from)).implode('/', $to);
}

/**
 * Stand in for the plugins directory's listing for the rest of the request.
 *
 * `get_plugins()` answers from the non-persistent `plugins` cache group when it
 * is primed. The listing only decides which paths exist: every entry carries a
 * header that is deliberately not the file's, so a test can tell the header
 * that was read from the file apart from the one the listing holds. The cache
 * key is per site, so prime while switched to the site being read.
 */
function primeInstalledPlugins(string ...$paths): void
{
    $installed = [];

    foreach ($paths as $path) {
        $installed[$path] = ['Name' => 'from the listing', 'Version' => '0', 'TextDomain' => 'from-the-listing'];
    }

    wp_cache_set('plugins', ['' => $installed], 'plugins');
}

/**
 * The `plugins` block of the options response.
 *
 * @return list<array{path: string, scope: string, header: array<string, string>|null}>
 */
function activePluginsPayload(?int $site = null): array
{
    $plugins = payload(optionsGet($site), 'plugins');

    assert(\is_array($plugins), 'the options endpoint did not return a plugins block');

    /** @var list<array{path: string, scope: string, header: array<string, string>|null}> $plugins */
    return $plugins;
}

afterEach(function (): void {
    wp_cache_delete('plugins', 'plugins');
});

it('serves a per-site plugin with the header its file carries on disk', function (): void {
    // The case #338 exists for: the path names one build, the file on disk is
    // another, and only the header can tell a consumer which is running. The
    // listing's own header differs, so this also proves the file is read.
    $path = fixturePlugin('versioned/versioned.php');
    primeInstalledPlugins($path);
    update_option('active_plugins', [$path]);
    update_site_option('active_sitewide_plugins', []);

    expect(activePluginsPayload())->toBe([
        [
            'path' => $path,
            'scope' => 'site',
            'header' => ['Name' => 'Versioned Fixture', 'Version' => '1.6.6', 'TextDomain' => 'versioned-fixture'],
        ],
    ]);
});

it('serves a network-activated plugin under the network scope', function (): void {
    // `active_sitewide_plugins` keys by path where `active_plugins` lists
    // values, so a reader that took the values would serve activation
    // timestamps instead of paths.
    $path = fixturePlugin('versioned/versioned.php');
    primeInstalledPlugins($path);
    update_option('active_plugins', []);
    update_site_option('active_sitewide_plugins', [$path => 1_700_000_000]);

    expect(activePluginsPayload())->toBe([
        [
            'path' => $path,
            'scope' => 'network',
            'header' => ['Name' => 'Versioned Fixture', 'Version' => '1.6.6', 'TextDomain' => 'versioned-fixture'],
        ],
    ]);
});

it('serves per-site plugins before network ones, and a plugin active in both under each', function (): void {
    $versioned = fixturePlugin('versioned/versioned.php');
    $undeclared = fixturePlugin('undeclared/undeclared.php');
    primeInstalledPlugins($versioned, $undeclared);
    update_option('active_plugins', [$versioned, $undeclared]);
    update_site_option('active_sitewide_plugins', [$versioned => 1_700_000_000]);

    expect(array_map(fn (array $plugin): array => [$plugin['path'], $plugin['scope']], activePluginsPayload()))->toBe([
        [$versioned, 'site'],
        [$undeclared, 'site'],
        [$versioned, 'network'],
    ]);
});

it('serves an active plugin whose file is missing with a null header, not dropped', function (): void {
    // Active in the database and absent on disk is the disagreement a consumer
    // needs to see. Dropping it would make it indistinguishable from a plugin
    // that was never active.
    primeInstalledPlugins();
    update_option('active_plugins', ['gone-plugin/gone-plugin.php']);
    update_site_option('active_sitewide_plugins', []);

    expect(activePluginsPayload())->toBe([
        ['path' => 'gone-plugin/gone-plugin.php', 'scope' => 'site', 'header' => null],
    ]);
});

it('never reads a file the plugins listing does not name, even one that exists', function (): void {
    // The stored path is a key into the listing, never a path to open. This
    // file exists and carries a header, and the listing does not name it, so
    // it is served as missing.
    $path = fixturePlugin('versioned/versioned.php');
    primeInstalledPlugins();
    update_option('active_plugins', [$path]);
    update_site_option('active_sitewide_plugins', []);

    expect(activePluginsPayload())->toBe([
        ['path' => $path, 'scope' => 'site', 'header' => null],
    ]);
});

it('does not serve a plugin installed on disk but not active on the site', function (): void {
    $versioned = fixturePlugin('versioned/versioned.php');
    primeInstalledPlugins($versioned, fixturePlugin('undeclared/undeclared.php'));
    update_option('active_plugins', [$versioned]);
    update_site_option('active_sitewide_plugins', []);

    expect(array_column(activePluginsPayload(), 'path'))->toBe([$versioned]);
});

it('serves a header field the file does not declare as an empty string, not an inferred one', function (): void {
    // `get_plugin_data()` fills a missing `Text Domain` with the plugin's
    // directory name. Served, that would read as a declared value.
    $path = fixturePlugin('undeclared/undeclared.php');
    primeInstalledPlugins($path);
    update_option('active_plugins', [$path]);
    update_site_option('active_sitewide_plugins', []);

    expect(activePluginsPayload()[0]['header'])->toBe(['Name' => 'Undeclared Fixture', 'Version' => '', 'TextDomain' => '']);
});

it('serves the stored active plugins, not what a filter on the option adds', function (): void {
    // LearnDash v3 appends a legacy path through `option_active_plugins` on
    // every request outside the plugins screen. Read through `get_option()`,
    // that path would be served as an active plugin missing from disk.
    $path = fixturePlugin('versioned/versioned.php');
    primeInstalledPlugins($path);
    update_option('active_plugins', [$path]);
    update_site_option('active_sitewide_plugins', []);

    $inject = fn (mixed $value): array => [...(\is_array($value) ? $value : []), 'injected/injected.php'];
    add_filter('option_active_plugins', $inject);

    try {
        expect(array_column(activePluginsPayload(), 'path'))->toBe([$path])
            ->and(get_option('active_plugins'))->toContain('injected/injected.php');
    } finally {
        remove_filter('option_active_plugins', $inject);
    }
});

it('serves the stored network plugins, not what a filter on the network option adds', function (): void {
    // LearnDash v3 filters `site_option_active_sitewide_plugins` the same way
    // it filters the per-site list (#341), adding a legacy path whenever its
    // own entry is network-active. Read through `get_site_option()`, that path
    // would be served as a network plugin missing from disk and counted by the
    // denylist's provenance check.
    $path = fixturePlugin('versioned/versioned.php');
    primeInstalledPlugins($path);
    update_option('active_plugins', []);
    update_site_option('active_sitewide_plugins', [$path => 1_700_000_000]);

    $inject = fn (mixed $value): array => [...(\is_array($value) ? $value : []), 'injected-network/injected.php' => 1_700_000_000];
    add_filter('site_option_active_sitewide_plugins', $inject);

    try {
        $response = optionsGet();
        $plugins = payload($response, 'plugins');
        $added = payload($response, 'denylist', 'added');

        assert(\is_array($plugins));

        expect(array_column($plugins, 'path'))->toBe([$path])
            ->and($added)->not->toContain('injected-network')
            ->and(get_site_option('active_sitewide_plugins'))->toHaveKey('injected-network/injected.php');
    } finally {
        remove_filter('site_option_active_sitewide_plugins', $inject);
    }
});

it('reads the active plugins of the site being read, not the one answering', function (): void {
    // Per-site activation lives in each site's own `active_plugins`, so the
    // switch `?site=` makes has to happen before this is read. The listing is
    // cached per site too, so it is primed on the site being read.
    $path = fixturePlugin('versioned/versioned.php');
    update_option('active_plugins', []);
    update_site_option('active_sitewide_plugins', []);

    $site = makeSite();

    switch_to_blog($site);
    update_option('active_plugins', [$path]);
    primeInstalledPlugins($path);
    restore_current_blog();

    expect(activePluginsPayload($site))->toBe([
        [
            'path' => $path,
            'scope' => 'site',
            'header' => ['Name' => 'Versioned Fixture', 'Version' => '1.6.6', 'TextDomain' => 'versioned-fixture'],
        ],
    ])->and(activePluginsPayload())->toBe([]);

    switch_to_blog($site);
    wp_cache_delete('plugins', 'plugins');
    restore_current_blog();
});

it('lists the installed plugins from disk when nothing stands in for them', function (): void {
    // The one test that does not prime the listing, so it proves the listing
    // is read from disk. It uses whichever plugin the surrounding install
    // carries; a WordPress download ships at least one.
    if (! \function_exists('get_plugins')) {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
    }

    wp_cache_delete('plugins', 'plugins');
    $onDisk = get_plugins();
    wp_cache_delete('plugins', 'plugins');

    if ($onDisk === []) {
        $this->markTestSkipped('the surrounding install has no plugin on disk to read');
    }

    $path = (string) array_key_first($onDisk);
    $fromFile = get_file_data(WP_PLUGIN_DIR.'/'.$path, ['Name' => 'Plugin Name', 'Version' => 'Version', 'TextDomain' => 'Text Domain']);

    update_option('active_plugins', [$path]);
    update_site_option('active_sitewide_plugins', []);

    expect(activePluginsPayload())->toBe([
        ['path' => $path, 'scope' => 'site', 'header' => $fromFile],
    ]);
});
