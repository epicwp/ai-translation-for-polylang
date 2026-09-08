/**
 * Per-browser dismissal of individual preflight checks.
 *
 * When the user has reviewed a check, understood the implication, and
 * wants to skip it on subsequent attempts in the same browser, we store
 * the check name in localStorage. The dismissal is intentionally NOT
 * synced server-side: a different device, a different user, or a
 * cleared browser cache should see the check again. That re-prompts the
 * user when the context has changed, which is desirable for warnings
 * that may have stopped being accurate.
 *
 * Dismissals are filtered out by `runPreflight` before the modal opens.
 * If every blocking check is dismissed, the modal does not open at all
 * and the translation proceeds as if preflight passed.
 */

const KEY = 'pllat_dismissed_preflight_checks';

/**
 * @returns {string[]}
 */
export function getDismissed() {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed.filter((v) => typeof v === 'string') : [];
  } catch (e) {
    return [];
  }
}

/**
 * @param {string} name - Check name (e.g. 'woocommerce_variations').
 * @returns {boolean}
 */
export function isDismissed(name) {
  return getDismissed().includes(name);
}

/**
 * @param {string} name
 */
export function dismiss(name) {
  if (!name) return;
  const current = getDismissed();
  if (current.includes(name)) return;
  current.push(name);
  try {
    window.localStorage.setItem(KEY, JSON.stringify(current));
  } catch (e) {
    // localStorage unavailable (private mode, quota) — silently degrade;
    // the worst case is that the check shows again next time.
  }
}

/**
 * Remove a check name from the dismissed list. Currently only used by
 * tests / future "Manage dismissed warnings" UI; exported for symmetry.
 *
 * @param {string} name
 */
export function undismiss(name) {
  const current = getDismissed().filter((n) => n !== name);
  try {
    window.localStorage.setItem(KEY, JSON.stringify(current));
  } catch (e) {
    // ignore
  }
}
