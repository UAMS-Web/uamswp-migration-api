<?php

/**
 * Resolves uploads-relative file paths to the attachments that own them.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use WP_Post;
use WP_Query;

/**
 * Which attachment a file belongs to, including a generated size of it.
 *
 * Content embeds a file, not an id. The `wp-image-N` class an `<img>` carries
 * is not a reliable id either: a replaced image keeps its old class, so on 17
 * sites of the importer's corpus 442 references name a live attachment that
 * is not the one their `src` points at (#300). The file is the only thing that
 * identifies the image actually shown.
 *
 * **Matched against each record's own size list, never by guessing a name.**
 * Content usually embeds a generated size, `photo-750x629.png`. Stripping the
 * `-WxH` suffix finds the original for most of them, and is wrong for an
 * original whose own name ends in `-WxH`. So the stripped name is used only to
 * find CANDIDATES, and a candidate matches only when the requested path is its
 * `_wp_attached_file`, one of the `sizes` its `_wp_attachment_metadata` lists,
 * or its `original_image`.
 *
 * Both reads go through core's own API — a `meta_query` and
 * `wp_get_attachment_metadata()` — never SQL against the tables.
 */
final class AttachmentFiles
{
    /**
     * The most paths one request may name.
     *
     * Each path expands to at most seven candidate names, so this bounds the
     * candidate query at 700 values. A consumer chunks above it, the same way
     * it already chunks `include[]`.
     */
    public const MAX_PATHS = 100;

    /**
     * The suffixes core adds to an original when it replaces the stored file.
     *
     * A large upload is stored as `photo-scaled.jpg` and a rotated one as
     * `photo-rotated.jpg`, while its sizes keep the original's name —
     * `photo-300x200.jpg`. Stripping a size suffix therefore yields a name no
     * record stores, and the candidate list has to include these as well.
     */
    private const REPLACED_ORIGINAL_SUFFIXES = ['-scaled', '-rotated'];

    /**
     * A requested path as it is compared, or null if it names nothing.
     *
     * A leading slash is tolerated because a path cut from a URL keeps it, and
     * `_wp_attached_file` never has one. Nothing else is rewritten: a path is
     * matched exactly as stored, case included.
     */
    public static function normalize(mixed $path): ?string
    {
        if (! \is_string($path)) {
            return null;
        }

        $path = ltrim(trim($path), '/');

        return $path === '' ? null : $path;
    }

    /**
     * Every stored name that could own one of these paths.
     *
     * A superset by design. It only narrows the query; `matches()` decides.
     *
     * @param  list<string>  $paths  Normalized paths.
     * @return list<string>
     */
    public static function candidates(array $paths): array
    {
        $names = [];

        foreach ($paths as $path) {
            $bases = [$path];

            // `photo-750x629.png` could be a size of `photo.png`.
            if (preg_match('/^(.+)-\d+x\d+(\.[^.\/]+)$/', $path, $matches) === 1) {
                $bases[] = $matches[1].$matches[2];
            }

            foreach ($bases as $base) {
                $names[$base] = $base;

                // A PDF's preview is `doc-pdf.jpg`, listed among the sizes of
                // the record that stores `doc.pdf`.
                if (preg_match('/^(.+)-pdf\.jpg$/', $base, $matches) === 1) {
                    $names[$matches[1].'.pdf'] = $matches[1].'.pdf';
                }

                if (preg_match('/^(.+)(\.[^.\/]+)$/', $base, $matches) !== 1) {
                    continue;
                }

                foreach (self::REPLACED_ORIGINAL_SUFFIXES as $suffix) {
                    $name = $matches[1].$suffix.$matches[2];
                    $names[$name] = $name;
                }
            }
        }

        return array_values($names);
    }

    /**
     * Whether a path names this attachment's file or one of its sizes.
     *
     * Sizes and `original_image` are stored as bare file names beside the
     * attached file, so they are compared under that file's directory.
     *
     * @param  mixed  $metadata  The record's `_wp_attachment_metadata`, as stored.
     */
    public static function matches(string $path, string $attachedFile, mixed $metadata): bool
    {
        if ($attachedFile === '') {
            return false;
        }

        if ($path === $attachedFile) {
            return true;
        }

        if (! \is_array($metadata)) {
            return false;
        }

        $directory = \dirname($attachedFile);
        $prefix = $directory === '.' ? '' : $directory.'/';

        $names = [];

        if (isset($metadata['original_image']) && \is_string($metadata['original_image'])) {
            $names[] = $metadata['original_image'];
        }

        if (isset($metadata['sizes']) && \is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (\is_array($size) && isset($size['file']) && \is_string($size['file'])) {
                    $names[] = $size['file'];
                }
            }
        }

        foreach ($names as $name) {
            if ($path === $prefix.$name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids of every attachment one of these paths names.
     *
     * A path matching nothing contributes nothing, so the caller can tell which
     * references a site does not serve. A path two records share returns both:
     * choosing between them is the consumer's decision, not this lookup's.
     *
     * @param  list<string>  $paths  Normalized paths.
     * @param  list<string>  $statuses  Statuses the request is serving.
     * @return list<int>
     */
    public static function resolve(array $paths, array $statuses): array
    {
        if ($paths === []) {
            return [];
        }

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => $statuses,
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            // Filters off for the same reason as the collection query (D-045):
            // a plugin that removes rows here removes them from the answer.
            'suppress_filters' => true,
            'update_post_term_cache' => false,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => '_wp_attached_file',
                    'value' => self::candidates($paths),
                    'compare' => 'IN',
                ],
            ],
        ]);

        // `fields => ids` returns ids; the type union is WP_Query's own, and so
        // is the null the stubs allow from 7.x on, which means no candidates.
        $candidates = array_map(
            static fn (int|WP_Post $post): int => $post instanceof WP_Post ? $post->ID : $post,
            $query->posts ?: []
        );

        // One meta query for every candidate rather than one each: an `ids`
        // query primes nothing, and both reads below are meta.
        update_postmeta_cache($candidates);

        $ids = [];

        /*
         * The comparison below is what decides, not the query. The query's `IN`
         * runs under the column's collation, which is case-insensitive, so it
         * can return `Photo.png` for `photo.png`; a stored path is matched
         * exactly as stored.
         */
        foreach ($candidates as $id) {
            $file = get_post_meta($id, '_wp_attached_file', true);

            if (! \is_string($file)) {
                continue;
            }

            // Unfiltered: the stored size list, not a plugin's view of it.
            $metadata = wp_get_attachment_metadata($id, true);

            foreach ($paths as $path) {
                if (self::matches($path, $file, $metadata)) {
                    $ids[] = $id;

                    break;
                }
            }
        }

        return $ids;
    }
}
