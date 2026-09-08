import { useMemo } from "@wordpress/element";
import { useDashboardData } from "./useDashboardData";
import { usePollingManager } from "./usePollingManager";

/**
 * Dashboard data with smart polling
 *
 * Combines data fetching with automatic polling that starts/stops
 * based on whether there are active translations.
 *
 * Polling logic:
 * - Starts when any content type has translationState === 'pending' or 'translating'
 * - Stops when no active translations are detected
 * - Polls every 3 seconds when active
 *
 * @returns {Object} - { data, isFetching, isPolling, refetch }
 */
export const useDashboardPolling = () => {
  const { data, isFetching, refetch } = useDashboardData();

  /**
   * Determine if we should poll based on content types state or discovery
   * Memoized to prevent unnecessary polling restarts
   */
  const shouldPoll = useMemo(() => {
    const hasActiveTranslations = Object.values(data.contentTypes).some((contentType) => {
      const state = contentType.translationState;
      return state === "pending" || state === "translating";
    });
    const hasActiveDiscovery = !!data.discovery?.needed;
    return hasActiveTranslations || hasActiveDiscovery;
  }, [data.contentTypes, data.discovery?.needed]);

  // Use polling manager to handle polling lifecycle
  const { isPolling, forceRefresh } = usePollingManager(
    shouldPoll,
    refetch,
    1500 // Poll every 1.5 seconds when active (faster feedback)
  );

  return {
    data,
    isFetching,
    isPolling,
    hasActiveTranslations: shouldPoll,
    refetch: forceRefresh,
  };
};
