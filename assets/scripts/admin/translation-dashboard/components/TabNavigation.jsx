import { __ } from '@wordpress/i18n';

const TabNavigation = ({ activeTab, onTabChange }) => {
  const tabs = [
    { id: "content", label: __('Content', 'polylang-ai-automatic-translation'), icon: "dashicons-admin-site-alt3" },
    { id: "strings", label: __('Strings', 'polylang-ai-automatic-translation'), icon: "dashicons-translation" },
  ];

  return (
    <div className="pllat-bg-white pllat-rounded-lg pllat-mb-6" style={{ border: '1px solid #e5e7eb', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
      <div className="pllat-flex pllat-border-b pllat-border-gray-200">
        {tabs.map((tab, index) => (
          <button
            key={tab.id}
            className={`
              pllat-relative pllat-flex pllat-items-center pllat-px-5 pllat-py-3 pllat-text-sm pllat-font-medium pllat-transition-colors pllat-duration-150
              pllat-border-0 pllat-rounded-none pllat-shadow-none pllat-outline-none pllat-cursor-pointer
              ${index !== 0 ? "pllat-border-l pllat-border-gray-200" : ""}
              ${
                activeTab === tab.id
                  ? "pllat-text-blue-600 pllat-bg-gray-50"
                  : "pllat-text-gray-600 hover:pllat-text-gray-900 hover:pllat-bg-gray-50"
              }
            `}
            style={{
              boxShadow: "none",
              WebkitAppearance: "none",
              appearance: "none",
              background: activeTab === tab.id ? "#f9fafb" : "transparent",
            }}
            onClick={() => onTabChange(tab.id)}
          >
            <span
              className={`dashicons ${tab.icon} pllat-mr-2 ${
                activeTab === tab.id ? "pllat-text-blue-600" : "pllat-text-gray-400"
              }`}
            ></span>
            {tab.label}
            {activeTab === tab.id && (
              <span className="pllat-absolute pllat-bottom-0 pllat-left-0 pllat-right-0 pllat-h-0.5 pllat-bg-blue-600"></span>
            )}
          </button>
        ))}
      </div>
    </div>
  );
};

export default TabNavigation;
