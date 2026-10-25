<?php

/**
 * Admin page for issuing the network API token.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Admin;

use UAMSWP\MigrationAPI\Auth;
use UAMSWP\MigrationAPI\Token;

/**
 * The page an operator uses to issue and rotate this network's API token.
 *
 * **Location and capability follow `Auth::capability()` rather than restating
 * it.** The page and the API must not drift: a page reachable by someone the
 * API would refuse issues a credential its holder could not otherwise obtain,
 * and a page stricter than the API is a setup procedure nobody can complete.
 * One expression, read by both.
 *
 * | | Multisite | Single-site |
 * | --- | --- | --- |
 * | Hook | `network_admin_menu` | `admin_menu` |
 * | Location | `/wp-admin/network/` | `/wp-admin/` |
 * | Capability | `manage_network_options` | `manage_options` |
 *
 * **`Auth::capability()` is filterable, and that filter now reaches further
 * than it did.** `uamswp_migration_api_capability` was a control over who may
 * READ; this page makes it a control over who may MINT A CREDENTIAL, and a
 * token outlives the account that issued it. Lowering the filter therefore
 * grants something that does not end when the user is removed. Deliberate, and
 * recorded rather than quietly avoided: the ticket asks for one expression so
 * the page and the API cannot drift, and the alternative — pinning the page to
 * the unfiltered baseline — means a deployment that raises the bar can mint
 * tokens it cannot use. The filter is only reachable by code already installed
 * on the site, which is a higher privilege than any token grants, so the
 * exposure is a misconfiguration rather than an attack surface. See
 * `docs/DECISIONS.md` D-063.
 *
 * **The plaintext is rendered in the response to the request that generated it
 * and is never stored, not even briefly.** The redirect-and-transient pattern
 * usual for an admin form would put the credential in the options table, which
 * on a single-site install is the table `GET /options` serves — the disclosure
 * this whole design exists to avoid. So the form posts to the page and the
 * result renders inline.
 */
final class TokenPage
{
    /**
     * Page slug.
     */
    private const SLUG = 'uamswp-migration-api-token';

    /**
     * Nonce action for the generate form.
     */
    private const NONCE_ACTION = 'uamswp_migration_api_issue_token';

    /**
     * Nonce field name.
     */
    private const NONCE_NAME = 'uamswp_migration_api_token_nonce';

    /**
     * Register the page on whichever admin this install has.
     */
    public static function register(): void
    {
        add_action(is_multisite() ? 'network_admin_menu' : 'admin_menu', [self::class, 'addPage']);
    }

    /**
     * Add the menu entry.
     */
    public static function addPage(): void
    {
        $title = __('Migration API token', 'uamswp-migration-api');

        if (is_multisite()) {
            add_submenu_page(
                'settings.php',
                $title,
                $title,
                Auth::capability(),
                self::SLUG,
                [self::class, 'render']
            );

            return;
        }

        add_options_page(
            $title,
            $title,
            Auth::capability(),
            self::SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Render the page, issuing a token first when the form was submitted.
     */
    public static function render(): void
    {
        // `add_submenu_page` already gates the page on this capability. Checked
        // again here because the callback is a public method on a loaded class
        // and nothing about that guarantees it was reached through the menu.
        if (! current_user_can(Auth::capability())) {
            wp_die(
                esc_html__('You do not have permission to manage the migration API token.', 'uamswp-migration-api'),
                '',
                ['response' => 403]
            );
        }

        $issued = self::handleSubmission();

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Migration API token', 'uamswp-migration-api').'</h1>';

        self::renderIssued($issued);
        self::renderCurrent();
        self::renderForm($issued !== null);

        echo '</div>';
    }

    /**
     * Issue a token when this request is a valid submission.
     *
     * @return array{token: string, identifier: string}|null
     */
    private static function handleSubmission(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return null;
        }

        if (! isset($_POST[self::NONCE_NAME])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        return Token::issue();
    }

    /**
     * Show a freshly issued token. This is the only time it is displayed.
     *
     * @param  array{token: string, identifier: string}|null  $issued  Newly issued token.
     */
    private static function renderIssued(?array $issued): void
    {
        if ($issued === null) {
            return;
        }

        echo '<div class="notice notice-success"><p><strong>';
        echo esc_html__('Copy this token now. It is not shown again and is not recoverable.', 'uamswp-migration-api');
        echo '</strong></p><p><code>'.esc_html($issued['token']).'</code></p><p>';
        printf(
            /* translators: %s: the token's public identifier. */
            esc_html__('Identifier: %s', 'uamswp-migration-api'),
            '<code>'.esc_html($issued['identifier']).'</code>'
        );
        echo '</p></div>';
    }

    /**
     * Describe the token this network currently holds, if any.
     */
    private static function renderCurrent(): void
    {
        $record = Token::record();
        $current = \is_array($record) ? ($record['current'] ?? null) : null;

        if (! \is_array($current)) {
            echo '<p>'.esc_html__('No token has been issued for this network.', 'uamswp-migration-api').'</p>';

            return;
        }

        $identifier = \is_string($current['identifier'] ?? null) ? $current['identifier'] : '';
        $created = \is_int($current['created'] ?? null) ? $current['created'] : null;

        echo '<p>';
        printf(
            /* translators: %s: the token's public identifier. */
            esc_html__('Current token: %s', 'uamswp-migration-api'),
            '<code>'.esc_html($identifier).'</code>'
        );

        if ($created !== null) {
            echo ' — ';
            printf(
                /* translators: %s: date the token was issued. */
                esc_html__('issued %s', 'uamswp-migration-api'),
                esc_html(self::formatTimestamp($created))
            );
        }

        echo '</p>';

        self::renderOverlap($record);
    }

    /**
     * Describe a rotated-away token that is still accepted.
     *
     * Worth showing rather than hiding: during the window two credentials
     * authenticate, and an operator deciding whether a rotation is complete
     * needs to know that.
     *
     * @param  array<string, mixed>  $record  Stored record.
     */
    private static function renderOverlap(array $record): void
    {
        $previous = $record['previous'] ?? null;

        if (! \is_array($previous) || ! \is_int($previous['expires'] ?? null)) {
            return;
        }

        if ($previous['expires'] <= time()) {
            return;
        }

        echo '<p>';
        printf(
            /* translators: 1: the previous token's identifier, 2: expiry time. */
            esc_html__('The previous token %1$s is still accepted until %2$s.', 'uamswp-migration-api'),
            '<code>'.esc_html(\is_string($previous['identifier'] ?? null) ? $previous['identifier'] : '').'</code>',
            esc_html(self::formatTimestamp($previous['expires']))
        );
        echo '</p>';
    }

    /**
     * A timestamp in the site's configured date and time format.
     *
     * `get_option()` returns mixed and `wp_date()` returns `string|false`, so
     * both are narrowed here rather than at each call site.
     */
    private static function formatTimestamp(int $timestamp): string
    {
        $date = get_option('date_format');
        $time = get_option('time_format');

        // Both options are stored by core and are normally strings, but an
        // install can hold anything there. Falling back keeps a malformed
        // setting from turning this page into a fatal.
        $format = (\is_string($date) ? $date : 'Y-m-d').' '.(\is_string($time) ? $time : 'H:i');

        return (string) wp_date($format, $timestamp);
    }

    /**
     * Render the generate/regenerate form.
     *
     * @param  bool  $justIssued  Whether a token was issued in this request.
     */
    private static function renderForm(bool $justIssued): void
    {
        $record = Token::record();
        $hasToken = \is_array($record) && \is_array($record['current'] ?? null);

        $label = $hasToken
            ? __('Regenerate token', 'uamswp-migration-api')
            : __('Generate token', 'uamswp-migration-api');

        if ($hasToken && ! $justIssued) {
            echo '<p>';
            printf(
                /* translators: %s: the overlap window, already formatted. */
                esc_html__('Regenerating issues a new token and keeps the current one working for %s, so the consumer can be updated without a refused request in between.', 'uamswp-migration-api'),
                esc_html(human_time_diff(0, Token::rotationWindow()))
            );
            echo '</p>';
        }

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        submit_button($label);
        echo '</form>';
    }
}
