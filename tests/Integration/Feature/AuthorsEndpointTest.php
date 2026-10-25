<?php

/**
 * The authors endpoint — WXR's `wp:author` block, as a resource.
 */

declare(strict_types=1);

/*
 * Core builds that block from `SELECT DISTINCT post_author FROM wp_posts WHERE
 * post_status != 'auto-draft'` and emits six fields per author
 * (wp-admin/includes/export.php:418-430). These tests hold the endpoint to all
 * six, and to core's rule about *which* users appear — which on a multisite
 * network with one shared user table is the difference between the people who
 * wrote a site's content and everyone who has ever logged in.
 */

/**
 * The authors collection.
 *
 * @param  array<string, mixed>  $params
 * @return list<array<string, mixed>>
 */
function authorsList(array $params = []): array
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/authors');

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    $data = rest_do_request($request)->get_data();

    assert(is_array($data), 'the authors endpoint did not return a list');

    /** @var list<array<string, mixed>> $data */
    return $data;
}

/**
 * The record for a user id, or null.
 *
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>|null
 */
function authorsFind(array $records, int $id): ?array
{
    foreach ($records as $record) {
        if (($record['id'] ?? null) === $id) {
            return $record;
        }
    }

    return null;
}

/**
 * The `X-WP-Total` the collection reports.
 *
 * @param  array<string, mixed>  $params
 */
function authorsTotal(array $params = []): int
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/authors');

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    $total = rest_do_request($request)->get_headers()['X-WP-Total'] ?? null;

    return is_numeric($total) ? (int) $total : -1;
}

/**
 * The raw collection response, for reading headers.
 *
 * @param  array<string, mixed>  $params
 */
function authorsResponse(array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/authors');

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('carries every field of the WXR author block', function (): void {
    $id = makeUser('author');

    wp_update_user([
        'ID' => $id,
        'display_name' => 'Dr Reed',
        'first_name' => 'Alice',
        'last_name' => 'Reed',
    ]);

    makePost(['post_author' => $id]);

    $record = authorsFind(authorsList(['per_page' => 100]), $id);

    assert(is_array($record));

    $user = get_userdata($id);

    assert($user instanceof WP_User);

    expect($record)->toHaveKeys(['id', 'login', 'email', 'display_name', 'first_name', 'last_name'])
        ->and($record['login'])->toBe($user->user_login)
        // The email ships, per D-040. Withholding it protects nothing that the
        // 259 exported XML files do not already contain, and 861 authors need
        // something stable to match on.
        ->and($record['email'])->toBe($user->user_email)
        ->and($record['email'])->not->toBe('')
        ->and($record['display_name'])->toBe('Dr Reed')
        // Usermeta rather than columns, resolved lazily by `WP_User::__get()`.
        ->and($record['first_name'])->toBe('Alice')
        ->and($record['last_name'])->toBe('Reed')
        // The one superset field: URL-safe, stable, and matchable without email.
        ->and($record['nicename'])->toBe($user->user_nicename);
});

it('serves only users who actually authored something', function (): void {
    // Core's rule, and it matters most here: on a multisite network the user
    // table is shared, so "every user" and "every author" differ by everyone
    // who has ever logged in to any of the 33 sites.
    $author = makeUser('author');
    $bystander = makeUser('author');

    makePost(['post_author' => $author]);

    $records = authorsList(['per_page' => 100]);

    expect(authorsFind($records, $author))->not->toBeNull()
        ->and(authorsFind($records, $bystander))->toBeNull();
});

it('does not conjure an author out of an abandoned draft', function (): void {
    // `auto-draft` is the row WordPress creates when an editor opens the "new
    // post" screen and walks away. Core excludes it from the author query, and
    // so does this — otherwise opening the editor once puts someone in the
    // export permanently.
    $ghost = makeUser('author');

    makePost(['post_author' => $ghost, 'post_status' => 'auto-draft']);

    expect(authorsFind(authorsList(['per_page' => 100]), $ghost))->toBeNull();
});

it('pages by id ascending with a total that matches', function (): void {
    // D-006. The header has to agree with the collection, or a consumer reads a
    // total that says it has finished when it has not.
    // Measured as a delta. A threshold ("at least four") is a guess about
    // what the fixture inherited, and guessing wrong is how a test ends up
    // asserting something it cannot know.
    $before = authorsTotal(['per_page' => 100]);

    for ($i = 0; $i < 3; $i++) {
        makePost(['post_author' => makeUser('author')]);
    }

    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/authors');
    $request->set_param('per_page', 2);

    $response = rest_do_request($request);
    $data = $response->get_data();

    assert(is_array($data));

    $ids = [];

    foreach ($data as $record) {
        $ids[] = is_array($record) ? ($record['id'] ?? null) : null;
    }

    $ascending = $ids;
    sort($ascending);

    $total = $response->get_headers()['X-WP-Total'] ?? null;

    expect($ids)->toHaveCount(2)
        ->and($ids)->toBe($ascending)
        ->and(authorsTotal(['per_page' => 100]) - $before)->toBe(3)
        ->and(is_numeric($total) ? (int) $total : -1)->toBe(authorsTotal(['per_page' => 100]));
});

/**
 * Remove a user's rows outright, leaving posts pointing at nothing.
 *
 * Not `wp_delete_user()`, which either deletes the user's posts or reassigns
 * them — both of which destroy the state under test. The real-world shape is a
 * post that OUTLIVED its account, so the row goes and the `post_author` value
 * stays exactly as it was.
 */
function authorsOrphanUser(int $id): void
{
    $wpdb = testDatabase();

    $wpdb->delete($wpdb->users, ['ID' => $id], ['%d']);
    $wpdb->delete($wpdb->usermeta, ['user_id' => $id], ['%d']);

    clean_user_cache($id);
    wp_cache_flush();
}

it('reports an author whose user row is gone, rather than dropping it silently', function (): void {
    $kept = makeUser('author');
    $lost = makeUser('author');

    makePost(['post_author' => $kept]);
    makePost(['post_author' => $lost]);

    authorsOrphanUser($lost);

    /*
     * The state this exists for. Core's `wxr_authors_list()` runs
     * `array_filter()` over the resolved users and reports nothing, so a
     * consumer holding a post with `author: N` cannot tell "author N is on a
     * page I have not fetched" from "author N does not exist".
     *
     * Measured before it was built: 2,592 of 216,197 items across the 259
     * captured exports (1.20%) carry an author that does not resolve.
     */
    $response = authorsResponse();

    expect(headers($response)['X-UAMSWP-Orphaned-Authors'] ?? null)->toBe('1');

    $ids = headers($response)['X-UAMSWP-Orphaned-Author-Ids'] ?? '';

    expect(explode(',', $ids))->toContain((string) $lost);

    // And the surviving author is still served, so this is a report and not a
    // refusal.
    expect(authorsFind(authorsList(), $kept))->not->toBeNull();
    expect(authorsFind(authorsList(), $lost))->toBeNull();
});

it('counts only the authors it can actually serve', function (): void {
    $kept = makeUser('author');
    $lost = makeUser('author');

    makePost(['post_author' => $kept]);
    makePost(['post_author' => $lost]);

    authorsOrphanUser($lost);

    /*
     * `X-WP-Total` used to be built from the unfiltered id list while the
     * records dropped the orphan — so the total promised a record no page
     * could ever contain, and a consumer paging to the end came up short with
     * nothing to explain it. The total now describes what is served, and the
     * orphan is reported separately.
     */
    $total = headers(authorsResponse())['X-WP-Total'] ?? '0';

    expect((int) $total)->toBe(\count(authorsList()));
});

it('keeps pages dense when an orphan falls inside one', function (): void {
    $ids = [];

    for ($i = 0; $i < 5; $i++) {
        $id = makeUser('author');
        makePost(['post_author' => $id]);
        $ids[] = $id;
    }

    sort($ids);

    // Orphan one in the MIDDLE, which is the case that used to shorten a page.
    authorsOrphanUser($ids[2]);

    $first = authorsList(['per_page' => 2, 'page' => 1]);
    $second = authorsList(['per_page' => 2, 'page' => 2]);

    /*
     * Partitioning before slicing is what makes both pages full. Filtering
     * after the slice returned a short second page, which reads to a consumer
     * exactly like the end of the collection.
     */
    expect($first)->toHaveCount(2);
    expect($second)->toHaveCount(2);

    $id = static function (array $record): int {
        $value = $record['id'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    };

    $seen = array_merge(array_map($id, $first), array_map($id, $second));

    expect($seen)->not->toContain($ids[2]);
    expect(\count(array_unique($seen)))->toBe(4);
});

it('says zero when every author resolves', function (): void {
    makePost(['post_author' => makeUser('author')]);

    /*
     * The header is always present, never conditional. A consumer must be able
     * to tell "no orphans" from "this deployment does not report orphans" —
     * an absent header means the second, and would send someone to check a
     * version number instead of reading a zero.
     */
    expect(headers(authorsResponse())['X-UAMSWP-Orphaned-Authors'] ?? null)->toBe('0');
    expect(headers(authorsResponse())['X-UAMSWP-Orphaned-Author-Ids'] ?? null)->toBe('');
});
