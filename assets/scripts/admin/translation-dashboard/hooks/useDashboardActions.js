import { useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { __, sprintf } from "@wordpress/i18n";
import usePreflight from "../../hooks/usePreflight";

/**
 * Extract error message from various error formats
 */
const extractErrorMessage = (error) => {
  if (error.error) {
    return error.error;
  }
  if (error.message) {
    return error.message;
  }
  return __( "Unknown error occurred", "polylang-ai-automatic-translation" );
};

/**
 * Dashboard actions hook
 *
 * Handles user actions like starting and canceling translations.
 * Simplified to use refetch pattern instead of optimistic updates.
 *
 * @param {Function} refetch - Function to refetch dashboard data
 * @returns {Object} - { startContentTranslation, cancelRun, isProcessing }
 */
export const useDashboardActions = (refetch) => {
  const [isProcessing, setIsProcessing] = useState(false);
  // Preflight failure lives in the hook (not in the wrapping callback)
  // because ContentTypeCard memoizes and ignores callback identity, which
  // means a `handleStartTranslation` wrapper defined in the parent would
  // get frozen at first render and never re-run with new state setters.
  // Storing here means the dashboard can subscribe to it directly.
  const [preflightFailure, setPreflightFailure] = useState(null);
  // Pending start invocation, parked while the preflight modal is shown.
  // null when no pending request.
  const [pendingInvocation, setPendingInvocation] = useState(null);

  const { runPreflight } = usePreflight();

  const clearPreflightFailure = () => {
    setPreflightFailure(null);
    setPendingInvocation(null);
  };

  /**
   * The actual create-run call. Split off from startContentTranslation so
   * the pre-check path and the modal-driven "Continue anyway" path can
   * share it.
   */
  const doStart = async (contentTypeSlug, contentTypeData, options = {}) => {
    setIsProcessing(true);
    try {
      const type = contentTypeData.type === "post" ? "post" : "term";

      const data = {};
      if (options.config) {
        if (options.config.instructions) {
          data.instructions = options.config.instructions;
        }
        if (options.config.limit !== undefined && options.config.limit !== null) {
          data.limit = options.config.limit;
        }
        if (options.config.forced) {
          data.forced = true;
        }
        if (options.config.languages) {
          data.languages = options.config.languages;
        }
        if (options.config.selected_fields && options.config.selected_fields.length > 0) {
          data.selected_fields = options.config.selected_fields;
        }
      }

      const response = await apiFetch({
        path: `/pllat/v1/bulk/content/${type}/${contentTypeSlug}`,
        method: "POST",
        data,
      });

      if (response.success === false) {
        throw new Error(response.error || __( "Failed to start translation", "polylang-ai-automatic-translation" ));
      }

      refetch();

      return { success: true, runId: response.data.runId };
    } catch (error) {
      if (error?.code === "pllat_preflight_failed" && error?.preflight) {
        setPreflightFailure(error.preflight);
        return { success: false, preflight: error.preflight };
      } else if (error?.code === "pllat_index_not_ready" && error?.preflight) {
        setPreflightFailure(error.preflight);
        return { success: false, preflight: error.preflight };
      }

      const errorMessage = extractErrorMessage(error);
      alert( sprintf( __( "Failed to start translation: %s", "polylang-ai-automatic-translation" ), errorMessage ) );
      return { success: false, error: errorMessage };
    } finally {
      setIsProcessing(false);
    }
  };

  /**
   * Start a translation run for a specific content type.
   *
   * Runs a preflight pre-check first; if blocking issues remain, parks
   * the invocation and opens the modal. The caller resumes via
   * `continueAnyway` from the modal's button.
   *
   * @returns {Promise<Object>} { success, runId?, error?, preflight? }
   */
  const startContentTranslation = async (
    contentTypeSlug,
    contentTypeData,
    options = {}
  ) => {
    if (isProcessing) {
      return { success: false, error: __( "Another action is in progress", "polylang-ai-automatic-translation" ) };
    }

    // Preflight pre-check before we commit to the run. Bulk scope =>
    // global checks (no post/term context). Network or permission errors
    // fall through to the actual create-run call, which has its own
    // defensive 422 handling.
    let pre = { result: null, blockingChecks: [] };
    try {
      pre = await runPreflight("bulk", {});
    } catch (e) {
      // ignore — proceed to the create-run call
    }

    if (pre.blockingChecks.length > 0) {
      setPendingInvocation({ contentTypeSlug, contentTypeData, options });
      setPreflightFailure(pre.result);
      return { success: false, preflight: pre.result };
    }

    return doStart(contentTypeSlug, contentTypeData, options);
  };

  /**
   * Resume the parked invocation after the user clicked "Continue anyway"
   * in the preflight modal. No-op if nothing is parked.
   */
  const continueAnyway = async () => {
    if (!pendingInvocation) return { success: false };
    const inv = pendingInvocation;
    setPendingInvocation(null);
    setPreflightFailure(null);
    return doStart(inv.contentTypeSlug, inv.contentTypeData, inv.options);
  };

  /**
   * Cancel an active translation run
   *
   * @param {string} type - Content type ('post' or 'term')
   * @param {string} entity - Post type or taxonomy name
   * @returns {Promise<Object>} - { success, error? }
   */
  const cancelRun = async (type, entity) => {
    if (!type || !entity || isProcessing) {
      return { success: false, error: __( "Invalid parameters or action in progress", "polylang-ai-automatic-translation" ) };
    }

    if (!confirm( __( "Are you sure you want to cancel this translation run?", "polylang-ai-automatic-translation" ) )) {
      return { success: false, error: __( "Cancelled by user", "polylang-ai-automatic-translation" ) };
    }

    setIsProcessing(true);

    try {
      const response = await apiFetch({
        path: `/pllat/v1/bulk/content/${type}/${entity}/cancel`,
        method: "POST",
      });

      if (!response.success) {
        throw new Error(response.error || __( "Failed to cancel run", "polylang-ai-automatic-translation" ));
      }

      // Immediate refetch for faster UI feedback (don't wait for next poll)
      refetch();

      return { success: true };
    } catch (error) {
      const errorMessage = extractErrorMessage(error);
      alert( sprintf( __( "Failed to cancel translation: %s", "polylang-ai-automatic-translation" ), errorMessage ) );
      return { success: false, error: errorMessage };
    } finally {
      setIsProcessing(false);
    }
  };

  return {
    startContentTranslation,
    cancelRun,
    isProcessing,
    preflightFailure,
    clearPreflightFailure,
    continueAnyway,
  };
};
