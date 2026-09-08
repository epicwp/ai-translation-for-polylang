<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Installer\Migrations\Migrate_To_2_0_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_2_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_3_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_4_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_6_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_7_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_8_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_2_9_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_0_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_1_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_2_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_3_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_4_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_5_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_6_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_7_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_8_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_9_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_10_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_11_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_12_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_13_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_14_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_15_0;
use PLLAT\Common\Installer\Migrations\Migrate_To_3_16_0;
use PLLAT\Common\Installer\Migrations\Migration_Helpers;

/**
 * Handles DB schema creation and upgrades for this plugin.
 *
 * Schema lives in install(); per-version data migrations live in
 * Migrations/Migrate_To_X_Y_Z classes. run_migrations() walks the registry
 * below and runs every migration whose VERSION the user crossed during
 * the current upgrade.
 */
class Installer {

	/**
	 * Option holding the migration ledger (a bounded list of the migrations
	 * that have run, with outcome). Read by the System Report so remote
	 * diagnosis can see which migrations applied — and which failed — without
	 * SSH. Production was found on db_version 3.2.0 this way only by SSH.
	 */
	private const LEDGER_OPTION = 'pllat_migration_ledger';

	/**
	 * Keep at most this many ledger entries (newest wins).
	 */
	private const LEDGER_MAX = 50;

	/**
	 * Sequenced migration registry. Order is significant — migrations are
	 * always applied in chronological version order.
	 *
	 * Each entry is a class with public const VERSION and public static run().
	 *
	 * @var array<int, class-string>
	 */
	private const MIGRATIONS = array(
		Migrate_To_2_0_0::class,
		Migrate_To_2_2_0::class,
		Migrate_To_2_3_0::class,
		Migrate_To_2_4_0::class,
		Migrate_To_2_6_0::class,
		Migrate_To_2_7_0::class,
		Migrate_To_2_8_0::class,
		Migrate_To_2_9_0::class,
		Migrate_To_3_0_0::class,
		Migrate_To_3_1_0::class,
		Migrate_To_3_2_0::class,
		Migrate_To_3_3_0::class,
		Migrate_To_3_4_0::class,
		Migrate_To_3_5_0::class,
		Migrate_To_3_6_0::class,
		Migrate_To_3_7_0::class,
		Migrate_To_3_8_0::class,
		Migrate_To_3_9_0::class,
		Migrate_To_3_10_0::class,
		Migrate_To_3_11_0::class,
		Migrate_To_3_12_0::class,
		Migrate_To_3_13_0::class,
		Migrate_To_3_14_0::class,
		Migrate_To_3_15_0::class,
		Migrate_To_3_16_0::class,
	);

	/**
	 * Ensure current schema is installed.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$runs_table            = $wpdb->prefix . 'pllat_bulk_runs';
		$claims_table          = $wpdb->prefix . 'pllat_claims';
		$provider_health_table = $wpdb->prefix . 'pllat_provider_health';

		// Runs.
		$sql_runs = "CREATE TABLE {$runs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			spec LONGTEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			completed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_heartbeat BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		// Claims (lean replacement for pllat_jobs).
		$sql_claims = "CREATE TABLE {$claims_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NULL,
			source_kind VARCHAR(20) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_lang VARCHAR(10) NOT NULL,
			target_lang VARCHAR(10) NOT NULL,
			content_subtype VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			issue TEXT NULL,
			batch_state LONGTEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_heartbeat BIGINT UNSIGNED NOT NULL DEFAULT 0,
			as_action_id BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY claim_unit (run_id, source_kind, source_id, target_lang),
			KEY run_id (run_id),
			KEY status (status),
			KEY heartbeat_stale (status, last_heartbeat),
			KEY as_action_id (as_action_id)
		) {$charset_collate};";

		// Support access audit.
		$audit_table = $wpdb->prefix . 'pllat_support_access_audit';
		$sql_audit   = "CREATE TABLE {$audit_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			endpoint VARCHAR(255) NOT NULL,
			method VARCHAR(10) NOT NULL,
			ip VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			response_code SMALLINT UNSIGNED NULL,
			hit_count INT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_user_endpoint_time (user_id, endpoint, timestamp)
		) {$charset_collate};";

		// Provider health (circuit breaker per AI provider).
		$sql_provider_health = "CREATE TABLE {$provider_health_table} (
			provider VARCHAR(32) NOT NULL,
			consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
			last_failure_at INT UNSIGNED NOT NULL DEFAULT 0,
			circuit_open_until INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (provider)
		) {$charset_collate};";

		// Activity log (append-only per-field translation history).
		$activity_log_table = $wpdb->prefix . 'pllat_activity_log';
		$sql_activity_log   = "CREATE TABLE {$activity_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED DEFAULT NULL,
			source_kind VARCHAR(8) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_lang VARCHAR(10) NOT NULL DEFAULT '',
			target_lang VARCHAR(20) NOT NULL,
			reference VARCHAR(191) DEFAULT NULL,
			status VARCHAR(20) NOT NULL,
			error_message VARCHAR(500) DEFAULT NULL,
			logged_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_lookup (source_kind, source_id, target_lang, logged_at),
			KEY idx_run (run_id),
			KEY idx_date (logged_at)
		) {$charset_collate};";

		// Translation index (live Polylang pair cache). Originally created
		// by Migrate_To_3_9_0, but fresh installs short-circuit migrations
		// (pllat_db_version is set to PLLAT_DB_VERSION immediately, so
		// maybe_upgrade returns before the registry walks). Schema must live
		// here so install() alone produces a complete database.
		$index_table = $wpdb->prefix . 'pllat_translation_index';
		$sql_index   = "CREATE TABLE {$index_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_kind VARCHAR(8) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_lang VARCHAR(20) NOT NULL,
			target_lang VARCHAR(20) NOT NULL,
			target_id BIGINT UNSIGNED NULL,
			content_subtype VARCHAR(40) NOT NULL,
			content_status VARCHAR(20) NOT NULL,
			last_synced DATETIME NOT NULL,
			outdated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY src_target (source_kind, source_id, target_lang),
			KEY type_lang_pair (source_kind, content_subtype, source_lang, target_lang, target_id),
			KEY content_status (content_status),
			KEY outdated (outdated_at),
			KEY last_synced (last_synced, target_lang),
			KEY target_ref (source_kind, target_id),
			KEY done_agg (source_lang, content_status, source_kind, content_subtype, target_lang)
		) {$charset_collate};";

		// Translation field state (per-field source-content hash for edit detection).
		$field_state_table = $wpdb->prefix . 'pllat_translation_field_state';
		$sql_field_state   = "CREATE TABLE {$field_state_table} (
			source_kind VARCHAR(8) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			target_lang VARCHAR(20) NOT NULL,
			reference VARCHAR(191) NOT NULL,
			source_hash CHAR(16) NOT NULL,
			translated_at DATETIME NOT NULL,
			PRIMARY KEY  (source_kind, source_id, target_lang, reference),
			KEY idx_translated_at (translated_at)
		) {$charset_collate};";

		\dbDelta( $sql_runs );
		\dbDelta( $sql_claims );
		\dbDelta( $sql_audit );
		\dbDelta( $sql_provider_health );
		\dbDelta( $sql_activity_log );
		\dbDelta( $sql_index );
		\dbDelta( $sql_field_state );

		// Self-heal translation_index schema: existing installs that crossed
		// 3.9.0 before outdated_at was added (and went to 3.10.0) skip the
		// gated migration. Helper is idempotent and cheap (SHOW COLUMNS).
		if ( \in_array( $wpdb->prefix . 'pllat_translation_index', $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}pllat_translation_index'" ), true ) ) {
			Migration_Helpers::ensure_translation_index_outdated_column();
		}

		// Drop legacy AS actions for hooks the lean pipeline no longer uses.
		// Idempotent: safe to run on every install/upgrade cycle.
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( 'pllat_process_job' );
			\as_unschedule_all_actions( 'pllat_discovery_process' );
			\as_unschedule_all_actions( 'pllat_sync_recovery' );
		}

		// Record install timestamp (version is updated by maybe_upgrade after migrations).
		if ( ! \get_option( 'pllat_db_version' ) ) {
			\update_option( 'pllat_db_version', \defined( 'PLLAT_DB_VERSION' ) ? PLLAT_DB_VERSION : '1.0.0' );
		}
		\update_option( 'pllat_db_installed_at', \time() );

		// Set redirect transient if no license key exists.
		if ( \get_option( 'pllat_lic_k' ) ) {
			return;
		}

		\set_transient( 'pllat_activation_redirect', '1', 30 );
	}

	/**
	 * Run every migration the user crosses while upgrading from $from to $to.
	 *
	 * Iterates the MIGRATIONS registry in declared order; each migration's
	 * VERSION constant gates its execution.
	 */
	private static function run_migrations( string $from_version, string $to_version ): void {
		foreach ( self::MIGRATIONS as $migration ) {
			if (
				\version_compare( $from_version, $migration::VERSION, '<' ) &&
				\version_compare( $to_version, $migration::VERSION, '>=' )
			) {
				self::run_single_migration( $migration );
			}
		}
	}

	/**
	 * Run one migration, recording the outcome in the ledger.
	 *
	 * A throwing migration is caught and recorded as failed rather than
	 * fatal-looping the whole site on every request (install() has already
	 * created the current schema, so later migrations are largely idempotent
	 * ALTER/dbDelta guarded by SHOW COLUMNS). The recorded failure is what
	 * makes the problem diagnosable remotely instead of a silent white screen.
	 *
	 * Public so it can be exercised directly in tests with fixture migrations.
	 *
	 * @param class-string $migration Migration class with VERSION + run().
	 */
	public static function run_single_migration( string $migration ): void {
		try {
			$migration::run();
			self::record_migration( (string) $migration::VERSION, true );
		} catch ( \Throwable $e ) {
			self::record_migration( (string) $migration::VERSION, false, $e->getMessage() );
		}
	}

	/**
	 * Append a migration outcome to the bounded ledger option.
	 *
	 * @param string      $version Migration VERSION.
	 * @param bool        $ok      Whether it applied cleanly.
	 * @param string|null $error   Error message when it failed.
	 */
	public static function record_migration( string $version, bool $ok, ?string $error = null ): void {
		$ledger = \get_option( self::LEDGER_OPTION, array() );
		if ( ! \is_array( $ledger ) ) {
			$ledger = array();
		}

		$ledger[] = array(
			'version' => $version,
			'status'  => $ok ? 'applied' : 'failed',
			'at'      => \time(),
			'error'   => $ok ? null : (string) $error,
		);

		if ( \count( $ledger ) > self::LEDGER_MAX ) {
			$ledger = \array_slice( $ledger, -self::LEDGER_MAX );
		}

		\update_option( self::LEDGER_OPTION, $ledger, false );
	}

	/**
	 * Upgrade schema if needed.
	 */
	public function maybe_upgrade(): void {
		$current = \get_option( 'pllat_db_version' );
		$target  = \defined( 'PLLAT_DB_VERSION' ) ? PLLAT_DB_VERSION : '1.0.0';
		if ( $current === $target ) {
			return;
		}

		// Snapshot pending pllat_jobs / pllat_tasks / activity_log volume
		// before Migrate_To_3_11_0 drops them. The Legacy_Migration_Notice
		// handler reads this option to tell the customer *what* was retired
		// — they otherwise see a silent zeroed dashboard and assume the
		// upgrade lost their work. Bounded to upgrades that actually cross
		// the lean-pipeline boundary.
		if ( \is_string( $current ) && '' !== $current && \version_compare( $current, '3.11.0', '<' ) ) {
			self::capture_pre_lean_summary();
		}

		self::install();

		// Fresh install: get_option() returned false (the option was
		// absent until install() set it just above). install() already
		// creates the complete current schema, so the migration registry
		// — ALTERs that assume an older schema — must not be walked. Only
		// a genuine version-to-version upgrade has a string $current.
		if ( \is_string( $current ) && '' !== $current ) {
			self::run_migrations( $current, $target );
		}

		\update_option( 'pllat_db_version', $target );

		// Eager housekeeping so a tester upgrading mid-run gets immediate
		// recovery (abandon stale runs, top up workers, check completion)
		// instead of waiting up to 60s for the next recurring pipeline tick.
		// Gated by the version check above, so this fires exactly once per
		// upgrade. admin_init:1 runs after init, so Pipeline_Tick_Handler
		// is already registered.
		\do_action( 'pllat_pipeline_tick' );
	}

	/**
	 * Snapshot what is about to be dropped by the 3.11.0 lean migration.
	 *
	 * Stored as the `pllat_legacy_migration_summary` option (a one-shot
	 * record consumed by Legacy_Migration_Notice_Handler). Silent on
	 * fresh installs and on rerun: an empty queue records nothing, and
	 * the option only ever gets written by the upgrader.
	 */
	private static function capture_pre_lean_summary(): void {
		global $wpdb;

		$jobs_table     = $wpdb->prefix . 'pllat_jobs';
		$tasks_table    = $wpdb->prefix . 'pllat_tasks';
		$activity_table = $wpdb->prefix . 'pllat_activity_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived names, no user input.
		$existing = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}pllat_%'" );
		if ( ! \in_array( $jobs_table, $existing, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived names, no user input.
		$pending_jobs = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'pending'",
		);

		$pending_tasks = 0;
		if ( \in_array( $tasks_table, $existing, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived names, no user input.
			$pending_tasks = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tasks_table} WHERE status = 'pending'",
			);
		}

		$activity_rows = 0;
		if ( \in_array( $activity_table, $existing, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived names, no user input.
			$activity_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activity_table}" );
		}

		// Quiet upgrade — nothing meaningful in the queue.
		if ( 0 === $pending_jobs && 0 === $pending_tasks && 0 === $activity_rows ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived names, no user input.
		$by_lang = $wpdb->get_results(
			"SELECT lang_to AS lang, COUNT(*) AS n
			   FROM {$jobs_table}
			  WHERE status = 'pending'
		   GROUP BY lang_to",
			ARRAY_A,
		);
		if ( ! \is_array( $by_lang ) ) {
			$by_lang = array();
		}

		\update_option(
			'pllat_legacy_migration_summary',
			array(
				'captured_at'   => \time(),
				'pending_jobs'  => $pending_jobs,
				'pending_tasks' => $pending_tasks,
				'activity_rows' => $activity_rows,
				'by_lang'       => $by_lang,
			),
			false,
		);
	}
}
