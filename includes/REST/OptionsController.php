<?php

/**
 * Site options endpoint.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\REST;

use UAMSWP\MigrationAPI\Content\OptionsPayload;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /options` — the site's whole options table.
 *
 * The starkest of the three findings that retired WXR: **zero of 259 captured
 * exports contain a `<wp:option>` element**, because WXR has no element for
 * options at all. Every site migrated to date silently dropped its settings —
 * the UAMS and Find-a-Doc settings panels, the nine Find-a-Doc options pages,
 * and `page_for_posts` among them.
 *
 * Unpaginated, deliberately. The table is one page of a few thousand rows on
 * this network, a consumer wants all of it or none, and the pagination that
 * matters (D-006) exists to stop a record moving between pages while editors
 * work — which is a problem for an ordered collection of posts, not for a
 * key-value table read in one shot.
 */
final class OptionsController extends Controller
{
    /**
     * Register routes.
     */
    public function registerRoutes(): void
    {
        $this->registerReadRoute('/options', [$this, 'index']);
    }

    /**
     * The site's options, minus the reviewed denylist, plus ACF's view of them.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return new WP_REST_Response(OptionsPayload::build());
    }
}
