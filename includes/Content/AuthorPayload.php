<?php

/**
 * Serializes one post author into the migration payload.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Content;

use WP_User;

/**
 * One author, as a strict superset of WXR's `wp:author` block.
 *
 * Core builds that block from `SELECT DISTINCT post_author FROM wp_posts WHERE
 * post_status != 'auto-draft'` and emits six fields per author
 * (`wp-admin/includes/export.php:424-429`). This is those six, plus the
 * nicename.
 *
 * **The email ships.** It is personal data and it is also what 861 authors
 * across the captured corpus are matched on, and the argument for withholding
 * it does not survive contact with where that data already is: 2,090 author
 * blocks carrying emails sit in the 259 exported XML files. Withholding it here
 * protects nothing while risking attribution, and this API is authenticated and
 * already serves unpublished content and raw site settings. Recorded as D-040.
 */
final class AuthorPayload
{
    /**
     * Serialize one author.
     *
     * @return array<string, mixed>
     */
    public static function build(WP_User $user): array
    {
        return [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,

            /*
             * `first_name` and `last_name` are usermeta rather than columns on
             * the user row, and `WP_User` resolves them lazily through
             * `__get()`. Empty for most users on this network — measured at
             * zero of two on the development install — which is a fact about
             * the data rather than a reason to omit the field: WXR emits them
             * empty too, and a consumer that sees the key can tell an empty
             * name from an absent one.
             */
            'first_name' => (string) $user->first_name,
            'last_name' => (string) $user->last_name,

            /*
             * A superset of WXR, and the one field worth adding: the nicename
             * is the URL-safe slug WordPress derives from the login, it is
             * stable, and it is what an author archive is keyed on. Matching on
             * it does not require the email.
             */
            'nicename' => $user->user_nicename,
        ];
    }
}
