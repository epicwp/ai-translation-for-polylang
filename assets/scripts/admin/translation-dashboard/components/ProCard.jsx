import { __ } from "@wordpress/i18n";

const UPGRADE_URL =
  "https://www.epicwpsolutions.com/upgrade/?utm_source=plugin&utm_medium=dashboard&utm_campaign=free";

/**
 * Free edition stand-in for the bulk actions: a disabled "Translate all"
 * control with a Pro badge and the upgrade link.
 */
const ProCard = () => (
  <div className="pllat-space-y-2">
    <button
      type="button"
      className="pllat-w-full !pllat-px-4 !pllat-py-2 !pllat-rounded !pllat-font-medium button button-primary pllat-cursor-not-allowed pllat-opacity-60"
      disabled
      aria-disabled="true"
    >
      <span className="pllat-flex pllat-items-center pllat-justify-center pllat-gap-2">
        <span className="dashicons dashicons-translation pllat-text-sm"></span>
        {__("Translate all", "polylang-ai-automatic-translation")}
        <span className="pllat-inline-block pllat-rounded pllat-bg-amber-400 pllat-px-1.5 pllat-text-xs pllat-font-semibold pllat-uppercase pllat-text-gray-900">
          {__("Pro", "polylang-ai-automatic-translation")}
        </span>
      </span>
    </button>
    <p className="pllat-m-0 pllat-text-xs pllat-text-gray-500">
      {__("Translating every item of a content type at once is a Pro feature.", "polylang-ai-automatic-translation")}{" "}
      <a href={UPGRADE_URL} target="_blank" rel="noopener noreferrer" className="pllat-underline">
        {__("Upgrade to Pro", "polylang-ai-automatic-translation")}
      </a>
    </p>
  </div>
);

export default ProCard;
