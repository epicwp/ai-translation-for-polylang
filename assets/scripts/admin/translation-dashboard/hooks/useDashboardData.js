import { useState, useEffect, useRef, useCallback } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

/**
 * Pure dashboard data fetching hook
 *
 * Handles fetching dashboard data from the REST API with proper cleanup.
 * Does NOT contain polling logic - that's handled by useDashboardPolling.
 *
 * @returns {Object} - { data, isFetching, refetch }
 */
export const useDashboardData = () => {
  const [data, setData] = useState({
    overall: { translated: 0, total: 0 },
    contentTypes: {},
    targetLanguages: [],
    discovery: null,
  });
  const [isFetching, setIsFetching] = useState(false);
  const abortControllerRef = useRef(null);

  /**
   * Fetch dashboard data from API
   * Includes abort controller to prevent memory leaks and race conditions
   */
  const fetchData = useCallback(async () => {
    // Abort previous fetch if still running
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    setIsFetching(true);
    const controller = new AbortController();
    abortControllerRef.current = controller;

    try {
      const response = await apiFetch({
        path: "/pllat/v1/dashboard",
        signal: controller.signal,
      });

      const newData = {
        overall: {
          translated: response.overallProgress?.total_translated || 0,
          total: response.overallProgress?.total_possible_translations || 0,
        },
        contentTypes: response.contentTypes || {},
        targetLanguages: response.targetLanguages || [],
        discovery: response.discovery || null,
      };

      // Selective update: only update contentTypes that actually changed (based on hash)
      setData((prevData) => {
        const updatedContentTypes = {};
        let hasChanges = false;

        // For each contentType in new data
        Object.keys(newData.contentTypes).forEach((key) => {
          const newContentType = newData.contentTypes[key];
          const prevContentType = prevData.contentTypes[key];

          // If hash matches, reuse previous object reference (prevents re-render)
          if (prevContentType && prevContentType.hash === newContentType.hash) {
            updatedContentTypes[key] = prevContentType;
          } else {
            // Hash changed or new contentType - use new reference
            updatedContentTypes[key] = newContentType;
            hasChanges = true;
          }
        });

        // Check if overall, targetLanguages, or discovery changed
        const overallChanged =
          prevData.overall.translated !== newData.overall.translated ||
          prevData.overall.total !== newData.overall.total;

        const targetLanguagesChanged =
          JSON.stringify(prevData.targetLanguages) !== JSON.stringify(newData.targetLanguages);

        const discoveryChanged =
          JSON.stringify(prevData.discovery) !== JSON.stringify(newData.discovery);

        // Only update state if something actually changed
        if (!hasChanges && !overallChanged && !targetLanguagesChanged && !discoveryChanged) {
          return prevData; // No changes, keep same reference
        }

        return {
          ...newData,
          contentTypes: updatedContentTypes,
        };
      });
    } catch (error) {
      if (error.name !== "AbortError") {
        // Silent fail - polling will retry automatically
        console.error("Dashboard fetch error:", error);
      }
    } finally {
      setIsFetching(false);
      abortControllerRef.current = null;
    }
  }, []);

  // Initial fetch on mount
  useEffect(() => {
    fetchData();

    // Cleanup: abort any pending fetch on unmount
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, [fetchData]);

  return { data, isFetching, refetch: fetchData };
};
