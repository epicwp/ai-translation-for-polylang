<?php
/**
 * Meta_Copy_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Handlers;

\defined( 'ABSPATH' ) || exit;

use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Shapes Polylang's post meta copy list in every edition: copies page-builder
 * meta as-is when Polylang creates a translation and strips WordPress
 * per-post internals from the list.
 *
 * Classified custom fields (translate / copy / ignore) are a pro feature
 * handled by Meta_Fields_Module; without it nothing else contributes to
 * `pll_copy_post_metas`, so Polylang's own custom-fields synchronisation is
 * the only thing that copies ordinary meta.
 */
#[Handler( tag: 'init', priority: 11 )]
class Meta_Copy_Handler {
    /**
     * Page-builder meta key prefixes.
     *
     * Every key under them is copied as-is when Polylang creates a
     * translation (the builder integrations, pro, overwrite the content key
     * with its translation afterwards; free keeps the copy). Never
     * AI-classified in either edition.
     */
    public const BUILDER_PREFIXES = array(
        '_elementor_',
        '_bricks_',
    );

    /**
     * WP internal key prefixes to always exclude, from the copy list here
     * and from the AI meta scan (Meta_Field_Filter_Service, pro).
     */
    public const WP_INTERNAL_PREFIXES = array(
        '_edit_',
        '_wp_old_',
        '_oembed_',
        '_transient_',
        '_wp_trash_',
    );

    /**
     * Add the page-builder meta of the source to Polylang's meta copy list.
     *
     * Elementor and Bricks keep the layout in underscore-prefixed meta, which
     * Polylang's own copy skips as protected. Copying those keys as they are
     * when the translation is created keeps the layout on the translation in
     * every edition.
     *
     * Only contributes on creation-time copy (`$sync === false`). Polylang
     * also fires this filter with `$sync = true` from its live sync
     * (`PLL_Sync_Metas::update_meta` / `delete_meta`) on every meta write;
     * contributing keys there force-enables two-way live sync even when the
     * user disabled Polylang's custom-fields sync, so emptying or deleting
     * a meta on one translation wipes it on the source and every other
     * translation (issue #460).
     *
     * @param array<string> $keys Current meta keys to copy.
     * @param bool          $sync Whether this is a sync (vs. create) operation.
     * @param int           $from Source post ID.
     * @param int           $to   Target post ID.
     * @param string        $lang Target language slug.
     * @return array<string>
     */
    #[Filter( tag: 'pll_copy_post_metas', priority: 10 )]
    public function add_builder_keys( array $keys, bool $sync, int $from, int $to, string $lang ): array {
        if ( true === $sync ) {
            return $keys;
        }

        $prefixes = self::BUILDER_PREFIXES;
        $builder  = \array_filter(
            \array_map( 'strval', \array_keys( \get_post_meta( $from ) ) ),
            static function ( string $key ) use ( $prefixes ): bool {
                foreach ( $prefixes as $prefix ) {
                    if ( \str_starts_with( $key, $prefix ) ) {
                        return true;
                    }
                }
                return false;
            },
        );

        return \array_values( \array_unique( \array_merge( $keys, $builder ) ) );
    }

    /**
     * Strip WordPress internal per-post-instance meta keys from the copy list.
     *
     * Polylang Pro's sync-post-model copies ALL custom fields except
     * `_edit_last` and `_edit_lock`, so per-post-instance internals like
     * `_wp_old_slug`, `_wp_old_date`, `_wp_trash_meta_*` leak from the
     * source post to its translations. The leak is observable as e.g. a
     * French translation carrying the Dutch source's historical slug in
     * `_wp_old_slug` — confusing data, not functionally fatal, but never
     * what the customer wants.
     *
     * Runs at priority 100 — well after Polylang's own contribution at
     * priority 5 and the priority 10 contributions above — so we always
     * see the final list and can subtract.
     *
     * @param array<string> $keys Current meta keys to copy.
     * @return array<string>
     */
    #[Filter( tag: 'pll_copy_post_metas', priority: 100 )]
    public function strip_wp_internals_from_copy( array $keys ): array {
        $prefixes = self::WP_INTERNAL_PREFIXES;

        return \array_values(
            \array_filter(
                $keys,
                static function ( string $key ) use ( $prefixes ): bool {
                    foreach ( $prefixes as $prefix ) {
                        if ( \str_starts_with( $key, $prefix ) ) {
                            return false;
                        }
                    }
                    return true;
                },
            ),
        );
    }
}
