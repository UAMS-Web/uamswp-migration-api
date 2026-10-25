<?php

/**
 * The skip in the Formidable tests must never become permanent by accident.
 */

declare(strict_types=1);

/*
 * `FormidableEndpointTest` skips itself when Formidable is absent, which is
 * correct on CI and dangerous everywhere else: a bootstrap change that stopped
 * loading the plugin would turn the file green-by-skipping, and the four
 * silent-drop findings it exists to hold would go unguarded.
 *
 * `AcfEditionGuardTest` and `GravityFormsGuardTest` applied a third time
 * (D-030, D-044), not a new idea.
 */

it('loads Formidable whenever the surrounding install has it', function (): void {
    if (! formidableOnDisk()) {
        expect(class_exists('FrmForm'))->toBeFalse(
            'Formidable is not on disk, yet FrmForm exists — the on-disk probe is looking in the wrong place.'
        );

        return;
    }

    expect(class_exists('FrmForm'))->toBeTrue(
        'Formidable is on disk but the bootstrap did not load it, so every Formidable test is skipping silently.'
    );
    expect(class_exists('FrmField'))->toBeTrue();
});

it('loads Formidable Pro, without which the corpus cannot be represented', function (): void {
    if (! formidableOnDisk()) {
        $this->markTestSkipped('Formidable is not in the surrounding install.');
    }

    if (! constant('UAMSWP_MIGRATION_API_TESTS_FORMIDABLE_PRO_ON_DISK')) {
        $this->markTestSkipped('Formidable Pro is not in the surrounding install.');
    }

    /*
     * Pro is not decoration here. Across the importer's 12 committed exports —
     * 68 forms, 2,412 fields — about 23% of the fields are Pro-tier types
     * (`divider`, `end_divider`, `date`, `address`, `file`, `rte`, `lookup`,
     * `data`, `form`), and child forms exist only because Pro creates them.
     */
    expect(class_exists('FrmProDb'))->toBeTrue(
        'Formidable Pro is on disk but did not load, so Pro-tier field types and child forms go untested.'
    );
});

it('installed the Formidable tables the endpoint reads through', function (): void {
    if (! formidableOnDisk()) {
        $this->markTestSkipped('Formidable is not in the surrounding install.');
    }

    $wpdb = testDatabase();

    /*
     * Loading the plugin is not the same as it having installed — Formidable
     * creates its tables on ACTIVATION, and this bootstrap never activates
     * anything. Without these, every "no forms" assertion would pass for the
     * wrong reason.
     */
    foreach (['frm_forms', 'frm_fields'] as $table) {
        $name = $wpdb->prefix.$table;

        expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)))
            ->toBe($name, sprintf('`%s` is missing, so an empty result proves nothing.', $name));
    }
});

it('answers a clear 404 on a site that does not run Formidable', function (): void {
    if (formidableOnDisk()) {
        $this->markTestSkipped('Formidable is present here. This is the branch CI covers.');
    }

    actAsSuperAdmin();

    $response = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1/forms/formidable'));

    expect($response->get_status())->toBe(404);
    expect(errorCode($response))->toBe('uamswp_migration_api_forms_plugin_not_active');
});

it('answers the same 404 for one Formidable form', function (): void {
    if (formidableOnDisk()) {
        $this->markTestSkipped('Formidable is present here. This is the branch CI covers.');
    }

    actAsSuperAdmin();

    // The by-id route checks for the plugin itself before it reads anything,
    // so it is held to the answer the collection gives (#363).
    $response = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1/forms/formidable/1'));

    expect($response->get_status())->toBe(404)
        ->and(errorCode($response))->toBe('uamswp_migration_api_forms_plugin_not_active');
});
