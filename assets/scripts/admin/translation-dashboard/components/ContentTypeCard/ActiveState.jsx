import { __, _n, sprintf } from '@wordpress/i18n';

const ActiveState = ({ status, runId, runProgress, onCancelRun }) => {
  const dotsLoader = window.pllat?.assets?.icons?.dotsLoader;
  const ringLoader = window.pllat?.assets?.icons?.ringLoader;

  const statusConfig = {
    pending: {
      iconSrc: dotsLoader,
      label: __('Starting...', 'polylang-ai-automatic-translation'),
      cancelIcon: "dashicons-dismiss",
      iconFilter: "invert(31%) sepia(100%) saturate(2080%) hue-rotate(197deg) brightness(96%) contrast(93%)", // Blue
    },
    translating: {
      iconSrc: ringLoader,
      label: __('Translating...', 'polylang-ai-automatic-translation'),
      cancelIcon: "dashicons-dismiss",
      iconFilter: "invert(48%) sepia(79%) saturate(2476%) hue-rotate(86deg) brightness(92%) contrast(101%)", // Green
    },
  };

  const config = statusConfig[status];

  // Helper function to format time duration
  const formatTime = (seconds) => {
    if (!seconds || seconds < 1) return null; // Don't show if 0 or less
    if (seconds < 60) return `${seconds}s`;
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return secs > 0 ? `${mins}m ${secs}s` : `${mins}m`;
  };

  // Build progress label
  let progressLabel = config.label;
  if (runProgress && runProgress.total > 0) {
    progressLabel = `${runProgress.completed}/${runProgress.total} (${runProgress.percentage}%)`;
  }

  return (
    <div className="pllat-space-y-2">
      <div className="pllat-flex pllat-gap-2">
        <button
          className="pllat-flex-1 !pllat-px-4 !pllat-py-2 !pllat-rounded !pllat-font-medium button button-secondary pllat-cursor-default"
          disabled
        >
          <span className="pllat-flex pllat-items-center pllat-justify-center">
            <img
              src={config.iconSrc}
              width="16"
              height="16"
              alt=""
              className="pllat-mr-2"
              style={{ filter: config.iconFilter }}
            />
            {progressLabel}
          </span>
        </button>
        {onCancelRun && (
          <button
            type="button"
            aria-label={__('Cancel translation', 'polylang-ai-automatic-translation')}
            title={__('Cancel translation', 'polylang-ai-automatic-translation')}
            onClick={() => onCancelRun(runId)}
            className="pllat-flex pllat-items-center pllat-justify-center !pllat-w-9 !pllat-px-0 !pllat-py-2 !pllat-rounded !pllat-bg-white !pllat-text-gray-500 !pllat-border !pllat-border-solid !pllat-border-gray-300 !pllat-shadow-none pllat-cursor-pointer pllat-transition-colors hover:!pllat-bg-red-50 hover:!pllat-border-red-300 hover:!pllat-text-red-600 focus-visible:!pllat-outline-none focus-visible:!pllat-ring-2 focus-visible:!pllat-ring-red-200"
          >
            <span
              className={`dashicons ${config.cancelIcon}`}
              style={{ fontSize: '18px', width: '18px', height: '18px', lineHeight: 1 }}
            ></span>
          </button>
        )}
      </div>

      {/* Progress details */}
      {runProgress && runProgress.total > 0 && (
        <div className="pllat-text-xs pllat-text-gray-600 pllat-space-y-1">
          {/* Progress bar */}
          <div className="pllat-w-full pllat-bg-gray-200 pllat-rounded-full pllat-h-1.5">
            <div
              className="pllat-bg-green-600 pllat-h-1.5 pllat-rounded-full pllat-transition-all pllat-duration-300"
              style={{ width: `${runProgress.percentage}%` }}
            ></div>
          </div>

          {/* Time and ETA */}
          <div className="pllat-flex pllat-justify-between pllat-items-center">
            <span>
              {runProgress.elapsedTime > 0 && formatTime(runProgress.elapsedTime) && (
                <span className="pllat-mr-3">
                  ⏱ {formatTime(runProgress.elapsedTime)}
                </span>
              )}
              {runProgress.estimatedTime > 0 && runProgress.pending > 0 && formatTime(runProgress.estimatedTime) && (
                <span className="pllat-text-gray-500">
                  {sprintf(__('~%s remaining', 'polylang-ai-automatic-translation'), formatTime(runProgress.estimatedTime))}
                </span>
              )}
            </span>
            {runProgress.failed > 0 && (
              <span className="pllat-text-red-600">
                {sprintf(_n('%d failed', '%d failed', runProgress.failed, 'polylang-ai-automatic-translation'), runProgress.failed)}
              </span>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default ActiveState;
