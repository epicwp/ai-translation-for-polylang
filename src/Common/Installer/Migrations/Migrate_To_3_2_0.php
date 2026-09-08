<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration 3.2.0: namespace taxonomy classification keys with tax__ prefix.
 *
 * Existing classification data may contain taxonomy slugs alongside post type
 * slugs. Prefix taxonomy-only entries with "tax__" to prevent slug collisions.
 */
final class Migrate_To_3_2_0 {
	public const VERSION = '3.2.0';

	public static function run(): void {
		$options = array(
			'pllat_meta_translate_keys',
			'pllat_meta_copy_keys',
			'pllat_meta_ignore_keys',
		);

		foreach ( $options as $option ) {
			$data = \get_option( $option, array() );

			if ( ! \is_array( $data ) ) {
				continue;
			}

			$updated = false;

			foreach ( \array_keys( $data ) as $key ) {
				if ( \str_starts_with( $key, 'tax__' ) ) {
					continue;
				}

				if ( \taxonomy_exists( $key ) && ! \post_type_exists( $key ) ) {
					$data[ 'tax__' . $key ] = $data[ $key ];
					unset( $data[ $key ] );
					$updated = true;
				}
			}

			if ( $updated ) {
				\update_option( $option, $data, false );
			}
		}
	}
}
