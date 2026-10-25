<?php

/**
 * Serializes a site's options table into the migration payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use UAMSWP\MigrationAPI\ACF\FieldReader;
use UAMSWP\MigrationAPI\Config;

/**
 * The whole options table, minus a reviewed denylist — plus ACF's view of it.
 *
 * This is the gap the project exists to close as much as any: **zero of 259
 * captured WXR exports contain a `<wp:option>` element**, so every site migrated
 * to date silently dropped its settings. WXR has no element for them at all.
 *
 * Served as the whole table rather than an enumerated list of names, because
 * enumeration is what loses the setting nobody thought to name. The filter is a
 * **denylist**, so the failure direction is leaking a credential rather than
 * dropping a setting — which is why `config/defaults.php` carries a reviewed
 * list rather than an inferred one (#6).
 */
final class OptionsPayload
{
    /**
     * Option names that are never denied, whatever the patterns say.
     *
     * ACF stores an options-page value at `options_<field>` and its field-key
     * reference at `_options_<field>`. The reference holds a key like
     * `field_fad_google_key` — never a value — so no credential can live in
     * one, and denying it breaks the pairing D-007 exists to protect. Measured:
     * `%_key` catches `_options_fad_google_key`, whose entire content is the
     * string `field_fad_google_key`.
     */
    private const NEVER_DENIED_PREFIX = '_options_';

    /**
     * The row an ACF options-page value is stored under.
     *
     * The sibling of `NEVER_DENIED_PREFIX`: `options_<field>` holds the value,
     * `_options_<field>` holds the field key. One is worth denying and the
     * other is never worth denying, which is why they are separate constants
     * rather than one with an underscore bolted on.
     */
    private const ACF_OPTION_PREFIX = 'options_';

    /**
     * How deep `containsSecret()` will walk into a decoded option value.
     *
     * Sixteen because the deepest real nesting measured on this network is six
     * — `facetwp_settings` at `templates.14.query_obj.filters.1.k` — so the
     * bound sits well clear of anything legitimate while still terminating on a
     * self-referential structure.
     */
    private const MAX_INSPECTION_DEPTH = 16;

    /** Served: the name is `_options_*`, which is never denied. */
    public const VERDICT_EXEMPT = 'exempt';

    /** Withheld: the option NAME matches a denylist pattern. */
    public const VERDICT_NAME = 'name';

    /** Withheld: a KEY inside the value matches the nested-key list. */
    public const VERDICT_NESTED_KEY = 'nested_key';

    /** Withheld: a string in or under the value looks like a credential. */
    public const VERDICT_SHAPE = 'shape';

    /** Served. */
    public const VERDICT_SERVED = 'served';

    /**
     * Build the payload for the current site.
     *
     * @return array{options: array<string, mixed>, acf: array<string, mixed>, denylist: array<string, mixed>, denied: array{total: int, by_name: int, by_shape: int}, plugins: list<array{path: string, scope: 'site'|'network', header: array{Name: string, Version: string, TextDomain: string}|null}>}
     */
    public static function build(): array
    {
        $byName = 0;
        $byShape = 0;
        $options = [];
        $rows = self::rows();

        // One reading of what is active, shared by the two fields that report
        // on it, and taken from the table read above rather than through
        // `get_option()` for the reason `rows()` gives.
        $active = self::activePluginPaths($rows['active_plugins'] ?? '');

        foreach ($rows as $name => $value) {
            /*
             * The two nested verdicts fold into `by_shape` rather than getting
             * a bucket of their own, and the payload shape is unchanged by #38.
             * `by_name` means "a reviewer read this option's name and decided",
             * which is a stronger claim than the nested list can make: it fires
             * on a key nobody enumerated, inside a value nobody read. That is
             * the same thing `by_shape` already means, so it belongs there —
             * and an unexpected `by_shape` staying the signal to look is the
             * point of the split.
             */
            $verdict = self::classify($name, $value);

            if ($verdict === self::VERDICT_NAME) {
                $byName++;

                continue;
            }

            if ($verdict === self::VERDICT_SHAPE || $verdict === self::VERDICT_NESTED_KEY) {
                $byShape++;

                continue;
            }

            $options[$name] = self::value($value);
        }

        // The ACF view reads the same table back through `acf_get_value()`, so
        // it withholds on its own account and reports into the same counters.
        // Before #33 it did not, and a row denied here shipped in full there.
        $acf = self::acf();

        return [
            'options' => $options,
            'acf' => $acf['pages'],
            'denylist' => self::denylistProvenance($active),
            /*
             * Counts, never the names. Reporting *that* something was withheld
             * keeps the payload honest — a consumer can tell "this site has no
             * such setting" from "we declined to send it" — while naming them
             * would hand a reader the map of which credentials exist.
             *
             * Split by reason because the two fail differently. A name match is
             * a deliberate, reviewed decision; a shape match is a guess about an
             * option nobody enumerated, and it is the one that will occasionally
             * withhold something harmless. An unexpected `by_shape` is the
             * signal to look, and it is visible without naming anything.
             */
            'denied' => [
                'total' => $byName + $byShape + $acf['by_name'] + $acf['by_shape'],
                'by_name' => $byName + $acf['by_name'],
                'by_shape' => $byShape + $acf['by_shape'],
            ],
            'plugins' => self::activePlugins($active),
        ];
    }

    /**
     * Why one option row is served or withheld.
     *
     * **The single source of truth for the filter**, and public for exactly one
     * reason: `scripts/audit-option-denylist.php` reviews the denylist against
     * a production options table nobody has read, and it used to answer this
     * question with its own transcription of the rules. The transcription went
     * stale the moment #33 taught the endpoint to look inside values, and the
     * script spent a release reporting on a denylist that was not shipped —
     * overstating exposure, and unable to confirm the new protection worked at
     * all (#38). Its own docblock had named the hazard: *the audit has to
     * classify exactly as the endpoint filters, or it reports on a denylist
     * nobody ships.*
     *
     * One implementation and two callers makes that drift **impossible** rather
     * than merely unlikely — which is worth more than the encapsulation this
     * spends, because the alternative is a copy that looks right for as long as
     * nobody changes the original.
     *
     * The order is the filter's order and is load-bearing: the `_options_*`
     * exemption outranks every pattern (D-007), and a name match outranks the
     * value rules so a reviewed decision is reported as one.
     *
     * @return self::VERDICT_* One of the five verdict constants.
     */
    public static function classify(string $name, string $value): string
    {
        if (self::isExempt($name)) {
            return self::VERDICT_EXEMPT;
        }

        if (self::isDeniedByName($name)) {
            return self::VERDICT_NAME;
        }

        if (self::looksLikeSecret($value)) {
            return self::VERDICT_SHAPE;
        }

        /*
         * Strings are decoded at every depth, not only at the top (#457).
         * Formidable's license-check caches are serialized arrays whose
         * `value` element is a JSON string, and the license sits inside that
         * string under `error.license`. Decoding only the top level walked to
         * `value`, found a string with no credential shape, and served the
         * license that `frmpro-credentials` is withheld for by name.
         */
        return self::secretIn(self::decoded($value), 0, null, true) ?? self::VERDICT_SERVED;
    }

    /**
     * Whether the denylist has been reviewed against the plugins running here.
     *
     * The staleness signal, and it lives in the payload because this is the
     * only moment anything holds both facts at once — the denylist is in the
     * repository, the plugin set is on the server, and nothing in between sees
     * them together. A recurring reminder would depend on somebody noticing a
     * change that happened somewhere else; this notices it at the point of use.
     *
     * Reports rather than refuses. Refusing would make the guard undeniable and
     * would turn a plugin activation on any of 33 sites into a halted
     * migration, which is the wrong trade while the value-shape rule carries
     * most of the load. The hard check belongs in the consumer, which knows
     * whether a given call is a scratch query or the production pull.
     *
     * @param  list<array{0: string, 1: 'site'|'network'}>  $active
     * @return array{reviewed_against: int, current: int, stale: bool, added: list<string>, removed: list<string>}
     */
    private static function denylistProvenance(array $active): array
    {
        $reviewed = Config::strings('option_denylist_reviewed_against');
        $current = self::activePluginSlugs($active);

        sort($reviewed);
        sort($current);

        // Plugin slugs are not secrets, and naming them is the whole point: a
        // reviewer needs to know *what* arrived, not merely that a count moved.
        $notYetReviewed = array_values(array_diff($current, $reviewed));

        return [
            'reviewed_against' => \count($reviewed),
            'current' => \count($current),
            /*
             * A SUBSET test, not equality — and the distinction is the whole
             * design. Every site in a network runs its own plugin set, so the
             * baseline is the **union** across the network. Requiring an exact
             * match would mean no individual site ever matched the union, and
             * the field would read `stale` forever on all 33 sites: a signal
             * that is always on is one nobody reads.
             *
             * What actually matters is whether this site runs anything the
             * denylist has never been checked against. A plugin in the baseline
             * that this site does not run is not a risk; it is just another
             * site's plugin.
             *
             * An empty baseline is not "unchanged", it is "never pinned".
             */
            'stale' => $reviewed === [] || $notYetReviewed !== [],
            'added' => $notYetReviewed,
            // Reported but not staleness-triggering: a plugin reviewed and no
            // longer running anywhere is worth seeing when the baseline is next
            // refreshed, and is not a reason to distrust today's payload.
            'removed' => array_values(array_diff($reviewed, $current)),
        ];
    }

    /**
     * Every active plugin's stored path, with the header its file carries on disk.
     *
     * The one field in this payload that describes the **disk** rather than the
     * database (#338). `active_plugins` says which entry file a site runs; it
     * cannot say which build of the plugin that file is, and a consumer that
     * infers the build from the path is reading the database and guessing about
     * the disk. `UAMS-Web/wordpress-importer#1134` recorded exactly that going
     * wrong: `uamswp-minervakb/uamswp-minervakb.php` active, and the file at
     * that path carrying MinervaKB's v1 header.
     *
     * A path with no plugin in `get_plugins()`'s listing is served with a `null`
     * header, never dropped: that is the database and the disk disagreeing,
     * which is the thing a consumer needs to see. The stored path is only ever
     * a key into that listing, and a file is read only once the listing has
     * named it, so no stored value can make this read a file the plugins
     * directory does not already hold.
     *
     * A plugin active per-site and network-wide appears once under each scope.
     *
     * @param  list<array{0: string, 1: 'site'|'network'}>  $active
     * @return list<array{path: string, scope: 'site'|'network', header: array{Name: string, Version: string, TextDomain: string}|null}>
     */
    private static function activePlugins(array $active): array
    {
        $installed = self::installedPlugins();
        $plugins = [];

        foreach ($active as [$path, $scope]) {
            $plugins[] = [
                'path' => $path,
                'scope' => $scope,
                'header' => isset($installed[$path]) ? self::header($path) : null,
            ];
        }

        return $plugins;
    }

    /**
     * Every plugin installed on disk, keyed by its path under the plugins
     * directory.
     *
     * Used for which plugins exist, never for their headers. `get_plugins()`
     * lives in an admin include that a REST request does not load, so it is
     * required here. Its cache group is non-persistent, so the listing is read
     * from disk once per request and never served from an earlier one.
     *
     * @return array<array<mixed>>
     */
    private static function installedPlugins(): array
    {
        if (! \function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }

    /**
     * The three header fields exactly as the file declares them.
     *
     * Read with `get_file_data()` and no context, which applies no filter,
     * rather than taken from `get_plugins()`. That listing is built by
     * `get_plugin_data()`, which fills a missing `Text Domain` with the
     * plugin's directory name, so a plugin that declares no text domain would
     * be served one — and a consumer telling plugin generations apart by that
     * header could not tell a declared value from an inferred one. A field the
     * file does not declare is an empty string here.
     *
     * @return array{Name: string, Version: string, TextDomain: string}
     */
    private static function header(string $path): array
    {
        $header = get_file_data(WP_PLUGIN_DIR.'/'.$path, [
            'Name' => 'Plugin Name',
            'Version' => 'Version',
            'TextDomain' => 'Text Domain',
        ]);

        return [
            'Name' => $header['Name'],
            'Version' => $header['Version'],
            'TextDomain' => $header['TextDomain'],
        ];
    }

    /**
     * The stored path of every plugin active on this site or across the
     * network, with the scope it is active in.
     *
     * The per-site list is decoded from the `active_plugins` row as `rows()`
     * read it, not through `get_option()`: a plugin can filter
     * `option_active_plugins`, and one on this network does — LearnDash v3
     * appends a legacy path that is not in the table on every request outside
     * the plugins screen, which would be served here as an active plugin
     * missing from disk. The network list is read the same way, from
     * `sitemeta` through `storedNetworkOption()`, because LearnDash v3 filters
     * `site_option_active_sitewide_plugins` too (#341).
     *
     * Network-activated plugins are keyed by path in `active_sitewide_plugins`
     * where per-site ones are values in `active_plugins`, which is a shape
     * difference worth stating rather than rediscovering.
     *
     * @return list<array{0: string, 1: 'site'|'network'}>
     */
    private static function activePluginPaths(string $storedPerSite): array
    {
        $perSite = self::decoded($storedPerSite);
        $network = self::decoded(self::storedNetworkOption('active_sitewide_plugins') ?? '');

        $paths = [];

        if (\is_array($perSite)) {
            foreach ($perSite as $path) {
                if (\is_string($path)) {
                    $paths[] = [$path, 'site'];
                }
            }
        }

        if (\is_array($network)) {
            foreach (array_keys($network) as $path) {
                if (\is_string($path)) {
                    $paths[] = [$path, 'network'];
                }
            }
        }

        return $paths;
    }

    /**
     * A network option exactly as `sitemeta` stores it, or `null` when there
     * is none.
     *
     * The one place under `includes/` that reads `sitemeta` directly, and it
     * reads through `get_site_option()`'s filter-free twin for the reason
     * `rows()` gives: a `site_option_{$name}` filter can rewrite the value, and
     * LearnDash v3 adds a legacy path to `active_sitewide_plugins` that way.
     * `NetworkOptionGuardTest` watches this method by name and holds its
     * callers to the same allowlist as `get_site_option()`. It also fails on
     * any other route to the table it can see: the table's name, the metadata
     * API with the `'site'` type, and the `site-options` cache group. A table
     * name assembled by concatenation is beyond a token scan.
     *
     * Single-site installs have no `sitemeta`, and no network to activate a
     * plugin across, so they have nothing to read.
     */
    private static function storedNetworkOption(string $name): ?string
    {
        global $wpdb;

        // Narrowed for the reason `rows()` gives. Without a handle, or on a
        // single site, there is nothing stored to read.
        if (! $wpdb instanceof \wpdb || ! is_multisite()) {
            return null;
        }

        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM %i WHERE meta_key = %s AND site_id = %d',
            $wpdb->sitemeta,
            $name,
            get_current_network_id(),
        ));

        return \is_string($value) ? $value : null;
    }

    /**
     * Directory slugs of every plugin active on this site or across the network.
     *
     * @param  list<array{0: string, 1: 'site'|'network'}>  $active
     * @return list<string>
     */
    private static function activePluginSlugs(array $active): array
    {
        $slugs = [];

        foreach ($active as [$path]) {
            $slug = explode('/', $path)[0];

            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Every option on the current site, as stored.
     *
     * Read straight from the table rather than through `get_option()`, and that
     * is the raw-not-curated rule (D-004) rather than a shortcut: `get_option()`
     * runs each value through `option_{$name}` filters, so a plugin can rewrite
     * what a migration would carry. The table is core's own, not a plugin's
     * schema, so this is not the raw-SQL prohibition — that one exists because
     * a plugin's storage can change shape underneath us, and `wp_options` is
     * the one table whose shape is WordPress itself.
     *
     * @return array<string, string>
     */
    private static function rows(): array
    {
        global $wpdb;

        // Narrowed rather than asserted: `assert()` is compiled out in
        // production, so it would be a type hint that vanishes exactly where
        // this code runs. An absent handle yields an empty table, which the
        // caller reports as zero options rather than as a fatal.
        if (! $wpdb instanceof \wpdb) {
            return [];
        }

        $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A);

        if (! \is_array($rows)) {
            return [];
        }

        $options = [];

        foreach ($rows as $row) {
            $name = $row['option_name'] ?? null;
            $value = $row['option_value'] ?? '';

            if (\is_string($name) && $name !== '') {
                $options[$name] = \is_string($value) ? $value : '';
            }
        }

        return $options;
    }

    /**
     * Whether an option is served whatever the rules say.
     */
    private static function isExempt(string $name): bool
    {
        return str_starts_with($name, self::NEVER_DENIED_PREFIX);
    }

    /**
     * Whether the name matches a reviewed denylist pattern.
     */
    private static function isDeniedByName(string $name): bool
    {
        foreach (Config::optionDenylist() as $pattern) {
            if (self::matchesLikePattern($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value looks like a secret, whatever it is called.
     *
     * The second half of the guard, and the half that catches what nobody
     * enumerated. The production audit found `acf_pro_license` on 32 of 33
     * sites — 128 to 172 characters of base64 — by its **shape**, before any
     * pattern named it, and the name-only denylist would have served it.
     *
     * A name-based denylist can only ever catch credentials someone already
     * thought of, and this network gained three new paid plugins in the time
     * this API has been under construction. So the two run together: names
     * catch the empty credential that has no shape (`wp-short-pixel-apiKey` is
     * empty on ten of its eleven sites), shapes catch the credential with a
     * name nobody predicted.
     *
     * Deliberately narrow. Prose carries spaces and never matches. What matches
     * is an unbroken run of hex or base64, a JWT, or a known vendor prefix —
     * which is what a token looks like and very little else does. It will still
     * occasionally withhold something harmless: 25 `_wp_all_import_functions_hash_*`
     * integrity hashes on this network are hex-64 and would go. That is the
     * accepted cost, and it is why the withheld count is reported split by
     * reason.
     *
     * **And it looks inside the value, not only at it.** A PHP-serialized array
     * carries `:` and `"`, so it never matches the leaf rules above — which for
     * one release read as a virtue, and was the hole: the identical secret was
     * withheld as a bare value and served one level down inside a settings
     * array. `wp_mail_smtp` keeps a SendGrid key, a Gmail client secret and a
     * license key in one row that way; `geot_settings` keeps a 65-character
     * `api_secret` on this install today (#33).
     *
     * The *name* rule is deliberately not recursed alongside it. Measured
     * against all 1,223 options here, the shape rule finds 3 nested leaves in 2
     * options and 2 of them are real credentials, while the name rule finds 24
     * in 10 and almost all are noise — `templates.12.query_obj.orderby.0.key`,
     * `is_valid_key`, MinervaKB's UI label text. That is D-032's own split
     * holding one level down: a name match is a reviewed decision about a name
     * somebody chose, a shape match is a guess about content nobody enumerated.
     * Names do not generalize to keys inside a value; shapes do.
     */
    private static function hasCredentialShape(string $value): bool
    {
        if (self::looksLikeSecret($value)) {
            return true;
        }

        return self::containsSecret(self::decoded($value), 0);
    }

    /**
     * The leaf test: does this one string look like a credential?
     *
     * **Private, and the docblock that said otherwise was wrong.** It claimed
     * this was widened for `GravityFeedPayload`, which calls `secretIn()`,
     * `isDeniedNestedKey()` and `decoded()` and has never called this. A
     * reader auditing the class surface was told to preserve a caller that
     * does not exist. The three methods the feed filter does reach are public
     * for exactly the reason this one is not.
     */
    private static function looksLikeSecret(string $value): bool
    {
        $trimmed = trim($value);

        if (\strlen($trimmed) < 32) {
            return false;
        }

        // A JSON Web Token, whatever its length. `tidio-access-token` is 708
        // characters of one.
        if (str_starts_with($trimmed, 'eyJ')) {
            return true;
        }

        /*
         * Vendor-prefixed tokens, which announce themselves.
         *
         * `ya29.` and `1//` are Google's OAuth2 access and refresh tokens, and
         * they were missing here until #33 — neither is hex, and both carry a
         * `.` or a `-` that keeps them out of the base64 run below, so they
         * matched nothing at all. SEOPress Pro keeps a pair in
         * `seopress_google_analytics_option_name1` and the Gravity Forms
         * Google Analytics add-on keeps another in its settings row. A refresh
         * token is the one worth naming: it goes on minting access tokens long
         * after a migration is over.
         */
        if (preg_match('/^(sk_|pk_|xox[bp]-|ghp_|gho_|AKIA|SG\.|ya29\.|1\/\/)/', $trimmed) === 1) {
            return true;
        }

        // An unbroken run of hex — the shape of a hash or a raw key.
        if (preg_match('/^[a-f0-9]{32,}$/i', $trimmed) === 1) {
            return true;
        }

        // …or of base64. 40 rather than 32, because shorter runs collide with
        // ordinary alphanumeric settings far more often.
        return preg_match('/^[A-Za-z0-9+\/]{40,}={0,2}$/', $trimmed) === 1;
    }

    /**
     * A stored value decoded far enough to look inside it, or `null`.
     *
     * **For inspection only.** What ships is still whatever `value()` returns,
     * so D-009 stands untouched: a JSON string is a value rather than an
     * encoding WordPress applied, and it goes out as the string it is. Reading
     * it here decides only whether to withhold the row — a question the stored
     * shape does not answer and the contents do.
     *
     * `allowed_classes: false` because inspecting a value must not be able to
     * construct anything. Plain `unserialize()` instantiates whatever class the
     * string names and runs its `__wakeup()`; every such object arrives here as
     * `__PHP_Incomplete_Class` instead, which is inert and which `containsSecret()`
     * treats as opaque.
     */
    public static function decoded(string $stored): mixed
    {
        if (is_serialized($stored)) {
            return @unserialize($stored, ['allowed_classes' => false]);
        }

        $trimmed = ltrim($stored);

        // JSON, but only where the value announces itself as a container. A
        // bare `"1"` is valid JSON and decoding it would say nothing the leaf
        // rules have not already asked.
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        return json_decode($stored, true);
    }

    /**
     * The shape rule against a value ACF has already assembled.
     *
     * `acf_get_value()` returns real structure — a repeater is an array of rows
     * — so there is usually nothing left to decode. A string is still put
     * through the full rule rather than the leaf one, because a text field can
     * hold serialized or JSON content that ACF has no reason to unpack.
     */
    private static function assembledHasCredentialShape(mixed $value): bool
    {
        if (\is_string($value)) {
            return self::hasCredentialShape($value);
        }

        return self::containsSecret($value, 0);
    }

    /**
     * Whether any string anywhere inside a decoded value looks like a secret.
     */
    private static function containsSecret(mixed $node, int $depth): bool
    {
        return self::secretIn($node, $depth) !== null;
    }

    /**
     * Which rule finds a secret inside a decoded value, or `null` for none.
     *
     * The same walk as `containsSecret()`, reporting *which* of the two nested
     * rules fired rather than only that one did. `classify()` needs the
     * distinction and the payload's two-bucket count does not, which is why
     * this is the implementation and the boolean is the wrapper.
     *
     * Public for the same second caller `looksLikeSecret()` is (#38, #80).
     * Feed meta arrives from `GFAPI` already JSON-decoded, so it is exactly the
     * *decoded* structure this walk expects and needs no `decoded()` step of
     * its own — which is why the reuse is this method rather than `classify()`.
     *
     * @param  list<string>|null  $exemptValues  Values a caller declares to be
     *                                           references rather than
     *                                           secrets, applied at every
     *                                           depth. `null` for the option
     *                                           path, which exempts nothing.
     * @param  bool  $decodeStrings  Whether a string leaf that announces itself
     *                               as a container is decoded and walked.
     *                               Both callers that classify a stored value
     *                               pass `true`: `classify()` for an option,
     *                               whose serialized array can hold a JSON
     *                               string (#457), and the feed path, whose meta
     *                               nests settings groups. Off by default for
     *                               the ACF path, whose values ACF has already
     *                               assembled.
     * @return self::VERDICT_NESTED_KEY|self::VERDICT_SHAPE|null
     */
    public static function secretIn(mixed $node, int $depth, ?array $exemptValues = null, bool $decodeStrings = false): ?string
    {
        /*
         * Fails CLOSED. `unserialize()` can return an array that contains
         * itself — `R:` back-references are part of the format — and a plain
         * recursive walk over one never returns. Withholding at the bound keeps
         * that from being a way to smuggle a value past the rule by burying it,
         * and an option legitimately nested deeper than this is withheld
         * visibly, in the `by_shape` count, rather than served silently.
         */
        if ($depth > self::MAX_INSPECTION_DEPTH) {
            return self::VERDICT_SHAPE;
        }

        if (\is_string($node)) {
            if (self::looksLikeSecret($node)) {
                return self::VERDICT_SHAPE;
            }

            /*
             * The leaf IS the container, which is why no shape rule fires on
             * it: `{"client_secret":"…"}` is not hex, not base64, not
             * `eyJ`-prefixed. `decoded()` returns null unless the string
             * announces itself as one, so this costs a first-character test on
             * everything else, and the depth bound above still applies.
             */
            if ($decodeStrings) {
                $decoded = self::decoded($node);

                if ($decoded !== null) {
                    return self::secretIn($decoded, $depth + 1, $exemptValues, $decodeStrings);
                }
            }

            return null;
        }

        /*
         * `allowed_classes: false` turns every serialized object into a
         * `__PHP_Incomplete_Class`, which keeps its properties but is not an
         * array — so without this cast a secret stored on an object would walk
         * straight past the loop below. Casting reads the properties without
         * constructing anything.
         */
        if (\is_object($node)) {
            return self::secretIn((array) $node, $depth + 1, $exemptValues, $decodeStrings);
        }

        if (! \is_array($node)) {
            return null;
        }

        foreach ($node as $key => $child) {
            /*
             * A NON-EMPTY STRING, and both halves are load-bearing.
             *
             * A credential is a string. `wp_user_roles` maps
             * `administrator.capabilities.seopress_manage_license` to boolean
             * `true` — a capability named after a license screen, not a license
             * — and testing the key alone withheld all 22 KB of this network's
             * role definitions. That is precisely the false positive D-032
             * already paid for once with `%user_roles`.
             *
             * Empty because withholding a whole configuration over a blank
             * field is the same mistake in miniature: four of the eight nested
             * name matches on this install are empty strings, WP All Import's
             * unused `license` among them.
             *
             * An array under a matching key is not waived — the loop below
             * walks into it and tests its own keys, which is how the Gravity
             * Forms `auth_token` pair (`token`, `refresh`) is caught.
             */
            /*
             * A caller that passes `$exemptValues` is declaring a closed set of
             * values that are references rather than secrets, and it changes
             * this test in two ways that apply at EVERY depth or at none.
             *
             * `GravityFeedPayload` is that caller: a User Registration feed
             * stores `password` as the ID OF THE FIELD the password comes from,
             * so withholding by name discards exactly the mapping the endpoint
             * exists to serve. The exemption used to live at the top level
             * only, which made one rule with two behaviors — an `int` coerced
             * and a bare `4` waived at depth 0, neither at depth 1. Three
             * disclosure paths lived in that gap.
             *
             * A NUMBER is a candidate here for the same reason it is one there:
             * feed meta arrives through `json_decode()`, so a value an add-on
             * wrote as a JSON number is an `int` and every string-only rule
             * walks past it.
             *
             * An ARRAY under a matching key is withheld here TOO, and that is
             * the rule that took three rounds to get right. It was applied at
             * the top level of feed meta and not below it, so
             * `['credentials' => …]` was withheld while
             * `['gateway' => ['credentials' => …]]` was served whole — the same
             * defect one level down, three times running, because the rule
             * lived in a wrapper that only saw depth 0.
             *
             * Walking in instead of withholding was the old behavior, and it is
             * what let a credential travel under a benign child key. The
             * recursion below still runs for a key that does NOT match, so the
             * `auth_token` pair is caught exactly as before.
             */
            $named = \is_int($child) || \is_float($child) ? (string) $child : $child;

            $exempt = $exemptValues !== null
                && \is_string($named)
                && \in_array($named, $exemptValues, true);

            $hasValue = (\is_string($named) && $named !== '') || \is_array($child);

            if (! $exempt && $hasValue && \is_string($key) && self::isDeniedNestedKey($key)) {
                return self::VERDICT_NESTED_KEY;
            }

            $found = self::secretIn($child, $depth + 1, $exemptValues, $decodeStrings);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Whether a key inside a value is named like a credential.
     *
     * Public for `GravityFeedPayload`, which applies it to the TOP level of a
     * feed's meta. That is the right list for feed meta rather than
     * `option_denylist`: a feed's meta keys are keys inside a value in every
     * sense but the storage, and D-032's split — reviewed names for option
     * rows, a narrower list for keys nobody enumerated — holds for them.
     */
    public static function isDeniedNestedKey(string $key): bool
    {
        foreach (Config::strings('option_denylist_nested_keys') as $pattern) {
            if (self::matchesLikePattern($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a name matches a SQL `LIKE` pattern.
     *
     * The patterns are written as `LIKE` because that is the shape a reviewer
     * reads them in, and because the same list would be usable in a `WHERE`
     * clause. Matching them in PHP instead keeps the denylist and the read in
     * one place: filtering in SQL would mean the count of withheld options had
     * to come from a second query, and the two could disagree.
     *
     * Public alongside `classify()` and for the same reason (#38). `classify()`
     * says *whether* a row is withheld; the audit additionally reports *which
     * pattern* withheld it, and prints the patterns that fired on nothing so a
     * reviewer can see which are speculative. Deriving that needs the matcher,
     * and the script transcribing it once already produced a copy that drifted.
     */
    public static function matchesLikePattern(string $name, string $pattern): bool
    {
        $regex = '';
        $length = \strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $character = $pattern[$i];

            // `\_` and `\%` are LIKE's escapes for a literal underscore or
            // percent. Without honoring them `%api\_key%` would match far more
            // than it reads as.
            if ($character === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '/');

                continue;
            }

            $regex .= match ($character) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($character, '/'),
            };
        }

        return preg_match('/^'.$regex.'$/i', $name) === 1;
    }

    /**
     * One option value, decoded only where WordPress itself encoded it.
     *
     * `maybe_unserialize()` unpacks a PHP-serialized array into real structure
     * and leaves everything else alone — which is exactly the distinction that
     * matters here. `wp_options` holds JSON strings too (MinervaKB's
     * configuration among them), and a JSON string is a *value*, not an
     * encoding WordPress applied. Unpacking it would be a judgment about its
     * type that the stored row does not make, and it would not round-trip.
     */
    private static function value(string $stored): mixed
    {
        return maybe_unserialize($stored);
    }

    /**
     * ACF's structured view of the options pages, keyed by page slug.
     *
     * D-026: the flat table above is not lossy, but it is flat in the way WXR
     * is. `remove_ontology_criteria` is stored as `"1"` — the repeater's row
     * count — with the rows themselves in numbered `options_..._0_*` keys, while
     * `acf_get_value( 'options', $field )` returns them assembled. Both shapes
     * ship, for the reason D-005 gives on posts.
     *
     * Options pages are discovered from the field groups' own location rules
     * rather than through `acf_get_options_pages()`. That keeps the plugin
     * inside the four ACF functions D-024 pinned — the allowlist in
     * `tests/Unit/AcfApiGuardTest.php` is what would otherwise have to be
     * widened — and it is not a workaround: a page with no field group has
     * nothing to serve anyway.
     *
     * **The denylist applies here too, and used not to.** `acf_get_value( 'options',
     * $field )` resolves to `get_option( "options_{$name}" )`, so every value the
     * flat map above withheld was read straight back out and served in full one
     * key later — the same response reporting a withholding that had not
     * happened, next to a dangling `_options_*` field-key reference (#33). The
     * two rules are applied to the option name the field is stored under and to
     * the assembled value, and a denied field leaves **both** maps: they are
     * keyed differently, so removing it from one alone ships it under the other
     * spelling.
     *
     * @return array{pages: array<string, mixed>, by_name: int, by_shape: int}
     */
    private static function acf(): array
    {
        $pages = [];
        $byName = 0;
        $byShape = 0;

        foreach (self::optionsPageSlugs() as $slug) {
            $read = FieldReader::read(['options_page' => $slug], 'options');

            foreach ($read['identities'] as $identity) {
                ['key' => $key, 'name' => $name] = $identity;

                // The row ACF reads this field out of. `isExempt()` is not
                // consulted: the exemption protects `_options_<field>`, whose
                // content is a field key, and this is the value row beside it.
                if (self::isDeniedByName(self::ACF_OPTION_PREFIX.$name)) {
                    unset($read['keys'][$key], $read['names'][$name]);
                    $byName++;

                    continue;
                }

                if (self::assembledHasCredentialShape($read['keys'][$key] ?? null)) {
                    unset($read['keys'][$key], $read['names'][$name]);
                    $byShape++;
                }
            }

            if ($read['keys'] === [] && $read['names'] === []) {
                continue;
            }

            $pages[$slug] = [
                'keys' => $read['keys'],
                'names' => $read['names'],
                'name_collisions' => $read['collisions'],
            ];
        }

        return ['pages' => $pages, 'by_name' => $byName, 'by_shape' => $byShape];
    }

    /**
     * Every options-page slug named by a field group's location rules.
     *
     * @return list<string>
     */
    private static function optionsPageSlugs(): array
    {
        $slugs = [];

        foreach (acf_get_field_groups() as $group) {
            $location = \is_array($group) ? ($group['location'] ?? null) : null;

            if (! \is_array($location)) {
                continue;
            }

            foreach ($location as $ruleGroup) {
                // A rule group can be a bare string on a malformed field group;
                // #7 hit four of them on this network while tallying location
                // parameters, so this is a real shape rather than defensiveness.
                if (! \is_array($ruleGroup)) {
                    continue;
                }

                foreach ($ruleGroup as $rule) {
                    if (! \is_array($rule)) {
                        continue;
                    }

                    if (($rule['param'] ?? null) !== 'options_page') {
                        continue;
                    }

                    $value = $rule['value'] ?? null;

                    if (\is_string($value) && $value !== '') {
                        $slugs[$value] = true;
                    }
                }
            }
        }

        return array_keys($slugs);
    }
}
