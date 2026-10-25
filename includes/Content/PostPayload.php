<?php

/**
 * Serializes one post into the migration payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use UAMSWP\MigrationAPI\ACF\FieldReader;
use UAMSWP\MigrationAPI\Config;
use WP_Post;

/**
 * One record, as a strict superset of what a WXR `<item>` carries.
 *
 * Every field a WXR export emits appears here, mapped element for element, plus
 * the two things WXR cannot carry: current ACF values for the post types whose
 * data lives in ACF custom database tables, and a structured view of them.
 *
 * Nothing is rendered, resolved or composed (D-004). `post_content` is the
 * stored string, not `the_content`; a term is its stored row, not a link. The
 * consumer of this API is an importer, and every judgment made here is one it
 * would have to un-make.
 */
final class PostPayload
{
    /**
     * Serialize one post.
     *
     * @return array<string, mixed>
     */
    public static function build(WP_Post $post): array
    {
        $acf = FieldReader::forPost($post->ID);

        return [
            'id' => $post->ID,
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'title' => $post->post_title,
            'author' => self::author($post),

            /*
             * Both the local and the GMT column, as WXR emits both. They are not
             * derivable from one another without knowing the site's timezone at
             * the moment each row was written, which is not recoverable after
             * the fact.
             */
            'date_created' => $post->post_date,
            'date_created_gmt' => $post->post_date_gmt,
            'date_modified' => $post->post_modified,
            'date_modified_gmt' => $post->post_modified_gmt,

            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'parent' => $post->post_parent,
            'menu_order' => $post->menu_order,
            'guid' => $post->guid,
            'link' => (string) get_permalink($post),
            'is_sticky' => is_sticky($post->ID),
            'ping_status' => $post->ping_status,
            'comment_status' => $post->comment_status,
            'post_password' => $post->post_password,

            'terms' => self::terms($post),

            /*
             * Attachments only, mirroring core: the exporter emits
             * `wp:attachment_url` inside an `if ( 'attachment' === $post->post_type )`
             * and nothing else (export.php:650-652). Null on every other
             * record, so the key's presence is not a signal a consumer has
             * to guess at.
             */
            'attachment' => self::attachment($post),

            /*
             * Both meta shapes, on every record (D-005). `meta` is what an
             * existing importer already reads; `acf` is the only shape carrying
             * current values for `location` and `provider`, whose postmeta sits
             * frozen because Find-a-Doc disables `store_acf_values_in_core_meta`.
             */
            'meta' => self::meta($post),
            'acf' => [
                'keys' => $acf['keys'],
                'names' => $acf['names'],
                /*
                 * Field names can collide across field groups where keys cannot
                 * (D-025). Reported rather than resolved: `names` keeps the
                 * first, `keys` is lossless regardless, and a consumer that
                 * trusts `names` can see when it should not.
                 */
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

            /*
             * The configured identifiers, as plain strings (#88, D-104). Read
             * from the same stored view as `acf.names`, so the two never
             * disagree about what the site holds.
             */
            'identifiers' => IdentifiersPayload::build($post, $acf['names']),
        ];
    }

    /**
     * The file behind an attachment, or null for every other post type.
     *
     * **`attachment_url` is the one item-level WXR element nothing else here
     * carries, and neither neighboring field substitutes for it.** `guid` looks
     * like the file URL and is not one — WordPress documents it as an opaque
     * identifier, it is stored with whatever scheme the site used when the row
     * was written (`http://` on this install, against an `https://` site), and
     * core's own exporter emits it separately from `wp:attachment_url` precisely
     * because they are different things. `link` is the attachment *page*
     * permalink. Only `wp_get_attachment_url()` resolves the file, which is what
     * core calls and therefore what parity means.
     *
     * `metadata` is the decoded form of `_wp_attachment_metadata`, and it ships
     * *beside* the raw row rather than instead of it (D-005). The flat `meta`
     * map keeps the serialized string, because that is byte-for-byte what
     * `wp:postmeta` carries and an existing importer already decodes it;
     * this is the assembled shape for a consumer that would rather not. The
     * sizes array is where an asset importer finds every derivative it has to
     * fetch, so leaving it serialized would make the common case parse a string
     * the API had already parsed.
     *
     * @return array<string, mixed>|null
     */
    private static function attachment(WP_Post $post): ?array
    {
        if ($post->post_type !== 'attachment') {
            return null;
        }

        $url = wp_get_attachment_url($post->ID);
        $metadata = wp_get_attachment_metadata($post->ID);
        $file = get_post_meta($post->ID, '_wp_attached_file', true);
        $alt = get_post_meta($post->ID, '_wp_attachment_image_alt', true);

        return [
            'url' => \is_string($url) ? $url : '',

            // On the post row rather than in meta, and absent from WXR — which
            // makes it a superset rather than parity. An asset importer needs
            // it to decide what it is fetching before it fetches.
            'mime_type' => $post->post_mime_type,

            // The path relative to the uploads directory, which is what
            // survives a move between hosts where an absolute URL does not.
            'file' => \is_string($file) ? $file : '',
            'alt' => \is_string($alt) ? $alt : '',
            'metadata' => \is_array($metadata) ? $metadata : null,
        ];
    }

    /**
     * Every postmeta row, exactly as stored — plus what WordPress's own
     * exporter synthesizes at export time for a post type that needs it (#77).
     *
     * Underscore-prefixed keys included, and that is load-bearing
     * (D-007): ACF field-key references (`_fieldname`), navigation
     * (`_menu_item_*`) and featured images (`_thumbnail_id`) all live behind
     * that prefix, so the filter that looks like hygiene silently breaks three
     * unrelated things.
     *
     * Values stay as WordPress hands them back — arrays of raw strings, one
     * entry per row, serialized payloads left serialized. WXR emits one
     * `<wp:postmeta>` per row and does not unserialize either, so this is the
     * shape an existing importer already parses. Unpacking here would be a
     * judgment about a value's type that the stored row does not actually make.
     *
     * @return array<string, mixed>
     */
    private static function meta(WP_Post $post): array
    {
        $meta = get_post_meta($post->ID);

        $rows = [];

        if (\is_array($meta)) {
            foreach ($meta as $key => $value) {
                if (\is_string($key)) {
                    $rows[$key] = $value;
                }
            }
        }

        return $rows + self::synthesizedMeta($post);
    }

    /**
     * Export-time meta WordPress's own `export_wp()` adds to a WXR document
     * that is never a real postmeta row (#77).
     *
     * **`_tablepress_export_table_id` is not stored.** TablePress injects it
     * during export and strips it again on import —
     * `models/model-table.php` reads it at import to resolve which table a
     * shortcode refers to, then deletes it, so it exists only inside a WXR
     * document and this endpoint's raw-postmeta read (`get_post_meta()`
     * above) can never see it. `wordpress-importer` depends on the key to
     * match a `[table id=N /]` shortcode in page content to the table it
     * imported; without it, table linking silently breaks on an API-sourced
     * import while a WXR-sourced one keeps working.
     *
     * The value is reachable another way: `tablepress_tables`'s `table_post`
     * map (table id => post id) is the authoritative source TablePress
     * itself reads from, and `/options` already serves that row unfiltered.
     * Synthesizing the field WordPress's own exporter also synthesizes keeps
     * "strict superset of WXR" true here rather than approximately true.
     *
     * @return array<string, mixed>
     */
    private static function synthesizedMeta(WP_Post $post): array
    {
        if ($post->post_type !== 'tablepress_table') {
            return [];
        }

        $tables = get_option('tablepress_tables');

        // TablePress stores this option JSON-ENCODED, so `get_option()` hands back
        // a STRING on every real install: `TablePress_WP_Option` writes through
        // `wp_json_encode()` and reads back through `json_decode()`. Without this
        // decode the guard below rejected every production value, and the field
        // was never emitted once -- while the suite stayed green, because its
        // fixtures seeded a PHP array that TablePress does not write (#253).
        //
        // `json_decode()` returns null on anything that is not valid JSON, so the
        // guard still fails closed: this widens what is accepted without weakening
        // what is rejected. A value that is already an array passes through
        // untouched, so nothing that worked before stops working.
        if (\is_string($tables)) {
            $tables = json_decode($tables, true);
        }

        if (! \is_array($tables) || ! \is_array($tables['table_post'] ?? null)) {
            return [];
        }

        $tableId = array_search($post->ID, $tables['table_post'], true);

        if (! \is_int($tableId) && ! \is_string($tableId)) {
            return [];
        }

        return ['_tablepress_export_table_id' => [(string) $tableId]];
    }

    /**
     * The post's author, by id and by login.
     *
     * WXR identifies an author by `dc:creator`, which is the **login** — so the
     * login is what an existing importer matches on, while the id is what
     * anything reading this database directly needs. A deleted author yields a
     * null login rather than dropping the id, because the id is still the
     * truthful record of what the row says.
     *
     * @return array{id: int, login: ?string, display_name: ?string}
     */
    private static function author(WP_Post $post): array
    {
        $id = (int) $post->post_author;
        $user = $id > 0 ? get_userdata($id) : false;

        return [
            'id' => $id,
            'login' => $user === false ? null : $user->user_login,
            'display_name' => $user === false ? null : $user->display_name,
        ];
    }

    /**
     * Assigned terms, grouped by taxonomy.
     *
     * Scoped to the taxonomies configuration exposes *and* the ones actually
     * registered for this post type — one eligibility list serves the whole
     * network, and not every site registers every taxonomy.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function terms(WP_Post $post): array
    {
        $taxonomies = array_values(
            array_intersect(get_object_taxonomies($post->post_type), Config::taxonomies())
        );

        if ($taxonomies === []) {
            return [];
        }

        $terms = wp_get_object_terms($post->ID, $taxonomies);

        if (! \is_array($terms)) {
            return [];
        }

        $grouped = [];

        foreach ($terms as $term) {
            $grouped[$term->taxonomy][] = [
                'term_id' => $term->term_id,
                'slug' => $term->slug,
                'name' => $term->name,
                'parent' => $term->parent,
                // WXR carries the slug and the display name and nothing else,
                // but a term's description is content an editor wrote and the
                // term endpoint is a separate ticket — so it travels here.
                'description' => $term->description,
            ];
        }

        return $grouped;
    }
}
