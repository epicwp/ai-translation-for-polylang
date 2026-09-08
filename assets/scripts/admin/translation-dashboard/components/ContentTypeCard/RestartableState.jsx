import { __ } from '@wordpress/i18n';

const RestartableState = ({ status, onConfigureTranslation }) => {
  const statusConfig = {
    cancelled: {
      icon: "dashicons-admin-settings",
      label: __('Configure & Retry', 'polylang-ai-automatic-translation'),
    },
    failed: {
      icon: "dashicons-admin-settings",
      label: __('Configure & Retry', 'polylang-ai-automatic-translation'),
    },
  };

  const config = statusConfig[status];

  return (
    <button
      className="pllat-w-full !pllat-px-4 !pllat-py-2 !pllat-rounded !pllat-font-medium button button-primary pllat-cursor-pointer"
      onClick={onConfigureTranslation}
    >
      <span className="pllat-flex pllat-items-center pllat-justify-center">
        <span className={`dashicons ${config.icon} pllat-mr-2`}></span>
        {config.label}
      </span>
    </button>
  );
};

export default RestartableState;
