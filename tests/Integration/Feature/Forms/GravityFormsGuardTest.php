<?php

/**
 * The skip in the Gravity Forms tests must never become permanent by accident.
 */

declare(strict_types=1);

/*
 * `GravityFormsEndpointTest` skips itself when Gravity Forms is absent, which is
 * correct on CI and dangerous everywhere else: a bootstrap change that stopped
 * loading the plugin would turn the whole file green-by-skipping, and the two
 * findings it exists to hold — inactive forms dropped, trashed forms leaked —
 * would go unguarded with nothing red.
 *
 * So the skip condition is itself asserted. This is `AcfEditionGuardTest`
 * applied to a second optional plugin (D-030), not a new idea.
 */

it('loads Gravity Forms whenever the surrounding install has it', function (): void {
    if (! gravityFormsOnDisk()) {
        expect(class_exists('GFAPI'))->toBeFalse(
            'Gravity Forms is not on disk, yet GFAPI exists — the on-disk probe is looking in the wrong place.'
        );

        return;
    }

    expect(class_exists('GFAPI'))->toBeTrue(
        'Gravity Forms is on disk but the bootstrap did not load it, so every Gravity Forms test is skipping silently.'
    );
});

it('installed the Gravity Forms tables the endpoint reads through', function (): void {
    if (! gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is not in the surrounding install.');
    }

    $wpdb = testDatabase();

    /*
     * Loading the plugin is not the same as it having installed. Without these
     * tables `GFAPI::get_forms()` returns an empty array rather than raising,
     * so every assertion about "no forms" would pass for the wrong reason.
     *
     * `gf_addon_feed` is here for the same reason and is installed by the
     * bootstrap rather than by Gravity Forms. Its install is conditional on
     * `GFAddOn` being loaded and on the feed add-on file being readable, so a
     * release that moves either would skip it silently and every feed test
     * would report SKIPPED — a reassuring yellow where the whole feed suite
     * had stopped running.
     */
    foreach (['gf_form', 'gf_form_meta', 'gf_addon_feed'] as $table) {
        $name = $wpdb->prefix.$table;

        expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)))
            ->toBe($name, sprintf('`%s` is missing, so an empty result proves nothing.', $name));
    }
});

it('answers a clear 404 on a site that does not run Gravity Forms', function (): void {
    if (gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is present here. This is the branch CI covers.');
    }

    actAsSuperAdmin();

    /*
     * The mirror image of `GravityFormsEndpointTest`, and the reason the two
     * files skip on opposite conditions: `unavailable()` cannot execute on a
     * machine where the plugin is loaded, so without this it would be code no
     * environment ever runs. Between the two files every branch executes
     * somewhere.
     */
    $response = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1/forms/gravity'));

    expect($response->get_status())->toBe(404);
    expect(errorCode($response))->toBe('uamswp_migration_api_forms_plugin_not_active');
});

it('says the process, not the site, when Gravity Forms is missing under ?site=', function (): void {
    if (gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is present here. This is the branch CI covers.');
    }

    actAsSuperAdmin();

    /*
     * Whether `GFAPI` exists is a property of the process, not of the site
     * being read — plugins load for the site that booted the request, and
     * `switch_to_blog()` does not re-run that. So under `?site=` the plain
     * "not active on this site" would be a claim this code cannot support,
     * and D-034 already settled that the honest answer names the process and
     * points at the other site's REST root.
     */
    // A real second site, because the site gate resolves `?site=` before the
    // route runs and a non-existent id 404s there instead, on a different code.
    $site = makeSite();

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/forms/gravity');
    $request->set_param('site', $site);

    $response = rest_do_request($request);

    expect($response->get_status())->toBe(404);
    expect(errorCode($response))->toBe('uamswp_migration_api_forms_plugin_not_active');

    $data = $response->get_data();
    assert(is_array($data), 'the error did not carry a body');

    $message = $data['message'] ?? '';
    assert(is_string($message), 'the error carried no message');

    expect($message)->toContain('process');
    expect($message)->not->toContain('not active on this site');
});

it('answers the same 404 on every other Gravity Forms route', function (string $route): void {
    if (gravityFormsOnDisk()) {
        $this->markTestSkipped('Gravity Forms is present here. This is the branch CI covers.');
    }

    actAsSuperAdmin();

    // Each route checks for the plugin itself before it reads anything, so
    // each one is held to the answer the collection gives (#363).
    $response = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1'.$route));

    expect($response->get_status())->toBe(404)
        ->and(errorCode($response))->toBe('uamswp_migration_api_forms_plugin_not_active');
})->with([
    'one form' => ['/forms/gravity/1'],
    'every feed' => ['/forms/gravity/feeds'],
    "one form's feeds" => ['/forms/gravity/1/feeds'],
]);
