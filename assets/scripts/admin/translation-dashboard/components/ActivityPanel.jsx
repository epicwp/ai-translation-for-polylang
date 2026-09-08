import { useState, useCallback, useMemo } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { DatePicker, Dropdown } from '@wordpress/components';
import useActivityData from '../hooks/useActivityData';
import ActivityFeedItem from './ActivityFeedItem';

/**
 * Flatten jobs (with nested tasks) into a reverse-chronological feed.
 * Only includes completed, in_progress, and failed items — no pending.
 *
 * For each job:
 *   - Task items for each non-pending task (completed/in_progress/failed)
 *   - Job completion item when all tasks are done (completed or failed)
 *
 * Order: newest job first, within a job: job completion → tasks (reverse)
 */
const flattenToFeed = (jobs) => {
  const feed = [];

  for (const job of jobs) {
    const tasks = job.tasks || [];
    const isJobDone = job.status === 'completed' || job.status === 'failed';

    // Job completion/status entry (only when job is done or actively processing)
    if (isJobDone || job.status === 'in_progress') {
      feed.push({
        type: 'job',
        status: job.status,
        contentType: job.content_type,
        contentTypeLabel: job.content_type_label,
        contentSlug: job.content_slug,
        contentId: job.id_from,
        contentTitle: job.title,
        langFrom: job.lang_from,
        langTo: job.lang_to,
        duration: job.duration,
        fieldCount: job.total_tasks,
        targetUrl: job.target_url,
        sourceUrl: job.source_url,
        issue: job.issue,
        jobId: job.id,
        taskReference: null,
        timestamp: job.started_at,
        _key: `job-${job.id}`,
      });
    }

    // Task entries (only non-pending, in reverse order so newest appears first)
    const activeTasks = tasks.filter((t) => t.status !== 'pending');
    for (let i = activeTasks.length - 1; i >= 0; i--) {
      const task = activeTasks[i];
      feed.push({
        type: 'task',
        status: task.status,
        fieldLabel: task.label || task.reference_field || task.reference,
        fieldType: task.reference_type || 'core',
        contentType: job.content_type,
        contentTypeLabel: job.content_type_label,
        contentSlug: job.content_slug,
        contentId: job.id_from,
        contentTitle: job.title,
        langFrom: job.lang_from,
        langTo: job.lang_to,
        duration: null,
        fieldCount: 0,
        targetUrl: null,
        sourceUrl: null,
        issue: task.issue,
        jobId: job.id,
        taskReference: task.reference,
        timestamp: job.started_at,
        _key: `task-${job.id}-${task.reference}`,
      });
    }
  }

  return feed;
};

const FILTERS = [
  { key: 'all', label: __('All', 'polylang-ai-automatic-translation') },
  { key: 'active', label: __('Active', 'polylang-ai-automatic-translation') },
  { key: 'done', label: __('Done', 'polylang-ai-automatic-translation') },
  { key: 'errors', label: __('Errors', 'polylang-ai-automatic-translation') },
];

const ActivityPanel = ({ hasActiveTranslations = false }) => {
  const {
    jobs,
    isLoading,
    pagination,
    hasActiveRun,
    selectedDate,
    setSelectedDate,
    statusFilter,
    setStatusFilter,
    newItemCount,
    scrollRef,
    scrollToTop,
    setIsScrolled,
    loadMore,
  } = useActivityData(true, true, hasActiveTranslations);

  const [errorDetail, setErrorDetail] = useState(null);

  const handleErrorDetailClick = useCallback((jobId, taskReference) => {
    const job = jobs.find((j) => j.id === jobId);
    if (!job) {
      return;
    }
    const task = (job.tasks || []).find((t) => t.reference === taskReference);
    setErrorDetail({
      job,
      task: task || null,
      issue: task?.issue || job.issue || '',
      attempts: task?.attempts || 0,
      log: task?.log || null,
    });
  }, [jobs]);

  const handleScroll = useCallback(
    (e) => {
      const el = e.target;
      const atTop = el.scrollTop < 50;
      setIsScrolled(!atTop);

      const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 100;
      if (nearBottom && pagination.page < pagination.totalPages && !isLoading) {
        loadMore();
      }
    },
    [setIsScrolled, pagination, isLoading, loadMore],
  );


  const feedItems = useMemo(() => flattenToFeed(jobs), [jobs]);

  return (
    <>
    <div
      className="pllat-bg-white pllat-rounded-lg pllat-flex pllat-flex-col pllat-sticky pllat-top-8"
      style={{
        border: '1px solid #e5e7eb',
        boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
        height: 'calc(100vh - 100px)',
      }}
    >
        {/* Header */}
        <div className="pllat-flex-shrink-0 pllat-px-5 pllat-pt-4 pllat-pb-3" style={{ borderBottom: '1px solid #e5e7eb' }}>
          <div className="pllat-flex pllat-items-center pllat-justify-between pllat-mb-3">
            <div className="pllat-flex pllat-items-center pllat-gap-2">
              <h2 className="pllat-text-base pllat-font-semibold pllat-m-0">{__('Activity', 'polylang-ai-automatic-translation')}</h2>
              {hasActiveRun && (
                <span className="pllat-relative pllat-flex pllat-h-2 pllat-w-2">
                  <span className="pllat-animate-ping pllat-absolute pllat-inline-flex pllat-h-full pllat-w-full pllat-rounded-full pllat-bg-blue-400 pllat-opacity-75"></span>
                  <span className="pllat-relative pllat-inline-flex pllat-rounded-full pllat-h-2 pllat-w-2 pllat-bg-blue-500"></span>
                </span>
              )}
            </div>
            <Dropdown
              popoverProps={{ placement: 'bottom-end' }}
              renderToggle={({ isOpen, onToggle }) => (
                <button
                  type="button"
                  onClick={onToggle}
                  aria-expanded={isOpen}
                  className="pllat-inline-flex pllat-items-center pllat-gap-1 pllat-bg-transparent pllat-border-0 pllat-px-0 pllat-py-0 pllat-text-[13px] pllat-text-gray-500 hover:pllat-text-gray-800 pllat-cursor-pointer pllat-transition-colors"
                  style={{ boxShadow: 'none' }}
                >
                  {selectedDate
                    ? new Date(selectedDate + 'T00:00:00').toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric',
                      })
                    : __('Select date', 'polylang-ai-automatic-translation')
                  }
                  <span className="dashicons dashicons-calendar-alt" style={{ fontSize: '13px', width: '13px', height: '13px' }} />
                </button>
              )}
              renderContent={({ onClose }) => (
                <DatePicker
                  currentDate={selectedDate ? selectedDate + 'T00:00:00' : undefined}
                  onChange={(newDate) => {
                    const dateStr = newDate.split('T')[0];
                    setSelectedDate(dateStr);
                    onClose();
                  }}
                />
              )}
            />
          </div>

          {/* Status filters */}
          <div className="pllat-flex pllat-gap-1.5">
            {FILTERS.map((f) => (
              <button
                key={f.key}
                type="button"
                onClick={() => setStatusFilter(f.key)}
                className={`
                  pllat-px-2.5 pllat-py-1 pllat-text-xs pllat-font-medium pllat-rounded-full pllat-cursor-pointer pllat-transition-colors pllat-border pllat-border-solid
                  ${statusFilter === f.key
                    ? 'pllat-bg-gray-800 pllat-text-white pllat-border-gray-800'
                    : 'pllat-bg-white pllat-text-gray-600 pllat-border-gray-200 hover:pllat-bg-gray-50'
                  }
                `}
                style={{ boxShadow: 'none' }}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        {/* New items banner */}
        {newItemCount > 0 && (
          <button
            type="button"
            onClick={scrollToTop}
            className="pllat-flex-shrink-0 pllat-w-full pllat-px-4 pllat-py-2 pllat-bg-blue-500 pllat-text-white pllat-text-xs pllat-font-medium pllat-text-center pllat-cursor-pointer pllat-border-0 hover:pllat-bg-blue-600 pllat-transition-colors"
          >
            {sprintf(
              _n('%d new item ↑', '%d new items ↑', newItemCount, 'polylang-ai-automatic-translation'),
              newItemCount,
            )}
          </button>
        )}

        {/* Feed */}
        <div
          ref={scrollRef}
          className="pllat-flex-1 pllat-overflow-y-auto pllat-py-2 pllat-bg-gray-50"
          onScroll={handleScroll}
        >
          {isLoading && jobs.length === 0 ? (
            <div className="pllat-p-12 pllat-text-center">
              <span className="dashicons dashicons-update pllat-animate-spin pllat-text-gray-400 pllat-text-2xl"></span>
              <p className="pllat-text-sm pllat-text-gray-500 pllat-mt-2">{__('Loading activity...', 'polylang-ai-automatic-translation')}</p>
            </div>
          ) : feedItems.length === 0 ? (
            <div className="pllat-flex pllat-flex-col pllat-items-center pllat-justify-center pllat-h-full">
              <span className="dashicons dashicons-list-view pllat-text-gray-300 pllat-text-3xl pllat-block pllat-mb-5"></span>
              <p className="pllat-text-sm pllat-text-gray-500 pllat-m-0">
                {__('No activity for this date', 'polylang-ai-automatic-translation')}
              </p>
            </div>
          ) : (
            <>
              {feedItems.map((item) => (
                <ActivityFeedItem
                  key={item._key}
                  item={item}
                  onErrorDetailClick={handleErrorDetailClick}
                />
              ))}
              {isLoading && jobs.length > 0 && (
                <div className="pllat-p-4 pllat-text-center">
                  <span className="dashicons dashicons-update pllat-animate-spin pllat-text-gray-400"></span>
                </div>
              )}
            </>
          )}
        </div>

      </div>

      {/* Error detail modal */}
      {errorDetail && (
        <>
          <div
            className="pllat-fixed pllat-inset-0"
            style={{ zIndex: 100000, backgroundColor: 'rgba(0,0,0,0.3)' }}
            onClick={() => setErrorDetail(null)}
          />
          <div
            className="pllat-fixed pllat-bg-white pllat-rounded-lg pllat-shadow-xl pllat-p-6"
            style={{
              zIndex: 100001,
              top: '50%',
              left: '50%',
              transform: 'translate(-50%, -50%)',
              width: '500px',
              maxHeight: '80vh',
              overflow: 'auto',
            }}
          >
            <div className="pllat-flex pllat-items-center pllat-justify-between pllat-mb-4">
              <h3 className="pllat-text-sm pllat-font-semibold pllat-m-0">{__('Error Details', 'polylang-ai-automatic-translation')}</h3>
              <button
                type="button"
                onClick={() => setErrorDetail(null)}
                className="pllat-text-gray-400 hover:pllat-text-gray-600 pllat-bg-transparent pllat-border-0 pllat-cursor-pointer pllat-p-0"
              >
                <span className="dashicons dashicons-no-alt"></span>
              </button>
            </div>

            {errorDetail.issue && (
              <div className="pllat-mb-3">
                <p className="pllat-text-xs pllat-font-medium pllat-text-gray-500 pllat-mb-1 pllat-mt-0">{__('Issue', 'polylang-ai-automatic-translation')}</p>
                <p className="pllat-text-sm pllat-text-red-600 pllat-m-0">{errorDetail.issue}</p>
              </div>
            )}

            {errorDetail.attempts > 0 && (
              <div className="pllat-mb-3">
                <p className="pllat-text-xs pllat-font-medium pllat-text-gray-500 pllat-mb-1 pllat-mt-0">{__('Attempts', 'polylang-ai-automatic-translation')}</p>
                <p className="pllat-text-sm pllat-text-gray-700 pllat-m-0">{errorDetail.attempts}</p>
              </div>
            )}

            {errorDetail.log && (
              <div>
                <p className="pllat-text-xs pllat-font-medium pllat-text-gray-500 pllat-mb-1 pllat-mt-0">{__('Log Entry', 'polylang-ai-automatic-translation')}</p>
                <pre className="pllat-text-xs pllat-bg-gray-50 pllat-p-3 pllat-rounded pllat-border pllat-border-gray-200 pllat-overflow-auto pllat-m-0 pllat-whitespace-pre-wrap" style={{ maxHeight: '300px' }}>
                  {typeof errorDetail.log === 'string' ? errorDetail.log : JSON.stringify(errorDetail.log, null, 2)}
                </pre>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
};

export default ActivityPanel;
