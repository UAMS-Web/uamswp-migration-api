<?php

/**
 * Typed fixtures and response readers for the Feature suite.
 *
 * Everything WordPress' test suite and the REST API hand back is loosely typed:
 * the factories return `mixed`, and `WP_REST_Response::get_data()` returns `mixed`
 * because a route may return anything. Reaching through those values inline leaves
 * `tests/` impossible to analyze at `level: max`, which the repo analyzes it at
 * deliberately.
 *
 * These helpers narrow once, in one place, with `assert()` rather than a cast — a
 * cast would paper over a genuinely wrong shape, where the assertion fails loudly
 * and names the fixture that broke.
 */

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use UAMSWP\MigrationAPI\Tests\Support\WordPressTestCase;

/**
 * Create a user with the given role.
 */
function makeUser(string $role = 'subscriber'): int
{
    $id = WordPressTestCase::factory()->user->create(['role' => $role]);

    assert(is_int($id), 'the user factory returned something other than an id');

    return $id;
}

/**
 * Which ACF the bootstrap loaded — `pro` or `free`.
 *
 * Read through `constant()` rather than named directly. A constant defined
 * inside a conditional in `tests/Integration/bootstrap.php` is `constant.notFound` to
 * PHPStan when referenced by name, but it traces the `constant()` call and
 * narrows this to `'pro'|'free'` — so routing through here is both analyzable
 * and *better* typed than naming it inline.
 */
function acfEdition(): string
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_ACF'), 'the bootstrap did not record which ACF it loaded');

    return constant('UAMSWP_MIGRATION_API_TESTS_ACF');
}

/**
 * Whether ACF PRO is present in the surrounding install.
 *
 * Distinct from `acfEdition()`: the pair is what lets a PRO-only skip be told
 * apart from a PRO-only test that stopped running.
 */
function acfProOnDisk(): bool
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_ACF_PRO_ON_DISK'), 'the bootstrap did not record whether ACF PRO is present');

    return (bool) constant('UAMSWP_MIGRATION_API_TESTS_ACF_PRO_ON_DISK');
}

/**
 * Whether ACF Custom Database Tables is present in the surrounding install.
 *
 * The third optional layer, after ACF PRO and ACF free. Read through
 * `constant()` for the same reason as the others.
 */
function cdtAvailable(): bool
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_CDT'), 'the bootstrap did not record whether ACF CDT is present');

    return (bool) constant('UAMSWP_MIGRATION_API_TESTS_CDT');
}

/**
 * Whether Gravity Forms is present in the surrounding install.
 *
 * Paid, so it is never on CI — the fourth optional layer, read through
 * `constant()` for the same reason as the others. A test that needs a real
 * `GFAPI` calls this and `skip()`s; `tests/Integration/Feature/Forms/GravityFormsGuardTest.php`
 * is what stops the skip becoming permanent by accident.
 */
function gravityFormsOnDisk(): bool
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_GRAVITY_FORMS_ON_DISK'), 'the bootstrap did not record whether Gravity Forms is present');

    return (bool) constant('UAMSWP_MIGRATION_API_TESTS_GRAVITY_FORMS_ON_DISK');
}

/**
 * The directory holding the importer's committed Gravity Forms exports.
 *
 * An empty string when this machine has not been told where they are, which is
 * the skip condition for the fixture-parity test.
 */
function gravityFormsFixtureDir(): string
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_GF_FIXTURE_DIR'), 'the bootstrap did not record the Gravity Forms fixture directory');

    $dir = constant('UAMSWP_MIGRATION_API_TESTS_GF_FIXTURE_DIR');

    return is_string($dir) ? $dir : '';
}

/**
 * Whether Formidable Forms is present in the surrounding install.
 *
 * The fifth optional plugin, read through `constant()` like the others. Pro is
 * recorded separately because about 23% of the captured corpus uses Pro-tier
 * field types, so a base-only install cannot represent it.
 */
function formidableOnDisk(): bool
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_FORMIDABLE_ON_DISK'), 'the bootstrap did not record whether Formidable is present');

    return (bool) constant('UAMSWP_MIGRATION_API_TESTS_FORMIDABLE_ON_DISK');
}

/**
 * The directory holding the importer's committed Formidable exports.
 *
 * Empty when this machine has not been told where they are, which is the skip
 * condition for the fixture-parity test. Not vendored, for the same reason the
 * Gravity Forms corpus is not: they are production form definitions.
 */
function formidableFixtureDir(): string
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURE_DIR'), 'the bootstrap did not record the Formidable fixture directory');

    $dir = constant('UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURE_DIR');

    return is_string($dir) ? $dir : '';
}

/**
 * The WordPress database handle, typed.
 *
 * `global $wpdb` is `mixed` to the analyzer, so every `$wpdb->` in a test is an
 * error at `level: max`. Narrowed once here, like everything else in this file.
 */
function testDatabase(): wpdb
{
    global $wpdb;

    assert($wpdb instanceof wpdb, 'the WordPress database handle is not available');

    return $wpdb;
}

/**
 * The throwaway directory the bootstrap points ACF CDT at.
 */
function cdtFixtureDir(): string
{
    assert(defined('UAMSWP_MIGRATION_API_TESTS_CDT_DIR'), 'the bootstrap did not record the CDT fixture directory');

    $dir = constant('UAMSWP_MIGRATION_API_TESTS_CDT_DIR');

    assert(is_string($dir), 'the recorded CDT fixture directory is not a path');

    return $dir;
}

/**
 * Create a post.
 *
 * @param  array<string, mixed>  $args  Overrides passed straight to the factory.
 */
function makePost(array $args = []): int
{
    $id = WordPressTestCase::factory()->post->create($args);

    assert(is_int($id), 'the post factory returned something other than an id');

    return $id;
}

/**
 * Create a super admin and make it the current user.
 */
function actAsSuperAdmin(): int
{
    $id = makeUser('administrator');

    grant_super_admin($id);
    wp_set_current_user($id);

    return $id;
}

/**
 * Create a user with the given role and make it the current user.
 */
function actAs(string $role): int
{
    $id = makeUser($role);

    wp_set_current_user($id);

    return $id;
}

/**
 * Create an attachment from a real file, optionally attached to a parent.
 *
 * `create_upload_object()` rather than a bare post row: it runs the upload
 * through `wp_generate_attachment_metadata()`, so the record carries a genuine
 * `_wp_attachment_metadata` with a `sizes` map. A hand-written row would let a
 * test assert against metadata the site never actually produces.
 */
function makeAttachment(string $file, int $parent = 0): int
{
    $id = WordPressTestCase::factory()->attachment->create_upload_object($file, $parent);

    assert(is_int($id), 'the attachment factory returned something other than an id');

    return $id;
}

/**
 * Create a term in a taxonomy.
 *
 * @param  array<string, mixed>  $args  Overrides passed straight to the factory.
 */
function makeTerm(array $args = []): int
{
    $id = WordPressTestCase::factory()->term->create($args);

    assert(is_int($id), 'the term factory returned something other than an id');

    return $id;
}

/**
 * The skip message for a test that needs a network.
 *
 * The suite runs once as a network and once as a single site (#14), and
 * `MULTISITE` is fixed for the process, so a test that only means something on
 * one shape skips on the other with this as its reason. The skip cannot hide a
 * run that never happened: `InstallShapeGuardTest.php` reds a process whose
 * install shape is not the one it was started for.
 */
function networkOnly(string $why): string
{
    return "Needs a network: {$why}. This process runs as a single site.";
}

/**
 * The skip message for a test that needs a single site.
 */
function singleSiteOnly(string $why): string
{
    return "Needs a single site: {$why}. This process runs as a network.";
}

/**
 * Create an additional site in the network, or skip on a single site.
 *
 * A single site has no second site to create, so every test that builds one
 * is a network test, and the skip lives here rather than on each of them.
 */
function makeSite(): int
{
    if (! is_multisite()) {
        Assert::markTestSkipped(networkOnly('it creates a second site'));
    }

    $id = WordPressTestCase::factory()->blog->create();

    assert(is_int($id), 'the blog factory returned something other than a blog id');

    return $id;
}

/**
 * Read a nested value out of a REST response payload.
 *
 * Returns `mixed` on purpose — `expect()` accepts it, and inventing a type for
 * each endpoint's shape would be a second, drifting copy of the contract.
 */
function payload(WP_REST_Response $response, string ...$path): mixed
{
    $value = $response->get_data();

    foreach ($path as $key) {
        assert(is_array($value), "payload path '{$key}' is not inside an array");

        $value = $value[$key] ?? null;
    }

    return $value;
}

/**
 * The error code of a response, or null when it is not an error.
 */
function errorCode(WP_REST_Response $response): ?string
{
    $error = $response->as_error();

    return $error instanceof WP_Error ? (string) $error->get_error_code() : null;
}

/**
 * Response headers, keyed by header name.
 *
 * @return array<string, string>
 */
function headers(WP_REST_Response $response): array
{
    /** @var array<string, string> */
    return $response->get_headers();
}
