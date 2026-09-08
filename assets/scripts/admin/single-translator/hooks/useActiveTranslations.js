/**
 * Hook for polling active translations.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';

const POLL_INTERVAL = 3000; // 3 seconds
const MIN_DISPLAY_TIME = 2000; // 2 seconds minimum progress display
// How long to keep polling for the async worker to claim the run before we
// treat "no active work" as "still queued" rather than "completed". Covers
// WP-Cron / Action Scheduler dispatch lag on low-traffic sites. See #392.
const NEVER_ACTIVE_TIMEOUT = 30000; // 30 seconds

/**
 * Custom hook to poll for active translation progress.
 *
 * @param {boolean} enabled - Whether polling is enabled
 * @param {Function} onPoll - Callback to refresh main status on each poll
 * @param {Function} onComplete - Optional callback when all translations complete
 * @returns {Object} Polling state and methods
 */
export function useActiveTranslations(enabled, onPoll = null, onComplete = null) {
	const [polling, setPolling] = useState(false);
	const [hasActive, setHasActive] = useState(false);
	const [startTime, setStartTime] = useState(null);
	// Latches true once we've observed has_active go true during this polling
	// session. Distinguishes "worker hasn't claimed the run yet" (async
	// dispatch lag) from "run finished" — without it a slow WP-Cron makes the
	// first few polls read has_active=false and the UI falsely reports the
	// translation completed before it even started. See ticket #392.
	const seenActiveRef = useRef(false);

	/**
	 * Poll for active translations.
	 */
	const poll = useCallback(async () => {
		if (!enabled) {
			return;
		}

		try {
			// Call onPoll to refresh main status
			if (onPoll) {
				const data = await onPoll();

				// Use backend's has_active flag (single source of truth)
				const hasActiveNow = data.has_active || false;
				setHasActive(hasActiveNow);

				// Latch once the worker has actually claimed the run. Until
				// this flips true, a has_active=false reading means "not
				// picked up yet", not "done" (see #392).
				if (hasActiveNow) {
					seenActiveRef.current = true;
				}

				if (!hasActiveNow && polling) {
					const elapsed = startTime ? Date.now() - startTime : 0;

					if (seenActiveRef.current) {
						// We saw the worker run and it is now idle → genuine
						// completion. Keep the minimum-display floor so a fast
						// run still shows progress briefly.
						if (elapsed >= MIN_DISPLAY_TIME) {
							setPolling(false);
							if (onComplete) {
								onComplete(data); // Pass fresh data to callback
							}
						}
					} else if (elapsed >= NEVER_ACTIVE_TIMEOUT) {
						// The async worker never claimed the run within the
						// grace window (slow WP-Cron / Action Scheduler). Stop
						// polling and report "still queued" instead of a false
						// "completed" — it will process in the background.
						setPolling(false);
						if (onComplete) {
							onComplete(data, { neverActive: true });
						}
					}
					// Otherwise keep polling - next interval will check again
				}
			}
		} catch (err) {
			// Fail silently during polling
			console.error('Failed to poll translation status:', err);
		}
	}, [enabled, polling, startTime, onPoll, onComplete]);

	/**
	 * Start polling immediately.
	 */
	const startPolling = useCallback(() => {
		seenActiveRef.current = false;
		setStartTime(Date.now());
		setPolling(true);
		// useEffect handles initial poll - no need to call poll() here
	}, []);

	/**
	 * Stop polling.
	 */
	const stopPolling = useCallback(() => {
		setPolling(false);
		setHasActive(false);
		setStartTime(null);
	}, []);

	/**
	 * Set up polling interval.
	 */
	useEffect(() => {
		if (!enabled || !polling) {
			return;
		}

		// Initial poll
		poll();

		// Set up interval
		const interval = setInterval(poll, POLL_INTERVAL);

		return () => {
			clearInterval(interval);
		};
	}, [enabled, polling, poll]);

	return {
		hasActive,
		polling,
		startPolling,
		stopPolling,
	};
}

export default useActiveTranslations;
