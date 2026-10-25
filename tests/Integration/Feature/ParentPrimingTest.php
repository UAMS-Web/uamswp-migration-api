<?php

/**
 * A page must not pay a query per record to resolve parents.
 */

declare(strict_types=1);

/*
 * `WP_Query` primes the caches for the ids it returned and knows nothing about
 * what those rows point at. `PostPayload` then reads each record's parent —
 * `get_permalink()` on an attachment resolves its parent to build the URL — so
 * before `primeParents()` every record cost a `SELECT * FROM wp_posts WHERE
 * ID = N` and a single-id postmeta prime of its own.
 *
 * Measured on the development install against `origin/main`, a page of
 * attachments cost 48 queries at 1 record and 77 at 27 — roughly 1.2 queries
 * per record on top of a fixed overhead. After priming it is 46 and 48, i.e.
 * flat. (This comment previously said "50 at both", which matches neither
 * column of the table D-045 records from the same harness.)
 *
 * The test asserts the SHAPE of that rather than either number: cost must not
 * grow with page size. Absolute counts depend on the plugin set of whatever
 * install is running the suite, so pinning one would make this a test of the
 * environment.
 */

/**
 * Queries spent serving a page of `$size` attachments, each with its own parent.
 */
function queriesForAttachmentPage(int $size): int
{
    /*
     * A cold cache per measurement. Without this the second call reads
     * everything the first primed and reports a saving that is the cache's,
     * not the code's — which would make the test pass with `primeParents()`
     * deleted.
     */
    wp_cache_flush();

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/posts/attachment');
    $request->set_param('per_page', $size);

    $before = get_num_queries();

    $response = rest_do_request($request);

    $spent = get_num_queries() - $before;

    assert($response->get_status() === 200, 'the attachments page did not return 200');

    $data = $response->get_data();
    assert(is_array($data) && \count($data) === $size, 'the page did not return the expected number of records');

    return $spent;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('does not spend a query per record resolving post parents', function (): void {
    $file = DIR_TESTDATA.'/images/canola.jpg';

    if (! is_readable($file)) {
        $this->markTestSkipped('The WordPress test image set is not available.');
    }

    /*
     * A DISTINCT parent per attachment, which is the case priming has to
     * handle. Sharing one parent would pass even without the fix, because the
     * first lookup would populate the cache for all the rest — the fixture has
     * to be able to exhibit the failure.
     */
    for ($i = 0; $i < 10; $i++) {
        makeAttachment($file, makePost(['post_title' => 'Parent '.$i]));
    }

    $small = queriesForAttachmentPage(2);
    $large = queriesForAttachmentPage(10);

    /*
     * Eight more records may cost a little — a wider `IN (…)` is still one
     * query, and WordPress primes a few caches lazily — but it must not cost
     * anything like eight times two. Unbatched, those eight records added
     * sixteen queries; the bound is well inside that and well outside the
     * batched cost.
     */
    expect($large - $small)->toBeLessThanOrEqual(4);
});

it('serves the parent id it resolved, so priming cannot be masking a wrong answer', function (): void {
    $file = DIR_TESTDATA.'/images/canola.jpg';

    if (! is_readable($file)) {
        $this->markTestSkipped('The WordPress test image set is not available.');
    }

    $expected = [];

    for ($i = 0; $i < 3; $i++) {
        $parent = makePost(['post_title' => 'The parent '.$i]);
        $expected[makeAttachment($file, $parent)] = $parent;
    }

    /*
     * Read from the COLLECTION, not the single-record route. `show()` never
     * calls `primeParents()`, so asserting there would pin a value the priming
     * code has no part in producing — a test that passes whatever priming
     * does, which is worth nothing.
     *
     * What this does NOT catch, stated so nobody assumes otherwise: priming
     * the WRONG ids. That mutation was run, and it cannot change an answer —
     * a cache miss just sends `get_permalink()` back to the database for the
     * right row, so the payload stays correct and only the cost goes up. The
     * counting test is what catches it, and does.
     *
     * What this catches is the failure a caching change can actually cause:
     * the page serving a parent that is stale, shared between records, or
     * absent, because something now reads a primed value instead of resolving
     * it. Three distinct parents rather than one, so a single shared value
     * cannot satisfy it.
     */
    wp_cache_flush();

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/posts/attachment');
    $request->set_param('per_page', 100);

    $data = rest_do_request($request)->get_data();
    assert(is_array($data), 'the attachments collection did not return a list');

    $served = [];

    foreach ($data as $record) {
        if (is_array($record) && is_numeric($record['id'] ?? null)) {
            $served[(int) $record['id']] = $record['parent'] ?? null;
        }
    }

    foreach ($expected as $child => $parent) {
        expect($served[$child] ?? null)->toBe($parent);
    }
});
