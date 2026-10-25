<?php

/**
 * Base case for every test that boots WordPress.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Tests\Support;

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Site;
use WP_REST_Server;
use WP_UnitTest_Factory;
use WP_UnitTestCase;

/**
 * WordPress test case, adapted to PHPUnit 11 and to this plugin's global state.
 *
 * Two jobs: bridge the one place WordPress' test suite still calls a PHPUnit API
 * that no longer exists, and undo the process-wide state this plugin sets, which
 * `WP_UnitTestCase`'s transaction rollback does not cover.
 */
abstract class WordPressTestCase extends WP_UnitTestCase
{
    /**
     * The fixture factory, reachable from a test closure and typed.
     *
     * Core declares this `protected static`, so a Pest closure has to reach it as
     * `$this->factory()` — and PHPStan resolves `$this` inside a Pest closure to
     * `PHPUnit\Framework\TestCase`, where the method does not exist. Everything
     * downstream then degrades to `mixed`.
     *
     * Widening it to public turns that into an ordinary typed static call, which
     * `tests/Support/helpers.php` builds the fixture helpers on. Nothing is
     * overridden or reimplemented — the parent still owns the factory.
     */
    public static function factory(): WP_UnitTest_Factory
    {
        return parent::factory();
    }

    /**
     * Wire the deprecation hooks without parsing docblock annotations.
     *
     * WordPress core reads `@expectedDeprecated` and `@expectedIncorrectUsage`
     * annotations here through `PHPUnit\Util\Test::parseTestMethodAnnotations()`
     * and `getName()`, both removed in PHPUnit 10. This is the ONLY call in the
     * whole of `wp-phpunit` that reaches a removed PHPUnit API — verified by
     * scanning the package — which is why one override is enough to run core's
     * test case on PHPUnit 11 rather than pinning the suite to PHPUnit 9.
     *
     * The annotations themselves are a core-test idiom for asserting that a
     * deprecated function was called. This plugin has no deprecated surface of
     * its own, so dropping the parsing costs nothing; the hook wiring below is
     * kept verbatim, because `assertPostConditions()` still reconciles against
     * the properties these handlers populate.
     */
    public function expectDeprecated(): void
    {
        add_action('deprecated_function_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_argument_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_class_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_file_included', [$this, 'deprecated_function_run'], 10, 4);
        add_action('deprecated_hook_run', [$this, 'deprecated_function_run'], 10, 4);
        add_action('doing_it_wrong_run', [$this, 'doing_it_wrong_run'], 10, 3);

        /*
         * `add_filter`, where core's own test suite writes `add_action`.
         *
         * These six are filters — `_deprecated_function()` and friends gate on
         * `apply_filters('deprecated_function_trigger_error', true)`, and returning
         * false is what suppresses the notice. Registering a returning callback as
         * an action happens to work, because `add_action()` is an alias, but it
         * misstates the contract and reads as a bug to any analyzer. The six above
         * stay `add_action` because those genuinely are `do_action` hooks.
         */
        add_filter('deprecated_function_trigger_error', '__return_false');
        add_filter('deprecated_argument_trigger_error', '__return_false');
        add_filter('deprecated_class_trigger_error', '__return_false');
        add_filter('deprecated_file_trigger_error', '__return_false');
        add_filter('deprecated_hook_trigger_error', '__return_false');
        add_filter('doing_it_wrong_trigger_error', '__return_false');
    }

    /**
     * Replace a core method whose docblock trips PHPUnit's metadata parser.
     *
     * The body is intentionally empty. Core marks its own version deprecated as of
     * WordPress 5.9 and notes it has not been functional since PHPUnit 7, so there
     * is no behavior to preserve. Its docblock, however, names the group annotation
     * in prose, and PHPUnit's metadata parser reads that as real metadata in a
     * doc-comment — a deprecation warning on every run today, an error in PHPUnit 12.
     *
     * Which is why no annotation-shaped token appears in this docblock either.
     */
    protected function checkRequirements(): void {}

    /**
     * Give each test a REST server built from the current hook state.
     *
     * `rest_get_server()` memoizes into `$wp_rest_server` and fires `rest_api_init`
     * exactly once per process. Without this, a test that filters routes after an
     * earlier test has already built the server silently asserts against the
     * earlier test's route table.
     */
    public function set_up(): void
    {
        parent::set_up();

        Config::flush();

        /*
         * Keep a database error off standard output on a single site too.
         *
         * `WP_UnitTestCase` turns `$wpdb->show_errors` on for every class. On a
         * network `wpdb::print_error()` then writes only to a log file named by a
         * constant this suite never defines; on a single site it prints an HTML
         * `<div>`, and Pest's renderer fails on the class that markup carries
         * (`Style [...] not found`), ending the run. A test that provokes a query
         * failure on purpose reads `$wpdb->last_error`, which is set before
         * printing is considered, so hiding the output changes no assertion.
         */
        global $wpdb, $wp_rest_server;

        if (! is_multisite() && $wpdb instanceof \wpdb) {
            $wpdb->hide_errors();
        }

        $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init', $wp_rest_server);
    }

    /**
     * Undo what the rollback cannot.
     *
     * `Site` tracks whether it owes a `restore_current_blog()` in a private static,
     * and a test that fails mid-dispatch never reaches `rest_post_dispatch`. Left
     * alone, every later test in the process reads the wrong site — a failure that
     * surfaces as wrong data rather than as an error.
     */
    public function tear_down(): void
    {
        Site::restore();
        Config::flush();

        global $wp_rest_server;

        $wp_rest_server = null;

        parent::tear_down();
    }
}
