<?php

/**
 * Plugin Name:       UAMSWP Migration API
 * Plugin URI:        https://github.com/UAMS-Web/uamswp-migration-api
 * Description:       Read-only migration API serving raw, current WordPress content to the Statamic importer. Temporary — remove when the migration completes.
 * Version:           0.7.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            UAMS Web
 * Author URI:        https://github.com/UAMS-Web
 * License:           GPLv2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uamswp-migration-api
 * Network:           true
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

// No direct access.
if (! \defined('ABSPATH')) {
    exit;
}

/*
 * Production runs PHP 8.2.30. Bail rather than fatal on an older stack —
 * WordPress honors the `Requires PHP` header on activation, but a site can be
 * downgraded underneath an already-active plugin.
 */
if (version_compare(PHP_VERSION, '8.2', '<')) {
    add_action(
        'admin_notices',
        static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %s: current PHP version. */
                        __('UAMSWP Migration API requires PHP 8.2 or later. This site runs %s, so the plugin is inactive.', 'uamswp-migration-api'),
                        PHP_VERSION
                    )
                )
            );
        }
    );

    return;
}

const VERSION = '0.1.0';
const REST_NAMESPACE = 'uamswp-migration/v1';

define(__NAMESPACE__.'\PLUGIN_FILE', __FILE__);
define(__NAMESPACE__.'\PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Autoload plugin classes from includes/.
 *
 * Deliberately hand-rolled rather than Composer: this plugin has no third-party
 * dependencies and is deleted when the migration completes, so a vendor/ tree
 * would be pure overhead.
 */
spl_autoload_register(
    static function (string $class): void {
        $prefix = __NAMESPACE__.'\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, \strlen($prefix));
        $path = PLUGIN_DIR.'includes/'.str_replace('\\', '/', $relative).'.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
);

Plugin::instance()->boot();
