<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Repositories;

\defined( 'ABSPATH' ) || exit;

/**
 * Translation field state repository.
 *
 * Per-(source_kind, source_id, target_lang, reference) signature ledger.
 * Worker writes on translation success; Content_Change_Handler invalidates
 * on source edit; reconciliation reads find_for_pair as the gap-skip
 * short-circuit.
 *
 * Loss of this table costs LLM money (re-translations), not correctness —
 * reconciliation always reads live source/target as authority and only
 * uses field_state to skip already-done work.
 */
class Translation_Field_State_Repository {

    public function upsert(
        string $source_kind,
        int $source_id,
        string $target_lang,
        string $reference,
        string $source_hash
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pllat_translation_field_state';
        $sql   = "INSERT INTO {$table}
            (source_kind, source_id, target_lang, reference, source_hash, translated_at)
            VALUES (%s, %d, %s, %s, %s, NOW())
            ON DUPLICATE KEY UPDATE
                source_hash = VALUES(source_hash),
                translated_at = NOW()";
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name; values are prepared.
            $wpdb->prepare( $sql, $source_kind, $source_id, $target_lang, $reference, $source_hash ),
        );
    }

    public function find( string $source_kind, int $source_id, string $target_lang, string $reference ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'pllat_translation_field_state';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_kind = %s AND source_id = %d AND target_lang = %s AND reference = %s",
                $source_kind,
                $source_id,
                $target_lang,
                $reference,
            ),
            \ARRAY_A,
        );
        return null === $row ? null : $row;
    }

    /**
     * @return array<string, string>  reference => source_hash
     */
    public function find_for_pair( string $source_kind, int $source_id, string $target_lang ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pllat_translation_field_state';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT reference, source_hash FROM {$table}
                 WHERE source_kind = %s AND source_id = %d AND target_lang = %s
                 ORDER BY reference ASC",
                $source_kind,
                $source_id,
                $target_lang,
            ),
            \ARRAY_A,
        );
        $map = array();
        foreach ( $rows ?? array() as $row ) {
            $map[ $row['reference'] ] = $row['source_hash'];
        }
        return $map;
    }

    /**
     * @param array<int, string> $target_langs
     * @param array<int, string> $references
     */
    public function invalidate( string $source_kind, int $source_id, array $target_langs, array $references ): void {
        if ( \count( $target_langs ) === 0 || \count( $references ) === 0 ) {
            return;
        }
        global $wpdb;
        $table   = $wpdb->prefix . 'pllat_translation_field_state';
        $lang_ph = \implode( ',', \array_fill( 0, \count( $target_langs ), '%s' ) );
        $ref_ph  = \implode( ',', \array_fill( 0, \count( $references ), '%s' ) );
        $params  = \array_merge( array( $source_kind, $source_id ), $target_langs, $references );

        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- 2 fixed placeholders plus one %s per target lang and per reference, matching $params.
            $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE source_kind = %s AND source_id = %d
                   AND target_lang IN ({$lang_ph})
                   AND reference IN ({$ref_ph})",
                ...$params,
            ),
        );
    }

    public function delete_for_source( string $source_kind, int $source_id ): void {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'pllat_translation_field_state',
            array( 'source_kind' => $source_kind, 'source_id' => $source_id ),
            array( '%s', '%d' ),
        );
    }
}
