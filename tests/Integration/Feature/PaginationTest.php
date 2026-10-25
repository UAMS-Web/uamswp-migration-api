<?php

/**
 * Pagination bounds and stable ordering.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Config;
use UAMSWP\MigrationAPI\Pagination;

/**
 * Build pagination from a request carrying the given parameters.
 *
 * @param  array<string, mixed>  $params
 */
function paginationFor(array $params): Pagination
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/');

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return Pagination::fromRequest($request);
}

it('defaults to the configured page size', function (): void {
    $pagination = paginationFor([]);

    expect($pagination->page)->toBe(1)
        ->and($pagination->perPage)->toBe(50)
        ->and($pagination->offset())->toBe(0);
});

it('computes the offset from page and page size', function (): void {
    expect(paginationFor(['page' => 3, 'per_page' => 25])->offset())->toBe(50)
        ->and(paginationFor(['page' => 1, 'per_page' => 25])->offset())->toBe(0)
        ->and(paginationFor(['page' => 40, 'per_page' => 200])->offset())->toBe(7800)
        // A page size of one is the case where `offset()`'s own floor is the
        // divisor, so a floor above one would skip a record per page (#298).
        ->and(paginationFor(['page' => 3, 'per_page' => 1])->offset())->toBe(2);
});

it('clamps the page size to the configured maximum', function (): void {
    expect(paginationFor(['per_page' => 5000])->perPage)->toBe(200)
        ->and(paginationFor(['per_page' => 200])->perPage)->toBe(200)
        ->and(paginationFor(['per_page' => 199])->perPage)->toBe(199);
});

it('floors the page and page size to one', function (): void {
    // A zero or negative offset would re-serve the first page under a different
    // page number, so a migration would read those records twice.
    expect(paginationFor(['page' => 0])->page)->toBe(1)
        ->and(paginationFor(['page' => -5])->page)->toBe(1)
        ->and(paginationFor(['per_page' => 0])->perPage)->toBe(1)
        ->and(paginationFor(['per_page' => -5])->perPage)->toBe(1);
});

/*
 * Added to kill surviving mutants — `composer mutate` changed the `50` and `200`
 * literals on Pagination::fromRequest() and nothing failed, because the shipped
 * config always supplies both keys so the hardcoded fallbacks never ran.
 */
it('falls back to its own bounds when configuration supplies none', function (): void {
    $filter = static fn (array $config): array => array_diff_key(
        $config,
        array_flip(['per_page_default', 'per_page_max'])
    );

    add_filter('uamswp_migration_api_config', $filter);
    Config::flush();

    try {
        expect(paginationFor([])->perPage)->toBe(50)
            ->and(paginationFor(['per_page' => 5000])->perPage)->toBe(200)
            // The schema advertises the same fallback it serves (#298).
            ->and(Pagination::params()['per_page']['default'] ?? null)->toBe(50);
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
        Config::flush();
    }
});

/*
 * Also a surviving mutant: the `1` in `max(1, $max)` is only load-bearing when the
 * configured maximum is itself below 1, which no other test produces. A zero
 * maximum must still yield a usable page size rather than a query for no rows.
 */
it('still returns a usable page size when the configured maximum is zero', function (): void {
    $filter = static function (array $config): array {
        $config['per_page_max'] = 0;

        return $config;
    };

    add_filter('uamswp_migration_api_config', $filter);
    Config::flush();

    try {
        expect(paginationFor(['per_page' => 50])->perPage)->toBe(1);
    } finally {
        remove_filter('uamswp_migration_api_config', $filter);
        Config::flush();
    }
});

/*
 * And a third: removing the `(int)` cast in toInt() survived, because every test
 * passed an integer already. Query strings arrive as strings, so this is the
 * ordinary case in production rather than an edge one.
 */
it('coerces numeric strings and floats to integers', function (): void {
    expect(paginationFor(['page' => '3', 'per_page' => '25'])->page)->toBe(3)
        ->and(paginationFor(['page' => '3', 'per_page' => '25'])->perPage)->toBe(25)
        ->and(paginationFor(['page' => '3', 'per_page' => '25'])->offset())->toBe(50)
        // Truncated, not rounded — a fractional page size is nonsense either way,
        // but the direction should be deliberate.
        ->and(paginationFor(['per_page' => 25.9])->perPage)->toBe(25);
});

it('falls back to defaults for non-numeric parameters', function (): void {
    // REST arguments arrive as mixed even with a declared schema, because a
    // caller can always send something the schema does not describe.
    expect(paginationFor(['page' => 'abc', 'per_page' => 'xyz'])->page)->toBe(1)
        ->and(paginationFor(['page' => 'abc', 'per_page' => 'xyz'])->perPage)->toBe(50)
        ->and(paginationFor(['page' => [], 'per_page' => null])->perPage)->toBe(50);
});

/*
 * D-006, expressed as a test. A migration pages through a live site while editors
 * work; date ordering lets a record move between pages mid-run so it is read twice
 * or skipped, and a skipped record produces no error anywhere.
 */
it('orders by ID ascending and will not let a caller override it', function (): void {
    $args = paginationFor(['page' => 2, 'per_page' => 10])->applyToQueryArgs([
        'post_type' => 'provider',
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    expect($args['orderby'])->toBe('ID')
        ->and($args['order'])->toBe('ASC')
        ->and($args['posts_per_page'])->toBe(10)
        ->and($args['offset'])->toBe(10)
        ->and($args['no_found_rows'])->toBeTrue()
        // Base arguments that do not collide survive untouched.
        ->and($args['post_type'])->toBe('provider');
});

it('stamps total-count headers, rounding partial pages up', function (): void {
    $stamped = headers(paginationFor(['per_page' => 25])->decorate(new WP_REST_Response([]), 51));

    expect($stamped['X-WP-Total'])->toBe('51')
        ->and($stamped['X-WP-TotalPages'])->toBe('3');
});

/*
 * `ceil()` returns a float, and a float past 1e15 casts to a string in exponent
 * form. Without the integer cast the header would read `1.0E+15`, which no
 * consumer parses as a page count (#298).
 */
it('writes the page count as an integer however large the total', function (): void {
    $stamped = headers(paginationFor(['per_page' => 1])->decorate(new WP_REST_Response([]), 10 ** 15));

    expect($stamped['X-WP-TotalPages'])->toBe('1000000000000000');
});

it('reports zero pages for an empty result and never a negative total', function (): void {
    $pagination = paginationFor([]);

    expect(headers($pagination->decorate(new WP_REST_Response([]), 0))['X-WP-Total'])->toBe('0')
        ->and(headers($pagination->decorate(new WP_REST_Response([]), -10))['X-WP-Total'])->toBe('0');
});

/**
 * Dispatch a page of `post` records, as the super admin, with the given
 * query parameters.
 *
 * @param  array<string, mixed>  $params
 */
function paginatedGet(array $params): WP_REST_Response
{
    actAsSuperAdmin();

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/posts/post');
    $request->set_query_params($params);

    return rest_do_request($request);
}

/*
 * D-084. The schema used to be advisory: an `absint` sanitizer stopped core
 * from validating it, and then rewrote what arrived, so `page=-3` was served
 * as page 3 -- a well-formed response from the wrong page. Each value here is
 * refused rather than rewritten.
 */
it('refuses a page or page size that is not a positive integer', function (): void {
    $refused = [
        ['page' => '0'],
        ['page' => '-3'],
        ['page' => 'abc'],
        ['page' => '2.5'],
        ['per_page' => '0'],
        ['per_page' => '-2'],
        ['per_page' => 'abc'],
    ];

    foreach ($refused as $params) {
        $response = paginatedGet($params);

        expect($response->get_status())->toBe(400, (string) json_encode($params))
            ->and(errorCode($response))->toBe('rest_invalid_param');
    }
});

/*
 * The other half of D-084, and the reason `per_page` carries no `maximum`: the
 * importer's contract promises an oversized page size is CLAMPED rather than
 * refused. The clamp itself is asserted on `fromRequest()` above.
 */
it('serves an oversized page size rather than refusing it', function (): void {
    expect(paginatedGet(['per_page' => '5000'])->get_status())->toBe(200);
});

/*
 * The boundary values are served, so the floor the schema declares is 1 and
 * not something above it.
 */
it('serves the first page and a page size of one', function (): void {
    $ids = [makePost(), makePost()];

    $response = paginatedGet(['page' => '1', 'per_page' => '1', 'include' => $ids]);
    $data = $response->get_data();

    expect($response->get_status())->toBe(200)
        ->and(\is_array($data) ? \count($data) : -1)->toBe(1);
});

/*
 * An omitted `page` is page 1, through dispatch, where the schema's default is
 * what supplies it.
 */
it('serves the first page when no page is given', function (): void {
    $first = makePost();
    makePost();

    $data = paginatedGet(['per_page' => '1', 'include' => [$first]])->get_data();
    $record = \is_array($data) && isset($data[0]) && \is_array($data[0]) ? $data[0] : [];

    expect($record['id'] ?? null)->toBe($first);
});

/*
 * The schema is also a published contract: a consumer reading the route's own
 * description learns the defaults and the floor from it, so it has to agree
 * with what is served.
 */
it('advertises the page and page-size contract it enforces', function (): void {
    actAsSuperAdmin();

    $response = rest_do_request(new WP_REST_Request('OPTIONS', '/uamswp-migration/v1/posts/post'));
    $page = payload($response, 'endpoints', '0', 'args', 'page');
    $perPage = payload($response, 'endpoints', '0', 'args', 'per_page');

    expect($page)->toMatchArray(['type' => 'integer', 'default' => 1, 'minimum' => 1])
        ->and($perPage)->toMatchArray(['type' => 'integer', 'default' => 50, 'minimum' => 1])
        ->and(\is_array($perPage) && array_key_exists('maximum', $perPage))->toBeFalse()
        ->and(payload($response, 'endpoints', '0', 'args', 'page', 'description'))->toBe('Page of results to return, 1-based.')
        ->and(payload($response, 'endpoints', '0', 'args', 'per_page', 'description'))->toBe('Records per page.');
});
