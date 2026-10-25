<?php

/**
 * `GET /redirects/redirection` and `GET /redirects/pretty-links`.
 */

declare(strict_types=1);

use PrettyLinks\Bootstrap;
use PrettyLinks\Database\Migrator;
use PrettyLinks\Repositories\Links;
use Redirection\Database\Schema\Latest;
use UAMSWP\MigrationAPI\REST\RedirectsController;

/**
 * Dispatch a GET against a redirects route, authenticated.
 *
 * @param  array<string, mixed>  $params  Query parameters.
 */
function redirectsGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * Record both redirect plugins as active on the current site, as activation
 * would. The bootstrap loads them from `vendor/` without activating anything.
 */
function activateRedirectPlugins(): void
{
    update_option('active_plugins', [
        RedirectsController::REDIRECTION_PLUGIN,
        RedirectsController::PRETTY_LINKS_PLUGIN,
    ]);
}

/**
 * A Redirection group, through the plugin's own model.
 */
function makeRedirectionGroup(string $name, bool $enabled = true): Red_Group
{
    $group = Red_Group::create($name, 1, $enabled);

    assert($group instanceof Red_Group, 'Redirection did not create the group');

    return $group;
}

/**
 * A Redirection rule, through the plugin's own model.
 *
 * @param  array<string, mixed>  $details  Overrides for the plugin's create call.
 */
function makeRedirectionRule(int $groupId, array $details = []): Red_Item
{
    $item = Red_Item::create(array_merge([
        'url' => '/old-'.wp_generate_password(6, false),
        'action_type' => 'url',
        'action_code' => 301,
        'match_type' => 'url',
        'action_data' => ['url' => 'https://example.org/new'],
        'group_id' => $groupId,
    ], $details));

    assert($item instanceof Red_Item, 'Redirection did not create the rule');

    return $item;
}

/**
 * A Pretty Links link, through the plugin's own repository. A `name` is always
 * passed: without one the plugin fetches the target page over the network to
 * read its title.
 *
 * @param  array<string, mixed>  $data  Overrides for the plugin's create call.
 * @return array<string, mixed>
 */
function makePrettyLink(array $data = []): array
{
    $link = (new Links)->create(array_merge([
        'url' => 'https://example.org/target',
        'slug' => 'go-'.strtolower(wp_generate_password(6, false)),
        'name' => 'Test link',
        'redirect_type' => '301',
    ], $data));

    assert(! isset($link['error']), 'Pretty Links did not create the link');

    return $link;
}

/**
 * The served rows of a collection response.
 *
 * @return list<array<string, mixed>>
 */
function redirectRows(WP_REST_Response $response): array
{
    $data = $response->get_data();

    assert(is_array($data), 'a collection response did not return an array');

    $rows = [];

    foreach ($data as $row) {
        assert(is_array($row), 'a collection record was not an array');

        /** @var array<string, mixed> $row */
        $rows[] = $row;
    }

    return $rows;
}

/**
 * One served row by id.
 *
 * @return array<string, mixed>
 */
function redirectRow(WP_REST_Response $response, int $id): array
{
    foreach (redirectRows($response) as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("row {$id} was not served");
}

/**
 * Whether a served Redirection row reports its group enabled.
 *
 * @param  array<string, mixed>  $row  A served row.
 */
function groupEnabled(array $row): mixed
{
    $group = $row['group'] ?? null;

    return is_array($group) ? ($group['enabled'] ?? null) : null;
}

/**
 * The id of a link Pretty Links created.
 *
 * @param  array<string, mixed>  $link  The plugin's create result.
 */
function prettyLinkId(array $link): int
{
    $id = $link['id'] ?? null;

    assert(is_int($id), 'Pretty Links returned no integer id');

    return $id;
}

/**
 * Every link the current site serves, as one response.
 *
 * The plugin's tables are not core's, so the test installer never drops them,
 * and a row an earlier run committed is still there (#436). One page is a guess
 * about how many rows exist, and `per_page` is capped at `per_page_max`, so this
 * walks every page the first one reports. The result carries the first page's
 * status and headers, so `X-WP-Total` still describes the whole collection.
 */
function everyPrettyLink(): WP_REST_Response
{
    $first = redirectsGet('/redirects/pretty-links', ['per_page' => 200]);
    $rows = redirectRows($first);
    $pages = (int) (headers($first)['X-WP-TotalPages'] ?? 1);

    for ($page = 2; $page <= $pages; $page++) {
        $rows = [...$rows, ...redirectRows(redirectsGet('/redirects/pretty-links', ['per_page' => 200, 'page' => $page]))];
    }

    return new WP_REST_Response($rows, $first->get_status(), $first->get_headers());
}

/**
 * The ids a collection response served, in order.
 *
 * @return list<int>
 */
function redirectIds(WP_REST_Response $response): array
{
    $ids = [];

    foreach (redirectRows($response) as $row) {
        $id = $row['id'] ?? null;

        assert(is_int($id), 'a row carried no integer id');

        $ids[] = $id;
    }

    return $ids;
}

/**
 * Each site's `redirect_plugins` report from `/sites`, keyed by site id.
 *
 * @return array<int, mixed>
 */
function redirectPluginsBySite(): array
{
    $byId = [];

    foreach (redirectRows(redirectsGet('/sites')) as $site) {
        $id = $site['id'] ?? null;

        assert(is_int($id), 'a site carried no integer id');

        $byId[$id] = $site['redirect_plugins'] ?? null;
    }

    return $byId;
}

/**
 * A second site with both plugins active on it and none of their tables.
 *
 * Activation does not create either plugin's tables, so this is a state a
 * real site can be in.
 */
function siteWithoutRedirectTables(): int
{
    $site = makeSite();

    switch_to_blog($site);

    try {
        activateRedirectPlugins();
    } finally {
        restore_current_blog();
    }

    return $site;
}

/**
 * Make every query naming a table fail, as an unreadable table would.
 */
function breakQueriesOn(string $table): void
{
    add_filter(
        'query',
        // The endpoint's own table probe selects `1` and is left alone, so
        // the table is found and its read is what fails.
        static fn (string $query): string => str_contains($query, $table) && str_starts_with(ltrim($query), 'SELECT') && ! str_starts_with(ltrim($query), 'SELECT 1 FROM')
            ? str_replace($table, $table.'_unreadable', $query)
            : $query
    );
}

beforeEach(function (): void {
    actAsSuperAdmin();
    activateRedirectPlugins();
});

describe('Redirection', function (): void {
    it('serves a plain rule with its source, target, code, order and state', function (): void {
        $group = makeRedirectionGroup('Vanity');
        $rule = makeRedirectionRule($group->get_id(), ['url' => '/plain', 'action_code' => 302]);

        $response = redirectsGet('/redirects/redirection');

        expect($response->get_status())->toBe(200);

        $row = redirectRow($response, $rule->get_id());

        expect($row['url'])->toBe('/plain')
            ->and($row['regex'])->toBeFalse()
            ->and($row['match_type'])->toBe('url')
            ->and($row['action_type'])->toBe('url')
            ->and($row['action_code'])->toBe(302)
            ->and($row['action_data'])->toBe('https://example.org/new')
            ->and($row['action_data_parsed'])->toBe(['url' => 'https://example.org/new'])
            ->and($row['position'])->toBeInt()
            ->and($row['enabled'])->toBeTrue()
            ->and($row['group_id'])->toBe($group->get_id())
            ->and($row['group'])->toBe([
                'id' => $group->get_id(),
                'name' => 'Vanity',
                'module_id' => 1,
                'enabled' => true,
            ]);
    });

    it('serves a regex rule as a regex', function (): void {
        $group = makeRedirectionGroup('Vanity');
        $rule = makeRedirectionRule($group->get_id(), ['url' => '^/news/(.*)$', 'regex' => true]);

        $row = redirectRow(redirectsGet('/redirects/redirection'), $rule->get_id());

        expect($row['regex'])->toBeTrue()
            ->and($row['url'])->toBe('^/news/(.*)$');
    });

    it('distinguishes a disabled rule from an enabled one', function (): void {
        $group = makeRedirectionGroup('Vanity');
        $enabled = makeRedirectionRule($group->get_id());
        $disabled = makeRedirectionRule($group->get_id());
        $disabled->disable();

        $response = redirectsGet('/redirects/redirection');

        expect(redirectRow($response, $enabled->get_id())['enabled'])->toBeTrue()
            ->and(redirectRow($response, $disabled->get_id())['enabled'])->toBeFalse()
            ->and(groupEnabled(redirectRow($response, $disabled->get_id())))->toBeTrue();
    });

    it('distinguishes a rule in a disabled group from one in an enabled group', function (): void {
        $live = makeRedirectionGroup('Live');
        $off = makeRedirectionGroup('Off');
        $inLive = makeRedirectionRule($live->get_id());
        $inOff = makeRedirectionRule($off->get_id());
        $off->disable();

        $response = redirectsGet('/redirects/redirection');

        expect(groupEnabled(redirectRow($response, $inLive->get_id())))->toBeTrue()
            ->and(groupEnabled(redirectRow($response, $inOff->get_id())))->toBeFalse();
    });

    it('reports a rule enabled inside a disabled group, which only the group state shows', function (): void {
        // The plugin's UI disables every rule when it disables their group,
        // but nothing stops a rule being re-enabled inside it afterwards.
        $off = makeRedirectionGroup('Off', false);
        $rule = makeRedirectionRule($off->get_id());

        $row = redirectRow(redirectsGet('/redirects/redirection'), $rule->get_id());

        expect($row['enabled'])->toBeTrue()
            ->and(groupEnabled($row))->toBeFalse();
    });

    it('serves a referrer condition both as stored and as the plugin parses it', function (): void {
        $group = makeRedirectionGroup('Vanity');
        $rule = makeRedirectionRule($group->get_id(), [
            'match_type' => 'referrer',
            'action_data' => [
                'referrer' => 'https://partner.example',
                'regex' => false,
                'url_from' => 'https://example.org/from-partner',
                'url_notfrom' => 'https://example.org/elsewhere',
            ],
        ]);

        $row = redirectRow(redirectsGet('/redirects/redirection'), $rule->get_id());

        expect($row['match_type'])->toBe('referrer')
            ->and(is_string($row['action_data']) ? unserialize($row['action_data']) : null)->toBeArray()
            ->and($row['action_data_parsed'])->toMatchArray([
                'referrer' => 'https://partner.example',
                'url_from' => 'https://example.org/from-partner',
                'url_notfrom' => 'https://example.org/elsewhere',
            ]);
    });

    it('carries no hit counter or access time', function (): void {
        $group = makeRedirectionGroup('Vanity');
        $rule = makeRedirectionRule($group->get_id());

        $row = redirectRow(redirectsGet('/redirects/redirection'), $rule->get_id());

        expect($row)->not->toHaveKey('hits')
            ->and($row)->not->toHaveKey('last_access')
            ->and($row)->not->toHaveKey('last_count');
    });

    it('serves every rule, ordered by id ascending, with totals', function (): void {
        $group = makeRedirectionGroup('Vanity');
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = makeRedirectionRule($group->get_id())->get_id();
        }

        $all = redirectsGet('/redirects/redirection', ['per_page' => 200]);
        $served = redirectIds($all);
        $sorted = $served;
        sort($sorted);

        // Counted straight off the table rather than through
        // `Red_Item::get_all()`, the call the endpoint itself makes, so the
        // two can disagree.
        $total = (int) testDatabase()->get_var('SELECT COUNT(*) FROM '.testDatabase()->prefix.'redirection_items');

        expect($served)->toBe($sorted)
            ->and($served)->toHaveCount($total)
            ->and(array_values(array_intersect($served, $ids)))->toBe($ids)
            ->and(headers($all)['X-WP-Total'] ?? null)->toBe((string) $total);

        $page = redirectsGet('/redirects/redirection', ['per_page' => 2, 'page' => 2]);

        expect(redirectIds($page))->toBe(array_slice($served, 2, 2));
    });

    it("reads another site's rules with that site's flag defaults, not the booting site's", function (): void {
        $other = makeSite();

        switch_to_blog($other);

        try {
            (new Latest)->install();
            activateRedirectPlugins();
            update_option('redirection_options', ['flag_case' => false, 'flag_trailing' => false]);
            Red_Options::reset();
            $rule = makeRedirectionRule(makeRedirectionGroup('Other')->get_id(), ['url' => '/on-the-other-site']);
        } finally {
            Red_Options::reset();
            restore_current_blog();
        }

        // The booting site ignores case, and its settings are cached, as they
        // are once Redirection has handled a request there.
        update_option('redirection_options', ['flag_case' => true, 'flag_trailing' => true]);
        Red_Options::reset();
        Red_Options::get();

        $row = redirectRow(redirectsGet('/redirects/redirection', ['site' => $other]), $rule->get_id());
        $source = is_array($row['match_data']) ? ($row['match_data']['source'] ?? null) : null;

        expect($row['url'])->toBe('/on-the-other-site')
            ->and(is_array($source) ? ($source['flag_case'] ?? null) : null)->toBeFalse()
            ->and(is_array($source) ? ($source['flag_trailing'] ?? null) : null)->toBeFalse();
    });

    it('answers an error, not an empty list, where the plugin is active and its tables are missing', function (): void {
        $response = redirectsGet('/redirects/redirection', ['site' => siteWithoutRedirectTables()]);

        expect($response->get_status())->toBe(500)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_tables_missing');
    });

    it('answers an error, not an empty list, when the rules cannot be read', function (): void {
        makeRedirectionRule(makeRedirectionGroup('Vanity')->get_id());
        breakQueriesOn(testDatabase()->prefix.'redirection_items');

        $response = redirectsGet('/redirects/redirection');

        expect($response->get_status())->toBe(500)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_read_failed');
    });

    it('answers an error, not a rule with no group, when the groups cannot be read', function (): void {
        makeRedirectionRule(makeRedirectionGroup('Vanity')->get_id());
        breakQueriesOn(testDatabase()->prefix.'redirection_groups');

        $response = redirectsGet('/redirects/redirection');

        expect($response->get_status())->toBe(500)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_read_failed');
    });

    it('refuses, through `?site=`, a site where the plugin is not active', function (): void {
        $response = redirectsGet('/redirects/redirection', ['site' => makeSite()]);

        expect($response->get_status())->toBe(404)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_plugin_not_active');
    });

    it('refuses a site where the plugin is not active', function (): void {
        update_option('active_plugins', [RedirectsController::PRETTY_LINKS_PLUGIN]);

        $response = redirectsGet('/redirects/redirection');

        expect($response->get_status())->toBe(404)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_plugin_not_active');
    });
});

describe('Pretty Links', function (): void {
    it('serves a link with its slug, target, type and the options a redirect cannot express', function (): void {
        $link = makePrettyLink([
            'slug' => 'cloaked',
            'url' => 'https://example.org/landing',
            'redirect_type' => 'cloak',
            'nofollow' => 1,
            'sponsored' => 1,
            'param_forwarding' => 'on',
            'new_window' => 1,
        ]);

        $response = everyPrettyLink();

        expect($response->get_status())->toBe(200);

        $row = redirectRow($response, prettyLinkId($link));

        expect($row['slug'])->toBe('cloaked')
            ->and($row['url'])->toBe('https://example.org/landing')
            ->and($row['redirect_type'])->toBe('cloak')
            ->and($row['nofollow'])->toBeTrue()
            ->and($row['sponsored'])->toBeTrue()
            ->and($row['param_forwarding'])->toBeTrue()
            ->and($row['new_window'])->toBeTrue()
            ->and($row['deleted_at'])->toBeNull()
            ->and($row)->toHaveKeys(['name', 'description', 'track_me', 'prettypay_link', 'source', 'created_at', 'updated_at', 'pretty_url']);
    });

    it('serves a plain 301 with its defaults off', function (): void {
        $link = makePrettyLink(['slug' => 'plain', 'nofollow' => 0, 'sponsored' => 0]);

        $row = redirectRow(everyPrettyLink(), prettyLinkId($link));

        expect($row['redirect_type'])->toBe('301')
            ->and($row['nofollow'])->toBeFalse()
            ->and($row['param_forwarding'])->toBeFalse();
    });

    it('carries no click or unique counter', function (): void {
        $link = makePrettyLink();

        $row = redirectRow(everyPrettyLink(), prettyLinkId($link));

        expect($row)->not->toHaveKey('clicks')
            ->and($row)->not->toHaveKey('uniques');
    });

    it("omits a trashed link, as the plugin's own list does", function (): void {
        $kept = makePrettyLink();
        $trashed = makePrettyLink();

        expect((new Links)->softDelete(prettyLinkId($trashed)))->toBeTrue();

        $ids = redirectIds(everyPrettyLink());

        expect($ids)->toContain(prettyLinkId($kept))
            ->and($ids)->not->toContain(prettyLinkId($trashed));
    });

    it("serves every link, ordered by id ascending, with totals matching the plugin's count", function (): void {
        for ($i = 0; $i < 5; $i++) {
            makePrettyLink();
        }

        $own = (new Links)->search(['status' => 'any', 'per_page' => 1, 'page' => 1]);
        $all = everyPrettyLink();
        $served = redirectIds($all);
        $sorted = $served;
        sort($sorted);

        expect($served)->toBe($sorted)
            ->and(count($served))->toBe($own['total'])
            ->and(headers($all)['X-WP-Total'] ?? null)->toBe((string) $own['total']);

        $page = redirectsGet('/redirects/pretty-links', ['per_page' => 2, 'page' => 2]);

        expect(redirectIds($page))->toBe(array_slice($served, 2, 2));
    });

    it('serves an empty page for a page past the integer range, not the first page', function (): void {
        makePrettyLink();

        $response = redirectsGet('/redirects/pretty-links', ['page' => PHP_INT_MAX]);

        expect($response->get_status())->toBe(200)
            ->and(redirectRows($response))->toBe([]);
    });

    it("reads another site's links, and only that site's", function (): void {
        $other = makeSite();

        switch_to_blog($other);

        try {
            activateRedirectPlugins();
            $path = constant('PRLI_PATH');
            assert(is_string($path));
            (new Migrator(testDatabase(), $path, Bootstrap::version()))->maybeRun();
            // Pretty Links installs its events table on `plugins_loaded`, so
            // only for the site that booted, and creating a link records an
            // event.
            testDatabase()->query('CREATE TEMPORARY TABLE '.testDatabase()->prefix.'prlidt_events LIKE '.testDatabase()->base_prefix.'prlidt_events');
            $there = makePrettyLink();
        } finally {
            restore_current_blog();
        }

        // Created only now, because the migration above runs `CREATE TABLE`,
        // and MySQL commits the open transaction before any DDL. A link made
        // before it outlived the test's rollback, one per run (#436).
        $here = makePrettyLink();

        $response = redirectsGet('/redirects/pretty-links', ['site' => $other, 'per_page' => 200]);
        $slugs = array_column(redirectRows($response), 'slug');

        expect($response->get_status())->toBe(200)
            ->and($slugs)->toBe([$there['slug']])
            ->and($slugs)->not->toContain($here['slug']);
    });

    it('answers an error, not an empty list, where the plugin is active and its tables are missing', function (): void {
        $response = redirectsGet('/redirects/pretty-links', ['site' => siteWithoutRedirectTables()]);

        expect($response->get_status())->toBe(500)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_tables_missing');
    });

    it('answers an error, not an empty list, when the links cannot be read', function (): void {
        makePrettyLink();
        breakQueriesOn(testDatabase()->prefix.'prli_links');

        $response = redirectsGet('/redirects/pretty-links');

        expect($response->get_status())->toBe(500)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_read_failed');
    });

    it('refuses, through `?site=`, a site where the plugin is not active', function (): void {
        $response = redirectsGet('/redirects/pretty-links', ['site' => makeSite()]);

        expect($response->get_status())->toBe(404)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_plugin_not_active');
    });

    it('refuses a site where the plugin is not active', function (): void {
        update_option('active_plugins', [RedirectsController::REDIRECTION_PLUGIN]);

        $response = redirectsGet('/redirects/pretty-links');

        expect($response->get_status())->toBe(404)
            ->and(errorCode($response))->toBe('uamswp_migration_api_redirect_plugin_not_active');
    });
});

describe('discovery', function (): void {
    it('advertises each route as available where the plugin is active', function (): void {
        $endpoints = payload(redirectsGet('/'), 'endpoints', 'redirects');

        expect($endpoints)->toMatchArray([
            'redirection' => ['collection' => '/redirects/redirection', 'available' => true, 'params' => ['page', 'per_page']],
            'pretty_links' => ['collection' => '/redirects/pretty-links', 'available' => true, 'params' => ['page', 'per_page']],
        ]);

        update_option('active_plugins', []);

        expect(payload(redirectsGet('/'), 'endpoints', 'redirects', 'redirection', 'available'))->toBeFalse()
            ->and(payload(redirectsGet('/'), 'endpoints', 'redirects', 'pretty_links', 'available'))->toBeFalse();
    });

    it('reports per site whether each plugin is active', function (): void {
        $other = makeSite();

        switch_to_blog($other);
        update_option('active_plugins', [RedirectsController::PRETTY_LINKS_PLUGIN]);
        restore_current_blog();

        $byId = redirectPluginsBySite();

        expect($byId[get_current_blog_id()] ?? null)->toBe(['redirection' => true, 'pretty_links' => true])
            ->and($byId[$other] ?? null)->toBe(['redirection' => false, 'pretty_links' => true]);
    });

    it('counts a network-activated plugin as active on every site', function (): void {
        $other = makeSite();
        update_site_option('active_sitewide_plugins', [RedirectsController::REDIRECTION_PLUGIN => time()]);

        $byId = redirectPluginsBySite();

        expect($byId[$other] ?? null)->toBe(['redirection' => true, 'pretty_links' => false]);
    });
});
