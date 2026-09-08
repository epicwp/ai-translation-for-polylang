import { useState } from "@wordpress/element";

const AutoTranslateToggle = ({ enabled, onToggle }) => {
  const [isChanging, setIsChanging] = useState(false);
  const isDisabled = true; // Temporarily disabled

  const handleToggle = () => {
    if (isDisabled) return;
    setIsChanging(true);
    onToggle(!enabled);

    // Simulate API call
    setTimeout(() => {
      setIsChanging(false);
    }, 500);
  };

  return (
    <div className="pllat-flex pllat-items-center pllat-space-x-3">
      <span className="pllat-text-sm pllat-font-medium pllat-text-gray-700">Auto-Translate:</span>

      <div
        onClick={handleToggle}
        disabled={isChanging || isDisabled}
        className={`
                    pllat-relative pllat-inline-flex pllat-h-6 pllat-w-11 pllat-items-center pllat-rounded-full pllat-shadow-sm pllat-border-0 pllat-px-1
                    pllat-transition-colors focus:pllat-outline-none focus:pllat-ring-1 focus:pllat-ring-gray-300
                    ${enabled ? "pllat-bg-green-500" : "pllat-bg-gray-300"}
                    ${
                      isChanging || isDisabled
                        ? "pllat-opacity-50 pllat-cursor-not-allowed"
                        : "pllat-cursor-pointer"
                    }
                `}
        role="switch"
        aria-checked={enabled}
      >
        <span className="pllat-sr-only">Toggle auto-translate</span>
        <span
          className={`
                        pllat-inline-block pllat-h-4 pllat-w-4 pllat-transform pllat-rounded-full pllat-bg-white pllat-shadow-sm pllat-transition-transform
                        ${enabled ? "pllat-translate-x-6" : "pllat-translate-x-1"}
                    `}
        />
      </div>

      <span
        className={`pllat-text-sm pllat-font-medium ${
          enabled ? "pllat-text-green-600" : "pllat-text-gray-500"
        }`}
      >
        {enabled ? "ON" : "OFF"}
      </span>

      {isDisabled && (
        <span className="pllat-inline-flex pllat-items-center pllat-px-2 pllat-py-1 pllat-rounded pllat-text-xs pllat-font-medium pllat-bg-yellow-100 pllat-text-yellow-800 pllat-border pllat-border-yellow-300">
          Coming Soon
        </span>
      )}

      {enabled && !isDisabled && (
        <span className="pllat-flex pllat-items-center pllat-text-xs pllat-text-gray-500">
          <span className="dashicons dashicons-info-outline pllat-mr-1"></span>
          Processing automatically
        </span>
      )}
    </div>
  );
};

export default AutoTranslateToggle;
