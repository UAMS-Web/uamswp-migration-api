<?php

/**
 * Serializes a record's configured identifiers.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use UAMSWP\MigrationAPI\Config;
use WP_Post;

/**
 * The `identifiers` block: a fixed set of strings per post type (#88, D-104).
 *
 * The one curated shape in a record, and kept apart from `meta` and `acf` so
 * neither of those changes meaning. Both still carry every row as stored. This
 * block exists because the importer matches people and locations on these
 * values, and the raw shapes make that harder than it needs to be: a People
 * Showcase field never saved has no `meta` key at all, and an ACF repeater
 * arrives as rows of sub-field maps whose empty form is whatever ACF stored.
 *
 * So every configured key is present on every record of its type, and an empty
 * value is served as an empty string, or an empty list for a repeater. A value
 * is never trimmed or cast to a number: a leading zero is part of an
 * identifier.
 */
final class IdentifiersPayload
{
    /**
     * Serialize the identifiers configured for a post's type.
     *
     * Null for a type with none configured, the way `attachment` is null on a
     * record that is not an attachment, so the key's presence never has to be
     * guessed at.
     *
     * @param  WP_Post  $post  The record.
     * @param  array<string, mixed>  $acfNames  The ACF reader's stored view by field name.
     * @return array<string, string|list<string>>|null
     */
    public static function build(WP_Post $post, array $acfNames): ?array
    {
        $configured = Config::identifiers($post->post_type);

        if ($configured === []) {
            return null;
        }

        $identifiers = [];

        foreach ($configured as $served => $entry) {
            $identifiers[$served] = match ($entry['source']) {
                'meta' => self::text(get_post_meta($post->ID, $entry['name'], true)),
                'acf' => self::text($acfNames[$entry['name']] ?? null),
                'acf_list' => self::rows($acfNames[$entry['name']] ?? null, $entry['column']),
            };
        }

        return $identifiers;
    }

    /**
     * One value as a string, verbatim.
     *
     * Read from the stored view only. A value the field definition supplied
     * (D-038) is not an identifier anyone recorded, so it is served as empty.
     * An integer reaches here only when a filter on ACF's read returns one,
     * and its digits are the value. A float is not accepted, since printing
     * one can change its digits.
     */
    private static function text(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * A repeater as one string per row, in row order.
     *
     * A repeater with no rows does not come back from ACF as a list, and one
     * never saved has no value at all, so anything that is not a list of rows
     * is served as no rows. A row missing the column keeps its place as
     * an empty string, so the list still has one element per row.
     *
     * @return list<string>
     */
    private static function rows(mixed $value, string $column): array
    {
        if (! \is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            $rows[] = self::text(\is_array($row) ? ($row[$column] ?? null) : null);
        }

        return $rows;
    }
}
