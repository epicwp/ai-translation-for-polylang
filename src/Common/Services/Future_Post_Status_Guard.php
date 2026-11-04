<?php
/**
 * Future_Post_Status_Guard class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Pins a 'future' post's status (and date) while a callback writes to it.
 *
 * WordPress re-derives post_status from post_date_gmt on every
 * wp_insert_post()/wp_update_post() call: a 'future' post whose scheduled
 * date has already passed silently becomes 'publish' on the very next
 * write. A translation job writes a scheduled target across several
 * separate calls (one per field, plus a later internal-links pass) — on a
 * delayed Action Scheduler queue or a broken wp-cron, the source's
 * publish date can slip into the past mid-job, so an unguarded write-back
 * would publish the translation before the source (issue #468 /
 * TS-57452941). Wrapping those writes in around() keeps the target
 * 'future' for the duration of the callback; the target is left to
 * publish normally (via wp-cron or its own next real edit) once the job
 * is done.
 *
 * No-op for anything that isn't currently a 'future' post (including term
 * IDs — terms have no post_status, so get_post() finds nothing to pin,
 * and a term write-back never touches wp_insert_post_data in the first
 * place).
 */
final class Future_Post_Status_Guard {

    /**
     * Run $callback with $post_id's post_status (and dates) pinned if it is
     * currently 'future'. Scoped to this one post ID via the postarr 'ID'
     * check, so unrelated saves during the callback are unaffected.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function around( int $post_id, callable $callback ): mixed {
        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post || 'future' !== $post->post_status ) {
            return $callback();
        }

        $post_date     = $post->post_date;
        $post_date_gmt = $post->post_date_gmt;

        $pin = static function ( array $data, array $postarr ) use ( $post_id, $post_date, $post_date_gmt ): array {
            if ( (int) ( $postarr['ID'] ?? 0 ) !== $post_id ) {
                return $data;
            }
            $data['post_status']   = 'future';
            $data['post_date']     = $post_date;
            $data['post_date_gmt'] = $post_date_gmt;
            return $data;
        };

        // Highest priority so no other wp_insert_post_data filter can
        // re-derive the status after us.
        \add_filter( 'wp_insert_post_data', $pin, \PHP_INT_MAX, 2 );
        try {
            return $callback();
        } finally {
            \remove_filter( 'wp_insert_post_data', $pin, \PHP_INT_MAX );
        }
    }
}
