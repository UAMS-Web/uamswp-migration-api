<?php

/**
 * Integration bootstrap.
 *
 * Boots WordPress through `wp-phpunit` for the Integration suite, which runs
 * from its own Composer setup in this directory (#375): WordPress' test
 * bootstrap requires `yoast/phpunit-polyfills`, which supports PHPUnit 12 at
 * most, so it cannot share the root's Pest 5 on PHPUnit 13. This file loads
 * this directory's autoloader and never the root's. The unit suite runs from
 * the root with no WordPress at all.
 */

declare(strict_types=1);
use PrettyLinks\Bootstrap;
use PrettyLinks\Database\Migrator;
use Redirection\Database\Schema\Latest;

/*
 * Runs at most once.
 *
 * Two things load this file: `phpunit.xml`'s `bootstrap` attribute, and
 * `Pest.php` beside it. Pest evaluates its configuration file BEFORE PHPUnit
 * reaches the bootstrap, and `uses(WordPressTestCase::class)` resolves a class
 * extending `WP_UnitTestCase`, which does not exist until WordPress is up. So
 * Pest.php has to pull this in itself, and this guard keeps the second load from
 * re-running the installer and redefining every constant.
 */
if (\defined('UAMSWP_MIGRATION_API_TESTS_BOOTSTRAPPED')) {
    return;
}

define('UAMSWP_MIGRATION_API_TESTS_BOOTSTRAPPED', true);

if (! is_readable(__DIR__.'/vendor/autoload.php')) {
    fwrite(
        STDERR,
        PHP_EOL
        ."Refusing to run: the Integration suite's own Composer setup is not installed."
        .PHP_EOL
        .'Run `composer setup:integration` from the repository root first.'
        .PHP_EOL.PHP_EOL
    );

    exit(1);
}

require_once __DIR__.'/vendor/autoload.php';

$wordpress = rtrim((string) (getenv('WP_TESTS_ABSPATH') ?: dirname(__DIR__, 5)), '/').'/';
$testDatabase = (string) (getenv('WP_TESTS_DB_NAME') ?: 'uamswp_migration_api_tests');

/*
 * Refuse to run against the working install's database.
 *
 * WordPress' test installer DROPS EVERY TABLE it finds and rebuilds the schema
 * from scratch. Pointed at the development site it destroys it silently and
 * completely — there is no prompt and no dry run. The default database name is
 * distinct, so this only fires when someone overrides WP_TESTS_DB_NAME, which is
 * exactly the moment a typo costs a day.
 */
$liveConfig = $wordpress.'wp-config.php';

if (is_readable($liveConfig)) {
    $matched = preg_match(
        '/define\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/',
        (string) file_get_contents($liveConfig),
        $liveDatabase
    );

    if ($matched === 1 && $liveDatabase[1] === $testDatabase) {
        fwrite(
            STDERR,
            PHP_EOL
            ."Refusing to run: WP_TESTS_DB_NAME is '{$testDatabase}', which is the database"
            .PHP_EOL
            ."the WordPress install at {$wordpress} uses. The test installer drops every"
            .PHP_EOL
            .'table it finds. Point WP_TESTS_DB_NAME at a database you can afford to lose.'
            .PHP_EOL.PHP_EOL
        );

        exit(1);
    }
}

/*
 * Refuse a SECOND concurrent suite against this database.
 *
 * The guard above stops the suite running against the working install. This one
 * stops it running against ITSELF: WordPress' test installer drops every table
 * it finds, so two hand-run suites interleaving produce a wall of failures
 * indistinguishable from real regressions (#212). `AGENTS.md` has stated that as
 * a standing instruction since before the runner existed; nothing enforced it.
 *
 * Placed AFTER the live-database refusal deliberately -- pointing the suite at
 * the working install is the worse mistake and must be reported first, not
 * masked by a lock message about a database the run should never have named.
 */
require_once __DIR__.'/../Support/single-flight.php';

migrationApiAcquireSuiteLock($testDatabase, 'composer test:integration');

/*
 * Clear a `config/local.php` fixture a killed run left behind (#303), before
 * anything reads configuration.
 *
 * AFTER the lock, deliberately: a second suite in this tree is refused above
 * before it gets here, so it can never remove the fixture a live run is using.
 * Only a file carrying the suite's marker is touched; a real override is not.
 */
require_once __DIR__.'/../Support/config-fixture.php';

migrationApiClearStaleConfigFixture(\dirname(__DIR__, 2).'/config/local.php');

require_once __DIR__.'/../Support/phpunit-removed-classes.php';

/*
 * Point core's bootstrap at this repo's config. Left undefined it searches its own
 * package directory, finds nothing useful, and fails with a list of missing
 * constants rather than a missing file.
 */
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__.'/wp-tests-config.php');

$wpPhpunit = (string) getenv('WP_PHPUNIT__DIR');

require_once $wpPhpunit.'/includes/functions.php';

/*
 * Locate ACF, preferring PRO.
 *
 * The suite reads ACF through three functions and has to exercise them against
 * a real ACF rather than a stand-in (D-021, applied one layer out). Two copies
 * are available and they are not equivalent:
 *
 *   PRO  — the sibling plugin directory of the install this checkout sits in.
 *          The version production runs, and the only one with `repeater`,
 *          `flexible_content` and `clone` — the containers whose recursion is
 *          the entire mechanism of #21. Not installable on a CI runner: it is
 *          paid, and it is not a Composer dependency of anything.
 *   free — `wpackagist-plugin/advanced-custom-fields`, a dev dependency pinned
 *          to PRO's minor. Present everywhere, CI included. No PRO containers.
 *
 * PRO wins wherever it exists, so a developer's run is the high-fidelity one
 * and CI's is the portable one. `tests/Integration/Feature/ACF/AcfEditionGuardTest.php`
 * refuses a run that loaded free while PRO sat on disk — the one way this could
 * quietly lose the coverage that matters.
 */
$acfPro = \dirname(__DIR__, 3).'/advanced-custom-fields-pro/acf.php';
$acfFree = __DIR__.'/vendor/wpackagist-plugin/advanced-custom-fields/acf.php';

define('UAMSWP_MIGRATION_API_TESTS_ACF_PRO_ON_DISK', is_readable($acfPro));

$acf = is_readable($acfPro) ? $acfPro : $acfFree;

if (! is_readable($acf)) {
    fwrite(
        STDERR,
        PHP_EOL
        .'Refusing to run: no ACF was found. The suite needs it to exercise the field reader.'
        .PHP_EOL
        .'Run `composer setup:integration` to get the free copy, or check out this plugin inside a'
        .PHP_EOL
        .'WordPress install that has Advanced Custom Fields PRO alongside it.'
        .PHP_EOL.PHP_EOL
    );

    exit(1);
}

define('UAMSWP_MIGRATION_API_TESTS_ACF', is_readable($acfPro) ? 'pro' : 'free');

/*
 * ACF Custom Database Tables, when the surrounding install has it.
 *
 * A third optional layer, and the reason is D-024: the divergence this whole
 * plugin exists to serve — a `location` whose current value lives in a custom
 * table while its postmeta sits frozen — cannot be reproduced without it. Like
 * ACF PRO it is paid and is not a Composer package, so it is absent on CI.
 *
 * Loaded with an EMPTY table map, which makes it inert: `has_table()` finds
 * nothing, both intercepts pass through, and every other test behaves exactly
 * as it did before. `tests/Integration/Feature/ACF/CustomTableDivergenceTest.php` points it
 * at a fixture of its own for the one test that needs it.
 */
$cdt = \dirname(__DIR__, 3).'/acf-custom-database-tables/acf-custom-database-tables.php';

define('UAMSWP_MIGRATION_API_TESTS_CDT', is_readable($cdt));

/*
 * CDT is redirected at a throwaway fixture directory, and it has to happen
 * here rather than in the test that uses it.
 *
 * `json_dir` and `table_map_cache_dir` sit in CDT's `immutable` settings block,
 * which `Settings::get()` returns *before* applying any
 * `acfcdt/settings/{$key}` filter — so those filters are inert for exactly the
 * two keys worth overriding. The one real lever is `acfcdt/json_dir`, applied
 * once while the config is built at boot, which means the filter must already
 * be registered by then.
 *
 * Pointing it at a fixture rather than the install's own directory keeps the
 * suite off Find-a-Doc's production schema, and keeps it from writing a
 * `.cache` into a real plugin.
 */
$cdtFixtureDir = sys_get_temp_dir().'/uamswp-migration-api-cdt-'.getmypid();

define('UAMSWP_MIGRATION_API_TESTS_CDT_DIR', $cdtFixtureDir);

if (is_readable($cdt)) {
    if (! is_dir($cdtFixtureDir.'/.cache')) {
        mkdir($cdtFixtureDir.'/.cache', 0777, true);
    }

    copy(__DIR__.'/../Support/acf-cdt/table_uamswp_cdt_fixture.json', $cdtFixtureDir.'/table_uamswp_cdt_fixture.json');

    // The directory is per-process and disposable; nothing outlives the run.
    register_shutdown_function(static function () use ($cdtFixtureDir): void {
        foreach (glob($cdtFixtureDir.'/.cache/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($cdtFixtureDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($cdtFixtureDir.'/.cache');
        @rmdir($cdtFixtureDir);
    });
}

/*
 * Gravity Forms, when the surrounding install has it.
 *
 * Paid, and not a Composer package, so it is absent on CI for exactly the
 * reason ACF PRO is (D-030). The endpoint reads through `GFAPI` and nothing
 * else, so a stand-in would be testing the stand-in: the tests that need a
 * real one `skip()` when it is missing, and `tests/Integration/Feature/Forms/GravityFormsGuardTest.php`
 * reds a run that had it on disk and failed to load it, so the skip cannot
 * become silently permanent.
 */
$gravityForms = \dirname(__DIR__, 3).'/gravityforms/gravityforms.php';

define('UAMSWP_MIGRATION_API_TESTS_GRAVITY_FORMS_ON_DISK', is_readable($gravityForms));

/*
 * The importer's committed Gravity Forms exports, when this machine has them.
 *
 * `UAMSWP_MIGRATION_API_TESTS_GF_FIXTURES` points at
 * `wordpress-importer/src/Services/WordPressImport/Tests/Exports/GravityForms`.
 * An environment variable rather than a path guess, because that repo has no
 * fixed position relative to this one, and rather than committing a copy —
 * those files are real production form definitions carrying real notification
 * recipients, and this repo does not commit production artifacts.
 *
 * Unset, `GravityFormsFixtureParityTest` skips. That is the honest cost of not
 * vendoring the corpus, and the test says so rather than quietly passing.
 */
$frmFixtures = getenv('UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURES');

define(
    'UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURE_DIR',
    is_string($frmFixtures) && $frmFixtures !== '' && is_dir($frmFixtures) ? $frmFixtures : ''
);

$gfFixtures = getenv('UAMSWP_MIGRATION_API_TESTS_GF_FIXTURES');

define(
    'UAMSWP_MIGRATION_API_TESTS_GF_FIXTURE_DIR',
    is_string($gfFixtures) && $gfFixtures !== '' && is_dir($gfFixtures) ? $gfFixtures : ''
);

/*
 * Formidable Forms and Formidable Pro, when the surrounding install has them.
 *
 * The fifth and sixth optional plugins, on the arrangement D-044 established
 * for Gravity Forms. Pro matters rather than being optional decoration: across
 * the importer's 12 committed Formidable exports — 68 forms, 2,412 fields —
 * about 23% of the fields are Pro-tier types (`divider`, `end_divider`,
 * `date`, `address`, `file`, `rte`, `lookup`, `data`, `form`), so a base-only
 * install cannot represent the corpus this endpoint has to serve.
 *
 * Loaded from disk rather than requiring activation, exactly as ACF and Gravity
 * Forms are — the bootstrap never reads `active_plugins`.
 */
$formidable = \dirname(__DIR__, 3).'/formidable/formidable.php';
$formidablePro = \dirname(__DIR__, 3).'/formidable-pro/formidable-pro.php';

define('UAMSWP_MIGRATION_API_TESTS_FORMIDABLE_ON_DISK', is_readable($formidable));
define('UAMSWP_MIGRATION_API_TESTS_FORMIDABLE_PRO_ON_DISK', is_readable($formidablePro));

/*
 * Ninja Forms, from `vendor/` rather than from the surrounding install.
 *
 * The odd one out among the form plugins, and deliberately so. Gravity Forms
 * and Formidable are paid, so they are optional and their tests skip where
 * they are absent; Ninja Forms 3.x is free on wordpress.org, so it is a dev
 * dependency like ACF free and is present EVERYWHERE, CI included. Its
 * endpoint therefore has no skipping tier at all — the one form endpoint whose
 * coverage is the same on a runner as on a developer's machine.
 *
 * The surrounding install is checked first anyway: a developer who has it
 * activated there should be testing against the copy their site actually runs.
 */
$ninjaInstalled = \dirname(__DIR__, 3).'/ninja-forms/ninja-forms.php';
$ninjaVendored = __DIR__.'/vendor/wpackagist-plugin/ninja-forms/ninja-forms.php';
$ninja = is_readable($ninjaInstalled) ? $ninjaInstalled : $ninjaVendored;

if (! is_readable($ninja)) {
    fwrite(
        STDERR,
        PHP_EOL
        .'Refusing to run: no Ninja Forms was found. It is a dev dependency of tests/Integration, so `composer setup:integration` supplies it.'
        .PHP_EOL
    );

    exit(1);
}

define('UAMSWP_MIGRATION_API_TESTS_NINJA_FROM_INSTALL', is_readable($ninjaInstalled));

/*
 * Redirection and Pretty Links, from `vendor/` and nowhere else.
 *
 * Both are free on wordpress.org, so they are dev dependencies present
 * everywhere, CI included, and their tests never skip. Unlike Ninja Forms the
 * surrounding install is not consulted: neither plugin is installed on the
 * development network. They run on the vanity-domain installs, and
 * `composer.json` pins the exact versions those installs run (#374), because a
 * test against a different version proves nothing about the storage served.
 */
$redirection = __DIR__.'/vendor/wpackagist-plugin/redirection/redirection.php';
$prettyLinks = __DIR__.'/vendor/wpackagist-plugin/pretty-link/pretty-link.php';

/*
 * `UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS=1` loads neither, which
 * is the only way the routes' plugin-not-loaded 404 can run: both are dev
 * dependencies, so no tree this suite runs in lacks them (D-097). Set, it skips
 * the refusal below, both loads, and the table install, and every test that
 * needs either plugin fails or skips. It is for one invocation of
 * `RedirectPluginsAbsentTest.php`, never for `composer test` or the chain.
 */
define(
    'UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS',
    getenv('UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS') === '1'
);

foreach (['Redirection' => $redirection, 'Pretty Links' => $prettyLinks] as $redirectPlugin => $redirectPluginFile) {
    if (! UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS && ! is_readable($redirectPluginFile)) {
        fwrite(
            STDERR,
            PHP_EOL
            .'Refusing to run: no '.$redirectPlugin.' was found. It is a dev dependency of tests/Integration, so `composer setup:integration` supplies it.'
            .PHP_EOL
        );

        exit(1);
    }
}

/*
 * Load ACF, then the plugin under test. `muplugins_loaded` is the earliest hook
 * the test bootstrap fires, so the plugin registers its `rest_pre_dispatch`
 * handlers before anything dispatches — the same ordering a real request gets.
 *
 * ACF first: it registers its field types on `init`, and a field group declared
 * by a test is worthless until they exist.
 */
tests_add_filter(
    'muplugins_loaded',
    static function () use ($acf, $cdt, $cdtFixtureDir, $gravityForms, $formidable, $formidablePro, $ninja, $redirection, $prettyLinks): void {
        require_once $acf;

        /*
         * Refuse an ACF that is missing something `includes/` actually calls.
         *
         * The edition preference above takes ACF PRO from the surrounding install
         * whenever it is present, and until now nothing checked that the copy it
         * picked was new enough. `composer.json` pins the vendored free copy at
         * `~6.4.0`, so the floor was expressed for exactly the copy that gets used
         * only when PRO is absent — a constraint the common case bypasses.
         *
         * `josh-home` found this the expensive way (#165): its surrounding install
         * carried ACF PRO 6.2.9, `acf_get_metadata_by_field()` is `@since 6.4`, and
         * `FieldReader::isStoredRatherThanSupplied()` called a function that did not
         * exist. The failure arrived as 24 tests dying on
         * `Call to undefined function UAMSWP\MigrationAPI\ACF\acf_get_metadata_by_field()`
         * — PHP's namespace-fallback message, which names neither ACF nor a version
         * and reads like a bootstrap ordering fault.
         *
         * Checking the FUNCTIONS rather than the version number is deliberate: it is
         * the actual dependency, it needs no maintenance when a floor moves, and it
         * names precisely what is missing. `tests/Unit/AcfApiGuardTest.php` holds
         * `includes/` to an allowlist; this holds the loaded ACF to what that
         * allowlist actually invokes UNCONDITIONALLY.
         *
         * **`acf_get_metadata_by_field()` is now required directly, rather than the
         * pre-6.4 `acf_get_metadata()` fallback target it replaced here.** #169 kept
         * this list on the fallback target deliberately, precisely so a below-6.4
         * ACF stayed a supported run rather than a refusal, while `FieldReader`
         * called the newer function only behind `function_exists()`. #175 removed
         * that fallback once every development machine reached ACF PRO 6.4.2:
         * `FieldReader::storedMetadata()` now calls `acf_get_metadata_by_field()`
         * unconditionally, so leaving this list unchanged would require a function
         * nothing calls and stop requiring the one the reader now depends on
         * outright — reopening #165 with this guard pointed the wrong way to catch
         * it. See `docs/DECISIONS.md` D-069.
         */
        $acfRequired = [
            'acf_get_field_groups',
            'acf_get_fields',
            'acf_get_metadata_by_field',
            'acf_get_store',
            'acf_get_value',
        ];

        $acfMissing = array_values(array_filter(
            $acfRequired,
            static fn (string $function): bool => ! \function_exists($function)
        ));

        if ($acfMissing !== []) {
            // A constant's type is unknown to static analysis, and this one comes
            // from whichever ACF loaded — so narrow it rather than assume a string.
            $acfVersionConstant = \defined('ACF_VERSION') ? constant('ACF_VERSION') : null;
            $acfVersion = \is_string($acfVersionConstant) ? $acfVersionConstant : 'unknown';

            fwrite(
                STDERR,
                PHP_EOL
                .'Refusing to run: the ACF that loaded is missing '.\count($acfMissing)
                .' function(s) this plugin calls.'.PHP_EOL
                .'  loaded:   '.$acf.PHP_EOL
                .'  version:  '.$acfVersion.PHP_EOL
                .'  missing:  '.implode(', ', $acfMissing).PHP_EOL
                .'  edition:  '.(UAMSWP_MIGRATION_API_TESTS_ACF_PRO_ON_DISK ? 'PRO, from the surrounding install' : 'free, from vendor/').PHP_EOL
                .PHP_EOL
                .'`composer.json` pins the vendored copy at ~6.4.0, but ACF PRO in the'.PHP_EOL
                .'surrounding install is preferred when present and is not covered by that'.PHP_EOL
                ."constraint. Update that install's ACF, or remove it so the vendored copy".PHP_EOL
                .'is used. See #165.'.PHP_EOL
                .PHP_EOL
                .'`acf_get_metadata_by_field()` is required directly since #175: the pre-6.4'.PHP_EOL
                .'fallback it replaced is gone, so an ACF below 6.4 refuses here by name'.PHP_EOL
                .'rather than failing later on a namespace-fallback message.'.PHP_EOL
                .PHP_EOL
            );

            exit(1);
        }

        if (is_readable($cdt)) {
            // Registered before the plugin file is loaded, so it is in place
            // when CDT builds its config on `plugins_loaded`.
            add_filter('acfcdt/json_dir', static fn (): string => $cdtFixtureDir);

            require_once $cdt;
        }

        /*
         * Before the plugin under test, so `GFAPI` exists by the time anything
         * dispatches — the same order a real request sees, where Gravity Forms
         * is an ordinary active plugin rather than something loaded late.
         */
        if (is_readable($gravityForms)) {
            require_once $gravityForms;
        }

        // Base before Pro: Pro is an add-on and its classes extend base's.
        if (is_readable($formidable)) {
            require_once $formidable;

            if (is_readable($formidablePro)) {
                require_once $formidablePro;
            }
        }

        // Ninja Forms defines `Ninja_Forms()` on load. It does NOT build its
        // tables here — those are created on activation, which is why
        // `NF_Database_Migrations::migrate()` is called explicitly further
        // down. An earlier version of this comment said `init`, which would
        // have licensed deleting that call.
        require_once $ninja;

        // Both build their tables on activation (Redirection) or on
        // `after_setup_theme` (Pretty Links); either way the bootstrap installs
        // them explicitly further down rather than rely on a hook it never fires.
        if (! UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS) {
            require_once $redirection;
            require_once $prettyLinks;
        }

        require dirname(__DIR__, 2).'/uamswp-migration-api.php';
    }
);

require $wpPhpunit.'/includes/bootstrap.php';

/*
 * Drop the per-site tables an earlier run left behind (#440).
 *
 * The test installer drops only the tables `$wpdb->tables()` names: the main
 * site's and the network's. A table such as `wptests_3_options` outlives it
 * whenever a run committed one, and a site the factory creates later reuses
 * the id and inherits that table's rows. A Pretty Links migration state
 * committed there made `Migrator::maybeRun()` skip creating the new site's
 * tables, so the cross-site Pretty Links test failed when it ran alone.
 *
 * The installer has just reset `blogs` to the main site, so any per-site table
 * whose id names no site is stale. This runs inside the test run, against the
 * test database the guards above have already checked, and before any test
 * creates a site.
 */
$siteTablesDb = $GLOBALS['wpdb'] ?? null;

assert($siteTablesDb instanceof wpdb);

$liveSites = [];

foreach ($siteTablesDb->get_col("SELECT blog_id FROM {$siteTablesDb->blogs}") as $blogId) {
    if (is_numeric($blogId)) {
        $liveSites[] = (int) $blogId;
    }
}

$siteTablePattern = '/^'.preg_quote($siteTablesDb->base_prefix, '/').'(\d+)_\w+$/';

foreach ($siteTablesDb->get_col($siteTablesDb->prepare('SHOW TABLES LIKE %s', $siteTablesDb->esc_like($siteTablesDb->base_prefix).'%')) as $table) {
    if (is_string($table) && preg_match($siteTablePattern, $table, $site) === 1 && ! in_array((int) $site[1], $liveSites, true)) {
        $siteTablesDb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

/*
 * Install Formidable's tables, once, now that WordPress itself is installed.
 *
 * Gravity Forms does this unaided on load; Formidable does it on ACTIVATION,
 * and the bootstrap deliberately never activates anything — it requires plugin
 * files straight from disk. So the migration is run here explicitly.
 *
 * After the WordPress bootstrap rather than inside `muplugins_loaded`, because
 * `FrmMigrate` runs `dbDelta()` against tables that do not exist until the test
 * suite has installed WordPress.
 *
 * Once, here, rather than per test, and that placement is load-bearing: the
 * install writes Formidable's default style, which regenerates a stylesheet
 * inside the plugin's own `css/` directory and warns `mkdir(): File exists`
 * because that directory is already there. Out here the warning is a warning;
 * inside a test PHPUnit promotes it and every Formidable test reports one.
 */
if (is_readable($formidable) && class_exists('FrmMigrate')) {
    (new FrmMigrate)->upgrade();
}

/*
 * Install Ninja Forms' `nf3_*` tables, for the same reason and in the same
 * place as Formidable's above: they are created on ACTIVATION, and this
 * bootstrap never activates anything.
 *
 * `NF_Database_Migrations::migrate()` is what the activation hook reaches; it
 * runs `dbDelta()`, so it has to be after WordPress itself is installed.
 */
if (class_exists('NF_Database_Migrations')) {
    (new NF_Database_Migrations)->migrate();
}

/*
 * Install Redirection's and Pretty Links' tables, through each plugin's own
 * installer, and refuse to run when either is still missing afterwards.
 *
 * Redirection creates `redirection_items` and `redirection_groups` from its
 * activation hook, which this bootstrap never fires; `Latest::install()` is
 * what that hook reaches. Pretty Links runs its own `Migrator` on
 * `after_setup_theme`, which the test bootstrap does fire, but a migration
 * that backs off or loses its lock returns silently, so it is run again here
 * and its result checked rather than assumed.
 *
 * The refusal matters more than the install: the redirects endpoints answer a
 * missing table with an empty list, and an empty list is what a site with no
 * rules legitimately serves. A suite green against no tables proves nothing.
 */
if (! UAMSWP_MIGRATION_API_TESTS_WITHOUT_REDIRECT_PLUGINS) {
    (new Latest)->install();

    $redirectDb = $GLOBALS['wpdb'] ?? null;
    $prettyLinksPath = constant('PRLI_PATH');

    assert($redirectDb instanceof wpdb && is_string($prettyLinksPath));

    (new Migrator($redirectDb, $prettyLinksPath, Bootstrap::version()))->maybeRun();

    foreach (['redirection_items', 'redirection_groups', 'prli_links'] as $redirectTable) {
        $redirectTableName = $redirectDb->prefix.$redirectTable;

        if ($redirectDb->get_var($redirectDb->prepare('SHOW TABLES LIKE %s', $redirectTableName)) !== $redirectTableName) {
            fwrite(
                STDERR,
                PHP_EOL.'Refusing to run: `'.$redirectTableName."` was not created by its plugin's installer.".PHP_EOL
            );

            exit(1);
        }
    }
}

/*
 * Install the Gravity Forms add-on FEED table, for the same reason again and
 * with one difference worth stating.
 *
 * Gravity Forms installs its own tables unaided on load, and `gf_addon_feed` is
 * not among them: it is created by `GFFeedAddOn::setup()`, so it appears only
 * once a FEED add-on has initialized. Measured on the development network,
 * which runs Gravity Forms and three of its add-ons: 25 `gf_*` tables and no
 * `gf_addon_feed` among them. Without this the feed tests would not fail — they
 * would pass, because `GFAPI::get_feeds()` answers a missing table with a
 * `WP_Error` and the endpoint turns that into the empty list a form with no
 * feeds legitimately has. A green suite proving nothing is the failure this
 * whole family of bootstrap steps exists to avoid.
 *
 * Through the plugin's own class rather than a copy of its `CREATE TABLE`,
 * which is the same rule `includes/` follows: an anonymous concrete subclass is
 * enough, `GFFeedAddOn` declares no abstract methods, and `setup()` reads a
 * version option and runs `dbDelta()` and does nothing else. The file is loaded
 * explicitly because Gravity Forms requires it on demand rather than at boot.
 *
 * **Gated on `GFForms`, not `GFAddOn`.** `class-gf-feed-addon.php` itself opens
 * with `if ( ! class_exists( 'GFForms' ) ) { die(); }` and then
 * `require_once( 'class-gf-addon.php' )` to define `GFAddOn` for itself — so
 * `GFForms` is the real precondition, and checking `GFAddOn` beforehand tests
 * for a class this very require is what defines. Gated on `GFAddOn`, the
 * install runs only when something ELSE has already loaded it first, which is
 * an implicit dependency on load order rather than a stated one.
 */
if (class_exists('GFCommon') && class_exists('GFForms')) {
    $feedAddon = GFCommon::get_base_path().'/includes/addon/class-gf-feed-addon.php';

    if (is_readable($feedAddon)) {
        require_once $feedAddon;
    }

    /*
     * No property overrides, deliberately. `GFAddOn::setup()` calls
     * `install_translations()`, which downloads a language pack over the
     * network — but only when `$_enable_rg_autoupgrade` is true, and it
     * defaults to false. Leaving every property at its default is what keeps
     * the test bootstrap from making an outbound request, and redeclaring a
     * parent's untyped property to add a type would be a fatal error besides.
     */
    if (class_exists('GFFeedAddOn')) {
        (new class extends GFFeedAddOn {})->setup();
    }
}

// Typed fixtures and response readers. Required after the bootstrap, because
// their signatures name WordPress classes.
require_once __DIR__.'/../Support/helpers.php';

/*
 * Load the Pest configuration from here as well, so the Feature binding does not
 * depend on `--test-directory=.` (#443). Pest reads `<test directory>/Pest.php`
 * only when that flag reaches it, and it removes the flag from argv before any
 * plugin runs, so every `composer mutate` child is started without it. The
 * child then looks for `tests/Pest.php` under this directory, finds nothing,
 * and its Feature tests run without their transaction. This runs as
 * `phpunit.xml`'s bootstrap in every process, and `require_once` shares PHP's
 * once-table with Pest's own `include_once`, so a run that has the flag loads
 * the file once.
 */
require_once __DIR__.'/Pest.php';
