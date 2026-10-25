<?php

declare(strict_types=1);

/*
 * Holds `includes/` to the network options it is allowed to read.
 *
 * D-032 carries a caveat that would otherwise be a note nobody reads: the
 * option denylist protects `wp_options`, and **network** options live in
 * `sitemeta`, which a per-site endpoint never touches. `nonce_key` and
 * `nonce_salt` are real secrets and they are in there — which is exactly why
 * the `%salt` pattern matches nothing today.
 *
 * The moment someone serves network options, that caveat becomes a leak. And
 * unlike "a plugin changed on production", this is a change *in this
 * repository* — so it can be a failing test rather than a sentence in a
 * decision log. This is that test.
 *
 * An allowlist rather than a ban, because the plugin legitimately reads one:
 * `active_sitewide_plugins`, to report which plugins the denylist has been
 * reviewed against. Banning the function outright would have blocked that and
 * taught the next person to route around the guard.
 *
 * **The watch list covers the delegates as well as the wrappers, and it did not
 * always.** Core defines `update_site_option()` as a one-line call to
 * `update_network_option()` (`wp-includes/option.php`), and `add_site_option()`
 * the same way. Watching only the wrappers left the functions they delegate to
 * unwatched, so anything under `includes/` could write a network option with
 * the guard reporting nothing — and the asymmetry ran the wrong way, since
 * `get_network_option` **was** watched, so reading a value tripped the guard
 * while writing it did not. Found while building #141, whose own instruction is
 * to call `update_network_option()` directly.
 *
 * **It resolves a class constant rather than reporting it as dynamic.** An
 * option named by `self::OPTION` is not a name the guard cannot see; it is one
 * indirection away from a literal in the same file. Reporting it as `*` would
 * have made the allowlist unusable for exactly the call this guard most needs
 * to watch, and the obvious workaround — allowing `*` — disables the guard.
 */

/**
 * Every first-party PHP file under `includes/`, keyed by repo-relative path.
 *
 * @return array<string, string>
 */
function networkGuardSources(): array
{
    $root = \dirname(__DIR__, 2);
    $sources = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/includes', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
            // getPathname() returns the OS-native separator (backslash on Windows);
            // every consumer of this array's keys compares against a forward-slash
            // literal, matching how this repo names files everywhere else.
            $key = str_replace('\\', '/', substr($entry->getPathname(), \strlen($root) + 1));
            $sources[$key] = (string) file_get_contents($entry->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * `CONST_NAME => value` for every string class constant declared in a source.
 *
 * Only string constants, and only within the file being scanned. That is
 * enough for the calls this guard watches, and it keeps the resolution local:
 * a constant defined elsewhere still reports as dynamic rather than being
 * guessed at.
 *
 * @return array<string, string>
 */
function networkGuardConstants(string $source): array
{
    $tokens = token_get_all($source);
    $count = \count($tokens);
    $constants = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! \is_array($token)) {
            continue;
        }

        if ($token[0] !== T_CONST) {
            continue;
        }

        $name = null;

        for ($j = $i + 1; $j < $count && $j < $i + 8; $j++) {
            $next = $tokens[$j];

            if (\is_array($next) && $next[0] === T_STRING && $name === null) {
                $name = $next[1];

                continue;
            }

            if (\is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING && $name !== null) {
                $constants[$name] = trim($next[1], "'\"");

                break;
            }

            // A non-string constant (an int, an expression) is not a name this
            // guard can resolve, and must not be recorded as one.
            if ($next === ';') {
                break;
            }
        }
    }

    return $constants;
}

/**
 * Network options read by a file, as `path => option name`.
 *
 * Reads the first string literal after the call, which is what every one of
 * these functions takes as its option name. A dynamic name — `get_site_option(
 * $whatever)` — yields `*` and is reported rather than waved through: a
 * denylist cannot reason about a name it cannot see.
 *
 * @param  array<string, string>  $sources
 * @return list<array{string, string}>
 */
function networkGuardReads(array $sources): array
{
    // Wrappers AND the functions they delegate to. See the file docblock:
    // watching `update_site_option` alone leaves `update_network_option`
    // unwatched, and core implements the first as a call to the second.
    $functions = [
        'get_site_option',
        'get_network_option',
        'add_site_option',
        'add_network_option',
        'update_site_option',
        'update_network_option',
        // The raw read in `OptionsPayload`, watched so a `sitemeta` query is
        // held to the allowlist rather than routing around it (#341).
        'storedNetworkOption',
    ];
    $reads = [];

    foreach ($sources as $path => $source) {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        $constants = networkGuardConstants($source);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            $called = \is_array($token) && $token[0] === T_STRING ? $token[1] : null;

            if (! \in_array($called, $functions, true)) {
                continue;
            }

            // `function storedNetworkOption(string $name)` declares the method
            // this list watches; it is not a read, and its parameter is not a
            // name the allowlist could hold.
            $previous = $i - 1;

            while ($previous >= 0 && \is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }

            if ($previous >= 0 && \is_array($tokens[$previous]) && $tokens[$previous][0] === T_FUNCTION) {
                continue;
            }

            $name = '*';

            for ($j = $i + 1; $j < $count && $j < $i + 8; $j++) {
                $next = $tokens[$j];

                if (\is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $name = trim($next[1], "'\"");

                    break;
                }

                // `self::OPTION` and `Token::OPTION` alike: the name is one
                // indirection from a literal, so resolve it rather than
                // reporting a dynamic name the allowlist cannot express.
                if ($next === '::' || (\is_array($next) && $next[0] === T_DOUBLE_COLON)) {
                    $constant = $tokens[$j + 1] ?? null;

                    if (\is_array($constant) && $constant[0] === T_STRING && isset($constants[$constant[1]])) {
                        $name = $constants[$constant[1]];

                        break;
                    }
                }
            }

            $reads[] = [$path, $name];
        }
    }

    return $reads;
}

it('reads no network option outside the reviewed allowlist', function (): void {
    // `sitemeta` holds `nonce_key` and `nonce_salt`. The option denylist has
    // never been reviewed against it, and `%salt` matches nothing precisely
    // because the salts are not in `wp_options`. Serving a network option
    // without revisiting D-032 would ship them.
    // `uamswp_migration_api_token` is allowed deliberately, not by omission.
    // #141 issues a per-network token and has to store it; what is stored is a
    // SHA-256 hash, never the credential. It is listed here so that adding it
    // was a decision with a reason attached, which is the whole difference
    // between this guard passing and this guard being routed around. Its name
    // also ends in `token`, so `option_denylist`'s `%token` withholds it from
    // `GET /options` on the single-site branch where the record lands in
    // `wp_options`. See `docs/DECISIONS.md` D-063.
    $allowed = ['active_sitewide_plugins', 'uamswp_migration_api_token'];

    $unexpected = [];

    foreach (networkGuardReads(networkGuardSources()) as [$path, $name]) {
        if (! \in_array($name, $allowed, true)) {
            $unexpected[] = "{$path} reads network option '{$name}'";
        }
    }

    sort($unexpected);

    expect($unexpected)->toBe([]);
});

it('finds the one network option the plugin is meant to read', function (): void {
    // A guard that scans nothing passes forever. This one has a known positive
    // — the plugin reports which plugins the denylist was reviewed against —
    // so its silence is evidence rather than absence.
    $names = array_column(networkGuardReads(networkGuardSources()), 1);

    expect($names)->toContain('active_sitewide_plugins');
});

it('sees the token write through the class constant that names it', function (): void {
    // The negative control for the constant resolution above. If `self::OPTION`
    // resolved to `*` instead of the option name, the first test would still
    // pass — `*` is simply not in the allowlist, so it would report a failure
    // rather than a false green — but the allowlist entry would be dead text
    // and the guard would be describing a call it cannot actually name.
    $reads = networkGuardReads(networkGuardSources());

    $tokenWrites = array_values(array_filter(
        $reads,
        static fn (array $read): bool => $read[0] === 'includes/Token.php'
    ));

    expect($tokenWrites)->not->toBeEmpty();
    expect(array_column($tokenWrites, 1))->toContain('uamswp_migration_api_token');
    expect(array_column($tokenWrites, 1))->not->toContain('*');
});

it('watches the delegate as well as the wrapper', function (): void {
    // Core implements `update_site_option()` as `update_network_option(null,
    // ...)`. A watch list holding only the wrapper is one this repo can write
    // straight past, which is what it did until #141. Asserting the list itself
    // because the alternative — a fixture calling the unwatched function — puts
    // a network write into `includes/` purely to prove a test works.
    $source = (string) file_get_contents(__FILE__);

    foreach (['update_network_option', 'add_network_option', 'update_site_option', 'add_site_option'] as $function) {
        expect($source)->toContain("'{$function}'");
    }
});

/**
 * Every route to `sitemeta` in code, as `path:line in <method>`.
 *
 * Three shapes, because the table has three doors that name no watched
 * function: the table itself (`$wpdb->sitemeta`, or its name in a query), the
 * metadata API with `'site'` as its type, which core resolves to `sitemeta`,
 * and the `site-options` cache group core keeps network options in. A table
 * name assembled by concatenation, `'site'.'meta'`, is invisible to a token
 * scan by construction; that is the bound.
 *
 * Comments are skipped, so a docblock naming the table is not a read. The
 * enclosing method is tracked by brace depth, which is enough for the flat
 * classes under `includes/`; a closure is attributed to the method it sits in.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function networkGuardSitemetaMentions(array $sources): array
{
    $mentions = [];

    foreach ($sources as $path => $source) {
        $tokens = token_get_all($source);
        $method = null;
        $methodDepth = null;
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if ($token === '{' || (\is_array($token) && \in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;

                if ($methodDepth !== null && $depth < $methodDepth) {
                    $method = null;
                    $methodDepth = null;
                }
            }

            if (! \is_array($token)) {
                continue;
            }

            if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== '('; $j++) {
                    if (\is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $method = $tokens[$j][1];
                        $methodDepth = $depth + 1;

                        break;
                    }
                }
            }

            $literal = $token[0] === T_CONSTANT_ENCAPSED_STRING ? trim($token[1], "'\"") : null;

            if (stripos($token[1], 'sitemeta') !== false || $literal === 'site-options' || networkGuardIsSiteMetadataCall($tokens, $i)) {
                $mentions[] = "{$path}:{$token[2]} in ".($method ?? 'file scope');
            }
        }
    }

    return $mentions;
}

/**
 * Whether the token at `$i` calls the metadata API with `'site'` as its type.
 *
 * `_get_meta_table('site')` is `sitemeta`, so `get_metadata('site', …)` reads
 * network options without the filters and without naming the table.
 *
 * @param  array<int, mixed>  $tokens
 */
function networkGuardIsSiteMetadataCall(array $tokens, int $i): bool
{
    $token = $tokens[$i];

    if (! \is_array($token) || $token[0] !== T_STRING || ! \is_string($token[1]) || preg_match('/metadata|meta_cache/i', $token[1]) !== 1) {
        return false;
    }

    for ($j = $i + 1; isset($tokens[$j]) && $j < $i + 6; $j++) {
        $next = $tokens[$j];

        if (\is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING && \is_string($next[1])) {
            return trim($next[1], "'\"") === 'site';
        }
    }

    return false;
}

/**
 * The mentions of `sitemeta` outside `storedNetworkOption()`.
 *
 * @param  list<string>  $mentions
 * @return list<string>
 */
function networkGuardStrayMentions(array $mentions): array
{
    return array_values(array_filter(
        $mentions,
        static fn (string $mention): bool => ! str_ends_with($mention, ' in storedNetworkOption'),
    ));
}

it('reads sitemeta directly only inside the one watched method', function (): void {
    // `storedNetworkOption()` reads `sitemeta` with a query rather than
    // `get_site_option()`, because a filter on the option can rewrite it
    // (#341). A query names no watched function, so any other mention of the
    // table would be a network read this guard cannot see. Holding the table
    // to that one method holds it to the allowlist above.
    $mentions = networkGuardSitemetaMentions(networkGuardSources());

    expect($mentions)->not->toBeEmpty()
        ->and(networkGuardStrayMentions($mentions))->toBe([]);
});

it('reports every route to sitemeta outside the watched method, and not one inside it', function (): void {
    // The control for the test above, through the same functions: a scanner
    // that could not see the table, or could not tell the methods apart, would
    // pass that test forever.
    $sources = ['includes/Fixture.php' => '<?php
        final class Fixture {
            // sitemeta, in a comment, is not a read.
            private static function storedNetworkOption() { global $wpdb; return $wpdb->sitemeta; }
            private static function elsewhere() { global $wpdb; return $wpdb->get_var("SELECT meta_value FROM {$wpdb->sitemeta}"); }
            private static function viaMetadata() { return get_metadata("site", get_current_network_id(), "nonce_key", true); }
            private static function viaCache() { return wp_cache_get("1:nonce_key", "site-options"); }
            private static function postMeta() { return get_metadata("post", 1, "site", true); }
        }'];

    expect(networkGuardStrayMentions(networkGuardSitemetaMentions($sources)))->toBe([
        'includes/Fixture.php:5 in elsewhere',
        'includes/Fixture.php:6 in viaMetadata',
        'includes/Fixture.php:7 in viaCache',
    ]);
});
