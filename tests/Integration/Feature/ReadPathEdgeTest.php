<?php

/**
 * The read path's guards that ordinary fixtures do not reach.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Content\AttachmentFiles;
use UAMSWP\MigrationAPI\Content\PostPayload;
use UAMSWP\MigrationAPI\Content\TermPayload;
use UAMSWP\MigrationAPI\REST\AuthorsController;
use UAMSWP\MigrationAPI\REST\PostsController;
use UAMSWP\MigrationAPI\REST\RedirectsController;

/*
 * Pins the branches of the post, term, author and redirect routes, and of the
 * payloads they build, that need an input the endpoint tests never send: an
 * unknown type on the single-record route, a parameter that is not a list, a
 * timestamp that matches the pattern and still does not parse, a read that
 * comes back in a shape WordPress does not normally return, and a request
 * answered while `$wpdb` is not a database handle (#363).
 *
 * Where a shape cannot come out of WordPress unaided, a core filter serves it,
 * and each test first shows the same read without the filter, so a filter that
 * never ran cannot pass the test.
 */

/**
 * Dispatch an authenticated GET against a route in this namespace.
 *
 * @param  array<string, mixed>  $params  Query parameters.
 */
function readPathGet(string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1'.$route);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * The record ids in a collection response, in the order served.
 *
 * @return list<mixed>
 */
function readPathIds(WP_REST_Response|WP_Error $response): array
{
    assert($response instanceof WP_REST_Response, 'the route answered with an error');

    $data = $response->get_data();

    assert(\is_array($data), 'a collection response did not return an array');

    return array_values(array_map(static fn (mixed $record): mixed => \is_array($record) ? ($record['id'] ?? null) : null, $data));
}

/**
 * Call a controller method directly, outside the REST server.
 *
 * Direct calls skip the route's argument sanitizing, which is the only way a
 * parameter reaches the controller in a shape the schema would have coerced.
 *
 * @param  array<string, mixed>  $params
 */
function readPathCall(object $controller, string $method, array $params): WP_REST_Response|WP_Error
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/edge');

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    $response = $controller->{$method}($request);

    assert($response instanceof WP_REST_Response || $response instanceof WP_Error);

    return $response;
}

/**
 * Call a controller method while `$wpdb` is not a database handle.
 *
 * @param  array<string, mixed>  $params
 */
function readPathWithoutDatabase(object $controller, string $method, array $params = []): WP_REST_Response|WP_Error
{
    $handle = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = null;

    try {
        return readPathCall($controller, $method, $params);
    } finally {
        $GLOBALS['wpdb'] = $handle;
    }
}

/**
 * An attachment row whose stored path is exactly this one.
 */
function readPathAttachment(string $file): int
{
    $id = wp_insert_attachment(
        ['post_mime_type' => 'image/png', 'post_title' => basename($file), 'post_status' => 'inherit'],
        $file
    );

    assert($id > 0, 'the attachment was not inserted');

    return $id;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

describe('posts', function (): void {
    it('answers 404 for an unknown type on the single-record route', function (): void {
        $id = makePost();

        // The control: the same id on a served type is found.
        expect(readPathGet('/posts/post/'.$id)->get_status())->toBe(200);

        $response = readPathGet('/posts/uamswp_unserved_type/'.$id);

        expect($response->get_status())->toBe(404)
            ->and(errorCode($response))->toBe('uamswp_migration_api_unknown_post_type');
    });

    it('refuses a modified_after whose offset matches the pattern but does not parse', function (): void {
        // The control: an offset in range is read.
        expect(PostsController::validateModifiedAfter('2026-01-01T00:00:00+05:00'))->toBeTrue();

        // `+99:99` is two digits, a colon and two digits, so the pattern and
        // every range check pass; `DateTimeImmutable` is what refuses it.
        $refused = PostsController::validateModifiedAfter('2026-01-01T00:00:00+99:99');

        expect($refused)->toBeInstanceOf(WP_Error::class)
            ->and($refused instanceof WP_Error ? $refused->get_error_code() : null)->toBe('uamswp_migration_api_bad_modified_after')
            ->and(readPathGet('/posts/post', ['modified_after' => '2026-01-01T00:00:00+99:99'])->get_status())->toBe(400);
    });

    it('serves nothing for an include that is not a list', function (): void {
        $id = makePost();
        $controller = new PostsController;

        // The control: the same id sent as a list selects the record.
        expect(readPathIds(readPathCall($controller, 'index', ['type' => 'post', 'include' => [$id]])))->toBe([$id]);

        // The route's schema turns a scalar into a list before the controller
        // runs, so only a direct call delivers one. It was asked for, so it
        // filters, and a value that is not a list selects nothing.
        expect(readPathIds(readPathCall($controller, 'index', ['type' => 'post', 'include' => (string) $id])))->toBe([]);
    });

    it('serves nothing for a file that is not a list', function (): void {
        $id = readPathAttachment('2020/09/edge-scalar.png');
        $controller = new PostsController;

        expect(readPathIds(readPathCall($controller, 'index', ['type' => 'attachment', 'file' => ['2020/09/edge-scalar.png']])))->toBe([$id])
            ->and(readPathIds(readPathCall($controller, 'index', ['type' => 'attachment', 'file' => '2020/09/edge-scalar.png'])))->toBe([]);
    });

    it('primes parents past a page entry that is an id rather than a post', function (): void {
        $parent = makePost(['post_type' => 'page']);
        $child = get_post(makePost(['post_type' => 'page', 'post_parent' => $parent]));

        assert($child instanceof WP_Post);

        // `WP_Query` types a page as `int|WP_Post`, ids coming back when
        // `fields` is `ids`. The route never asks for that, so the method is
        // called directly with both shapes in one page.
        $prime = new ReflectionMethod(PostsController::class, 'primeParents');

        // The object cache keys by string, as core's own stubs declare.
        $cacheKey = (string) $parent;

        wp_cache_delete($cacheKey, 'posts');

        // The control: the parent is not cached before the call.
        expect(wp_cache_get($cacheKey, 'posts'))->toBeFalse();

        $prime->invoke(null, [PHP_INT_MAX, $child]);

        // The id was skipped rather than read as a post, and the post after it
        // still had its parent primed.
        expect(wp_cache_get($cacheKey, 'posts'))->toBeObject();
    });
});

describe('terms', function (): void {
    it('answers 404 for an unknown taxonomy on the single-record route', function (): void {
        $id = makeTerm(['taxonomy' => 'category']);

        expect(readPathGet('/terms/category/'.$id)->get_status())->toBe(200);

        $response = readPathGet('/terms/uamswp_unserved_tax/'.$id);

        expect($response->get_status())->toBe(404)
            ->and(errorCode($response))->toBe('uamswp_migration_api_unknown_taxonomy');
    });

    it('names the site to ask when a configured taxonomy is not registered here', function (): void {
        // Every configured taxonomy is registered on this install, so one is
        // added to the configuration and never registered.
        add_filter('uamswp_migration_api_config', static function (array $config): array {
            $taxonomies = $config['taxonomies'] ?? [];
            $config['taxonomies'] = array_merge(\is_array($taxonomies) ? $taxonomies : [], ['uamswp_never_registered_tax']);

            return $config;
        });

        Config::flush();

        $site = get_current_blog_id();

        try {
            $withoutSite = readPathGet('/terms/uamswp_never_registered_tax');
            $withSite = readPathGet('/terms/uamswp_never_registered_tax', ['site' => $site]);
        } finally {
            remove_all_filters('uamswp_migration_api_config');
            Config::flush();
        }

        $message = static fn (WP_REST_Response $response): mixed => payload($response, 'message');

        // The control: without `?site=` the message is about this site.
        expect(errorCode($withoutSite))->toBe('uamswp_migration_api_taxonomy_not_registered_here')
            ->and($message($withoutSite))->toBe('`uamswp_never_registered_tax` is served by this deployment but is not registered on this site.')
            ->and($withSite->get_status())->toBe(404)
            ->and(errorCode($withSite))->toBe('uamswp_migration_api_taxonomy_not_registered_here')
            ->and($message($withSite))->toBe("`uamswp_never_registered_tax` is served by this deployment but is not registered in the process handling this request. Request it from site {$site}'s own REST root.");
    })->skip(! is_multisite(), networkOnly('a single site refuses every `?site=`'));
});

describe('authors', function (): void {
    it('serves an empty collection when there is no database handle', function (): void {
        $author = makeUser('author');
        makePost(['post_author' => $author]);

        // The control: with the handle in place the author is served.
        expect(readPathIds(readPathCall(new AuthorsController, 'index', [])))->toContain($author);

        $response = readPathWithoutDatabase(new AuthorsController, 'index');

        assert($response instanceof WP_REST_Response);

        expect($response->get_data())->toBe([])
            ->and($response->get_headers()['X-WP-Total'] ?? null)->toBe('0')
            ->and($response->get_headers()['X-UAMSWP-Orphaned-Authors'] ?? null)->toBe('0');
    });
});

describe('redirects', function (): void {
    it('refuses a Redirection read when there is no database handle', function (): void {
        update_option('active_plugins', [RedirectsController::REDIRECTION_PLUGIN]);

        // The control: with the handle in place the rules are served.
        expect(readPathCall(new RedirectsController, 'indexRedirection', []))->toBeInstanceOf(WP_REST_Response::class);

        $response = readPathWithoutDatabase(new RedirectsController, 'indexRedirection');

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response instanceof WP_Error ? $response->get_error_code() : null)->toBe('uamswp_migration_api_redirect_read_failed')
            ->and($response instanceof WP_Error ? $response->get_error_message() : null)->toBe('Redirection could not read redirection_items on site '.get_current_blog_id().', so its rules are not served rather than served incomplete.');
    });
});

describe('payloads', function (): void {
    it('serves no terms when WordPress answers the term read with an error', function (): void {
        $term = makeTerm(['taxonomy' => 'category', 'name' => 'Edge category']);
        $post = get_post(makePost(['post_category' => [$term]]));

        assert($post instanceof WP_Post);

        // The control: the term is served without the filter.
        expect(PostPayload::build($post)['terms'])->not->toBe([]);

        $ran = 0;

        add_filter('wp_get_object_terms', static function () use (&$ran): WP_Error {
            $ran++;

            return new WP_Error('edge', 'unreadable');
        });

        expect(PostPayload::build($post)['terms'])->toBe([])
            ->and($ran)->toBeGreaterThan(0);
    });

    it('serves no term meta when the meta read is not an array', function (): void {
        $id = makeTerm(['taxonomy' => 'category']);
        add_term_meta($id, 'edge_key', 'edge value');
        $term = get_term($id);

        assert($term instanceof WP_Term);

        expect(TermPayload::build($term)['meta'])->toBe(['edge_key' => ['edge value']]);

        $ran = 0;

        // `get_metadata()` returns a filter's answer as it is when no key is
        // asked for, so a string reaches the payload unchanged.
        add_filter('get_term_metadata', static function (mixed $value, int $objectId) use ($id, &$ran): mixed {
            if ($objectId !== $id) {
                return $value;
            }

            $ran++;

            return 'not an array';
        }, 10, 2);

        expect(TermPayload::build($term)['meta'])->toBe([])
            ->and($ran)->toBe(1);
    });

    it('adds no extension variants for a path with no extension', function (): void {
        // The control: a path with an extension gains the replaced-original
        // variants, so the variant step runs for an ordinary path.
        expect(\count(AttachmentFiles::candidates(['2020/09/photo.png'])))->toBeGreaterThan(1)
            ->and(AttachmentFiles::candidates(['2020/09/readme']))->toBe(['2020/09/readme']);
    });

    it('skips a candidate whose stored path is not a string', function (): void {
        $id = readPathAttachment('2020/09/edge-meta.png');

        expect(AttachmentFiles::resolve(['2020/09/edge-meta.png'], ['inherit']))->toBe([$id]);

        $ran = 0;

        // The meta query matched the row in SQL; the stored value read back
        // afterwards is what the filter replaces.
        add_filter('get_post_metadata', static function (mixed $value, int $objectId, string $key) use ($id, &$ran): mixed {
            if ($objectId !== $id || $key !== '_wp_attached_file') {
                return $value;
            }

            $ran++;

            return [42];
        }, 10, 3);

        expect(AttachmentFiles::resolve(['2020/09/edge-meta.png'], ['inherit']))->toBe([])
            ->and($ran)->toBe(1);
    });
});
