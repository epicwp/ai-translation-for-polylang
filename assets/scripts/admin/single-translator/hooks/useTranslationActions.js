/**
 * Hook for translation actions (start, exclude, cancel).
 */

import { useState, useCallback } from "@wordpress/element";
import {
  startTranslation,
  setExclusion,
  cancelTranslation,
} from "../utils/api";

/**
 * Custom hook for translation actions.
 *
 * @param {Function} onSuccess - Callback after successful action
 * @returns {Object} Action methods and state
 */
export function useTranslationActions(onSuccess) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  // Structured preflight failure (422 with code: pllat_preflight_failed).
  // Surfaced to the consumer so it can render PreflightFailedDialog instead
  // of a flat error string.
  const [preflightFailure, setPreflightFailure] = useState(null);
  const clearPreflightFailure = useCallback(() => setPreflightFailure(null), []);

  const { type, id } = window.pllatSingleTranslator;

  /**
   * Start translation.
   *
   * @param {Array<string>} targetLanguages - Target language codes
   * @param {boolean} force - Force re-translation
   * @param {string} instructions - Custom AI instructions
   * @param {Array<string>} selectedFields - Selected field keys for scoped force translation
   */
  const translate = useCallback(
    async (
      targetLanguages,
      force = false,
      instructions = "",
      selectedFields = [],
      acknowledgePreflight = false,
    ) => {
      try {
        setLoading(true);
        setError(null);

        const response = await startTranslation(
          type,
          id,
          targetLanguages,
          force,
          instructions,
          selectedFields,
          acknowledgePreflight,
        );

        if (onSuccess) {
          onSuccess(response);
        }

        return response;
      } catch (err) {
        if (err?.code === "pllat_preflight_failed" && err?.preflight) {
          setPreflightFailure(err.preflight);
        } else if (err?.code === "pllat_index_not_ready" && err?.preflight) {
          setPreflightFailure(err.preflight);
        } else if (err?.code === "pllat_nothing_to_translate") {
          // Caller handles this via a non-error notice; don't set the
          // generic error banner.
        } else {
          setError(err.message);
        }
        throw err;
      } finally {
        setLoading(false);
      }
    },
    [type, id, onSuccess],
  );

  /**
   * Toggle exclusion status.
   *
   * @param {boolean} excluded - Whether to exclude from translation
   */
  const toggleExclusion = useCallback(
    async (excluded) => {
      try {
        setLoading(true);
        setError(null);

        const response = await setExclusion(type, id, excluded);

        if (onSuccess) {
          onSuccess(response);
        }

        return response;
      } catch (err) {
        setError(err.message);
        throw err;
      } finally {
        setLoading(false);
      }
    },
    [type, id, onSuccess],
  );

  /**
   * Cancel active translation.
   */
  const cancel = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await cancelTranslation(type, id);

      if (onSuccess) {
        onSuccess(response);
      }

      return response;
    } catch (err) {
      setError(err.message);
      throw err;
    } finally {
      setLoading(false);
    }
  }, [type, id, onSuccess]);

  /**
   * Clear error state.
   */
  const clearError = useCallback(() => {
    setError(null);
  }, []);

  return {
    translate,
    toggleExclusion,
    cancel,
    loading,
    error,
    clearError,
    preflightFailure,
    setPreflightFailure,
    clearPreflightFailure,
  };
}

export default useTranslationActions;
