/**
 * Shared preflight pre-check hook.
 *
 * Calls POST /pllat/v1/preflight/check before the actual translate
 * request fires, so blocking issues (fail) or recommendations (warn)
 * surface in a modal *before* the user commits to a run rather than
 * after the fact. Dismissed checks (per-browser localStorage) are
 * filtered out — if every blocking check is dismissed, the caller can
 * proceed without showing the modal.
 *
 * Usage:
 *   const { runPreflight } = usePreflight();
 *   const { result, blockingChecks } = await runPreflight('single', { post_id });
 *   if (blockingChecks.length === 0) {
 *     await translate(...);            // straight path
 *   } else {
 *     setPreflightFailure(result);     // open modal; resume on user decision
 *   }
 */

import { useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { getDismissed } from '../utils/preflightDismissed';

/**
 * @typedef {Object} PreflightCheckRow
 * @property {string} name
 * @property {string} level         - 'pass' | 'warn' | 'fail'.
 * @property {string} message
 * @property {string|null} actionable
 * @property {string|null} help_url
 * @property {string|null} admin_link
 * @property {string|null} admin_link_label
 */

/**
 * @typedef {Object} PreflightResult
 * @property {string} overall_level - 'pass' | 'warn' | 'fail' aggregate.
 * @property {PreflightCheckRow[]} checks
 */

const usePreflight = () => {
  /**
   * @param {'run_start'|'single'|'bulk'} scope
   * @param {Object} [context] - REST context (e.g. { post_id, term_id }).
   * @returns {Promise<{ result: PreflightResult, blockingChecks: PreflightCheckRow[] }>}
   *   `blockingChecks` is the subset of checks that aren't dismissed and
   *   aren't 'pass'. Empty array means the modal does not need to open.
   */
  const runPreflight = useCallback(async (scope, context = {}) => {
    const result = await apiFetch({
      path: '/pllat/v1/preflight/check',
      method: 'POST',
      data: { scope, context },
    });

    const dismissed = new Set(getDismissed());
    const blockingChecks = (result?.checks || []).filter(
      (c) => (c.level === 'fail' || c.level === 'warn') && !dismissed.has(c.name),
    );

    return { result, blockingChecks };
  }, []);

  return { runPreflight };
};

export default usePreflight;
