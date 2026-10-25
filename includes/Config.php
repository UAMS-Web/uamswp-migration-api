<?php

/**
 * Configuration surface.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

/**
 * Loads and exposes plugin configuration.
 *
 * Adding a post type or taxonomy to the API is a configuration change, never a
 * code change — that is what lets one plugin serve every site in the network.
 */
final class Config
{
    /**
     * Resolved configuration, memoized per request.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $config = null;

    /**
     * Resolve the full configuration array.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        // Resolved from this file's location rather than a runtime-defined
        // constant: `define(__NAMESPACE__.'\PLUGIN_DIR', …)` builds its name at
        // runtime, so no static analyzer can follow it.
        $dir = __DIR__.'/../';

        $defaults = require $dir.'config/defaults.php';

        /** @var array<string, mixed> $config */
        $config = \is_array($defaults) ? $defaults : [];

        $local = $dir.'config/local.php';

        if (is_readable($local)) {
            $overrides = require $local;

            if (\is_array($overrides)) {
                /** @var array<string, mixed> $config */
                $config = array_merge($config, $overrides);
            }
        }

        /**
         * Filters the migration API configuration.
         *
         * A filter can return anything, so the result is re-checked rather than
         * trusted — the docblock describes intent, not a guarantee.
         *
         * @param  array<string, mixed>  $config  Resolved configuration.
         */
        self::$config = self::asConfig(
            apply_filters('uamswp_migration_api_config', $config),
            $config
        );

        return self::$config;
    }

    /**
     * Narrow an untrusted filter result back to a configuration array.
     *
     * Exists because the WordPress stubs type `apply_filters()` as returning
     * whatever was passed in, so a direct `is_array()` check on its result is
     * reported as always-true. A filter can return anything at all, so the
     * check is real — taking the value as `mixed` is what makes that legible
     * to the analyzer rather than suppressed with an ignore.
     *
     * @param  mixed  $value  Whatever the filter returned.
     * @param  array<string, mixed>  $fallback  Value to keep if the filter returned a non-array.
     * @return array<string, mixed>
     */
    private static function asConfig(
        mixed $value,
        array $fallback,
    ): array {
        if (! \is_array($value)) {
            return $fallback;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Read a single configuration key.
     *
     * Prefer the typed accessors below where the shape is known — an untyped
     * read forces every caller to cast, and a cast from `mixed` is exactly the
     * unchecked assumption the analyzer is there to catch.
     *
     * @param  string  $key  Configuration key.
     * @param  mixed  $default  Value returned when the key is absent.
     */
    public static function get(
        string $key,
        mixed $default = null,
    ): mixed {
        return self::all()[$key] ?? $default;
    }

    /**
     * Read a configuration key as an integer.
     *
     * @param  string  $key  Configuration key.
     * @param  int  $default  Value returned when the key is absent or non-numeric.
     */
    public static function int(
        string $key,
        int $default,
    ): int {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read a configuration key as a list of strings.
     *
     * @param  string  $key  Configuration key.
     * @return list<string>
     */
    public static function strings(string $key): array
    {
        $value = self::get($key, []);

        if (! \is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_string(...)));
    }

    /**
     * Post types exposed on the current site.
     *
     * Eligibility comes from configuration; existence is checked against the
     * current site, so a type configured for Find-a-Doc simply doesn't appear on
     * a departmental site rather than erroring.
     *
     * @return list<string>
     */
    public static function postTypes(): array
    {
        return array_values(
            array_filter(
                self::strings('post_types'),
                static fn (string $type): bool => post_type_exists($type)
            )
        );
    }

    /**
     * Taxonomies exposed on the current site.
     *
     * @return list<string>
     */
    public static function taxonomies(): array
    {
        return array_values(
            array_filter(
                self::strings('taxonomies'),
                static fn (string $taxonomy): bool => taxonomy_exists($taxonomy)
            )
        );
    }

    /**
     * Whether a post type may be served on the current site.
     *
     * @param  string  $post_type  Post type slug.
     */
    public static function allowsPostType(string $post_type): bool
    {
        return \in_array($post_type, self::postTypes(), true);
    }

    /**
     * Whether a taxonomy may be served on the current site.
     *
     * @param  string  $taxonomy  Taxonomy slug.
     */
    public static function allowsTaxonomy(string $taxonomy): bool
    {
        return \in_array($taxonomy, self::taxonomies(), true);
    }

    /**
     * Option-name LIKE patterns that must never be served.
     *
     * @return list<string>
     */
    public static function optionDenylist(): array
    {
        return self::strings('option_denylist');
    }

    /**
     * The identifiers served for one post type, keyed by the name each is
     * served under (D-104).
     *
     * An entry naming no source string, or an `acf_list` entry naming no
     * `column`, is dropped rather than served empty: an empty value is a claim
     * about the record, and a malformed entry is a fact about the
     * configuration. An entry naming several sources takes the first of
     * `meta`, `acf`, `acf_list`.
     *
     * @param  string  $post_type  Post type slug.
     * @return array<string, array{source: 'meta'|'acf'|'acf_list', name: string, column: string}>
     */
    public static function identifiers(string $post_type): array
    {
        $all = self::get('identifiers', []);
        $entries = \is_array($all) ? ($all[$post_type] ?? null) : null;

        if (! \is_array($entries)) {
            return [];
        }

        $identifiers = [];

        foreach ($entries as $served => $entry) {
            if (! \is_string($served) || ! \is_array($entry)) {
                continue;
            }

            foreach (['meta', 'acf', 'acf_list'] as $source) {
                $name = $entry[$source] ?? null;

                if (! \is_string($name) || $name === '') {
                    continue;
                }

                $column = $entry['column'] ?? '';
                $column = \is_string($column) ? $column : '';

                // A list read without a column would serve one empty string
                // per row: a well-formed list of wrong values.
                if ($source === 'acf_list' && $column === '') {
                    break;
                }

                $identifiers[$served] = [
                    'source' => $source,
                    'name' => $name,
                    'column' => $column,
                ];

                break;
            }
        }

        return $identifiers;
    }

    /**
     * Reset memoized configuration. Test seam; also used after a site switch.
     */
    public static function flush(): void
    {
        self::$config = null;
    }
}
