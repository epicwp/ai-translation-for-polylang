import { _n, sprintf } from '@wordpress/i18n';
import { getLanguageData } from "../../shared/utils/languages";

const LanguageProgress = ({ code, translated, total }) => {
  const progress = total > 0 ? Math.round((translated / total) * 100) : 0;
  const remaining = total - translated;
  const isComplete = translated === total;

  // Get language data from Polylang
  const langData = getLanguageData(code);
  const label = langData?.name || langData?.label || code.toUpperCase();
  const flagUrl = langData?.flag;

  return (
    <div className="pllat-space-y-2">
      <div className="pllat-flex pllat-items-center pllat-justify-between pllat-text-sm">
        <div className="pllat-flex pllat-items-center pllat-space-x-2">
          {flagUrl && <img src={flagUrl} alt={label} className="pllat-w-4 pllat-h-auto" />}
          <span className="pllat-font-medium">{label}</span>
        </div>
        <span
          className={`pllat-text-xs ${
            isComplete ? "pllat-text-green-600" : "pllat-text-gray-500"
          }`}
        >
          {translated}/{total}
        </span>
      </div>

      <div className="pllat-relative">
        {/* Progress bar background */}
        <div className="pllat-w-full pllat-bg-gray-200 pllat-rounded-full pllat-h-2">
          <div
            className={`pllat-h-2 pllat-rounded-full pllat-transition-all pllat-duration-500 ${
              isComplete ? "pllat-bg-green-500" : "pllat-bg-blue-600"
            }`}
            style={{ width: `${progress}%` }}
          />
        </div>

        {/* Percentage label */}
        {!isComplete && (
          <div
            className="pllat-absolute -pllat-top-0.5 pllat-text-[10px] pllat-text-gray-600 pllat-font-medium"
            style={{ left: `${Math.min(progress, 90)}%` }}
          >
            {progress}%
          </div>
        )}

        {/* Complete checkmark */}
        {isComplete && (
          <div className="pllat-absolute pllat-right-0 -pllat-top-1">
            <span className="dashicons dashicons-yes pllat-text-green-600 pllat-text-sm"></span>
          </div>
        )}
      </div>

      {/* Remaining count - always show to maintain consistent height */}
      <div className="pllat-text-xs pllat-text-gray-500">{sprintf(_n('%d remaining', '%d remaining', remaining, 'polylang-ai-automatic-translation'), remaining)}</div>
    </div>
  );
};

export default LanguageProgress;
