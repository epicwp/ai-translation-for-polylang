<?php
/**
 * Meta_Field_Scan_Result value object.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of a meta field AI classification scan.
 */
class Meta_Field_Scan_Result {

	/**
	 * @param int                  $translate_count Number of keys classified as translate.
	 * @param int                  $copy_count      Number of keys classified as copy.
	 * @param int                  $ignore_count    Number of keys classified as ignore.
	 * @param array<string,string> $classifications Map of meta_key => classification.
	 * @param string               $post_type       Post type that was scanned.
	 */
	public function __construct(
		public readonly int $translate_count,
		public readonly int $copy_count,
		public readonly int $ignore_count,
		public readonly array $classifications,
		public readonly string $post_type,
	) {}
}
