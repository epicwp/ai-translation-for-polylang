import { __ } from '@wordpress/i18n';
import OverallProgress from "./OverallProgress";

const DashboardHeader = ({ data }) => {
  return (
    <div
      className="pllat-bg-white pllat-p-6 pllat-rounded-lg pllat-mb-6"
      style={{ border: '1px solid #e5e7eb', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}
    >
      <div className="pllat-mb-4">
        <div className="pllat-text-2xl pllat-font-semibold !pllat-pt-0 !pllat-mt-0 pllat-mb-2 pllat-flex pllat-items-center" role="heading" aria-level="2">
          <span className="dashicons dashicons-translation pllat-mr-2"></span>
          {__("Your website's translations", 'polylang-ai-automatic-translation')}
        </div>
        <p className="pllat-text-gray-600">
          {__('Manage translations across all your content types and languages', 'polylang-ai-automatic-translation')}
        </p>
      </div>

      <OverallProgress data={data.overall} />
    </div>
  );
};

export default DashboardHeader;
