<?php

/**
 * Attachment parity — the highest-volume item type in the captured exports.
 */

declare(strict_types=1);

/*
 * 2,011 of the 259 captured WXR exports' items are attachments, more than every
 * other post type combined, and `XmlParser::getAttachments()` is a distinct code
 * path the importer relies on (#8).
 *
 * The element that matters is `wp:attachment_url`, which core emits for
 * attachments and nothing else. Neither neighboring field substitutes: `guid`
 * looks like a file URL and is an opaque identifier stored with whatever scheme
 * the row was written under, and `link` is the attachment page.
 */

/**
 * An attachment with a real file behind it, and its parent.
 *
 * @return array{attachment: int, parent: int}
 */
function makeAttachmentWithParent(): array
{
    $parent = makePost(['post_title' => 'Parent Post']);

    $attachment = makeAttachment(\dirname(__DIR__, 2).'/Support/fixtures/pixel.png', $parent);

    return ['attachment' => $attachment, 'parent' => $parent];
}

/**
 * One record from the posts endpoint.
 *
 * @return array<string, mixed>
 */
function attachmentRecord(int $id, string $type = 'attachment'): array
{
    $data = rest_do_request(new WP_REST_Request('GET', "/uamswp-migration/v1/posts/{$type}/{$id}"))->get_data();

    assert(\is_array($data), 'the posts endpoint did not return a record');

    /** @var array<string, mixed> $data */
    return $data;
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('serves the file URL, which no other field carries', function (): void {
    $ids = makeAttachmentWithParent();

    // `guid` is moved off the real URL first, and that is what gives this test
    // teeth. Asserting only `toBe( wp_get_attachment_url() )` passed against an
    // implementation serving `$post->guid`, because on a freshly created
    // attachment the two are equal — the same false green as comparing a value
    // to the function the implementation itself calls. On a real site they
    // diverge (this network stores each `guid` with `http://` against an `https://` site),
    // and a test that only passes where they coincide guards nothing.
    // Written straight to the row: `wp_update_post()` silently ignores `guid`,
    // because core only sets it on insert. A test that used the wrapper would
    // look like it had moved the guid and would not have.
    global $wpdb;

    assert($wpdb instanceof wpdb);

    $wpdb->update($wpdb->posts, ['guid' => 'https://example.invalid/not-the-file'], ['ID' => $ids['attachment']], ['%s'], ['%d']);
    clean_post_cache($ids['attachment']);

    $record = attachmentRecord($ids['attachment']);
    $block = $record['attachment'];

    assert(\is_array($block));

    expect($block['url'])->toBe(wp_get_attachment_url($ids['attachment']))
        ->and($block['url'])->not->toBe('')
        // The three are distinct things, and only one of them resolves the file.
        ->and($block['url'])->not->toBe($record['guid'])
        ->and($block['url'])->not->toBe($record['link'])
        // …and the guid still ships untouched, because it is what WXR carries.
        ->and($record['guid'])->toBe('https://example.invalid/not-the-file');
});

it('decodes `_wp_attachment_metadata` while keeping the raw row beside it', function (): void {
    // D-005 on attachments. The flat `meta` map keeps the serialized string,
    // byte-for-byte what `wp:postmeta` carries, so an existing importer runs
    // unchanged; the decoded form is for a consumer that would rather not parse
    // a string the API has already parsed.
    $ids = makeAttachmentWithParent();
    $record = attachmentRecord($ids['attachment']);

    $block = $record['attachment'];
    $meta = $record['meta'];

    assert(\is_array($block));
    assert(\is_array($meta));

    $rows = $meta['_wp_attachment_metadata'] ?? null;

    assert(\is_array($rows));

    $raw = $rows[0] ?? null;

    expect($block['metadata'])->toBeArray()
        // The sizes map is where an asset importer finds the derivatives it has
        // to fetch — the reason decoding earns its place.
        ->and($block['metadata'])->toHaveKey('sizes')
        ->and($raw)->toBeString()
        ->and(is_serialized(\is_string($raw) ? $raw : ''))->toBeTrue();
});

it('serves the alt text and the uploads-relative path', function (): void {
    $ids = makeAttachmentWithParent();

    update_post_meta($ids['attachment'], '_wp_attachment_image_alt', 'A single pixel');

    $block = attachmentRecord($ids['attachment'])['attachment'];

    assert(\is_array($block));

    expect($block['alt'])->toBe('A single pixel')
        // Relative to the uploads directory, which is what survives a move
        // between hosts where an absolute URL does not.
        ->and($block['file'])->toBe(get_post_meta($ids['attachment'], '_wp_attached_file', true))
        ->and($block['file'])->not->toBe('')
        ->and($block['mime_type'])->toBe('image/png');
});

it('keeps `_thumbnail_id` on the parent so featured images still resolve', function (): void {
    // Underscore-prefixed, so this is D-007 again: the filter that looks like
    // hygiene detaches every featured image on the site.
    $ids = makeAttachmentWithParent();

    set_post_thumbnail($ids['parent'], $ids['attachment']);

    $meta = attachmentRecord($ids['parent'], 'post')['meta'];

    assert(\is_array($meta));

    expect($meta)->toHaveKey('_thumbnail_id')
        ->and($meta['_thumbnail_id'])->toBe([(string) $ids['attachment']]);
});

it('carries the attachment block only on attachments', function (): void {
    // Mirrors core, which emits `wp:attachment_url` inside an
    // `if ( 'attachment' === $post->post_type )` and nothing else. Null rather
    // than an absent key, so a consumer does not have to guess whether the
    // field is missing or the record is not an attachment.
    $ids = makeAttachmentWithParent();

    expect(attachmentRecord($ids['parent'], 'post')['attachment'])->toBeNull()
        ->and(attachmentRecord($ids['attachment'])['attachment'])->toBeArray();
});
