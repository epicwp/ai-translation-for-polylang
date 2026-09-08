/**
 * Job status constants and helpers.
 */

export const JOB_STATUS = {
	PENDING: 'pending',
	QUEUED: 'queued',
	IN_PROGRESS: 'in_progress',
	COMPLETED: 'completed',
	FAILED: 'failed',
	TRANSLATED: 'translated' // UI display status: completed job with existing translation
};

/**
 * Statuses that indicate active translation work.
 *
 * Note: 'pending' is NOT included because it means "job exists but not in a run".
 * Only jobs that are actively being processed ('queued' or 'in_progress') are considered active.
 */
export const ACTIVE_STATUSES = [
	JOB_STATUS.QUEUED,
	JOB_STATUS.IN_PROGRESS
];

/**
 * Check if a status indicates active translation.
 *
 * @param {string} status - The job status to check
 * @returns {boolean} True if status is active
 */
export function isActiveStatus(status) {
	return ACTIVE_STATUSES.includes(status);
}

/**
 * Check if a status indicates completion (success or failure).
 *
 * @param {string} status - The job status to check
 * @returns {boolean} True if status is terminal
 */
export function isTerminalStatus(status) {
	return status === JOB_STATUS.COMPLETED
		|| status === JOB_STATUS.FAILED
		|| status === JOB_STATUS.TRANSLATED;
}
