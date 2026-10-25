<?php

/**
 * Two different 404s, because they are two different facts.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;

/*
 * D-034 established that post-type and taxonomy registration is process-global:
 * it happens on `init` for whichever site booted the request, and
 * `switch_to_blog()` does not re-run it. A cross-site request for a type only
 * the target site registers therefore cannot be made to work — no amount of
 * switching re-runs another site's plugin set in one process.
 *
 * So the only question was how it fails, and what was wrong was never the 404
 * but what it said. "That post type is not served here" is false for a type
 * this deployment is configured to serve, and it sends a consumer to check its
 * configuration when the fix is to ask a different REST root (D-041).
 */

/**
 * The error code a route returns, or an empty string on success.
 */
function scopeErrorCode(string $route): string
{
    $response = rest_do_request(new WP_REST_Request('GET', '/uamswp-migration/v1'.$route));
    $data = $response->get_data();

    if (! is_array($data) || ! isset($data['code']) || ! is_string($data['code'])) {
        return '';
    }

    return $data['code'];
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('says something different for configured-but-unregistered than for unknown', function (): void {
    // `kb` is the real case: configured in `config/defaults.php`, present in
    // `wp_posts` on this network with 28 rows, and unregistered wherever
    // MinervaKB is inactive.
    $configured = 'kb';

    expect(Config::strings('post_types'))->toContain($configured)
        ->and(post_type_exists($configured))->toBeFalse();

    expect(scopeErrorCode('/posts/'.$configured))->toBe('uamswp_migration_api_type_not_registered_here')
        // …and a type nobody configured stays the other error, because that is
        // a different fact and a consumer acts on it differently.
        ->and(scopeErrorCode('/posts/definitely-not-a-type'))->toBe('uamswp_migration_api_unknown_post_type');
});

it('names the site to ask when the request was cross-site', function (): void {
    // The whole point of the message: the consumer can act on it. Without the
    // site id it would have to work out which root to re-issue against.
    $second = makeSite();

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/posts/kb');
    $request->set_param('site', $second);

    $data = rest_do_request($request)->get_data();

    assert(is_array($data));

    $message = $data['message'] ?? '';

    expect($data['code'])->toBe('uamswp_migration_api_type_not_registered_here')
        ->and($message)->toContain((string) $second)
        ->and($message)->toContain('REST root');
});

it('draws the same distinction for taxonomies', function (): void {
    // Applied to both controllers in one change, because a decision applied to
    // one of two is how they drift.
    //
    // The configured-but-unregistered case needs manufacturing here: every
    // taxonomy in `config/defaults.php` happens to be registered on this
    // install, so without adding one the test would only ever exercise half the
    // branch — which is exactly what an earlier draft did, and a mutation
    // swapping the taxonomy error code for the other one passed unnoticed.
    add_filter('uamswp_migration_api_config', function (array $config): array {
        $taxonomies = $config['taxonomies'] ?? [];

        $config['taxonomies'] = array_merge(\is_array($taxonomies) ? $taxonomies : [], ['uamswp_never_registered_tax']);

        return $config;
    });

    Config::flush();

    register_taxonomy('uamswp_scope_tax', 'post');

    $notConfigured = scopeErrorCode('/terms/uamswp_scope_tax');
    $unregistered = scopeErrorCode('/terms/uamswp_never_registered_tax');
    $served = scopeErrorCode('/terms/category');

    unregister_taxonomy('uamswp_scope_tax');
    remove_all_filters('uamswp_migration_api_config');
    Config::flush();

    // Configured and registered — no error at all.
    expect($served)->toBe('')
        // Registered here but never configured: the deployment does not serve it.
        ->and($notConfigured)->toBe('uamswp_migration_api_unknown_taxonomy')
        // Configured but absent from this process: a different fact, and the
        // one a consumer can act on by asking a different REST root.
        ->and($unregistered)->toBe('uamswp_migration_api_taxonomy_not_registered_here');
});

it('still serves a type that is both configured and registered', function (): void {
    // The case the alternative would have broken: refusing every cross-site
    // request would have removed this, which works on every site because core
    // types are registered everywhere.
    $second = makeSite();

    switch_to_blog($second);
    $id = makePost(['post_title' => 'Cross-site readable']);
    restore_current_blog();

    $request = new WP_REST_Request('GET', "/uamswp-migration/v1/posts/post/{$id}");
    $request->set_param('site', $second);

    $data = rest_do_request($request)->get_data();

    assert(is_array($data));

    expect($data['title'] ?? null)->toBe('Cross-site readable');
});
