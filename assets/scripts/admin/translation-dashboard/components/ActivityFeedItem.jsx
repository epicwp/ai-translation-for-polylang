import { __ } from '@wordpress/i18n';
import { getLanguageData } from '../../shared/utils/languages';

const STATUS_DOT = {
  completed: { color: '#10B981', pulse: false },
  in_progress: { color: '#3B82F6', pulse: true },
  failed: { color: '#EF4444', pulse: false },
};

const formatTimestamp = (ts) => {
  if (!ts) {
    return null;
  }
  const date = new Date(ts.includes('T') ? ts : ts.replace(' ', 'T') + 'Z');
  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const LangLabel = ({ code }) => {
  const data = getLanguageData(code);
  const flag = data?.flag;
  const name = data?.name || code?.toUpperCase() || '';
  const short = code?.toUpperCase() || '';

  return (
    <span className="pllat-inline-flex pllat-items-center pllat-gap-1" title={name}>
      {flag && <img src={flag} alt={name} className="pllat-w-3.5 pllat-h-auto" title={name} style={{ imageRendering: 'auto' }} />}
      <span>{short}</span>
    </span>
  );
};

/**
 * A single feed item — either a task event or a job completion event.
 *
 * item.type: 'task' | 'job'
 * item.status: 'completed' | 'in_progress' | 'failed'
 * item.fieldLabel: string (task only)
 * item.langFrom / item.langTo: string
 * item.contentType: 'post' | 'term'
 * item.contentId: number
 * item.contentTitle: string
 * item.duration: number|null (job only)
 * item.fieldCount: number (job only)
 * item.targetUrl: string|null
 * item.issue: string|null
 * item.jobId: number
 * item.taskReference: string|null
 */
const ActivityFeedItem = ({ item, onErrorDetailClick }) => {
  const dot = STATUS_DOT[item.status] || STATUS_DOT.completed;

  const typeLabel = item.contentTypeLabel || (item.contentType === 'post'
    ? __('Post', 'polylang-ai-automatic-translation')
    : __('Term', 'polylang-ai-automatic-translation'));

  return (
    <div
      className={`pllat-my-2.5 pllat-bg-white pllat-rounded-lg pllat-transition-colors pllat-overflow-hidden ${item.type === 'task' ? 'pllat-ml-8 pllat-mr-4' : 'pllat-mx-4'}`}
      style={{ border: '1px solid #e5e7eb', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}
    >
      {/* Line 1: Message */}
      <div className="pllat-flex pllat-items-start pllat-gap-3 pllat-px-4 pllat-py-3">
        {/* Status dot */}
        <span
          className={`pllat-flex-shrink-0 pllat-rounded-full pllat-mt-1.5 ${dot.pulse ? 'pllat-animate-pulse' : ''}`}
          style={{ width: '7px', height: '7px', backgroundColor: dot.color }}
        />

        <div className="pllat-flex-1 pllat-min-w-0 pllat-flex pllat-items-baseline pllat-justify-between pllat-gap-3">
          <span className="pllat-text-[13px] pllat-text-gray-800 pllat-leading-snug">
            {item.type === 'job' ? buildJobMessage(item) : buildTaskMessage(item)}
          </span>
          {/* View post/term link (completed job only, top-right) */}
          {item.type === 'job' && item.status === 'completed' && item.targetUrl && (
            <a
              href={item.targetUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="pllat-flex-shrink-0 pllat-text-blue-500 hover:pllat-text-blue-700 pllat-no-underline pllat-text-[11px]"
            >
              {__('View', 'polylang-ai-automatic-translation')} &#8599;
            </a>
          )}
        </div>
      </div>

      {/* Line 2: Meta (gray background, separated by border) */}
      <div
        className="pllat-flex pllat-items-center pllat-justify-between pllat-px-4 pllat-py-2 pllat-bg-gray-50 pllat-text-[11px]"
        style={{ borderTop: '1px solid #f3f4f6' }}
      >
        <span className="pllat-inline-flex pllat-items-center pllat-flex-wrap pllat-gap-x-1.5 pllat-gap-y-0.5">
            <span className="pllat-text-gray-500">
              {item.contentType === 'string'
                ? typeLabel
                : `${typeLabel} #${item.contentId}`
              }
            </span>

            {/* Field count (job only) */}
            {item.type === 'job' && item.fieldCount > 0 && (
              <>
                <span className="pllat-text-gray-300">&middot;</span>
                <span className="pllat-text-gray-500">
                  {`${item.fieldCount} ${item.fieldCount === 1 ? 'field' : 'fields'}`}
                </span>
              </>
            )}

            {/* Timestamp (job only) */}
            {item.type === 'job' && item.timestamp && (
              <>
                <span className="pllat-text-gray-300">&middot;</span>
                <span className="pllat-text-gray-400">{formatTimestamp(item.timestamp)}</span>
              </>
            )}

            {/* View error link */}
            {item.status === 'failed' && (
              <>
                <span className="pllat-text-gray-300">&middot;</span>
                <button
                  type="button"
                  onClick={() => onErrorDetailClick(item.jobId, item.taskReference)}
                  className="pllat-text-red-500 hover:pllat-text-red-700 pllat-cursor-pointer pllat-bg-transparent pllat-border-0 pllat-p-0 pllat-text-[11px]"
                >
                  {__('View error', 'polylang-ai-automatic-translation')}
                </button>
              </>
            )}
        </span>

        <span className="pllat-text-gray-500 pllat-inline-flex pllat-items-center pllat-gap-1">
          <LangLabel code={item.langFrom} /> <span>→</span> <LangLabel code={item.langTo} />
        </span>
      </div>
    </div>
  );
};

function buildJobMessage(item) {
  if (item.status === 'completed') {
    return (
      <>
        {__('Completed', 'polylang-ai-automatic-translation')}{' '}
        <strong>&ldquo;{item.contentTitle}&rdquo;</strong>
      </>
    );
  }
  if (item.status === 'failed') {
    return (
      <>
        <span className="pllat-text-red-600">{__('Failed', 'polylang-ai-automatic-translation')}</span>{' '}
        <strong>&ldquo;{item.contentTitle}&rdquo;</strong>
      </>
    );
  }
  // in_progress
  return (
    <>
      {__('Translating', 'polylang-ai-automatic-translation')}{' '}
      <strong>&ldquo;{item.contentTitle}&rdquo;</strong>...
    </>
  );
}

function getTaskPrefix(item) {
  if (item.contentType === 'string') {
    return __('strings', 'polylang-ai-automatic-translation');
  }
  if (item.contentSlug === 'nav_menu') {
    return __('menu item', 'polylang-ai-automatic-translation');
  }
  if (item.fieldType === 'meta' || item.fieldType === 'custom_data') {
    return __('meta field', 'polylang-ai-automatic-translation');
  }
  return __('field', 'polylang-ai-automatic-translation');
}

function buildTaskMessage(item) {
  const fieldPrefix = getTaskPrefix(item);
  // A failed item may have no specific field reference: batch-level failures
  // (whole-post LLM call returned malformed JSON, response truncated, etc.)
  // can't attribute to one field. Without this guard the UI renders
  // `field: ""` — empty quotes that look broken.
  const hasFieldLabel =
    typeof item.fieldLabel === 'string' && item.fieldLabel.length > 0;

  if (item.status === 'completed') {
    return (
      <>
        {__('Translated', 'polylang-ai-automatic-translation')}{' '}
        {fieldPrefix}: &ldquo;{item.fieldLabel}&rdquo;
      </>
    );
  }
  if (item.status === 'failed') {
    return (
      <>
        <span className="pllat-text-red-600">
          {__('Error translating', 'polylang-ai-automatic-translation')}
        </span>{' '}
        {hasFieldLabel ? (
          <>
            {fieldPrefix}: &ldquo;{item.fieldLabel}&rdquo;
          </>
        ) : (
          __('this item', 'polylang-ai-automatic-translation')
        )}
      </>
    );
  }
  // in_progress
  return (
    <>
      {__('Translating', 'polylang-ai-automatic-translation')}{' '}
      {fieldPrefix}: &ldquo;{item.fieldLabel}&rdquo;...
    </>
  );
}

export default ActivityFeedItem;
