import { __ } from "@wordpress/i18n";

/**
 * Free edition stand-in for the bulk actions: a sentence with the upgrade
 * link (localized by Upsell_Service). No disabled control on purpose.
 */
const ProCard = () => (
  <p className="pllat-m-0 pllat-text-xs pllat-text-gray-500">
    {__("Translating every item of a content type at once is a Pro feature.", "polylang-ai-automatic-translation")}{" "}
    <a href={window.pllat?.upgradeUrl} target="_blank" rel="noopener noreferrer" className="pllat-underline">
      {__("Upgrade to Pro", "polylang-ai-automatic-translation")}
    </a>
  </p>
);

export default ProCard;
