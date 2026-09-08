import { __ } from '@wordpress/i18n';

const AllTranslatedMessage = () => {
  return (
    <div className="pllat-text-center pllat-py-2">
      <span className="pllat-text-green-600 pllat-text-sm pllat-flex pllat-items-center pllat-justify-center">
        <span className="dashicons dashicons-yes-alt pllat-mr-1"></span>
        {__('All translated', 'polylang-ai-automatic-translation')}
      </span>
    </div>
  );
};

export default AllTranslatedMessage;
