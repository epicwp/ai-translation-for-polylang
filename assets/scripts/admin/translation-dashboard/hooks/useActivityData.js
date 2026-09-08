import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PER_PAGE = 25;

const useActivityData = (isOpen, isPolling, externalHasActive = false) => {
  const [jobs, setJobs] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [pagination, setPagination] = useState({ page: 1, totalPages: 1 });
  const [selectedDate, setSelectedDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [initialDateSet, setInitialDateSet] = useState(false);
  const [statusFilter, setStatusFilter] = useState('all');
  const [newItemCount, setNewItemCount] = useState(0);
  const [isScrolled, setIsScrolled] = useState(false);

  const scrollRef = useRef(null);
  const abortRef = useRef(null);
  const latestJobIdRef = useRef(null);
  const isScrolledRef = useRef(false);
  const hasFetchedRef = useRef(false);

  // Keep refs in sync with state so fetchActivity doesn't depend on them.
  isScrolledRef.current = isScrolled;

  const abortPending = useCallback(() => {
    if (abortRef.current) {
      abortRef.current.abort();
      abortRef.current = null;
    }
  }, []);

  const fetchDates = useCallback(async () => {
    try {
      const response = await apiFetch({
        path: '/pllat/v1/activity/dates',
      });
      const dates = response.dates || [];

      // On first load, switch to the most recent date only if it differs from today.
      // (selectedDate already defaults to today so activity fetches immediately.)
      if (!initialDateSet && dates.length > 0) {
        const today = new Date().toISOString().split('T')[0];
        if (dates[0] !== today) {
          setSelectedDate(dates[0]);
        }
        setInitialDateSet(true);
      }
    } catch (err) {
      if (err.name !== 'AbortError') {
        console.error('Error fetching activity dates:', err);
      }
    }
  }, [initialDateSet]);

  const fetchActivity = useCallback(
    async (page = 1, append = false) => {
      abortPending();

      const controller = new AbortController();
      abortRef.current = controller;

      if (!append) {
        // Only show loading spinner on initial load, not during polling refreshes.
        // This prevents flickering between "Loading" and "No activity" states.
        if (!hasFetchedRef.current) {
          setIsLoading(true);
        }
      }

      try {
        const statusParam = statusFilter !== 'all' ? `&status=${statusFilter}` : '';
        const response = await apiFetch({
          path: `/pllat/v1/activity?date=${selectedDate}&page=${page}&per_page=${PER_PAGE}${statusParam}`,
          signal: controller.signal,
        });

        const incoming = response.jobs || [];
        const paginationData = response.pagination || {};
        const totalItems = paginationData.total || 0;
        const perPageSize = paginationData.per_page || PER_PAGE;
        const total = Math.ceil(totalItems / perPageSize) || 1;

        if (append) {
          setJobs((prev) => [...prev, ...incoming]);
        } else {
          // When polling and scrolled away, detect new items
          if (isScrolledRef.current && latestJobIdRef.current && incoming.length > 0) {
            const latestId = latestJobIdRef.current;
            const newIdx = incoming.findIndex((j) => j.id === latestId);
            if (newIdx > 0) {
              setNewItemCount(newIdx);
            }
          }
          setJobs(incoming);
        }

        if (incoming.length > 0 && !append) {
          latestJobIdRef.current = incoming[0].id;
        }

        setPagination({ page, totalPages: total });
        setIsLoading(false);
        hasFetchedRef.current = true;
      } catch (err) {
        if (err.name !== 'AbortError') {
          console.error('Error fetching activity:', err);
          setIsLoading(false);
        }
      }
    },
    [selectedDate, statusFilter, abortPending],
  );

  const loadMore = useCallback(() => {
    if (pagination.page < pagination.totalPages) {
      fetchActivity(pagination.page + 1, true);
    }
  }, [pagination, fetchActivity]);

  const scrollToTop = useCallback(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTop = 0;
    }
    setNewItemCount(0);
    setIsScrolled(false);
  }, []);

  // Fetch dates on mount
  useEffect(() => {
    if (!isOpen) {
      return;
    }
    fetchDates();
  }, [isOpen, fetchDates]);

  // Auto-switch to today when an active run is detected (e.g. page left open overnight).
  const prevActiveRunRef = useRef(false);
  useEffect(() => {
    if (externalHasActive && !prevActiveRunRef.current) {
      const today = new Date().toISOString().split('T')[0];
      if (selectedDate && selectedDate !== today) {
        setSelectedDate(today);
      }
    }
    prevActiveRunRef.current = externalHasActive;
  }, [externalHasActive, selectedDate]);

  // Fetch activity when date or filter changes
  useEffect(() => {
    if (!isOpen || !selectedDate) {
      return;
    }
    setNewItemCount(0);
    setJobs([]);
    latestJobIdRef.current = null;
    hasFetchedRef.current = false;
    fetchActivity(1, false);
  }, [isOpen, selectedDate, statusFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  // Polling: fast (1.5s) when a run is active, slow (15s) heartbeat otherwise.
  useEffect(() => {
    if (!isOpen || !isPolling || !selectedDate) {
      return;
    }

    const delay = externalHasActive ? 1500 : 15000;
    const interval = setInterval(() => {
      fetchActivity(1, false);
    }, delay);

    return () => clearInterval(interval);
  }, [isOpen, isPolling, externalHasActive, selectedDate, statusFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  // Cleanup on unmount
  useEffect(() => {
    return () => abortPending();
  }, [abortPending]);

  return {
    jobs,
    isLoading,
    pagination,
    hasActiveRun: externalHasActive,
    selectedDate,
    setSelectedDate,
    statusFilter,
    setStatusFilter,
    newItemCount,
    scrollRef,
    scrollToTop,
    setIsScrolled,
    loadMore,
  };
};

export default useActivityData;
