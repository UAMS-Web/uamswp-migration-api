<?php

/**
 * The discovery endpoints — what a migration reads before it starts.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;

/*
 * These replace WXR's channel header and the importer's
 * `XmlParser::getContentTypes()` / `getBaseBlogUrl()`. The channel-header
 * assertions matter more than they look: the importer uses `base_blog_url` for
 * URL rewriting and internal-link resolution, so a value that is *nearly* right
 * produces links that are silently wrong rather than obviously broken.
 */

/**
 * Dispatch a discovery route, authenticated.
 *
 * @param  array<string, mixed>  $params
 */
function discoveryGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * A discovery response as a list of records.
 *
 * @param  array<string, mixed>  $params
 * @return list<array<string, mixed>>
 */
function discoveryList(string $route, array $params = []): array
{
    $data = discoveryGet($route, $params)->get_data();

    assert(is_array($data), "the {$route} endpoint did not return a list");

    /** @var list<array<string, mixed>> $data */
    return $data;
}

/**
 * One record out of a discovery response, narrowed once.
 *
 * @return array<string, mixed>
 */
function discoveryRecord(mixed $value): array
{
    assert(is_array($value), 'expected a discovery record');

    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * A numeric field out of a discovery payload, narrowed once.
 */
function discoveryInt(mixed $value): int
{
    assert(is_numeric($value), 'expected a numeric field in the discovery payload');

    return (int) $value;
}

/**
 * A string column out of a list of records.
 *
 * @param  list<array<string, mixed>>  $records
 * @return list<string>
 */
function discoveryColumn(array $records, string $key): array
{
    $values = [];

    foreach ($records as $record) {
        $value = $record[$key] ?? null;

        if (is_string($value)) {
            $values[] = $value;
        }
    }

    return $values;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('describes every site in the network', function (): void {
    $second = makeSite();

    $ids = [];

    foreach (discoveryList('/sites') as $site) {
        $ids[] = discoveryInt($site['id'] ?? null);
    }

    $ascending = $ids;
    sort($ascending);

    expect(discoveryGet('/sites')->get_status())->toBe(200)
        ->and($ids)->toContain(get_current_blog_id())
        ->and($ids)->toContain($second)
        // Ordered by id, so a consumer walking the network sees a stable order
        // rather than whatever the site query happened to return.
        ->and($ids)->toBe($ascending);
});

it('carries the WXR channel header field for field', function (): void {
    $site = discoveryRecord(discoveryList('/sites')[0]);

    expect($site)->toHaveKeys([
        'title', 'link', 'description', 'language',
        'wxr_version', 'base_site_url', 'base_blog_url', 'counts',
    ]);
});

it('reports `base_blog_url` byte-identically to what the exporter writes', function (): void {
    // The acceptance criterion this ticket turns on. Core's exporter fills
    // `wp:base_blog_url` by calling `bloginfo_rss( 'url' )`, and
    // `XmlParser::getBaseBlogUrl()` returns that string verbatim. So the
    // contract is `get_bloginfo_rss( 'url' )` — NOT `home_url()`, which skips
    // `strip_tags()`, `convert_chars()` and the `get_bloginfo_rss` filter.
    //
    // (Written without the literal close-tag sequence on purpose: PHP's lexer
    // honours it inside a `//` comment and drops out of PHP mode.)
    //
    // The filter is what gives this test teeth. Asserting against
    // `get_bloginfo_rss( 'url' )` alone proved nothing: no filter is registered
    // on it by default, `strip_tags()` is a no-op on a URL and `convert_chars()`
    // is a no-op without an `&`, so in a stock install it is byte-identical to
    // `home_url()` — and the substitution this test exists to catch would have
    // kept it green. Making the two differ is the only way to tell which one
    // the payload called.
    add_filter('get_bloginfo_rss', fn (string $info, string $show): string => $show === 'url' ? $info.'/rss-filtered' : $info, 10, 2);

    $site = discoveryRecord(discoveryList('/sites')[0]);

    expect($site['base_blog_url'])->toBe(home_url().'/rss-filtered')
        ->and($site['link'])->toBe(home_url().'/rss-filtered')
        // Named explicitly, because it is the actual regression: swap either
        // line in `DiscoveryPayload::site()` for `home_url()` and this fails.
        ->and($site['base_blog_url'])->not->toBe(home_url());

    remove_all_filters('get_bloginfo_rss');
});

it('distinguishes the base site URL from the base blog URL', function (): void {
    // They are not interchangeable, and on a network they genuinely differ:
    // `wxr_site_url()` returns `network_home_url()` under multisite, which can
    // carry a different scheme and a trailing slash where `get_bloginfo_rss()`
    // carries neither. Serving one where the other is meant would rewrite every
    // internal link to a URL that resolves but is wrong.
    $site = discoveryRecord(discoveryList('/sites')[0]);

    expect($site['base_site_url'])->toBe(is_multisite() ? network_home_url() : get_bloginfo_rss('url'));
});

it('reports the WXR version core would emit, parsed out of core', function (): void {
    // Deliberately does NOT `require` `wp-admin/includes/export.php`. An earlier
    // draft did, and that one line hollowed the test out: the require defines
    // `WXR_VERSION`, so `wxrVersion()` took its `defined()` short-circuit and
    // returned the constant, and the assertion compared `constant('WXR_VERSION')`
    // against itself. The regex branch was never executed — and that branch is
    // **100% of the production path**, because core requires that file from
    // `wp-admin/export.php` alone and a REST request never loads it.
    //
    // So: leave the constant undefined, and check the served value against a
    // version this test parses for itself. A hard failure rather than a skip if
    // the constant has leaked in from somewhere else, because a test that has
    // quietly stopped reaching its branch is the thing being fixed here.
    expect(\defined('WXR_VERSION'))->toBeFalse();

    preg_match(
        '/define\(\s*[\'"]WXR_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
        (string) file_get_contents(ABSPATH.'wp-admin/includes/export.php'),
        $matched
    );

    $version = $matched[1] ?? null;

    // If core ever writes it as `const WXR_VERSION = …` this goes red, which is
    // the point: `wxrVersion()` would start returning null and the importer
    // would be told it has no format version at all.
    expect($version)->toMatch('/^\d+\.\d+$/')
        ->and($site = discoveryRecord(discoveryList('/sites')[0]))->toHaveKey('wxr_version')
        ->and($site['wxr_version'])->toBe($version);
});

it("counts a site's content, excluding auto-drafts", function (): void {
    $before = discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null);

    makePost();
    makePost(['post_status' => 'auto-draft']);

    $after = discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null);

    // One real post counted, the abandoned "new post" row not — the same
    // exclusion the posts endpoint makes, so the planning figure and the
    // payload agree.
    expect(discoveryInt($after['posts']))->toBe(discoveryInt($before['posts']) + 1);
});

it('lists every registered post type, exposed or not', function (): void {
    // A consumer needs to tell "this site has no events" from "we are not
    // sending you the events". Listing only the exposed types would collapse
    // those into the same silence.
    $types = discoveryList('/post-types');
    $names = discoveryColumn($types, 'name');

    expect($names)->toContain('post')
        ->and($names)->toContain('page')
        // `revision` is registered by core and deliberately not served.
        ->and($names)->toContain('revision');

    $exposure = [];

    foreach ($types as $type) {
        $name = $type['name'] ?? null;

        if (is_string($name)) {
            $exposure[$name] = $type['exposed'] ?? null;
        }
    }

    expect($exposure['post'])->toBeTrue()
        ->and($exposure['revision'])->toBeFalse();
});

it("counts posts by status, matching the site's real registrations", function (): void {
    makePost(['post_status' => 'publish']);
    makePost(['post_status' => 'draft']);

    $post = [];

    foreach (discoveryList('/post-types') as $type) {
        if (($type['name'] ?? null) === 'post') {
            $post = $type;
        }
    }

    $counts = discoveryRecord($post['counts'] ?? null);

    // Asserted against `wp_count_posts()` rather than a literal: the suite
    // shares a database within a test file, so a hardcoded number would be a
    // hostage to whatever ran first.
    // Cast, because `wp_count_posts()` hands back a stdClass rather than
    // an array — which the narrowing helper caught rather than coerced.
    $expected = (array) wp_count_posts('post');

    $total = 0;

    foreach ($counts as $count) {
        $total += discoveryInt($count);
    }

    expect(discoveryInt($counts['publish']))->toBe(discoveryInt($expected['publish'] ?? null))
        ->and(discoveryInt($counts['draft']))->toBe(discoveryInt($expected['draft'] ?? null))
        ->and(discoveryInt($post['total'] ?? null))->toBe($total);
});

it('lists taxonomies with term counts and exposure', function (): void {
    $slug = 'discovery-probe-'.wp_generate_password(6, false);

    wp_insert_term($slug, 'category');

    $names = discoveryColumn(discoveryList('/taxonomies'), 'name');
    $category = [];

    foreach (discoveryList('/taxonomies') as $taxonomy) {
        if (($taxonomy['name'] ?? null) === 'category') {
            $category = $taxonomy;
        }
    }

    expect($names)->toContain('category')
        ->and($names)->toContain('post_tag')
        ->and($category['exposed'] ?? null)->toBeTrue()
        ->and(discoveryInt($category['terms'] ?? null))
        ->toBe(discoveryInt(wp_count_terms(['taxonomy' => 'category', 'hide_empty' => false])))
        ->and($category['object_types'] ?? null)->toContain('post');
});

it('refuses to read registrations for another site', function (): void {
    // The important finding in this ticket, and it is a negative result.
    //
    // Post-type and taxonomy registration is **process-global**: plugins
    // register on `init` for whichever site booted the request, and
    // `switch_to_blog()` does not re-run that. Measured — 16 types before a
    // switch and 16 after, and a type registered while switched is still
    // registered once restored.
    //
    // So `?site=N` here would serve the booted site's registrations alongside
    // site N's counts: a well-formed response mixing two sites. It refuses
    // instead, and names where to ask.
    $second = makeSite();

    foreach (['/post-types', '/taxonomies'] as $route) {
        $response = discoveryGet($route, ['site' => $second]);

        expect($response->get_status())->toBe(400)
            ->and(errorCode($response))->toBe('uamswp_migration_api_registration_is_not_site_scoped');
    }
});

it('serves registrations for the site that booted the request', function (): void {
    // The `site` parameter is refused only when it points somewhere else.
    // Passing the current site, or omitting it, is the ordinary case.
    $current = get_current_blog_id();

    expect(discoveryGet('/post-types')->get_status())->toBe(200)
        ->and(discoveryGet('/post-types', ['site' => $current])->get_status())->toBe(200)
        ->and(discoveryGet('/taxonomies', ['site' => $current])->get_status())->toBe(200);
})->skip(! is_multisite(), networkOnly('a single site refuses every `?site=`'));

it('serves counts for another site on `/sites`, where switching genuinely works', function (): void {
    // The contrast that makes the refusal above coherent rather than arbitrary:
    // options and counts *are* per-site, because they are database reads. It is
    // only the registration registry that a switch leaves alone.
    $second = makeSite();

    switch_to_blog($second);
    update_option('blogname', 'A Distinctly Named Site');
    restore_current_blog();

    $titles = [];

    foreach (discoveryList('/sites') as $site) {
        $titles[discoveryInt($site['id'] ?? null)] = $site['title'] ?? null;
    }

    expect($titles[$second])->toBe('A Distinctly Named Site')
        ->and($titles[get_current_blog_id()])->not->toBe('A Distinctly Named Site')
        ->and(get_current_blog_id())->not->toBe($second);
});

it("reports each site's own language, not the one the network booted in", function (): void {
    // `wp-settings.php` runs `$locale = get_locale();` at global scope during
    // bootstrap, and `get_locale()` opens with `if ( isset( $locale ) ) return …`.
    // `switch_to_blog()` updates `$wpdb`, `$GLOBALS['blog_id']`, the table
    // prefix and the cache prefix, and fires `switch_blog` — for which core
    // registers exactly one listener, `wp_switch_roles_and_user`, which does not
    // touch the locale. So the memo survives the switch.
    //
    // The failure it produced is this project's defining one: a 200 in which
    // `title`, `link` and `counts` are all site N's, and `language` alone
    // belongs to whichever site the process booted on. Core's exporter run on
    // that site writes its own `<language>`, so the payload contradicted the
    // channel header D-035 says it reproduces function for function.
    $second = makeSite();

    switch_to_blog($second);
    update_option('WPLANG', 'es_ES');
    restore_current_blog();

    $languages = [];

    foreach (discoveryList('/sites') as $record) {
        $site = discoveryRecord($record);
        $languages[discoveryInt($site['id'] ?? null)] = $site['language'] ?? null;
    }

    expect($languages[$second])->toBe('es-ES')
        // The current site is unchanged, which is the half that proves the
        // memo was restored rather than merely cleared.
        ->and($languages[get_current_blog_id()])->not->toBe('es-ES')
        ->and(get_locale())->not->toBe('es_ES');
});

it('separates the whole table from what this deployment can actually serve', function (): void {
    // #36. `counts.posts` used to be every row of `wp_posts` bar `auto-draft`,
    // which on the development install is 1,453 against 428 servable — a 3.4x
    // overstatement, two thirds of it revisions. A consumer sizing a run, or
    // checking a finished one for completeness, was working from a number
    // several times the real one.
    //
    // The original `auto-draft` exclusion carried the argument for the rest:
    // a row that inflates a planning figure does not belong in one. A revision
    // inflates it the same way, and so does a post type this deployment does
    // not serve.
    // Under 20 characters, which is the width of the `post_type` column.
    // `register_post_type()` accepts a longer name without complaint and the
    // INSERT then fails, so the fixture is asserted rather than assumed.
    register_post_type('uamswp_unserved', ['public' => false, 'supports' => ['title', 'editor']]);

    $post = makePost(['post_title' => 'Revisable', 'post_content' => 'first']);

    // A revision, made the way WordPress makes one.
    wp_update_post(['ID' => $post, 'post_content' => 'second']);

    $probe = wp_insert_post(['post_type' => 'uamswp_unserved', 'post_title' => 'Unserved', 'post_status' => 'publish'], true);

    expect($probe)->toBeInt()->toBeGreaterThan(0);

    $counts = discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null);
    $byType = discoveryRecord($counts['by_post_type'] ?? null);

    // The breakdown sees everything, including what is not served — that is
    // what makes it useful for reconciling a finished import per type.
    expect($byType)->toHaveKey('revision')
        ->and($byType)->toHaveKey('uamswp_unserved')
        ->and(discoveryInt($byType['uamswp_unserved']))->toBe(1);

    $served = 0;

    foreach ($byType as $type => $total) {
        if (\in_array((string) $type, Config::strings('post_types'), true)) {
            $served += discoveryInt($total);
        }
    }

    // The three figures are mutually consistent, which is the property a
    // consumer can actually rely on.
    expect(discoveryInt($counts['posts']))->toBe($served)
        ->and(discoveryInt($counts['rows']))->toBe(array_sum(array_map(discoveryInt(...), array_values($byType))))
        // …and the servable figure is strictly smaller, because revisions and
        // the unserved type are in the table and out of the plan.
        ->and(discoveryInt($counts['posts']))->toBeLessThan(discoveryInt($counts['rows']));

    unregister_post_type('uamswp_unserved');
});

it('keeps revisions out of the servable count and in the whole-table count', function (): void {
    // The narrowest statement of the bug, so a regression names itself.
    $before = discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null);

    $post = makePost(['post_content' => 'first']);
    wp_update_post(['ID' => $post, 'post_content' => 'second']);

    $after = discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null);

    $addedRows = discoveryInt($after['rows']) - discoveryInt($before['rows']);
    $addedPosts = discoveryInt($after['posts']) - discoveryInt($before['posts']);

    // One post and at least one revision landed in the table; only the post is
    // something this API will serve.
    expect($addedRows)->toBeGreaterThan($addedPosts)
        ->and($addedPosts)->toBe(1);
});

it("counts each site's own content, not the network's", function (): void {
    // The counts are taken inside the switch, so a second site with different
    // content reports different figures. Site scoping is the mechanism every
    // endpoint inherits and its failure is silent.
    $second = makeSite();

    // A new blog is created carrying WordPress' default post and page, so the
    // figure that means anything is the delta rather than the total.
    $baseline = discoveryRecord(discoveryRecord(discoveryList('/sites')[1])['counts'] ?? null);

    switch_to_blog($second);
    makePost();
    makePost();
    restore_current_blog();

    $counts = [];

    foreach (discoveryList('/sites') as $record) {
        $site = discoveryRecord($record);
        $counts[discoveryInt($site['id'] ?? null)] = discoveryRecord($site['counts'] ?? null);
    }

    expect(discoveryInt($counts[$second]['posts']) - discoveryInt($baseline['posts']))->toBe(2)
        ->and(discoveryInt($counts[get_current_blog_id()]['posts']))
        ->not->toBe(discoveryInt($counts[$second]['posts']));
});

it('counts by configuration, not by what this process happens to have registered', function (): void {
    // D-034: post-type registration is process-global, so a switch does not
    // re-run it. `/sites` was explicitly unaffected by that, because its counts
    // were pure database reads — and filtering them by `post_type_exists()`
    // reintroduced the exact mixture D-034 refuses elsewhere: site N's rows
    // judged against the booting site's registrations.
    //
    // On production that under-reports every site running a plugin the booting
    // site does not. Find-a-Doc registers `provider`; a request booted on a site
    // without it would report zero providers for the 30 sites that have them.
    $type = 'kb';

    expect(Config::strings('post_types'))->toContain($type)
        // The premise: eligible in configuration, absent from this process.
        ->and(post_type_exists($type))->toBeFalse();

    // Measured as a DELTA. Asserting `posts >= by_post_type[kb]` passed with a
    // single row of margin — it would have held on any site with one ordinary
    // post, whether or not the `kb` row was counted, which is a threshold
    // standing in for the thing being tested.
    $before = discoveryInt(discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null)['posts']);

    $inserted = wp_insert_post([
        'post_type' => $type,
        'post_title' => 'Configured but unregistered',
        'post_status' => 'publish',
    ], true);

    expect($inserted)->toBeInt();

    $counts = discoveryRecord(discoveryRecord(discoveryList('/sites')[0])['counts'] ?? null);
    $byType = discoveryRecord($counts['by_post_type'] ?? null);

    // The row is in the table, so the breakdown sees it…
    expect($byType)->toHaveKey($type)
        ->and(discoveryInt($byType[$type]))->toBe(1)
        // …and it is a type this deployment is configured to serve, so the
        // servable figure moves by exactly that row, whichever site booted the
        // request and whatever else the table holds.
        ->and(discoveryInt($counts['posts']) - $before)->toBe(1);
});

it('serves channel text as a WXR consumer would receive it', function (): void {
    // #37. Core writes the channel header with `bloginfo_rss()`, which applies
    // one filter more than `get_bloginfo_rss()` — `ent2ncr`, registered at
    // `default-filters.php:268` — so `&amp;` goes out as `&#38;` and the XML
    // parser hands the consumer back `&`. This payload was serving the encoded
    // form, so the two paths disagreed on any site whose name contains an
    // ampersand.
    //
    // Decoding is not curation under D-004: the entities were never in the row.
    // `get_bloginfo()` adds them on the way out, and undoing them returns the
    // text the site actually holds.
    update_option('blogname', 'Ear, Nose & Throat');
    update_option('blogdescription', 'Care & Research');

    $site = discoveryRecord(discoveryList('/sites')[0]);

    expect($site['title'])->toBe('Ear, Nose & Throat')
        ->and($site['description'])->toBe('Care & Research')
        // The encoded form is what it used to serve, and what a consumer would
        // have had to undo itself.
        ->and($site['title'])->not->toContain('&amp;')
        // …while the URLs stay byte-identical to `get_bloginfo_rss()`.
        ->and($site['base_blog_url'])->toBe(get_bloginfo_rss('url'))
        ->and($site['link'])->toBe(get_bloginfo_rss('url'));
});

it('leaves the URLs alone even when decoding would change them', function (): void {
    // The half the previous assertion could not test. Comparing
    // `base_blog_url` to `get_bloginfo_rss('url')` is satisfied by a decoded
    // URL too, because this install's URL carries no entities and the decode
    // is a no-op — so a mutation that decoded the URLs as well passed.
    //
    // `base_blog_url` is the one field here with a live consumer: the
    // importer uses it verbatim for URL rewriting (D-035). Giving the
    // fixture a URL that decoding *would* change is the only way to assert
    // it is left alone.
    update_option('home', 'https://example.test/?from=a&amp;to=b');

    $site = discoveryRecord(discoveryList('/sites')[0]);

    expect($site['base_blog_url'])->toContain('&amp;')
        ->and($site['base_blog_url'])->toBe(get_bloginfo_rss('url'))
        ->and($site['link'])->toBe(get_bloginfo_rss('url'));
});
