<?php

/**
 * Serializes one taxonomy term into the migration payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use UAMSWP\MigrationAPI\ACF\FieldReader;
use WP_Term;

/**
 * One term, as a strict superset of every taxonomy shape WXR carries.
 *
 * WXR expresses taxonomies three different ways and the importer's `XmlParser`
 * reads all three — `getTerms()`, `getTags()` and `getCategories()`. This is one
 * shape covering what each of them returns, so a consumer stops branching on
 * which legacy element a term happened to arrive in.
 *
 * Parity work rather than gap-filling: WXR does carry `wp:termmeta`, in 103 of
 * the 259 captured exports. What it cannot do is page, which is the same reason
 * the posts endpoint exists.
 *
 * **The slug is load-bearing and is not decoration.** Navigation menu selection
 * keys on slug rather than id, so a term served without one is a menu the
 * importer cannot rebuild. Ids do not survive a migration; slugs do.
 */
final class TermPayload
{
    /**
     * Serialize one term.
     *
     * @return array<string, mixed>
     */
    public static function build(WP_Term $term): array
    {
        $acf = FieldReader::read(['taxonomy' => $term->taxonomy], 'term_'.$term->term_id);

        return [
            'id' => $term->term_id,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,

            /*
             * `parent` is 0 for a top-level term, which is WordPress' own
             * sentinel and is left as it is rather than turned into null: the
             * stored value is what a consumer rebuilding a hierarchy needs, and
             * translating it here would be a judgment the row does not make.
             */
            'parent' => $term->parent,

            /*
             * The parent SLUG as well, because that is what every WXR shape
             * actually carries: core's exporter writes
             * `$terms[$t->parent]->slug` for `wp:term_parent` and the same for
             * `wp:category_parent` (wp-admin/includes/export.php:536, :560). An
             * id alone is not derivable back to it without the whole taxonomy
             * in hand, and ids do not survive a migration — the same argument
             * that makes the term's own slug load-bearing.
             *
             * Empty string for a top-level term, matching what the exporter
             * emits rather than inventing a null.
             */
            'parent_slug' => self::parentSlug($term),

            /*
             * `term_taxonomy_id` alongside `term_id` because they are not the
             * same number and WXR's `wp:term_id` is ambiguous about which it
             * means. A term shared across taxonomies has one `term_id` and a
             * `term_taxonomy_id` per taxonomy, and the relationship table keys
             * on the latter — so an importer rebuilding assignments needs it.
             */
            'term_taxonomy_id' => $term->term_taxonomy_id,
            'count' => $term->count,
            'link' => self::link($term),

            /*
             * Both meta shapes, on every record, for the reason D-005 gives on
             * posts: `meta` is what an existing importer already reads, and
             * `acf` is the assembled view. Find-a-Doc carries ACF field groups
             * on thirteen taxonomies, so for those terms the structured shape is
             * the one that reads as the editor entered it.
             */
            'meta' => self::meta($term),
            'acf' => [
                'keys' => $acf['keys'],
                'names' => $acf['names'],
                'name_collisions' => $acf['collisions'],
                /*
                 * Values the field DEFINITION supplied, not the row (D-038).
                 * Separated rather than mixed in above, so each map means
                 * exactly one thing: `keys`/`names` are what the site stored,
                 * and these are what ACF would return to a rendering page for a
                 * field nobody has filled in. Both are facts; composing them
                 * into one map was the defect.
                 */
                'defaulted' => [
                    'keys' => $acf['defaulted_keys'],
                    'names' => $acf['defaulted_names'],
                ],            ],
        ];
    }

    /**
     * The parent term's slug, or an empty string at the top level.
     *
     * Resolved against the same taxonomy, so a parent id that has been orphaned
     * — which a partially-deleted hierarchy can leave behind — yields an empty
     * string rather than a slug from somewhere else.
     */
    private static function parentSlug(WP_Term $term): string
    {
        if ($term->parent === 0) {
            return '';
        }

        $parent = get_term($term->parent, $term->taxonomy);

        return $parent instanceof WP_Term ? $parent->slug : '';
    }

    /**
     * Every termmeta row, exactly as stored.
     *
     * Underscore-prefixed keys included, and that is the same rule D-007 states
     * for postmeta rather than a separate decision: ACF writes a field-key
     * reference at `_<fieldname>` beside every value it stores, so a filter that
     * drops the prefix keeps the values and discards the map back to the fields
     * that produced them.
     *
     * Values stay as WordPress hands them back — an array of raw strings per
     * key, which nothing here decodes. `get_term_meta()` without a key returns
     * every row, which is the whole point.
     *
     * @return array<string, mixed>
     */
    private static function meta(WP_Term $term): array
    {
        $meta = get_term_meta($term->term_id);

        if (! \is_array($meta)) {
            return [];
        }

        $rows = [];

        foreach ($meta as $key => $value) {
            if (\is_string($key)) {
                $rows[$key] = $value;
            }
        }

        return $rows;
    }

    /**
     * The term's archive URL, or an empty string.
     *
     * `get_term_link()` returns a `WP_Error` when the taxonomy is not registered
     * — which happens on a network where one eligibility list serves every site
     * — so the error is turned into an empty string rather than allowed to
     * serialize as an object into the payload.
     */
    private static function link(WP_Term $term): string
    {
        $link = get_term_link($term);

        return \is_string($link) ? $link : '';
    }
}
