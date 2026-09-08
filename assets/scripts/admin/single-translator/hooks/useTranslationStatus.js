/**
 * Hook for fetching and managing translation status.
 */

import { useState, useCallback } from '@wordpress/element';
import { getStatus } from '../utils/api';

/**
 * Custom hook to fetch translation status.
 *
 * @returns {Object} Status data and methods
 */
export function useTranslationStatus() {
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const { type, id } = window.pllatSingleTranslator;

    /**
     * Fetch status from API.
     */
    const fetchStatus = useCallback(async () => {
        try {
            setLoading(true);
            setError(null);
            const data = await getStatus(type, id);
            setStatus(data);
            return data;
        } catch (err) {
            setError(err.message);
            throw err;
        } finally {
            setLoading(false);
        }
    }, [type, id]);

    /**
     * Refresh status (alias for fetchStatus).
     */
    const refresh = useCallback(() => {
        return fetchStatus();
    }, [fetchStatus]);

    return {
        status,
        loading,
        error,
        fetchStatus,
        refresh,
    };
}

export default useTranslationStatus;
