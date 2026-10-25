<?php

/**
 * Plugin bootstrap.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

use UAMSWP\MigrationAPI\Admin\TokenPage;
use UAMSWP\MigrationAPI\REST\AuthorsController;
use UAMSWP\MigrationAPI\REST\Controller;
use UAMSWP\MigrationAPI\REST\DiscoveryController;
use UAMSWP\MigrationAPI\REST\FormsController;
use UAMSWP\MigrationAPI\REST\IndexController;
use UAMSWP\MigrationAPI\REST\OptionsController;
use UAMSWP\MigrationAPI\REST\PostsController;
use UAMSWP\MigrationAPI\REST\RedirectsController;
use UAMSWP\MigrationAPI\REST\TermsController;

/**
 * Wires the plugin into WordPress.
 *
 * Read-only by design: no activation hook, no tables created. Removing the
 * plugin directory removes the plugin entirely, which is the point — this is
 * deleted when the migration completes.
 *
 * **One option is written, and it is the single exception.** `Token` stores the
 * hash of this network's API token, because #140 chose a plugin-issued
 * credential over the `wp-config.php` shared constant and a credential has to
 * live somewhere. It is written only when an operator issues a token from the
 * admin page — never on load, never by a request this API serves. Nothing else
 * here writes an option, and `tests/Unit/NetworkOptionGuardTest.php` is what
 * holds that line rather than this sentence. See #141 and `docs/DECISIONS.md`
 * D-063.
 *
 * The hash survives deleting the plugin directory, since there is no uninstall
 * hook. That is deliberate residue rather than an oversight: a stored hash
 * authenticates nothing on its own, and an uninstall hook that fired on a
 * temporary plugin's removal would be a write on a path this plugin otherwise
 * does not have.
 */
final class Plugin
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Registered controllers.
     *
     * @var list<Controller>
     */
    private array $controllers = [];

    /**
     * Guard against double bootstrapping.
     */
    private bool $booted = false;

    /**
     * Private constructor; use instance().
     */
    private function __construct() {}

    /**
     * Shared instance.
     */
    public static function instance(): self
    {
        if (! self::$instance instanceof Plugin) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Register hooks.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Site switch first (10), then the auth gate (11) so capabilities are
        // checked against the site actually being read.
        Site::register();
        add_filter('rest_pre_dispatch', [Auth::class, 'guardNamespace'], 11, 3);

        /*
         * Report that a credential was presented, BEFORE routing (#290).
         *
         * `rest_authentication_errors` is applied inside
         * `WP_REST_Server::check_authentication()`, which runs ahead of
         * `rest_pre_dispatch` and therefore ahead of the gate above. Answering
         * nothing leaves `null`, which tells WordPress no authentication was
         * attempted -- and a front-gate plugin reading `null === $result`
         * refuses the request there, so the gate never runs and a valid token
         * is never read.
         *
         * PRIORITY 10, deliberately and not by default. `wp-force-login`, the
         * plugin measured doing this on `inside.uams.edu/pass`, registers at 99
         * and yields on a non-null verdict -- so answering at 10 lands ahead of
         * it and is respected rather than overridden. It also means this
         * plugin never overrules a gate that decided first, which is the
         * behavior the filter's own contract asks for.
         */
        add_filter('rest_authentication_errors', [Auth::class, 'answerAuthenticationErrors'], 10, 1);

        add_action('rest_api_init', [$this, 'registerRoutes']);

        // The only surface outside REST. Registers a menu entry and nothing
        // else; issuing a token requires a deliberate POST from that page.
        TokenPage::register();

        /*
         * Backstop for the site switch, and still load-bearing -- but not for
         * the reason this comment used to give (#276).
         *
         * IT SAID a `WP_Error` returned from `rest_pre_dispatch` skips
         * `rest_post_dispatch` entirely. Measured against the live install, it
         * does not: an unauthenticated request is refused by the namespace gate
         * at `rest_pre_dispatch` priority 11 and still comes back carrying
         * `X-UAMSWP-Site`, which is stamped on `rest_post_dispatch`. That hook
         * runs in `serve_request()` regardless of how `dispatch()` returned.
         *
         * WHAT ACTUALLY REACHES THIS is the exit `Site::register()` already
         * names and leaves here: a request into this namespace matching no
         * route. Measured -- authenticated, `?site=` naming another site, a
         * route that does not exist -- the response is a 404 and the blog is
         * STILL SWITCHED afterwards. `tests/Integration/Feature/AnsweringSiteHeaderTest.php`
         * pins it, so the backstop cannot be removed on the strength of a
         * comment again.
         */
        add_action('shutdown', [Site::class, 'restore'], 0);
    }

    /**
     * Instantiate and register every controller.
     */
    public function registerRoutes(): void
    {
        foreach ($this->controllers() as $controller) {
            $controller->registerRoutes();
        }
    }

    /**
     * Keep only real controllers from an untrusted filter result.
     *
     * Same reasoning as Config::asConfig() — the WordPress stubs type
     * `apply_filters()` as returning its input, so filtering by `instanceof`
     * reads as always-true to the analyzer. A filter can return anything, so
     * the check protects a real case.
     *
     * @param  mixed  $value  Whatever the filter returned.
     * @return list<Controller>
     */
    private static function onlyControllers(mixed $value): array
    {
        if (! \is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                $value,
                static fn (mixed $controller): bool => $controller instanceof Controller
            )
        );
    }

    /**
     * Controllers served by this plugin.
     *
     * Endpoint controllers are appended here as each lands.
     *
     * @return list<Controller>
     */
    private function controllers(): array
    {
        if ($this->controllers !== []) {
            return $this->controllers;
        }

        $controllers = [
            new AuthorsController,
            new DiscoveryController,
            new FormsController,
            new IndexController,
            new OptionsController,
            new PostsController,
            new RedirectsController,
            new TermsController,
        ];

        /**
         * Filters the controllers registered by the migration API.
         *
         * @param  list<Controller>  $controllers  Controller instances.
         */
        $this->controllers = self::onlyControllers(
            apply_filters('uamswp_migration_api_controllers', $controllers)
        );

        return $this->controllers;
    }
}
