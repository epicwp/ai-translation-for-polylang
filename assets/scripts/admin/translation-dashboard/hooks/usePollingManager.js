import { useState, useEffect, useRef, useCallback } from "@wordpress/element";

/**
 * Generic polling manager hook
 *
 * Manages a polling interval that can be started/stopped declaratively.
 * Provides clean lifecycle management with proper cleanup.
 *
 * @param {boolean} shouldPoll - Whether polling should be active
 * @param {Function} pollFn - Function to call on each poll interval
 * @param {number} interval - Polling interval in milliseconds (default: 3000)
 * @returns {Object} - { isPolling, forceRefresh }
 *
 * @example
 * const { isPolling, forceRefresh } = usePollingManager(
 *   hasActiveTranslations,
 *   fetchDashboardData,
 *   3000
 * );
 */
export const usePollingManager = (shouldPoll, pollFn, interval = 3000) => {
  const intervalRef = useRef(null);
  const [isPolling, setIsPolling] = useState(false);
  const pollFnRef = useRef(pollFn);

  // Keep pollFn reference fresh to avoid stale closures
  useEffect(() => {
    pollFnRef.current = pollFn;
  }, [pollFn]);

  // Core polling control - declarative start/stop based on shouldPoll
  useEffect(() => {
    if (shouldPoll && !intervalRef.current) {
      // Start polling
      setIsPolling(true);
      intervalRef.current = setInterval(() => {
        pollFnRef.current();
      }, interval);
    } else if (!shouldPoll && intervalRef.current) {
      // Stop polling
      clearInterval(intervalRef.current);
      intervalRef.current = null;
      setIsPolling(false);
    }

    // Cleanup on unmount or when dependencies change
    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
        setIsPolling(false);
      }
    };
  }, [shouldPoll, interval]);

  // Force a single refresh (useful for manual triggers like button clicks)
  const forceRefresh = useCallback(() => {
    pollFnRef.current();
  }, []);

  return { isPolling, forceRefresh };
};
