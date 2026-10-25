<?php

/**
 * The admin page that issues the network API token, against a real WordPress.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Admin\TokenPage;
use UAMSWP\MigrationAPI\Auth;
use UAMSWP\MigrationAPI\Token;

/*
 * Pins what `TokenPage` does today (#363): who may reach it, what a valid and an
 * invalid submission do, and what the page says about the current and previous
 * token. The page renders straight to output, so each test captures it.
 *
 * **This suite runs as multisite, so the single-site branches are not reached.**
 * `register()` and `addPage()` choose `admin_menu` and `add_options_page()` when
 * `is_multisite()` is false, and `is_multisite()` reads a constant fixed for the
 * process (see `TokenStorageTest.php`). Those two lines stay unexercised here.
 *
 * **Checked by hand-applied mutations, not `--mutate`.** `Plugin::boot()` calls
 * `TokenPage::register()` during the test bootstrap, so the class is loaded
 * before the mutation runner starts and cannot be mutated (D-023). Ten edits to
 * `TokenPage.php` were applied one at a time; nine failed a test here. The one
 * that survives turns `expires <= time()` into `expires < time()` in
 * `renderOverlap()`: it differs only when `expires` equals the current second
 * at render time, which a test cannot arrange without a clock seam, because the
 * second has usually passed by the time the page renders.
 */

/**
 * Render the page and return what it printed.
 */
function renderTokenPage(): string
{
    ob_start();

    try {
        TokenPage::render();
    } finally {
        $output = (string) ob_get_clean();
    }

    return $output;
}

/**
 * Make the current request a form submission carrying the given nonce.
 */
function submitTokenForm(string $nonce): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['uamswp_migration_api_token_nonce'] = $nonce;
    $_REQUEST['uamswp_migration_api_token_nonce'] = $nonce;
}

/**
 * The page's admin functions live in files core loads only for admin requests.
 */
function loadAdminFunctions(): void
{
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    require_once ABSPATH.'wp-admin/includes/template.php';
}

beforeEach(function (): void {
    loadAdminFunctions();
    delete_network_option(null, Token::OPTION);
    unset($_POST['uamswp_migration_api_token_nonce'], $_REQUEST['uamswp_migration_api_token_nonce']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

afterEach(function (): void {
    unset($_POST['uamswp_migration_api_token_nonce'], $_REQUEST['uamswp_migration_api_token_nonce']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

it('registers on the network admin menu on a multisite install', function (): void {
    remove_action('network_admin_menu', [TokenPage::class, 'addPage']);
    remove_action('admin_menu', [TokenPage::class, 'addPage']);

    TokenPage::register();

    expect(has_action('network_admin_menu', [TokenPage::class, 'addPage']))->toBe(10)
        ->and(has_action('admin_menu', [TokenPage::class, 'addPage']))->toBeFalse();
});

it('adds the page under network settings, gated on the capability the API reads', function (): void {
    actAsSuperAdmin();

    TokenPage::addPage();

    $submenu = $GLOBALS['submenu'] ?? null;
    $entries = \is_array($submenu) && \is_array($submenu['settings.php'] ?? null) ? $submenu['settings.php'] : [];
    $entry = array_values(array_filter($entries, static fn (mixed $item): bool => \is_array($item) && ($item[2] ?? null) === 'uamswp-migration-api-token'))[0] ?? null;

    expect($entry)->toBeArray()
        ->and(\is_array($entry) ? $entry[0] : null)->toBe('Migration API token')
        ->and(\is_array($entry) ? $entry[1] : null)->toBe(Auth::capability())
        ->and(Auth::capability())->toBe('manage_network_options');
});

it('refuses a user without the capability with a 403 and issues nothing', function (): void {
    actAs('administrator');

    try {
        renderTokenPage();
        $this->fail('The page rendered for a user without the capability.');
    } catch (WPDieException $wpDieException) {
        expect($wpDieException->getCode())->toBe(403)
            ->and($wpDieException->getMessage())->toBe('You do not have permission to manage the migration API token.');
    }

    expect(Token::record())->toBeNull();
});

it('offers to generate a token when none has been issued', function (): void {
    actAsSuperAdmin();

    $output = renderTokenPage();

    expect($output)->toContain('<h1>Migration API token</h1>')
        ->and($output)->toContain('No token has been issued for this network.')
        ->and($output)->toContain('value="Generate token"')
        ->and($output)->toContain('name="uamswp_migration_api_token_nonce"')
        ->and($output)->not->toContain('Regenerating issues a new token')
        ->and(Token::record())->toBeNull();
});

it('issues a token on a valid submission and shows it once', function (): void {
    actAsSuperAdmin();
    submitTokenForm(wp_create_nonce('uamswp_migration_api_issue_token'));

    $output = renderTokenPage();

    $record = Token::record();
    $current = \is_array($record) && \is_array($record['current'] ?? null) ? $record['current'] : [];
    $identifier = \is_string($current['identifier'] ?? null) ? $current['identifier'] : '';

    expect(preg_match('#<p><code>([0-9a-f]{64})</code></p>#', $output, $match))->toBe(1)
        ->and(Token::matches($match[1] ?? ''))->toBeTrue()
        ->and($identifier)->not->toBe('')
        ->and($output)->toContain('Copy this token now. It is not shown again and is not recoverable.')
        ->and($output)->toContain('Identifier: <code>'.$identifier.'</code>')
        ->and($output)->toContain('Current token: <code>'.$identifier.'</code>')
        // Just issued: the button reads "Regenerate", and the rotation note,
        // which describes regenerating an existing token, is left out.
        ->and($output)->toContain('value="Regenerate token"')
        ->and($output)->not->toContain('Regenerating issues a new token');
});

it('ignores a POST that carries no nonce field', function (): void {
    actAsSuperAdmin();
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $output = renderTokenPage();

    expect(Token::record())->toBeNull()
        ->and($output)->not->toContain('Copy this token now.');
});

it('ignores the nonce field on a GET request', function (): void {
    actAsSuperAdmin();
    submitTokenForm(wp_create_nonce('uamswp_migration_api_issue_token'));
    $_SERVER['REQUEST_METHOD'] = 'GET';

    renderTokenPage();

    expect(Token::record())->toBeNull();
});

it('refuses a submission with a bad nonce and issues nothing', function (): void {
    actAsSuperAdmin();
    submitTokenForm('not-a-nonce');

    try {
        renderTokenPage();
        $this->fail('A submission with a bad nonce rendered the page.');
    } catch (WPDieException $wpDieException) {
        expect($wpDieException->getCode())->toBe(403);
    }

    expect(Token::record())->toBeNull();
});

it('describes the current token and offers to regenerate it', function (): void {
    actAsSuperAdmin();
    update_option('date_format', 'Y-m-d');
    update_option('time_format', 'H:i');
    $issued = Token::issue();

    $output = renderTokenPage();

    $record = Token::record();
    $current = \is_array($record) && \is_array($record['current'] ?? null) ? $record['current'] : [];
    $created = \is_int($current['created'] ?? null) ? $current['created'] : 0;

    expect($output)->toContain('Current token: <code>'.$issued['identifier']."</code> \u{2014} issued ".wp_date('Y-m-d H:i', $created))
        ->and($output)->toContain('Regenerating issues a new token and keeps the current one working for 1 hour,')
        ->and($output)->toContain('value="Regenerate token"')
        ->and($output)->not->toContain($issued['token'])
        ->and($output)->not->toContain('is still accepted until');
});

it('names the previous token while its overlap window is open', function (): void {
    actAsSuperAdmin();
    update_option('date_format', 'Y-m-d');
    update_option('time_format', 'H:i');
    $first = Token::issue();
    Token::issue();

    $record = Token::record();
    $previous = \is_array($record) && \is_array($record['previous'] ?? null) ? $record['previous'] : [];
    $expires = \is_int($previous['expires'] ?? null) ? $previous['expires'] : 0;

    expect(renderTokenPage())->toContain('The previous token <code>'.$first['identifier'].'</code> is still accepted until '.wp_date('Y-m-d H:i', $expires).'.');
});

it('leaves out a previous token whose window has closed or whose expiry is malformed', function (mixed $expires): void {
    actAsSuperAdmin();
    update_network_option(null, Token::OPTION, [
        'current' => ['identifier' => 'aaaa1111', 'hash' => 'x', 'created' => time()],
        'previous' => ['identifier' => 'bbbb2222', 'hash' => 'y', 'expires' => $expires],
    ]);

    expect(renderTokenPage())->not->toContain('bbbb2222');
})->with([
    'expired' => [time() - 1],
    'not an integer' => ['tomorrow'],
]);

it('renders a current token whose identifier and created time are malformed', function (): void {
    actAsSuperAdmin();
    update_network_option(null, Token::OPTION, [
        'current' => ['identifier' => 42, 'created' => 'yesterday'],
    ]);

    $output = renderTokenPage();

    expect($output)->toContain('Current token: <code></code></p>')
        ->and($output)->not->toContain('issued');
});

it('treats a record without a current token as none issued', function (): void {
    actAsSuperAdmin();
    update_network_option(null, Token::OPTION, ['previous' => null]);

    $output = renderTokenPage();

    expect($output)->toContain('No token has been issued for this network.')
        ->and($output)->toContain('value="Generate token"');
});

it('falls back to a fixed date format when the stored formats are not strings', function (): void {
    actAsSuperAdmin();
    // `update_option()` sanitizes these two into strings, so the malformed
    // values are served through `pre_option_` filters instead. The test case
    // restores hooks after each test.
    add_filter('pre_option_date_format', static fn (): array => ['not', 'a', 'string']);
    add_filter('pre_option_time_format', static fn (): int => 7);
    Token::issue();

    $record = Token::record();
    $current = \is_array($record) && \is_array($record['current'] ?? null) ? $record['current'] : [];
    $created = \is_int($current['created'] ?? null) ? $current['created'] : 0;

    expect(renderTokenPage())->toContain('issued '.wp_date('Y-m-d H:i', $created));
});
