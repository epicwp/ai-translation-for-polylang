<?php
declare(strict_types=1);

namespace PLLAT\Content\Services\Interfaces;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for content services that persist translations to WordPress content.
 */
interface Content_Service {
    /**
     * Parse a reference key into type and field components.
     *
     * @param string $reference The reference key to parse.
     * @return array{type:string,field:string} Parsed info with 'type' and 'field'.
     */
    public function parse_reference( string $reference ): array;

    /**
     * Write a single translated field to the target content (lean pipeline entry point).
     *
     * Per-field public API used by Lean_Job_Worker. Routing and the
     * pllat_handle_field_update integration filter are delegated to
     * update_content_field. The lean worker wraps its write loop in
     * Language_Manager::suspend_meta_sync/resume_meta_sync; this method
     * itself does not suspend anything.
     *
     * @param int    $target_id    Target post or term ID.
     * @param string $content_type 'post' | 'term'.
     * @param string $reference    Reference key (e.g. 'post_title', '_meta|key').
     * @param mixed  $value        Translated value.
     *
     * @throws \Exception When $content_type is unknown.
     */
    public function write_field_translation(
        int $target_id,
        string $content_type,
        string $reference,
        mixed $value,
    ): void;
}
