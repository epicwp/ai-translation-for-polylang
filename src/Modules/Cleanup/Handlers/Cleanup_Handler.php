<?php
/**
 * Cleanup_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Cleanup
 */

declare(strict_types=1);

namespace PLLAT\Cleanup\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Cleanup\Services\Cleanup_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Handles cleanup when content is deleted.
 *
 * Removes orphaned jobs bidirectionally:
 * - Jobs FROM deleted content (id_from = deleted ID)
 * - Jobs TO deleted content (id_to = deleted ID)
 *
 * This prevents database clutter and ensures referential integrity.
 */
#[Handler(
    tag: 'init',
    priority: 16,
    context: Handler::CTX_CRON | Handler::CTX_ADMIN | Handler::CTX_AJAX | Handler::CTX_CLI | Handler::CTX_REST,
)]
class Cleanup_Handler {
    /**
     * Constructor.
     *
     * @param Cleanup_Service $cleanup_service Cleanup service.
     */
    public function __construct(
        private Cleanup_Service $cleanup_service,
    ) {
    }

    /**
     * Clean up jobs when post is deleted.
     *
     * @param int $post_id The post ID being deleted.
     * @return void
     */
    #[Action( tag: 'before_delete_post' )]
    public function cleanup_post_jobs( int $post_id ): void {
        $this->cleanup_service->cleanup_jobs_for_content( 'post', $post_id );
    }

    /**
     * Clean up jobs when term is deleted.
     *
     * @param int $term_id The term ID being deleted.
     * @return void
     */
    #[Action( tag: 'pre_delete_term' )]
    public function cleanup_term_jobs( int $term_id ): void {
        $this->cleanup_service->cleanup_jobs_for_content( 'term', $term_id );
    }
}
