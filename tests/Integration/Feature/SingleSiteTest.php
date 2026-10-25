<?php

/**
 * The plugin on a single-site install, against a real WordPress (#14).
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Admin\TokenPage;
use UAMSWP\MigrationAPI\Auth;
use UAMSWP\MigrationAPI\Site;
use UAMSWP\MigrationAPI\Token;

/*
 * Every `! is_multisite()` branch in `includes/` runs here, in the process
 * `composer test:integration:single-site` starts. On a network the whole file
 * skips, and the network suite covers the other branch of each.
 *
 * The token is the sharpest of them. `update_network_option()` falls through to
 * `update_option()` on a single site, so the token's hash is stored in
 * `wp_options`, the table `GET /options` serves. These tests issue a real token
 * and dispatch the real request, rather than placing a record by hand.
 */

/**
 * Dispatch a GET in this plugin's namespace, with optional parameters.
 *
 * @param  array<string, mixed>  $params
 */
function singleSiteGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    delete_option(Token::OPTION);
});

it('requires `manage_options`, the single-site capability', function (): void {
    expect(Auth::capability())->toBe('manage_options');
})->skip(is_multisite(), singleSiteOnly('the capability is a site capability only on a single site'));

it('admits an administrator, who holds `manage_options`', function (): void {
    actAs('administrator');

    expect(current_user_can('manage_options'))->toBeTrue()
        ->and(singleSiteGet('/')->get_status())->toBe(200);
})->skip(is_multisite(), singleSiteOnly('a site administrator is authorized only on a single site'));

it('refuses an editor, who does not', function (): void {
    actAs('editor');

    $response = singleSiteGet('/');

    expect(current_user_can('manage_options'))->toBeFalse()
        ->and($response->get_status())->toBe(403)
        ->and(errorCode($response))->toBe('uamswp_migration_api_forbidden');
})->skip(is_multisite(), singleSiteOnly('the editor refusal pairs with the administrator admission'));

it('stores the token record in `wp_options`, and the hash rather than the token', function (): void {
    $issued = Token::issue();
    $stored = get_option(Token::OPTION);

    expect($stored)->toBeArray()
        ->and(serialize($stored))->not->toContain($issued['token']);
})->skip(is_multisite(), singleSiteOnly('only a single site stores the record in wp_options'));

it('does not serve the token record from `GET /options`', function (): void {
    actAs('administrator');
    Token::issue();

    $stored = get_option(Token::OPTION);
    $hash = \is_array($stored) && \is_array($stored['current'] ?? null) ? $stored['current']['hash'] ?? null : null;
    $hash = \is_string($hash) ? $hash : '';

    // The positive control: an ordinary option is served, so an absence below
    // is the denylist withholding the record and not an endpoint serving
    // nothing.
    update_option('uamswp_migration_api_single_site_probe', 'served');

    $response = singleSiteGet('/options');
    $options = payload($response, 'options');

    expect($response->get_status())->toBe(200)
        ->and($hash)->toHaveLength(64)
        ->and($options)->toBeArray()
        ->and(\is_array($options) ? array_keys($options) : [])->toContain('uamswp_migration_api_single_site_probe')
        ->and(\is_array($options) ? array_keys($options) : [])->not->toContain(Token::OPTION)
        // Searched across the whole payload, not only under the option's
        // name, so a copy of the hash under any other key would fail too.
        ->and((string) wp_json_encode($response->get_data()))->not->toContain($hash);
})->skip(is_multisite(), singleSiteOnly('only a single site stores the record in the table this endpoint serves'));

it('describes the one site on `GET /sites`', function (): void {
    actAs('administrator');

    $response = singleSiteGet('/sites');
    $sites = $response->get_data();

    expect($response->get_status())->toBe(200)
        ->and($sites)->toBeArray()
        ->and(\is_array($sites) ? \count($sites) : 0)->toBe(1)
        ->and(payload($response, '0', 'id'))->toBe(get_current_blog_id())
        // `wxr_site_url()`'s single-site rule, where a network uses
        // `network_home_url()`.
        ->and(payload($response, '0', 'base_site_url'))->toBe(get_bloginfo_rss('url'));
})->skip(is_multisite(), singleSiteOnly('a network lists every site'));

it('reports a single site in the namespace index, and names it in the header', function (): void {
    actAs('administrator');

    $response = singleSiteGet('/');

    expect($response->get_status())->toBe(200)
        ->and(payload($response, 'site', 'id'))->toBe(get_current_blog_id())
        ->and(payload($response, 'site', 'is_multisite'))->toBeFalse()
        ->and(headers($response)[Site::SITE_HEADER] ?? null)->toBe((string) get_current_blog_id());
})->skip(is_multisite(), singleSiteOnly('SiteScopingTest.php covers the index on a network'));

it('refuses `?site=` with a 400, for this site as for any other id', function (int $offset): void {
    actAs('administrator');

    $response = singleSiteGet('/options', ['site' => get_current_blog_id() + $offset]);

    expect($response->get_status())->toBe(400)
        ->and(errorCode($response))->toBe('uamswp_migration_api_not_multisite');
})->with([
    'this site' => [0],
    'another id' => [1],
])->skip(is_multisite(), singleSiteOnly('a network resolves `?site=`'));

it('registers the token page on the site admin menu', function (): void {
    remove_action('network_admin_menu', [TokenPage::class, 'addPage']);
    remove_action('admin_menu', [TokenPage::class, 'addPage']);

    TokenPage::register();

    expect(has_action('admin_menu', [TokenPage::class, 'addPage']))->toBe(10)
        ->and(has_action('network_admin_menu', [TokenPage::class, 'addPage']))->toBeFalse();
})->skip(is_multisite(), singleSiteOnly('a network registers on the network admin menu'));

it('adds the token page under Settings, gated on `manage_options`', function (): void {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    actAs('administrator');

    TokenPage::addPage();

    $submenu = $GLOBALS['submenu'] ?? null;
    $entries = \is_array($submenu) && \is_array($submenu['options-general.php'] ?? null) ? $submenu['options-general.php'] : [];
    $entry = array_values(array_filter($entries, static fn (mixed $item): bool => \is_array($item) && ($item[2] ?? null) === 'uamswp-migration-api-token'))[0] ?? null;

    expect($entry)->toBeArray()
        ->and(\is_array($entry) ? $entry[1] : null)->toBe('manage_options');
})->skip(is_multisite(), singleSiteOnly('a network adds the page under network settings'));

it('refuses the token page to an editor with a 403 and issues nothing', function (): void {
    actAs('editor');

    try {
        TokenPage::render();
        $this->fail('The page rendered for a user without `manage_options`.');
    } catch (WPDieException $wpDieException) {
        expect($wpDieException->getCode())->toBe(403);
    }

    expect(Token::record())->toBeNull();
})->skip(is_multisite(), singleSiteOnly('TokenPageTest.php covers the refusal on a network'));
