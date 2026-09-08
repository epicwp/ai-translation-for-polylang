<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration 3.1.0: unify meta field options.
 *
 * Merges old flat custom meta key arrays and per-post-type explored keys
 * into three unified per-content-type options. User-defined keys take
 * precedence over AI classifications in conflict.
 */
final class Migrate_To_3_1_0 {
	public const VERSION = '3.1.0';

	public static function run(): void {
		// 1. Read old data.
		$custom_post = \get_option( 'pllat_custom_post_meta_keys', array() );
		$custom_term = \get_option( 'pllat_custom_term_meta_keys', array() );

		$explored_translate = \get_option( 'pllat_explored_translate_meta_keys', array() );
		$explored_copy      = \get_option( 'pllat_explored_copy_meta_keys', array() );
		$explored_ignore    = \get_option( 'pllat_explored_ignore_meta_keys', array() );

		// Normalize.
		$custom_post = \is_array( $custom_post ) ? \array_filter( $custom_post, 'strlen' ) : array();
		$custom_term = \is_array( $custom_term ) ? \array_filter( $custom_term, 'strlen' ) : array();

		$explored_translate = \is_array( $explored_translate ) ? $explored_translate : array();
		$explored_copy      = \is_array( $explored_copy ) ? $explored_copy : array();
		$explored_ignore    = \is_array( $explored_ignore ) ? $explored_ignore : array();

		// 2. Start with explored data (already per-content-type).
		$translate = $explored_translate;
		$copy      = $explored_copy;
		$ignore    = $explored_ignore;

		// 3. Fan out custom post meta keys as "translate" across active post types.
		if ( \count( $custom_post ) > 0 ) {
			$post_types = \get_post_types( array( 'public' => true ) );

			foreach ( $post_types as $pt ) {
				$existing         = $translate[ $pt ] ?? array();
				$translate[ $pt ] = \array_values( \array_unique( \array_merge( $existing, $custom_post ) ) );

				// User intent wins: remove these keys from copy/ignore for this post type.
				if ( isset( $copy[ $pt ] ) ) {
					$copy[ $pt ] = \array_values( \array_diff( $copy[ $pt ], $custom_post ) );
				}
				if ( isset( $ignore[ $pt ] ) ) {
					$ignore[ $pt ] = \array_values( \array_diff( $ignore[ $pt ], $custom_post ) );
				}
			}
		}

		// 4. Fan out custom term meta keys as "translate" across active taxonomies.
		if ( \count( $custom_term ) > 0 ) {
			$taxonomies = \get_taxonomies( array( 'public' => true ) );

			foreach ( $taxonomies as $tax ) {
				$existing          = $translate[ $tax ] ?? array();
				$translate[ $tax ] = \array_values( \array_unique( \array_merge( $existing, $custom_term ) ) );
			}
		}

		// 5. Write new unified options.
		\update_option( 'pllat_meta_translate_keys', $translate, false );
		\update_option( 'pllat_meta_copy_keys', $copy, false );
		\update_option( 'pllat_meta_ignore_keys', $ignore, false );

		// 6. Clean up old options.
		\delete_option( 'pllat_custom_post_meta_keys' );
		\delete_option( 'pllat_custom_term_meta_keys' );
		\delete_option( 'pllat_explored_translate_meta_keys' );
		\delete_option( 'pllat_explored_copy_meta_keys' );
		\delete_option( 'pllat_explored_ignore_meta_keys' );
	}
}
