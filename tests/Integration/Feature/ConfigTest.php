<?php

/**
 * Configuration loading and per-site filtering.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;

/*
 * The design rule under test: adding a post type or taxonomy to the API is a
 * configuration change, never a code change. That is what lets one plugin serve
 * every site in a network whose sites register different types.
 */

it('exposes only the configured post types that exist on this site', function (): void {
    $exposed = Config::postTypes();

    // Registered by core on any install.
    expect($exposed)->toContain('post')
        ->and($exposed)->toContain('page')
        ->and($exposed)->toContain('attachment')
        // Configured, but Find-a-Doc is not installed here. Eligibility is not
        // existence — this is the filter that lets one list serve every site.
        ->and($exposed)->not->toContain('provider')
        ->and($exposed)->not->toContain('location')
        ->and($exposed)->not->toContain('kb');
});

it('exposes a configured post type as soon as the site registers it', function (): void {
    expect(Config::allowsPostType('provider'))->toBeFalse();

    register_post_type('provider', ['public' => true]);

    try {
        expect(Config::allowsPostType('provider'))->toBeTrue()
            ->and(Config::postTypes())->toContain('provider');
    } finally {
        unregister_post_type('provider');
    }
});

it('never exposes a post type that exists but is not configured', function (): void {
    register_post_type('not_in_the_config', ['public' => true]);

    try {
        expect(Config::allowsPostType('not_in_the_config'))->toBeFalse()
            ->and(Config::postTypes())->not->toContain('not_in_the_config');
    } finally {
        unregister_post_type('not_in_the_config');
    }
});

it('filters taxonomies the same way', function (): void {
    expect(Config::taxonomies())->toContain('category')
        ->and(Config::taxonomies())->toContain('post_tag')
        ->and(Config::taxonomies())->not->toContain('degree')
        ->and(Config::allowsTaxonomy('category'))->toBeTrue()
        ->and(Config::allowsTaxonomy('degree'))->toBeFalse();
});

it('lets the config filter change exposure with no code change', function (): void {
    $filter = static function (array $config): array {
        $config['per_page_max'] = 7;

        return $config;
    };

    add_filter('uamswp_migration_api_config', $filter);
    Config::flush();

    try {
        expect(Config::int('per_page_max', 200))->toBe(7);
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
        Config::flush();
    }

    expect(Config::int('per_page_max', 200))->toBe(200);
});

/*
 * `apply_filters()` returns whatever the filter returned, which the WordPress stubs
 * type as the value passed in. A filter can return anything at all, so Config
 * re-checks the result rather than trusting it — this asserts the fallback rather
 * than the docblock.
 */
it('keeps the previous configuration when a filter returns a non-array', function (): void {
    $filter = static fn (): string => 'not an array';

    add_filter('uamswp_migration_api_config', $filter);
    Config::flush();

    try {
        expect(Config::int('per_page_default', 0))->toBe(50)
            ->and(Config::postTypes())->toContain('post');
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
        Config::flush();
    }
});

it('reads typed values with a fallback for missing or non-numeric keys', function (): void {
    expect(Config::int('per_page_default', 999))->toBe(50)
        ->and(Config::int('no_such_key', 999))->toBe(999)
        ->and(Config::strings('no_such_key'))->toBe([])
        ->and(Config::get('no_such_key', 'fallback'))->toBe('fallback');
});

/*
 * The denylist is a denylist, so the failure direction is leaking a credential
 * rather than dropping one. It must also NOT carry a blanket underscore rule:
 * that is what core's network site-settings screen does, and it silently drops
 * ACF's `_options_<field>` field-key references.
 */
it('denies credential-shaped option names without a blanket underscore rule', function (): void {
    $denylist = Config::optionDenylist();

    expect($denylist)->toContain('\_transient\_%')
        ->and($denylist)->toContain('%password%')
        ->and($denylist)->toContain('%api\_key%')
        ->and($denylist)->not->toContain('\_%')
        ->and($denylist)->not->toContain('_%');
});

/**
 * Run `$callback` against a freshly resolved configuration, with `$filter`
 * applied to it, restoring both afterwards.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $filter
 */
function withConfig(callable $filter, callable $callback): void
{
    add_filter('uamswp_migration_api_config', $filter);
    Config::flush();

    try {
        $callback();
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
        Config::flush();
    }
}

/*
 * "Memoized per request" is a claim about cost, and nothing observed it: the
 * files are re-read and the filter re-applied on every call without it, with
 * the same answer each time (#298).
 */
it('resolves the configuration once and serves the memo after', function (): void {
    Config::flush();
    $before = did_filter('uamswp_migration_api_config');

    Config::all();
    Config::all();
    Config::int('per_page_max', 200);

    expect(did_filter('uamswp_migration_api_config') - $before)->toBe(1);
});

/*
 * Both files are found from this plugin's own location, never from the working
 * directory. A relative path would resolve against wherever PHP happened to be
 * started -- here, a directory carrying its own `config/` that must be ignored
 * (#298).
 */
it('reads its configuration files from the plugin, not the working directory', function (): void {
    $elsewhere = sys_get_temp_dir().'/uamswp-config-cwd-'.getmypid();
    $original = getcwd();

    @mkdir($elsewhere.'/config', 0777, true);
    file_put_contents($elsewhere.'/config/defaults.php', "<?php return ['per_page_max' => 11];");
    file_put_contents($elsewhere.'/config/local.php', "<?php return ['per_page_default' => 13];");

    chdir($elsewhere);
    Config::flush();

    try {
        expect(Config::int('per_page_max', 0))->toBe(200)
            ->and(Config::int('per_page_default', 0))->toBe(50);
    } finally {
        chdir((string) $original);
        Config::flush();
        unlink($elsewhere.'/config/defaults.php');
        unlink($elsewhere.'/config/local.php');
        rmdir($elsewhere.'/config');
        rmdir($elsewhere);
    }
});

/*
 * A numeric string in configuration is still read as an integer. Every value
 * the shipped defaults carry is already an `int`, so without this nothing
 * reached the cast (#298).
 */
it('reads a numeric-string configuration value as an integer', function (): void {
    withConfig(static function (array $config): array {
        $config['per_page_max'] = '7';

        return $config;
    }, static function (): void {
        expect(Config::int('per_page_max', 200))->toBe(7);
    });
});

/*
 * A list setting keeps only its strings and comes back as a list, whatever a
 * filter put in it (#298).
 */
it('reads a list setting as a list of its strings only', function (): void {
    withConfig(static function (array $config): array {
        $config['post_types'] = ['post', 42, 'page'];

        return $config;
    }, static function (): void {
        expect(Config::strings('post_types'))->toBe(['post', 'page']);
    });
});

/*
 * The shipped list names Find-a-Doc types this site does not register, so the
 * filtered lists have gaps in their keys. They are served as JSON, where a
 * list with gaps encodes as an object rather than an array (#298).
 */
it('returns the exposed post types and taxonomies as lists', function (): void {
    $postTypes = Config::postTypes();
    $taxonomies = Config::taxonomies();

    expect(json_encode($postTypes))->toStartWith('["')
        ->and(json_encode($taxonomies))->toStartWith('["');
});

/*
 * The comparison is exact. A slug that only compares EQUAL -- `1e1` and `10`
 * are the same number to a loose comparison -- is a different type, and must
 * not be served as one (#298).
 */
it('matches post types and taxonomies exactly, never loosely', function (): void {
    register_post_type('10', ['public' => true]);
    register_taxonomy('10', 'post');

    try {
        withConfig(static function (array $config): array {
            $config['post_types'] = ['10'];
            $config['taxonomies'] = ['10'];

            return $config;
        }, static function (): void {
            expect(Config::allowsPostType('10'))->toBeTrue()
                ->and(Config::allowsPostType('1e1'))->toBeFalse()
                ->and(Config::allowsTaxonomy('10'))->toBeTrue()
                ->and(Config::allowsTaxonomy('1e1'))->toBeFalse();
        });
    } finally {
        unregister_post_type('10');
        unregister_taxonomy('10');
    }
});

/*
 * `config/local.php` is the documented way for a deployment to override the
 * defaults without a filter, and until #298 nothing read one, so a change that
 * stopped loading it passed everything. It is written beside the defaults for
 * the length of this test only, and never over a real one. Its first line is
 * the suite's marker, so a copy a killed run leaves behind is cleared by the
 * bootstrap before the next run reads configuration (#303).
 */
it('merges a config/local.php beside the defaults over them', function (): void {
    $local = dirname(__DIR__, 3).'/config/local.php';

    if (file_exists($local)) {
        $this->markTestSkipped('A real config/local.php is present; not overwriting it.');
    }

    file_put_contents($local, migrationApiConfigFixtureMarker().PHP_EOL."return ['per_page_default' => 13];".PHP_EOL);
    Config::flush();

    try {
        expect(Config::int('per_page_default', 0))->toBe(13)
            ->and(Config::int('per_page_max', 0))->toBe(200);
    } finally {
        unlink($local);
        Config::flush();
    }
});

it('reads the identifiers for a post type, dropping a malformed entry', function (): void {
    // D-104. A malformed entry is a fact about the configuration, so it is
    // dropped rather than served as an empty identifier on every record.
    $filter = static fn (array $config): array => ['identifiers' => [
        'thing' => [
            'from_meta' => ['meta' => '_thing_id'],
            'from_list' => ['acf_list' => 'thing_rows', 'column' => 'thing_row_id'],
            'first_source_wins' => ['acf' => 'thing_field', 'meta' => '_thing_meta'],
            'meta_with_odd_column' => ['meta' => '_thing_other', 'column' => 3],
            'list_without_column' => ['acf_list' => 'thing_rows'],
            'list_with_odd_column' => ['acf_list' => 'thing_rows', 'column' => ['x']],
            'empty_name' => ['meta' => ''],
            'no_source' => ['column' => 'x'],
            'not_an_array' => 'meta',
            7 => ['meta' => '_numeric_key'],
        ],
        'broken' => 'meta',
    ]] + $config;

    add_filter('uamswp_migration_api_config', $filter);
    Config::flush();

    try {
        expect(Config::identifiers('thing'))->toBe([
            'from_meta' => ['source' => 'meta', 'name' => '_thing_id', 'column' => ''],
            'from_list' => ['source' => 'acf_list', 'name' => 'thing_rows', 'column' => 'thing_row_id'],
            'first_source_wins' => ['source' => 'meta', 'name' => '_thing_meta', 'column' => ''],
            'meta_with_odd_column' => ['source' => 'meta', 'name' => '_thing_other', 'column' => ''],
        ])->and(Config::identifiers('broken'))->toBe([])
            ->and(Config::identifiers('unconfigured'))->toBe([]);
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
        Config::flush();
    }
});
