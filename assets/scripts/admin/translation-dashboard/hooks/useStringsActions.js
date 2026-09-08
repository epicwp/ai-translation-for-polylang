import { useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Strings action hooks
 *
 * Wraps POST /pllat/v1/strings/translate and POST /pllat/v1/strings/cancel.
 * Calls refresh after each successful action.
 *
 * @param {Function} refresh - Function to refresh strings status data
 * @returns {Object} - { translate, cancel }
 */
export default function useStringsActions(refresh) {
  const translate = useCallback(
    async ({ groups, languages, force, limit, instructions }) => {
      const res = await apiFetch({
        path: '/pllat/v1/strings/translate',
        method: 'POST',
        data: { groups, languages, force, limit, instructions: instructions || '' },
      });
      if (refresh) await refresh();
      return res;
    },
    [refresh],
  );

  const cancel = useCallback(async () => {
    const res = await apiFetch({
      path: '/pllat/v1/strings/cancel',
      method: 'POST',
    });
    if (refresh) await refresh();
    return res;
  }, [refresh]);

  return { translate, cancel };
}
