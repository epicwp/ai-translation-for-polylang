import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Strings status polling hook
 *
 * Polls GET /pllat/v1/strings/status.
 * Fast poll (3s) while a batch is running, slow poll (15s) otherwise.
 *
 * @returns {Object} - { data, loading, refresh }
 */
export default function useStringsData() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const timer = useRef(null);
  const abortControllerRef = useRef(null);

  const fetchStatus = useCallback(async () => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    const controller = new AbortController();
    abortControllerRef.current = controller;

    try {
      const res = await apiFetch({
        path: '/pllat/v1/strings/status',
        signal: controller.signal,
      });
      setData(res);
      return res;
    } catch (e) {
      if (e.name !== 'AbortError') {
        console.error('Strings status fetch error:', e);
      }
      return null;
    } finally {
      setLoading(false);
      abortControllerRef.current = null;
    }
  }, []);

  useEffect(() => {
    let active = true;

    const tick = async () => {
      const res = await fetchStatus();
      if (!active) return;
      const running = res && ((res.progress && res.progress.done < res.progress.total) || (res.inFlight && res.inFlight.length > 0));
      timer.current = window.setTimeout(tick, running ? 3000 : 15000);
    };

    tick();

    return () => {
      active = false;
      if (timer.current) window.clearTimeout(timer.current);
      if (abortControllerRef.current) abortControllerRef.current.abort();
    };
  }, [fetchStatus]);

  return { data, loading, refresh: fetchStatus };
}
