<?php

/**
 * A plugin must not be able to remove records from a migration read.
 */

declare(strict_types=1);

/*
 * D-004 says raw, not curated. The `posts_*` clause filters are every plugin's
 * opportunity to disagree, and on this network GeoTargeting WP takes it: it
 * hooks `posts_where` at `PHP_INT_MAX` to exclude posts from the loop based on
 * where it believes the visitor is (`GeotWP_Public::handle_geotargeted_posts`).
 *
 * A migration that silently ships fewer records than the site holds is this
 * repo's defining failure mode — a well-formed response full of wrong data,
 * with nothing red anywhere. `suppress_filters` is what stops it, and this is
 * what stops the flag being flipped back without the argument being had again.
 */

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves a post that a posts_where filter tries to remove', function (): void {
    $kept = makePost(['post_title' => 'Ordinary']);
    $hidden = makePost(['post_title' => 'A plugin would rather you did not see this']);

    /*
     * The same shape GeoTargeting uses — highest priority, excluding by id.
     * Registered after the posts exist so it cannot affect their creation.
     */
    $filter =
        // `testDatabase()` rather than `global $wpdb`, which is `mixed` to the
        // analyzer — the same narrowing every other test in the suite uses.
        (static fn (string $where): string => $where.' AND '.testDatabase()->posts.'.ID != '.$hidden);

    add_filter('posts_where', $filter, PHP_INT_MAX);

    try {
        $request = new WP_REST_Request('GET', '/uamswp-migration/v1/posts/post');
        $request->set_param('per_page', 100);

        $response = rest_do_request($request);
        $data = $response->get_data();

        assert(is_array($data), 'the posts collection did not return a list');

        $ids = [];

        foreach ($data as $record) {
            if (is_array($record) && is_numeric($record['id'] ?? null)) {
                $ids[] = (int) $record['id'];
            }
        }

        // Both, not one. The filter is real and would exclude it.
        expect($ids)->toContain($kept);
        expect($ids)->toContain($hidden);

        /*
         * And the total agrees. `countMatching()` runs its own `WP_Query` from
         * the same arguments, so a flag set on one and not the other would
         * report a count that disagrees with the records beside it — which is
         * the same silent-shortfall failure wearing a different hat.
         */
        $total = $response->get_headers()['X-WP-Total'] ?? null;

        expect(is_numeric($total) ? (int) $total : -1)->toBe(\count($ids));
    } finally {
        remove_filter('posts_where', $filter, PHP_INT_MAX);
    }
});
