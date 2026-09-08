const OverallProgress = ({ data }) => {
  const { translated = 0, total = 0 } = data;
  const percentage = total > 0 ? Math.round((translated / total) * 100) : 0;
  const remaining = total - translated;

  return (
    <div className="pllat-mt-4">
      <div className="pllat-flex pllat-items-center pllat-justify-between pllat-mb-2">
        <span className="pllat-text-sm pllat-font-medium pllat-text-gray-700">
          Content Translation Progress
        </span>
        <span className="pllat-text-sm pllat-text-gray-500">
          {translated} of {total} items translated
        </span>
      </div>

      <div className="pllat-relative">
        <div className="pllat-w-full pllat-bg-gray-200 pllat-rounded-full pllat-h-4">
          <div
            className="pllat-bg-blue-600 pllat-h-4 pllat-rounded-full pllat-transition-all pllat-duration-500 pllat-relative"
            style={{ width: `${percentage}%` }}
          >
            <div className="pllat-absolute pllat-inset-0 pllat-rounded-full pllat-overflow-hidden">
              <div className="pllat-h-full pllat-w-full pllat-bg-gradient-to-r pllat-from-transparent pllat-via-white/20 pllat-to-transparent pllat-animate-shimmer" />
            </div>
          </div>
        </div>

        <div className="pllat-absolute pllat-inset-0 pllat-flex pllat-items-center pllat-justify-center">
          <span className="pllat-text-xs pllat-font-semibold pllat-text-white">
            {percentage}%
          </span>
        </div>
      </div>

      <div className="pllat-flex pllat-justify-between pllat-mt-2 pllat-text-xs pllat-text-gray-600">
        <span>
          <span className="pllat-font-medium">{remaining}</span> items remaining
        </span>
        <span>
          <span className="pllat-font-medium">{translated}</span> completed
        </span>
      </div>
    </div>
  );
};

export default OverallProgress;
