<?php
/**
 * Event_Codes class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Logging
 */

declare(strict_types=1);

namespace PLLAT\Common\Logging;

\defined( 'ABSPATH' ) || exit;

/**
 * Centralized event name constants for structured logging.
 *
 * Values are stable strings shared with Debug_Logger_Service so log files
 * stay parseable across releases.
 */
final class Event_Codes {
	// AI pipeline.
	public const AI_REQUEST_SENT         = 'ai_api_call_before';
	public const AI_RESPONSE_RECEIVED    = 'ai_api_call_after';
	public const AI_PROMPT_SYSTEM        = 'system_prompt_built';
	public const AI_PROMPT_USER          = 'user_prompt_built';
	public const AI_MESSAGES_BUILT       = 'translation_messages_built';
	public const AI_RESPONSE_INVALID     = 'invalid_ai_response';
	public const AI_RESPONSE_EMPTY       = 'empty_translation_received';
	public const AI_RESPONSE_TOO_LONG    = 'translation_too_long';
	public const BATCH_STARTED           = 'batch_translate_started';

	// Translation lifecycle.
	public const RUN_CREATED             = 'run_created';
	public const RUN_STARTED             = 'run_processing_started';
	public const RUN_COMPLETED           = 'run_completed';
	public const RUN_FAILED              = 'run_failed';
	public const RUN_CANCELLED           = 'run_cancelled';
	public const JOB_CLAIMED             = 'job_started';
	public const JOB_COMPLETED           = 'job_completed';
	public const JOB_FAILED              = 'job_failed';
	public const JOB_HEARTBEAT           = 'job_heartbeat';
	public const TASK_STARTED            = 'task_started';
	public const TASK_COMPLETED          = 'task_completed';
	public const TASK_FAILED             = 'task_failed';
	public const TASK_RETRY              = 'task_retry';

	// JSON patch pipeline.
	public const JSON_PATCH_APPLIED      = 'json_patch_applied';
	public const JSON_PATCH_FAILED       = 'json_patch_failed';
	public const JSON_PATCH_PATH_INVALID = 'json_patch_path_invalid';

	// Preflight / system.
	public const PREFLIGHT_PASSED        = 'preflight_passed';
	public const PREFLIGHT_WARNED        = 'preflight_warned';
	public const PREFLIGHT_FAILED        = 'preflight_failed';

	// Provider health.
	public const PROVIDER_CIRCUIT_OPEN   = 'provider_circuit_open';
	public const PROVIDER_CIRCUIT_CLOSED = 'provider_circuit_closed';
	public const PROVIDER_RATE_LIMITED   = 'provider_rate_limited';

	// Support access.
	public const SUPPORT_ACCESS_GRANTED  = 'support_access_granted';
	public const SUPPORT_ACCESS_REVOKED  = 'support_access_revoked';
	public const SUPPORT_ACCESS_EXPIRED  = 'support_access_expired';

	// Cancel / orphan.
	public const ORPHAN_JOB_DETECTED     = 'orphan_job_detected';

	private function __construct() {}
}
