<?php

declare(strict_types=1);

/*
 * The premise the whole plugin rests on, made repeatable.
 *
 * Finding #2 in `AGENTS.md` — that `location` and `provider` values live in ACF
 * custom database tables while their postmeta sits frozen — is why WXR was
 * retired and why this plugin reads through `acf_get_value()`. It went years as
 * an argument from code paths. This is the observation, and it re-runs on every
 * suite instead of living in a terminal paste somebody once posted.
 *
 * Self-contained on purpose. It does NOT use Find-a-Doc's table map: that map
 * is committed stale (`UAMSWP-Find-a-Doc#813`), it declares 118 columns of
 * production schema, and depending on it would make this test a hostage to
 * another repository's state. Instead the test points ACF Custom Database
 * Tables at a two-column fixture of its own, in a throwaway directory, built
 * fresh per run — which is also why this file ships no committed `.cache`, the
 * very artifact whose staleness caused all of this.
 *
 * Skipped where ACF CDT is absent, which includes CI: it is a paid plugin and
 * not a Composer package. That is the third layer gated this way, after ACF PRO
 * and the free fallback — see `docs/DECISIONS.md` D-030.
 *
 * This test reports a PHP deprecation, and it is left visible on purpose. It
 * comes from CDT's own `Psr4Autoloader` — "optional parameter declared before
 * required parameter" — which is third-party code this repo does not own and a
 * genuine warning about the PHP version that will break it. Suppressing it
 * would silence the day that happens, and would be the quiet green this repo
 * argues against everywhere else. It does not fail the suite: `failOnDeprecation`
 * is deliberately unset in `phpunit.xml`, so the marker informs without gating.
 */

use ACFCustomDatabaseTables\Container;
use ACFCustomDatabaseTables\Coordinator\TableCoordinator;
use ACFCustomDatabaseTables\Coordinator\TableCreationCoordinator;
use ACFCustomDatabaseTables\Core;
use ACFCustomDatabaseTables\Data\TableMap;
use UAMSWP\MigrationAPI\ACF\FieldReader;

/**
 * Build the table from the fixture and hydrate the runtime map.
 *
 * The redirection itself lives in `tests/Integration/bootstrap.php` — `json_dir` is one of
 * CDT's `immutable` settings, so it can only be moved by `acfcdt/json_dir`
 * before the config is built at boot. What is left for here is the work the
 * "Create/Update Tables" button does, in the same order it does it.
 */
function cdtBuildFixture(): void
{
    $container = cdtContainer();

    $creation = $container['coordinator.table_creation'];
    $tables = $container['coordinator.table'];

    assert($creation instanceof TableCreationCoordinator);
    assert($tables instanceof TableCoordinator);

    $creation->update_tables();
    $creation->rebuild_map_cache();

    $tables->fetch_map_from_cache();
}

/**
 * CDT's service container.
 *
 * `__get_container()` rather than `container()`: the latter is deprecated in
 * CDT 1.1, and `WP_UnitTestCase` turns a deprecation notice into a failure —
 * correctly, since a notice in a test is a warning about the next major.
 */
function cdtContainer(): Container
{
    $core = acf_custom_database_tables();

    assert($core instanceof Core, 'ACF Custom Database Tables did not boot');

    return $core->__get_container();
}

/**
 * Remove the table, the redirection and the fixture directory.
 */
function cdtTearDownFixture(): void
{
    $wpdb = testDatabase();

    $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'uamswp_cdt_fixture');

    // Only the generated cache goes; the fixture JSON and its directory are the
    // bootstrap's, and it removes them at shutdown.
    @unlink(cdtFixtureDir().'/.cache/_table_map.php');

    acf_remove_local_field_group('group_cdt_fixture');
    FieldReader::resetValueStore();
    wp_cache_flush();
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves the custom-table value in `acf` while `meta` still carries the frozen postmeta', function (): void {
    cdtBuildFixture();

    // The map has to actually resolve, or this test passes by reading postmeta
    // from both sides and proves nothing — which is precisely how the real
    // divergence stayed invisible for years.
    $tableMap = cdtContainer()['table_map'];

    assert($tableMap instanceof TableMap);

    $map = $tableMap->get_map();

    expect(array_keys((array) ($map['post_types'] ?? [])))->toContain('post');

    acf_add_local_field_group([
        'key' => 'group_cdt_fixture',
        'title' => 'CDT fixture',
        'fields' => [
            ['key' => 'field_cdt_fixture_headline', 'name' => 'cdt_fixture_headline', 'type' => 'text', 'label' => 'Headline'],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    ]);

    $id = makePost();

    // Postmeta: what WXR would export. Stale by construction, exactly as
    // Find-a-Doc's `location` rows are, because `store_acf_values_in_core_meta`
    // stopped them being updated.
    $frozen = 'FROZEN — the value postmeta was left holding.';
    update_post_meta($id, 'cdt_fixture_headline', $frozen);
    update_post_meta($id, '_cdt_fixture_headline', 'field_cdt_fixture_headline');

    // The custom table: what an editor actually saved since.
    $current = 'CURRENT — the value that exists only in the custom table.';

    $wpdb = testDatabase();
    $wpdb->replace($wpdb->prefix.'uamswp_cdt_fixture', ['post_id' => $id, 'cdt_fixture_headline' => $current]);

    wp_cache_flush();
    FieldReader::resetValueStore();

    $request = new WP_REST_Request('GET', "/uamswp-migration/v1/posts/post/{$id}");
    $response = rest_do_request($request);

    expect($response->get_status())->toBe(200)
        // The whole point: one payload, two answers, and the structured one wins.
        ->and(payload($response, 'acf', 'names', 'cdt_fixture_headline'))->toBe($current)
        ->and(payload($response, 'meta', 'cdt_fixture_headline'))->toBe([$frozen])
        // And the key view agrees with the name view, so D-025's two views do
        // not disagree about which store they read from.
        ->and(payload($response, 'acf', 'keys', 'field_cdt_fixture_headline'))->toBe($current);
})->skip(
    ! cdtAvailable(),
    'ACF Custom Database Tables is not installed alongside this checkout; the divergence cannot be reproduced without it.'
);

it('reports a value held only in the custom table as stored on both metadata probes', function (): void {
    // The reader asks `acf_get_metadata_by_field()` whether a row exists, and
    // below ACF 6.4 asks `acf_get_metadata()` instead (#22, D-107). Both must
    // see a custom-table row that has no postmeta beside it, or a `location`
    // or `provider` value would move into the defaulted view as if the field
    // definition had supplied it. The field carries a default so that move
    // would show.
    cdtBuildFixture();

    $field = [
        'key' => 'field_cdt_fixture_headline',
        'name' => 'cdt_fixture_headline',
        'type' => 'text',
        'label' => 'Headline',
        'default_value' => 'SUPPLIED by the field definition.',
    ];

    acf_add_local_field_group([
        'key' => 'group_cdt_fixture',
        'title' => 'CDT fixture',
        'fields' => [$field],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    ]);

    $id = makePost();
    $current = 'CURRENT, and held only in the custom table.';

    $wpdb = testDatabase();
    $wpdb->replace($wpdb->prefix.'uamswp_cdt_fixture', ['post_id' => $id, 'cdt_fixture_headline' => $current]);

    wp_cache_flush();
    FieldReader::resetValueStore();

    // No postmeta at all, so a probe that read core meta directly would report
    // no row here: the custom table is the only thing either probe can see.
    expect(get_post_meta($id, 'cdt_fixture_headline'))->toBe([]);

    // CDT answers `acf/pre_load_metadata` only for a field whose value it has
    // already fetched through `acf/pre_load_value`, so the probes are asked
    // in the reader's own order: the value first, then whether a row exists.
    expect(acf_get_value($id, $field))->toBe($current)
        ->and(acf_get_metadata($id, 'cdt_fixture_headline'))->toBe($current)
        ->and(acf_get_metadata_by_field($id, $field))->toBe($current);

    FieldReader::resetValueStore();

    $read = FieldReader::forPost($id);

    expect($read['names']['cdt_fixture_headline'] ?? null)->toBe($current)
        ->and($read['defaulted_names'])->not->toHaveKey('cdt_fixture_headline');
})->skip(
    ! cdtAvailable() || ! \function_exists('acf_get_metadata_by_field'),
    'Needs ACF Custom Database Tables, and ACF 6.4 or later to compare the two probes.'
);

afterEach(function (): void {
    if (cdtAvailable()) {
        cdtTearDownFixture();
    }
});
