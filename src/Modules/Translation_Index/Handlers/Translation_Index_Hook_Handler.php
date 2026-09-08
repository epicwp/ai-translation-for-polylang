<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Translation_Index\Repositories\Translation_Field_State_Repository;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Maintains the translation_index table from Polylang and WP post lifecycle
 * events. Each handler is a thin upsert/delete; no business logic.
 *
 * Term-side equivalents live in Phase 1.8 (in this same handler class).
 */
#[Handler( tag: 'init', priority: 20 )]
class Translation_Index_Hook_Handler {

	public function __construct(
		protected Translation_Index_Repository $repository,
		protected Translation_Field_State_Repository $field_state_repository,
		protected Language_Manager $language_manager,
	) {}

	/**
	 * Polylang fires pll_save_post when a post's translation group is saved.
	 *
	 * @param int      $post_id      The post that was saved.
	 * @param \WP_Post $post         The post object.
	 * @param array    $translations Map of lang_slug => post_id (includes the source itself).
	 */
	#[Action( tag: 'pll_save_post', priority: 10 )]
	public function handle_save_post( int $post_id, $post, array $translations ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$source_lang = $this->language_manager->get_post_language( $post_id );
		if ( '' === $source_lang ) {
			return;
		}

		$this->upsert_post_pairs( $post, $source_lang, $translations );
	}

	/**
	 * Polylang creates media translations through create_media_translation(),
	 * which links the translation group via save_translations() and fires
	 * `pll_translate_media` — never `pll_save_post`. Without handling that
	 * event, media translations never reach the index incrementally; they only
	 * appear after the periodic full rebuild, which is cron-dependent and can
	 * lag for hours. That is why posts index fine but media looks invisible.
	 *
	 * Re-index every member of the translation group so all pair directions are
	 * captured (the source media, the new translation, and any pre-existing
	 * siblings), mirroring what the prime walk produces for the same posts.
	 *
	 * @param int    $source_id Source media (attachment) post ID.
	 * @param int    $tr_id     The newly created translation's post ID.
	 * @param string $tr_lang   Language slug of the new translation.
	 */
	#[Action( tag: 'pll_translate_media', priority: 10 )]
	public function handle_translate_media( int $source_id, int $tr_id, string $tr_lang ): void {
		$group = $this->language_manager->get_post_translations( $source_id );

		foreach ( $group as $member_id ) {
			$member_id = (int) $member_id;
			$post      = \get_post( $member_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$source_lang = $this->language_manager->get_post_language( $member_id );
			if ( '' === $source_lang ) {
				continue;
			}
			$this->upsert_post_pairs( $post, $source_lang, $group );
		}
	}

	/**
	 * Upsert one post's index rows for every other-language pair in its group.
	 *
	 * @param \WP_Post                $post         The source post.
	 * @param string                  $source_lang  Source language slug.
	 * @param array<string,int|mixed> $translations Map of lang_slug => post_id (includes the source itself).
	 */
	private function upsert_post_pairs( \WP_Post $post, string $source_lang, array $translations ): void {
		foreach ( $translations as $lang => $other_id ) {
			if ( $lang === $source_lang ) {
				continue;
			}
			$other_id = (int) $other_id;
			if ( $other_id <= 0 ) {
				continue;  // skip placeholders; index holds only real Polylang pairs
			}
			$this->repository->upsert(
				Translation_Index_Repository::KIND_POST,
				$post->ID,
				$source_lang,
				(string) $lang,
				$other_id,
				$post->post_type,
				$post->post_status,
			);
		}
	}

	#[Action( tag: 'before_delete_post', priority: 10 )]
	public function handle_delete_post( int $post_id ): void {
		$this->repository->delete_for_source( Translation_Index_Repository::KIND_POST, $post_id );
		$this->repository->delete_target_references( Translation_Index_Repository::KIND_POST, $post_id );
		$this->field_state_repository->delete_for_source( Translation_Index_Repository::KIND_POST, $post_id );
	}

	#[Action( tag: 'wp_trash_post', priority: 10 )]
	public function handle_trash_post( int $post_id ): void {
		$this->update_content_status_for_post( $post_id, 'trash' );
	}

	/**
	 * Fires after a post has been restored from the trash.
	 *
	 * Uses `untrashed_post` (past tense) so the post status is already updated
	 * in the database when we read it. `untrash_post` fires before the status
	 * update, so `get_post()` would still return 'trash' at that point.
	 *
	 * @param int    $post_id         The post ID.
	 * @param string $previous_status The status the post had before trashing.
	 */
	#[Action( tag: 'untrashed_post', priority: 10 )]
	public function handle_untrash_post( int $post_id, string $previous_status ): void {
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$this->update_content_status_for_post( $post_id, $post->post_status );
	}

	/**
	 * Polylang fires pll_save_term when a term's translation group is saved.
	 *
	 * @param int    $term_id      The term that was saved.
	 * @param string $taxonomy     The taxonomy slug.
	 * @param array  $translations Map of lang_slug => term_id (includes the source itself).
	 */
	#[Action( tag: 'pll_save_term', priority: 10 )]
	public function handle_save_term( int $term_id, string $taxonomy, array $translations ): void {
		$source_lang = $this->language_manager->get_term_language( $term_id );
		if ( '' === $source_lang ) {
			return;
		}

		foreach ( $translations as $lang => $other_id ) {
			if ( $lang === $source_lang ) {
				continue;
			}
			$other_id = (int) $other_id;
			if ( $other_id <= 0 ) {
				continue;  // skip placeholders; index holds only real Polylang pairs
			}
			$this->repository->upsert(
				Translation_Index_Repository::KIND_TERM,
				$term_id,
				$source_lang,
				(string) $lang,
				$other_id,
				$taxonomy,
				'active',
			);
		}
	}

	#[Action( tag: 'pre_delete_term', priority: 10 )]
	public function handle_delete_term( int $term_id ): void {
		$this->repository->delete_for_source( Translation_Index_Repository::KIND_TERM, $term_id );
		$this->repository->delete_target_references( Translation_Index_Repository::KIND_TERM, $term_id );
		$this->field_state_repository->delete_for_source( Translation_Index_Repository::KIND_TERM, $term_id );
	}

	private function update_content_status_for_post( int $post_id, string $new_status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'pllat_translation_index',
			array( 'content_status' => $new_status, 'last_synced' => \current_time( 'mysql' ) ),
			array( 'source_kind' => Translation_Index_Repository::KIND_POST, 'source_id' => $post_id ),
			array( '%s', '%s' ),
			array( '%s', '%d' ),
		);
	}
}
