import { __ } from "@wordpress/i18n";

/**
 * A styled info/help message box displayed above tab content.
 *
 * @param {Object}  props
 * @param {string}  props.children - The message content.
 * @param {string}  [props.icon]   - Dashicon class name (default: info-outline).
 */
const InfoBox = ({ children, icon = "dashicons-info-outline" }) => {
  return (
    <div className="pllat-mb-5 pllat-px-4 pllat-py-3 pllat-bg-blue-50 pllat-border pllat-border-solid pllat-border-blue-200 pllat-rounded-lg pllat-flex pllat-items-start pllat-gap-2.5 pllat-text-sm pllat-text-blue-800 pllat-leading-relaxed">
      <span
        className={`dashicons ${icon} pllat-shrink-0 pllat-mt-0.5`}
        style={{ fontSize: "18px", width: "18px", height: "18px" }}
      />
      <span>{children}</span>
    </div>
  );
};

export default InfoBox;
