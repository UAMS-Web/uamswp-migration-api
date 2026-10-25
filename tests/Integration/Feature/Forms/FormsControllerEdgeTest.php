<?php

/**
 * The form routes' guards that ordinary fixtures do not reach.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\REST\FormsController;

/*
 * Pins the branches of `FormsController` that need an input the endpoint tests
 * never build: a missing feed table, a feed with no form, an id of zero, and a
 * request answered while `$wpdb` is not a database handle (#363). The branches
 * that run only when a form plugin is not loaded are covered by the guard tests
 * beside this file, in the environment where the plugin is absent.
 */

/**
 * Dispatch an authenticated GET against a form route.
 *
 * @param  array<string, mixed>  $params  Query parameters.
 */
function formsEdgeGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * Call a controller method directly while `$wpdb` is not a database handle.
 *
 * Dispatching through the REST server needs the database for the capability
 * check, so the method is called on the controller instead, with the handle
 * swapped out for the call alone.
 *
 * @param  WP_REST_Request<array<string, mixed>>  $request
 */
function formsEdgeWithoutDatabase(string $method, WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $controller = new FormsController;
    $handle = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = null;

    try {
        $response = $controller->{$method}($request);
    } finally {
        $GLOBALS['wpdb'] = $handle;
    }

    assert($response instanceof WP_REST_Response || $response instanceof WP_Error);

    return $response;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves an empty feed list when the feed table is missing', function (): void {
    if (! FormsController::gravityFormsAvailable()) {
        $this->markTestSkipped('Gravity Forms is not in the surrounding install.');
    }

    global $wpdb;

    assert($wpdb instanceof wpdb);

    $formId = GFAPI::add_form(['title' => 'Edge missing table', 'fields' => [['id' => 1, 'type' => 'text', 'label' => 'Name']]]);

    assert(is_int($formId));

    GFAPI::add_feed($formId, ['feedName' => 'present'], 'gravityformsuserregistration');

    // The control: with the table readable, the feed is served.
    expect(formsEdgeGet('/forms/gravity/feeds')->get_data())->not->toBe([]);

    // `GFAPI::get_feeds()` asks `GFCommon::table_exists()`, which answers from
    // this cache entry when it holds one, so the table reads as missing
    // without dropping it.
    $cacheKey = 'table_exists_'.$wpdb->prefix.'gf_addon_feed';
    GFCache::set($cacheKey, false);

    try {
        $response = formsEdgeGet('/forms/gravity/feeds');
    } finally {
        GFCache::delete($cacheKey);
    }

    expect($response->get_status())->toBe(200)
        ->and($response->get_data())->toBe([]);
});

it('leaves a feed whose form id is zero out of the feed collection', function (): void {
    if (! FormsController::gravityFormsAvailable()) {
        $this->markTestSkipped('Gravity Forms is not in the surrounding install.');
    }

    global $wpdb;

    assert($wpdb instanceof wpdb);

    $table = $wpdb->prefix.'gf_addon_feed';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        $this->markTestSkipped('`gf_addon_feed` was not installed by the bootstrap.');
    }

    $formId = GFAPI::add_form(['title' => 'Edge feeds', 'fields' => [['id' => 1, 'type' => 'text', 'label' => 'Name']]]);

    assert(is_int($formId));

    $kept = GFAPI::add_feed($formId, ['feedName' => 'kept'], 'gravityformsuserregistration');

    assert(is_int($kept));

    $wpdb->insert($table, [
        'form_id' => 0,
        'is_active' => 1,
        'feed_order' => 0,
        'meta' => wp_json_encode(['feedName' => 'orphan']),
        'addon_slug' => 'gravityformsuserregistration',
    ]);
    $orphan = (int) $wpdb->insert_id;

    expect($orphan)->toBeGreaterThan(0);

    $response = formsEdgeGet('/forms/gravity/feeds', ['per_page' => 100]);
    $feeds = $response->get_data();

    assert(is_array($feeds));

    $ids = array_map(static fn (mixed $feed): mixed => is_array($feed) ? ($feed['id'] ?? null) : null, $feeds);

    expect($response->get_status())->toBe(200)
        ->and($ids)->toContain($kept)
        ->and($ids)->not->toContain($orphan);
});

it('answers 404 for Formidable form id zero', function (): void {
    if (! FormsController::formidableAvailable()) {
        $this->markTestSkipped('Formidable Forms is not in the surrounding install.');
    }

    $response = formsEdgeGet('/forms/formidable/0');

    expect($response->get_status())->toBe(404)
        ->and(errorCode($response))->toBe('uamswp_migration_api_not_found');
});

it('answers 404 for Ninja form id zero', function (): void {
    $response = formsEdgeGet('/forms/ninja/0');

    expect($response->get_status())->toBe(404)
        ->and(errorCode($response))->toBe('uamswp_migration_api_not_found');
});

it('serves an empty Ninja collection when there is no database handle', function (): void {
    $response = formsEdgeWithoutDatabase('indexNinja', new WP_REST_Request('GET', '/uamswp-migration/v1/forms/ninja'));

    assert($response instanceof WP_REST_Response);

    expect($response->get_data())->toBe([])
        ->and($response->get_headers()['X-WP-Total'] ?? null)->toBe('0');
});

it('answers 404 for a Ninja form when there is no database handle', function (): void {
    global $wpdb;

    assert($wpdb instanceof wpdb);

    $form = new NF_Database_Models_Form($wpdb, '');
    $form->update_settings(['title' => 'Edge Ninja', 'key' => '']);
    $form->save();

    $id = $form->get_id();

    assert(is_numeric($id));

    // The control: with the handle in place the form is served.
    expect(formsEdgeGet('/forms/ninja/'.$id)->get_status())->toBe(200);

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/forms/ninja/'.$id);
    $request->set_url_params(['id' => (string) $id]);

    $response = formsEdgeWithoutDatabase('showNinja', $request);

    expect($response)->toBeInstanceOf(WP_Error::class)
        ->and($response instanceof WP_Error ? $response->get_error_code() : null)->toBe('uamswp_migration_api_not_found');
});
