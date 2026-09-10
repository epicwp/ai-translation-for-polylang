/**
 * One message box for the dashboard's empty states: a dashicon, a sentence
 * and an optional link button. Rendered instead of the content grid (no
 * provider, no target language) or above it (nothing translated yet).
 *
 * @param {Object} props
 * @param {string} props.icon      Dashicon class name.
 * @param {string} props.message   The sentence.
 * @param {Object} [props.action]  { label, href } for the button.
 */
const EmptyState = ({ icon, message, action = null }) => (
  <div
    className="pllat-bg-white pllat-p-6 pllat-rounded-lg pllat-mb-6 pllat-flex pllat-items-center pllat-gap-4"
    style={{ border: "1px solid #e5e7eb", boxShadow: "0 1px 3px rgba(0,0,0,0.04)" }}
  >
    <span
      className={`dashicons ${icon} pllat-shrink-0 pllat-text-blue-600`}
      style={{ fontSize: "28px", width: "28px", height: "28px" }}
    />
    <p className="pllat-m-0 pllat-flex-1 pllat-text-gray-700">{message}</p>
    {action && (
      <a href={action.href} className="button button-primary pllat-shrink-0">
        {action.label}
      </a>
    )}
  </div>
);

export default EmptyState;
