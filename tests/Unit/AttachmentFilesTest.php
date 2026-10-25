<?php

declare(strict_types=1);

use UAMSWP\MigrationAPI\Content\AttachmentFiles;

/*
 * The pure half of the file lookup: which stored names a path could belong to,
 * and whether a record owns it. The query half needs WordPress and lives in
 * `tests/Integration/Feature/AttachmentFileLookupTest.php`.
 *
 * These cover the branches the Feature suite never reaches, each of which could
 * be deleted there with nothing going red: a file at the uploads root, a
 * rotated original, and surrounding whitespace.
 */

it('normalizes a path and rejects what names nothing', function (): void {
    expect(AttachmentFiles::normalize('  /2020/09/photo.png '))->toBe('2020/09/photo.png')
        ->and(AttachmentFiles::normalize('   '))->toBeNull()
        ->and(AttachmentFiles::normalize('/'))->toBeNull()
        ->and(AttachmentFiles::normalize(42))->toBeNull()
        ->and(AttachmentFiles::normalize(null))->toBeNull();
});

it('lists the original and the replaced-original names a size could belong to', function (): void {
    expect(AttachmentFiles::candidates(['2020/09/photo-300x200.jpg']))->toEqualCanonicalizing([
        '2020/09/photo-300x200.jpg',
        '2020/09/photo-300x200-scaled.jpg',
        '2020/09/photo-300x200-rotated.jpg',
        '2020/09/photo.jpg',
        '2020/09/photo-scaled.jpg',
        '2020/09/photo-rotated.jpg',
    ]);
});

it('adds the PDF a preview image belongs to', function (): void {
    expect(AttachmentFiles::candidates(['2020/09/doc-pdf-116x150.jpg']))->toContain('2020/09/doc.pdf')
        ->and(AttachmentFiles::candidates(['2020/09/doc-pdf.jpg']))->toContain('2020/09/doc.pdf');
});

it('does not strip a size-shaped directory name', function (): void {
    expect(AttachmentFiles::candidates(['2020/banner-10x10/photo.png']))
        ->not->toContain('2020/banner.png');
});

it('matches a size of a file stored at the uploads root', function (): void {
    $metadata = ['sizes' => ['medium' => ['file' => 'photo-300x200.png']]];

    expect(AttachmentFiles::matches('photo-300x200.png', 'photo.png', $metadata))->toBeTrue()
        ->and(AttachmentFiles::matches('/photo-300x200.png', 'photo.png', $metadata))->toBeFalse();
});

it('matches the original of a rotated upload', function (): void {
    $metadata = ['original_image' => 'photo.jpg', 'sizes' => []];

    expect(AttachmentFiles::matches('2020/09/photo.jpg', '2020/09/photo-rotated.jpg', $metadata))->toBeTrue();
});

it('matches nothing for a record with no stored file or unreadable metadata', function (): void {
    expect(AttachmentFiles::matches('2020/09/photo.png', '', null))->toBeFalse()
        ->and(AttachmentFiles::matches('2020/09/photo-300x200.png', '2020/09/photo.png', 'not an array'))->toBeFalse()
        ->and(AttachmentFiles::matches('2020/09/photo-300x200.png', '2020/09/photo.png', ['sizes' => [['file' => 7]]]))->toBeFalse();
});

it('lists a PDF once when it is requested beside its preview', function (): void {
    expect(AttachmentFiles::candidates(['2020/09/doc-pdf.jpg', '2020/09/doc.pdf']))->toEqualCanonicalizing([
        '2020/09/doc-pdf.jpg',
        '2020/09/doc-pdf-scaled.jpg',
        '2020/09/doc-pdf-rotated.jpg',
        '2020/09/doc.pdf',
        '2020/09/doc-scaled.pdf',
        '2020/09/doc-rotated.pdf',
    ]);
});

it('matches nothing for a record with no stored file, even an empty path', function (): void {
    expect(AttachmentFiles::matches('', '', null))->toBeFalse();
});

it('reads metadata only when it is an array, never an array-like object', function (): void {
    // `_wp_attachment_metadata` is read back from a serialized string, so an
    // object can arrive here.
    // Each level is read only as an array; an object implementing ArrayAccess
    // or Traversable at any of them matches nothing.
    $size = ['file' => 'photo-300x200.png'];

    expect(AttachmentFiles::matches('2020/09/photo-300x200.png', '2020/09/photo.png', new ArrayObject(['sizes' => [$size]])))->toBeFalse()
        ->and(AttachmentFiles::matches('2020/09/photo-300x200.png', '2020/09/photo.png', ['sizes' => new ArrayObject([$size])]))->toBeFalse()
        ->and(AttachmentFiles::matches('2020/09/photo-300x200.png', '2020/09/photo.png', ['sizes' => [new ArrayObject($size)]]))->toBeFalse()
        ->and(AttachmentFiles::matches('2020/09/photo-300x200.png', '2020/09/photo.png', ['sizes' => [$size]]))->toBeTrue();
});
