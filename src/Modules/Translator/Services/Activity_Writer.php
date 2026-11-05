<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Append-only writer for the pllat_activity_log table.
 *
 * Pulled out of Lean_Job_Worker so per-field history bookkeeping lives
 * in one place and the worker loop reads as plain orchestration.
 *
 * Stateless — every call takes the run_id explicitly so the writer can
 * be reused outside the worker context (e.g. tests).
 */
class Activity_Writer {

	/**
	 * Append one 'completed' row per translated field.
	 *
	 * @param array<string, mixed>     $unit         The claimed unit (source_kind, source_id, target_lang).
	 * @param array<string, mixed>     $translations Reference => translated value.
	 * @param int|null                 $run_id       Run that produced these translations; null for ad-hoc writes.
	 */
	public function record_success( array $unit, array $translations, ?int $run_id ): void {
		global $wpdb;
		$now = \current_time( 'mysql', true );
		foreach ( \array_keys( $translations ) as $reference ) {
			$wpdb->insert(
				$wpdb->prefix . 'pllat_activity_log',
				array(
					'run_id'      => $run_id !== null && $run_id > 0 ? $run_id : null,
					'source_kind' => $unit['source_kind'],
					'source_id'   => (int) $unit['source_id'],
					'source_lang' => (string) ( $unit['source_lang'] ?? '' ),
					'target_lang' => $unit['target_lang'],
					'reference'   => (string) $reference,
					'status'      => 'completed',
					'logged_at'   => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			);
		}
	}

	/**
	 * Append a single 'failed' row when a claim cannot be processed.
	 *
	 * Error message is truncated to 500 chars to match the column width.
	 *
	 * @param array<string, mixed> $unit          The claimed unit.
	 * @param string               $error_message Exception message.
	 * @param int|null             $run_id        Run id; null for ad-hoc writes.
	 */
	public function record_failure( array $unit, string $error_message, ?int $run_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'pllat_activity_log',
			array(
				'run_id'        => $run_id !== null && $run_id > 0 ? $run_id : null,
				'source_kind'   => $unit['source_kind'],
				'source_id'     => (int) $unit['source_id'],
				'source_lang'   => (string) ( $unit['source_lang'] ?? '' ),
				'target_lang'   => $unit['target_lang'],
				'reference'     => null,
				'status'        => 'failed',
				'error_message' => \substr( $error_message, 0, 500 ),
				'logged_at'     => \current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
		);
	}

	/**
	 * Append a single 'skipped' row when a claimed unit needed no AI work.
	 *
	 * Reason is truncated to 500 chars to match the column width.
	 *
	 * @param array<string, mixed> $unit   The claimed unit.
	 * @param string               $reason Why nothing was translated (e.g. 'no_gap_fields').
	 * @param int|null             $run_id Run id; null for ad-hoc writes.
	 */
	public function record_skipped( array $unit, string $reason, ?int $run_id ): void {
		global $wpdb;
		$run_id = $run_id !== null && $run_id > 0 ? $run_id : null;
		if ( $this->has_skipped_row( $unit, $run_id ) ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'pllat_activity_log',
			array(
				'run_id'        => $run_id,
				'source_kind'   => $unit['source_kind'],
				'source_id'     => (int) $unit['source_id'],
				'source_lang'   => (string) ( $unit['source_lang'] ?? '' ),
				'target_lang'   => $unit['target_lang'],
				'reference'     => null,
				'status'        => 'skipped',
				'error_message' => \substr( $reason, 0, 500 ),
				'logged_at'     => \current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
		);
	}

	/**
	 * Whether this run already logged a 'skipped' row for the unit.
	 *
	 * A unit that stays claimable (no gaps, no target) is re-claimed within
	 * a run; one skipped outcome per unit and run keeps the log countable.
	 *
	 * @param array<string, mixed> $unit   The claimed unit.
	 * @param int|null             $run_id Normalised run id (null = ad-hoc).
	 */
	private function has_skipped_row( array $unit, ?int $run_id ): bool {
		global $wpdb;
		$logged_runs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT run_id FROM {$wpdb->prefix}pllat_activity_log WHERE status = 'skipped' AND source_kind = %s AND source_id = %d AND target_lang = %s",
				$unit['source_kind'],
				(int) $unit['source_id'],
				$unit['target_lang'],
			),
		);
		foreach ( $logged_runs as $logged_run ) {
			if ( ( null === $logged_run ? null : (int) $logged_run ) === $run_id ) {
				return true;
			}
		}
		return false;
	}
}
