<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Translation_Index\Repositories\Translation_Field_State_Repository;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use XWP\DI\Decorators\Handler;

/**
 * Invalidates translation_field_state rows when a source post or term has
 * fields edited. Called by Content_Change_Handler after diffing pre/post
 * field values.
 *
 * Resolves the per-source target language set (all enabled languages
 * except the source's own) and DELETEs the field_state rows for the
 * specified references.
 */
#[Handler( tag: 'init', priority: 20 )]
class Field_State_Invalidation_Handler {

    public function __construct(
        protected Translation_Field_State_Repository $field_state_repository,
        protected Translation_Index_Repository $index_repository,
        protected Language_Manager $language_manager,
    ) {}

    /**
     * @param array<int, string> $references Reference keys (e.g. 'post_title', '_meta|seo_title').
     */
    public function invalidate_post_fields( int $post_id, array $references ): void {
        $this->invalidate(
            Translation_Index_Repository::KIND_POST,
            $post_id,
            $this->language_manager->get_post_language( $post_id ),
            $references,
        );
    }

    /**
     * @param array<int, string> $references
     */
    public function invalidate_term_fields( int $term_id, array $references ): void {
        $this->invalidate(
            Translation_Index_Repository::KIND_TERM,
            $term_id,
            $this->language_manager->get_term_language( $term_id ),
            $references,
        );
    }

    /**
     * @param array<int, string> $references
     */
    private function invalidate( string $source_kind, int $source_id, string $source_lang, array $references ): void {
        if ( '' === $source_lang ) {
            return;
        }
        if ( \count( $references ) === 0 ) {
            return;
        }
        $target_langs = $this->resolve_target_langs( $source_lang );
        if ( \count( $target_langs ) === 0 ) {
            return;
        }
        $this->field_state_repository->invalidate( $source_kind, $source_id, $target_langs, $references );
        $this->index_repository->mark_outdated_bulk( $source_kind, $source_id, $target_langs );
    }

    /**
     * @return array<int, string>
     */
    private function resolve_target_langs( string $source_lang ): array {
        $all = $this->language_manager->get_available_languages();
        return \array_values( \array_filter( $all, static fn( string $lang ) => $lang !== $source_lang ) );
    }
}
