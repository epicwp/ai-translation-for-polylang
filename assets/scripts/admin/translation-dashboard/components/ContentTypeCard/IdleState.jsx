import { __ } from "@wordpress/i18n";

const IdleState = ({ onConfigureTranslation }) => {
  const licenseValid = window.pllat?.licenseValid || false;

  const handleClick = () => {
    console.log("IdleState button clicked");
    if (onConfigureTranslation) {
      onConfigureTranslation();
    } else {
      console.error("onConfigureTranslation is not defined!");
    }
  };

  return (
    <button
      className="pllat-w-full !pllat-px-4 !pllat-py-2 !pllat-rounded !pllat-font-medium button button-primary pllat-cursor-pointer"
      onClick={handleClick}
      disabled={!licenseValid}
      style={!licenseValid ? { opacity: 0.6, cursor: "not-allowed" } : {}}
    >
      <span className="pllat-flex pllat-items-center pllat-justify-center">
        <span className="dashicons dashicons-admin-settings pllat-mr-1 pllat-text-sm"></span>
        {__("Configure & Translate", "polylang-ai-automatic-translation")}
      </span>
    </button>
  );
};

export default IdleState;
