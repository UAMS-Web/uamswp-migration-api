<?php

/**
 * Discovery endpoints.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use UAMSWP\MigrationAPI\Content\DiscoveryPayload;
use UAMSWP\MigrationAPI\Site;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /sites`, `GET /post-types`, `GET /taxonomies`.
 *
 * So a migration can be planned against what a site actually holds rather than
 * a list someone maintained by hand. Between them these replace WXR's channel
 * header and the importer's `XmlParser::getContentTypes()` and
 * `getBaseBlogUrl()`.
 *
 * `/sites` is network-wide and ignores the `site` parameter — it describes
 * every site, which is the question a consumer asks *before* it knows which
 * site to request. Its per-site values are options and counts, both of which
 * `switch_to_blog()` genuinely changes.
 *
 * **`/post-types` and `/taxonomies` refuse the `site` parameter**, and that is
 * the interesting decision here. Post-type and taxonomy registration is
 * *process-global*, not per-site: plugins register on `init` for whichever site
 * booted the request, and `switch_to_blog()` does not re-run that. Measured —
 * 16 types before a switch and 16 after, and a type registered while switched
 * is still registered once restored.
 *
 * Serving `?site=N` here would therefore return the **booted** site's
 * registrations alongside site N's **counts**: a well-formed response mixing
 * two sites, which is this project's defining failure mode. Routes are
 * registered on every site in the network, so the correct way to ask site N
 * what it registers is to ask site N — through its own REST root.
 */
final class DiscoveryController extends Controller
{
    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute('/sites', [$this, 'sites']);
        $this->registerReadRoute('/post-types', [$this, 'postTypes']);
        $this->registerReadRoute('/taxonomies', [$this, 'taxonomies']);
    }

    /**
     * Every site in the network, with its WXR channel header and counts.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function sites(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return new WP_REST_Response(DiscoveryPayload::sites());
    }

    /**
     * Post types registered on the resolved site.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function postTypes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $refusal = self::refuseCrossSite($request, 'post types');

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        return new WP_REST_Response(DiscoveryPayload::postTypes());
    }

    /**
     * Taxonomies registered on the resolved site.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function taxonomies(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $refusal = self::refuseCrossSite($request, 'taxonomies');

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        return new WP_REST_Response(DiscoveryPayload::taxonomies());
    }

    /**
     * Refuse a cross-site request for something registration-scoped.
     *
     * Returns `null` when the request is for the site that booted it — which
     * includes the ordinary case of no `site` parameter at all.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    private static function refuseCrossSite(WP_REST_Request $request, string $what): ?WP_Error
    {
        /*
         * `Site::isSwitched()`, not a comparison against `get_current_blog_id()`:
         * the switch happens on `rest_pre_dispatch`, so by the time a handler
         * runs the current blog already *is* the requested one and the
         * comparison is always false. What matters is whether we are standing
         * somewhere other than where the registry was built.
         */
        if (! Site::isSwitched()) {
            return null;
        }

        $requested = $request['site'] ?? null;

        return new WP_Error(
            'uamswp_migration_api_registration_is_not_site_scoped',
            sprintf(
                /* translators: 1: what is being asked for, 2: the requested site id. */
                __('Registered %1$s cannot be read for another site. Registration is process-global — plugins register on `init` for the site that booted the request, and switching sites does not re-run that — so serving this would mix one site\'s registrations with site %2$d\'s counts. Ask site %2$d through its own REST root instead; this namespace is registered on every site in the network.', 'uamswp-migration-api'),
                $what,
                is_numeric($requested) ? (int) $requested : get_current_blog_id()
            ),
            ['status' => 400]
        );
    }
}
