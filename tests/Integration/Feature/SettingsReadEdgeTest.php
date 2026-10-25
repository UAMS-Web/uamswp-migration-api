<?php

/**
 * The options, discovery, ACF and configuration guards that ordinary fixtures do not reach.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\ACF\FieldReader;
use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Content\DiscoveryPayload;
use UAMSWP\MigrationAPI\Content\OptionsPayload;

/*
 * Pins the branches of `OptionsPayload`, `DiscoveryPayload`, `FieldReader` and
 * `Config` that need an input the endpoint tests never build (#363).
 *
 * Several of them are private helpers whose inputs come from ACF or `$wpdb`
 * already shaped, so a route cannot deliver the shape under test. Those are
 * called directly through reflection, with a control showing the same call on
 * an ordinary input first.
 */

/**
 * Call a private static method on one of the plugin's classes.
 *
 * @param  class-string  $class
 */
function settingsCall(string $class, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($class, $method))->invoke(null, ...$arguments);
}

/**
 * Run a callback while `$wpdb` is something other than core's handle.
 */
function settingsWithDatabase(mixed $handle, callable $callback): mixed
{
    $original = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = $handle;

    try {
        return $callback();
    } finally {
        $GLOBALS['wpdb'] = $original;
    }
}

/**
 * The options route's response, as an array.
 *
 * @return array<string, mixed>
 */
function settingsOptions(): array
{
    $data = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1/options'))->get_data();

    assert(\is_array($data), 'the options route did not return an array');

    /** @var array<string, mixed> $data */
    return $data;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

describe('OptionsPayload', function (): void {
    it('withholds an ACF options value by its shape, and leaves out a page with nothing left', function (): void {
        // The name `probe_note` matches no denylist pattern, so only the value
        // can withhold it.
        acf_add_local_field_group([
            'key' => 'group_settings_shape_probe',
            'title' => 'Shape probe',
            'fields' => [['key' => 'field_settings_note', 'name' => 'settings_note', 'type' => 'text', 'label' => 'Note']],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'uamswp-shape-page']]],
        ]);

        update_option('_options_settings_note', 'field_settings_note');

        try {
            // The control: an ordinary value is served under the page.
            update_option('options_settings_note', 'Contact us');
            $served = settingsOptions();

            update_option('options_settings_note', 'SG.'.str_repeat('a1b2c3d4', 8));
            $withheld = settingsOptions();
        } finally {
            acf_remove_local_field_group('group_settings_shape_probe');
        }

        $acf = static fn (array $data): array => \is_array($data['acf'] ?? null) ? $data['acf'] : [];
        $byShape = static fn (array $data): mixed => \is_array($data['denied'] ?? null) && \is_array($data['denied']['acf'] ?? null) ? $data['denied']['acf']['by_shape'] ?? null : null;

        expect($acf($served))->toHaveKey('uamswp-shape-page')
            ->and($acf($withheld))->not->toHaveKey('uamswp-shape-page')
            ->and($byShape($withheld))->toBe(\is_int($byShape($served)) ? $byShape($served) + 1 : null);
    });

    it('serves an ACF options value that is already a list, after checking every string in it', function (): void {
        acf_add_local_field_group([
            'key' => 'group_settings_list_probe',
            'title' => 'List probe',
            'fields' => [['key' => 'field_settings_colors', 'name' => 'settings_colors', 'type' => 'checkbox', 'label' => 'Colors', 'choices' => ['red' => 'Red', 'blue' => 'Blue']]],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'uamswp-list-page']]],
        ]);

        update_option('_options_settings_colors', 'field_settings_colors');
        update_option('options_settings_colors', ['red', 'blue']);

        try {
            $acf = settingsOptions()['acf'] ?? null;
        } finally {
            acf_remove_local_field_group('group_settings_list_probe');
        }

        $page = \is_array($acf) && \is_array($acf['uamswp-list-page'] ?? null) ? $acf['uamswp-list-page'] : [];
        $names = \is_array($page['names'] ?? null) ? $page['names'] : [];

        expect($names['settings_colors'] ?? null)->toBe(['red', 'blue']);
    });

    it('withholds a value nested past the inspection bound', function (): void {
        // The control: a plain string at depth 0 is not a secret.
        expect(OptionsPayload::secretIn('plain', 0))->toBeNull()
            ->and(OptionsPayload::secretIn('plain', 17))->toBe(OptionsPayload::VERDICT_SHAPE);
    });

    it('reads an underscore in a LIKE pattern as any one character', function (): void {
        expect(OptionsPayload::matchesLikePattern('a1b', 'a_b'))->toBeTrue()
            ->and(OptionsPayload::matchesLikePattern('ab', 'a_b'))->toBeFalse()
            ->and(OptionsPayload::matchesLikePattern('a_b', 'a\\_b'))->toBeTrue()
            ->and(OptionsPayload::matchesLikePattern('a1b', 'a\\_b'))->toBeFalse();
    });

    it('reads no options table and no network option without a database handle', function (): void {
        // The control: with the handle in place both read something.
        expect(settingsCall(OptionsPayload::class, 'rows'))->not->toBe([])
            ->and(settingsCall(OptionsPayload::class, 'storedNetworkOption', 'site_name'))->toBeString();

        expect(settingsWithDatabase(null, static fn (): mixed => settingsCall(OptionsPayload::class, 'rows')))->toBe([])
            ->and(settingsWithDatabase(null, static fn (): mixed => settingsCall(OptionsPayload::class, 'storedNetworkOption', 'site_name')))->toBeNull();
    });

    it('reads no options table when the handle answers the read with something other than rows', function (): void {
        // Core's `get_results()` always returns an array for `ARRAY_A`, but a
        // `db.php` drop-in replaces the handle with a subclass, and the
        // contract it inherits allows `null`.
        $handle = new class extends wpdb
        {
            public int $reads = 0;

            public function __construct()
            {
                $this->options = 'wp_options';
            }

            public function get_results($query = null, $output = OBJECT)
            {
                $this->reads++;

                return null;
            }
        };

        expect(settingsWithDatabase($handle, static fn (): mixed => settingsCall(OptionsPayload::class, 'rows')))->toBe([])
            ->and($handle->reads)->toBe(1);
    });

    it('finds options pages past malformed locations, rule groups and rules', function (): void {
        acf_add_local_field_group([
            'key' => 'group_settings_slug_probe',
            'title' => 'Slug probe',
            'fields' => [],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'uamswp-slug-page']]],
        ]);

        $added = 0;

        // ACF validates a local field group's location, so the malformed shapes
        // are added to what `acf_get_field_groups()` returns. Each is still an
        // array with a key, because ACF plucks `key` from every group before
        // this plugin sees the list.
        add_filter('acf/load_field_groups', static function (array $groups) use (&$added): array {
            $added++;

            return array_merge($groups, [
                ['key' => 'group_settings_location_string', 'location' => 'not a list'],
                ['key' => 'group_settings_rule_group_string', 'location' => ['a bare rule group']],
                ['key' => 'group_settings_rule_string', 'location' => [['a bare rule', ['param' => 'post_type', 'value' => 'post']]]],
            ]);
        });

        try {
            $slugs = settingsCall(OptionsPayload::class, 'optionsPageSlugs');
        } finally {
            acf_remove_local_field_group('group_settings_slug_probe');
        }

        expect($added)->toBeGreaterThan(0)
            ->and($slugs)->toContain('uamswp-slug-page')
            ->and($slugs)->not->toContain('post');
    });
});

describe('DiscoveryPayload', function (): void {
    it('leaves the locale memo unset after reading another site when it started unset', function (): void {
        $other = makeSite();

        // The control: a locale that was set comes back as it was.
        $GLOBALS['locale'] = 'xx_XX';
        settingsCall(DiscoveryPayload::class, 'site', $other);

        expect($GLOBALS['locale'] ?? null)->toBe('xx_XX');

        unset($GLOBALS['locale']);

        try {
            $payload = settingsCall(DiscoveryPayload::class, 'site', $other);
            $leftSet = isset($GLOBALS['locale']);
        } finally {
            unset($GLOBALS['locale']);
        }

        expect($payload)->toBeArray()
            ->and($leftSet)->toBeFalse();
    });

    it('counts nothing without a database handle', function (): void {
        expect(settingsCall(DiscoveryPayload::class, 'counts'))->not->toBe([])
            ->and(settingsWithDatabase(null, static fn (): mixed => settingsCall(DiscoveryPayload::class, 'counts')))->toBe([]);
    });
});

describe('FieldReader', function (): void {
    it('skips a field with no name, such as a tab', function (): void {
        acf_add_local_field_group([
            'key' => 'group_settings_tab_probe',
            'title' => 'Tab probe',
            'fields' => [
                ['key' => 'field_settings_tab', 'name' => '', 'type' => 'tab', 'label' => 'A tab'],
                ['key' => 'field_settings_title', 'name' => 'settings_title', 'type' => 'text', 'label' => 'Title'],
            ],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'uamswp-tab-page']]],
        ]);

        update_option('options_settings_title', 'Heading');

        try {
            $read = FieldReader::read(['options_page' => 'uamswp-tab-page'], 'options');
        } finally {
            acf_remove_local_field_group('group_settings_tab_probe');
        }

        expect(array_keys($read['keys']))->toBe(['field_settings_title'])
            ->and(array_column($read['identities'], 'key'))->toBe(['field_settings_title']);
    });

    it('leaves an array that is neither a row nor a list of rows as it was', function (): void {
        $field = ['key' => 'field_parent', 'name' => 'parent', 'sub_fields' => [['key' => 'field_child', 'name' => 'child']]];

        // The control: a row keyed by sub-field key is renamed.
        expect(settingsCall(FieldReader::class, 'rekey', ['field_child' => 'x'], $field))->toBe(['child' => 'x'])
            ->and(settingsCall(FieldReader::class, 'rekey', ['unrelated' => 'x'], $field))->toBe(['unrelated' => 'x']);
    });

    it('keeps a row entry whose key is not a sub-field key as it was', function (): void {
        $field = ['key' => 'field_parent', 'name' => 'parent', 'sub_fields' => [['key' => 'field_child', 'name' => 'child']]];

        expect(settingsCall(FieldReader::class, 'rekey', ['field_child' => 'x', 'stray' => 'y', 0 => 'z'], $field))
            ->toBe(['child' => 'x', 'stray' => 'y', 0 => 'z']);
    });

    it('renames the sub-fields of flexible content layouts', function (): void {
        $field = [
            'key' => 'field_flexible',
            'name' => 'flexible',
            'layouts' => [
                ['key' => 'layout_text', 'name' => 'text', 'sub_fields' => [['key' => 'field_layout_body', 'name' => 'body']]],
            ],
        ];

        expect(settingsCall(FieldReader::class, 'rekey', [['acf_fc_layout' => 'text', 'field_layout_body' => 'Hello']], $field))
            ->toBe([['acf_fc_layout' => 'text', 'body' => 'Hello']]);
    });
});

describe('Config', function (): void {
    it('reads a configured list that is not an array as empty', function (): void {
        // The control: the shipped value is a list.
        expect(Config::strings('post_types'))->not->toBe([]);

        add_filter('uamswp_migration_api_config', static function (array $config): array {
            $config['post_types'] = 'post';

            return $config;
        });

        Config::flush();

        try {
            $strings = Config::strings('post_types');
        } finally {
            remove_all_filters('uamswp_migration_api_config');
            Config::flush();
        }

        expect($strings)->toBe([]);
    });
});
