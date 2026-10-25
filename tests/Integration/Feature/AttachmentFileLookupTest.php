<?php

/**
 * `GET /posts/attachment?file[]=` — selecting attachments by the file they own.
 */

declare(strict_types=1);

use UAMSWP\MigrationAPI\Content\AttachmentFiles;

/*
 * The consumer resolves an embedded image by its `src`, because the
 * `wp-image-N` class a replaced image keeps names the OLD attachment (#300).
 * So every assertion here is about which record a file path selects, and the
 * cases that matter are the ones a name-based guess gets wrong: a generated
 * size, a replaced original, and an original whose own name looks like a size.
 *
 * Records are inserted with a controlled `_wp_attached_file` and a hand-built
 * `_wp_attachment_metadata` rather than uploaded, because an upload's path
 * carries today's date and the fixture image is too small to generate sizes.
 */

/**
 * An attachment row owning this uploads-relative file, with these sizes.
 *
 * @param  list<string>  $sizes  Generated size file names, stored bare.
 * @param  array<string, mixed>  $extra  Further metadata keys, such as `original_image`.
 */
function makeAttachmentFile(string $file, array $sizes = [], array $extra = []): int
{
    $id = wp_insert_attachment(
        ['post_mime_type' => 'image/png', 'post_title' => basename($file), 'post_status' => 'inherit'],
        $file
    );

    assert($id > 0, 'the attachment was not inserted');

    $metadata = ['file' => $file, 'sizes' => []];

    foreach ($sizes as $index => $size) {
        $metadata['sizes']["size-{$index}"] = ['file' => $size, 'mime-type' => 'image/png'];
    }

    // Written as meta rather than through `wp_update_attachment_metadata()`,
    // whose filter is a plugin's chance to rewrite what this test arranges.
    update_post_meta($id, '_wp_attachment_metadata', array_merge($metadata, $extra));

    return $id;
}

/**
 * A collection request, with these parameters, for one post type.
 *
 * @param  array<string, mixed>  $params
 */
function fileLookup(array $params, string $type = 'attachment'): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/uamswp-migration/v1/posts/'.$type);

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    return rest_do_request($request);
}

/**
 * The record ids in a collection response, in the order served.
 *
 * @return list<int>
 */
function fileLookupIds(WP_REST_Response $response): array
{
    $data = $response->get_data();

    assert(\is_array($data), 'a collection response did not return an array');

    $ids = [];

    foreach ($data as $record) {
        assert(\is_array($record) && \is_int($record['id'] ?? null), 'a collection record carried no id');
        $ids[] = $record['id'];
    }

    return $ids;
}

/**
 * The attachment ids a `file` request selects.
 *
 * @param  array<string, mixed>  $params
 * @return list<int>
 */
function attachmentIdsForFiles(array $params): array
{
    $response = fileLookup($params);

    expect($response->get_status())->toBe(200);

    return fileLookupIds($response);
}

beforeEach(function (): void {
    actAsSuperAdmin();
});

it('stores the path the lookup is matched against', function (): void {
    // The control every other test rests on: if the insert stored an absolute
    // path, or none, each "not selected" below would pass for that reason.
    $id = makeAttachmentFile('2020/09/control.png');

    expect(get_post_meta($id, '_wp_attached_file', true))->toBe('2020/09/control.png');
});

it('selects an attachment by its original file', function (): void {
    $wanted = makeAttachmentFile('2020/09/photo.png');
    $other = makeAttachmentFile('2020/09/other.png');

    $ids = attachmentIdsForFiles(['file' => ['2020/09/photo.png']]);

    expect($ids)->toBe([$wanted])
        ->and($ids)->not->toContain($other);
});

it('selects the parent attachment for a generated size', function (): void {
    $wanted = makeAttachmentFile('2020/09/guidelines.png', ['guidelines-750x629.png', 'guidelines-150x150.png']);

    expect(attachmentIdsForFiles(['file' => ['2020/09/guidelines-750x629.png']]))->toBe([$wanted]);
});

it('matches a size only when the record lists it', function (): void {
    // Stripping `-WxH` would map this to `guidelines.png`. The record never
    // generated a 999x999 size, so the file does not belong to it.
    makeAttachmentFile('2020/09/guidelines.png', ['guidelines-750x629.png']);

    expect(attachmentIdsForFiles(['file' => ['2020/09/guidelines-999x999.png']]))->toBe([]);
});

it('does not hand an original named like a size to the attachment that name suggests', function (): void {
    // The ambiguity a consumer-side strip cannot resolve: `banner-1200x400.png`
    // is its own original, and `banner.png` is a different attachment.
    $own = makeAttachmentFile('2020/09/banner-1200x400.png');
    $lookalike = makeAttachmentFile('2020/09/banner.png', ['banner-300x100.png']);

    $ids = attachmentIdsForFiles(['file' => ['2020/09/banner-1200x400.png']]);

    expect($ids)->toBe([$own])
        ->and($ids)->not->toContain($lookalike);
});

it('resolves a scaled upload by its original name and by its sizes', function (): void {
    // Core stores a large upload as `-scaled` and names its sizes after the
    // ORIGINAL, so neither requested name is the stored file.
    $wanted = makeAttachmentFile('2020/09/hero-scaled.jpg', ['hero-300x200.jpg'], ['original_image' => 'hero.jpg']);

    expect(attachmentIdsForFiles(['file' => ['2020/09/hero.jpg']]))->toBe([$wanted])
        ->and(attachmentIdsForFiles(['file' => ['2020/09/hero-300x200.jpg']]))->toBe([$wanted])
        ->and(attachmentIdsForFiles(['file' => ['2020/09/hero-scaled.jpg']]))->toBe([$wanted]);
});

it('compares a size under its own directory', function (): void {
    makeAttachmentFile('2020/09/photo.png', ['photo-750x629.png']);

    expect(attachmentIdsForFiles(['file' => ['2021/01/photo-750x629.png']]))->toBe([])
        ->and(attachmentIdsForFiles(['file' => ['2021/01/photo.png']]))->toBe([]);
});

it('omits unmatched paths rather than failing the request', function (): void {
    $wanted = makeAttachmentFile('2020/09/photo.png');

    $response = fileLookup(['file' => ['2020/09/photo.png', '2020/09/missing.png']]);

    expect($response->get_status())->toBe(200)
        ->and(fileLookupIds($response))->toBe([$wanted])
        ->and($response->get_headers()['X-WP-Total'] ?? null)->toBe('1');
});

it('serves nothing, not everything, when no path matches', function (): void {
    // The `include` trap again: `post__in` empty means NO filter to WP_Query.
    $unrelated = makeAttachmentFile('2020/09/photo.png');

    $response = fileLookup(['file' => ['2020/09/missing.png']]);

    expect($response->get_status())->toBe(200)
        ->and(fileLookupIds($response))->not->toContain($unrelated)
        ->and(fileLookupIds($response))->toBe([]);
});

it('tolerates the leading slash a path cut from a URL keeps', function (): void {
    $wanted = makeAttachmentFile('2020/09/photo.png');

    expect(attachmentIdsForFiles(['file' => ['/2020/09/photo.png']]))->toBe([$wanted]);
});

it('combines `file` with `include` as a union', function (): void {
    $byFile = makeAttachmentFile('2020/09/photo.png');
    $byId = makeAttachmentFile('2020/09/other.png');
    $neither = makeAttachmentFile('2020/09/third.png');

    $ids = attachmentIdsForFiles(['file' => ['2020/09/photo.png'], 'include' => [$byId]]);

    expect($ids)->toBe([$byFile, $byId])
        ->and($ids)->not->toContain($neither);
});

it('serves a record once when `file` and `include` both name it', function (): void {
    $id = makeAttachmentFile('2020/09/photo.png', ['photo-750x629.png']);

    expect(attachmentIdsForFiles([
        'file' => ['2020/09/photo.png', '2020/09/photo-750x629.png'],
        'include' => [$id],
    ]))->toBe([$id]);
});

it('refuses `file` on any post type other than attachment', function (): void {
    // Ignored, it would serve every post to a caller who asked for a few files.
    $response = fileLookup(['file' => ['2020/09/photo.png']], 'post');

    expect($response->get_status())->toBe(400)
        ->and(errorCode($response))->toBe('uamswp_migration_api_file_not_attachment');
});

it('refuses more paths than the documented maximum', function (): void {
    $paths = [];

    for ($i = 0; $i <= AttachmentFiles::MAX_PATHS; $i++) {
        $paths[] = "2020/09/file-{$i}.png";
    }

    expect(fileLookup(['file' => $paths])->get_status())->toBe(400)
        ->and(fileLookup(['file' => \array_slice($paths, 0, AttachmentFiles::MAX_PATHS)])->get_status())->toBe(200);
});

it('matches a path exactly as stored, case included', function (): void {
    // The candidate query compares under a case-insensitive collation and so
    // returns this record; the exact comparison after it is what refuses it.
    makeAttachmentFile('2020/09/Photo.png');

    expect(attachmentIdsForFiles(['file' => ['2020/09/photo.png']]))->toBe([]);
});

it('serves nothing when `file` carries no usable path', function (): void {
    // The trap `include` fell into: a list that reduces to empty must still
    // filter. Skipping the filter would serve every attachment, `200`.
    $unrelated = makeAttachmentFile('2020/09/photo.png');

    foreach ([[], [''], ['   ']] as $file) {
        $ids = attachmentIdsForFiles(['file' => $file]);

        expect($ids)->not->toContain($unrelated)
            ->and($ids)->toBe([]);
    }
});

it('resolves a PDF preview image to the PDF', function (): void {
    // Core names a PDF's preview `<name>-pdf.jpg` and lists it, with its
    // sizes, on the record that stores the `.pdf`.
    $wanted = makeAttachmentFile('2020/09/report.pdf', ['report-pdf.jpg', 'report-pdf-116x150.jpg']);

    expect(attachmentIdsForFiles(['file' => ['2020/09/report-pdf-116x150.jpg']]))->toBe([$wanted])
        ->and(attachmentIdsForFiles(['file' => ['2020/09/report-pdf.jpg']]))->toBe([$wanted]);
});
