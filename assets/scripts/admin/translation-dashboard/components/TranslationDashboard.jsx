import { useState } from "@wordpress/element";
import { __ } from '@wordpress/i18n';
import { useDashboardPolling } from "../hooks/useDashboardPolling";
import ContentTypeCard from "./ContentTypeCard";
import ActivityPanel from "./ActivityPanel";
import DashboardHeader from "./DashboardHeader";
import { DiscoveryOverlay } from "./DiscoveryOverlay";
import InfoBox from "./InfoBox";
import EmptyState from "./EmptyState";
import PreflightFailedDialog from "../../components/PreflightFailedDialog";

// The bundle entry (index.<edition>.jsx) composes the dashboard: the actions
// hook, the tab navigation, the extra tabs, the card actions and the config
// modal come in as props.
const TranslationDashboard = ({
  useActions = () => null,
  TabNavigation = null,
  tabs = {},
  CardActions,
  ConfigModal = null,
}) => {
  const { data, isFetching, isPolling, hasActiveTranslations, refetch } = useDashboardPolling();
  const actions = useActions(refetch);
  const [activeTab, setActiveTab] = useState("content");
  const ActiveTab = activeTab !== "content" ? tabs[activeTab] : null;

  // Empty states, in priority order. The first two come from the localized
  // config so they show without waiting for the first fetch; the third needs
  // the loaded content types.
  const adminUrl = window.pllat?.adminUrl || "";
  const hasContentTypes = Object.keys(data.contentTypes).length > 0;
  const emptyState = !window.pllat?.translatorConfigured
    ? {
        icon: "dashicons-admin-network",
        message: __("Add your OpenAI API key to start translating.", "polylang-ai-automatic-translation"),
        action: {
          label: __("Add API key", "polylang-ai-automatic-translation"),
          href: adminUrl + "admin.php?page=pllat-settings&tab=general",
        },
      }
    : (window.pllat?.languages?.length || 0) < 2
      ? {
          icon: "dashicons-translation",
          message: __("Add at least one more language in Polylang to translate into.", "polylang-ai-automatic-translation"),
          action: {
            label: __("Open Polylang languages", "polylang-ai-automatic-translation"),
            href: adminUrl + "admin.php?page=mlang",
          },
        }
      : null;
  const nothingTranslatedYet = !emptyState && hasContentTypes && data.overall.translated === 0;

  return (
    <>
      {/* Discovery Overlay */}
      {data?.discovery && <DiscoveryOverlay discoveryCheck={data.discovery} />}

      {/* Two-column layout: main content + activity panel */}
      <div
        className={`translation-dashboard pllat-flex pllat-gap-6 ${data?.discovery?.needed ? 'pllat-blur-sm pllat-pointer-events-none' : ''}`}
      >
        {/* Left: Main dashboard content */}
        <div className="pllat-flex-1 pllat-min-w-0">
          <DashboardHeader data={data} />

          {TabNavigation && (
            <TabNavigation activeTab={activeTab} onTabChange={setActiveTab} />
          )}

          {activeTab === "content" && emptyState && (
            <EmptyState icon={emptyState.icon} message={emptyState.message} action={emptyState.action} />
          )}

          {activeTab === "content" && !emptyState && (
            isFetching && !hasContentTypes ? (
              <div className="pllat-text-center pllat-py-12">
                <span
                  className="spinner is-active"
                  style={{ float: "none", width: "30px", height: "30px" }}
                />
                <p className="pllat-text-gray-500 pllat-mt-4">
                  {__("Loading content types...", "polylang-ai-automatic-translation")}
                </p>
              </div>
            ) : (
            <>
            <InfoBox>
<><strong>{__('Content', 'polylang-ai-automatic-translation')}</strong> — {__('This section shows the translation progress of your website\'s main content — posts, pages, custom post types, categories, tags, and custom taxonomies.', 'polylang-ai-automatic-translation')}</>
            </InfoBox>
            {nothingTranslatedYet && (
              <EmptyState
                icon="dashicons-edit-page"
                message={__("Nothing translated yet. Open a post, page or term and use the AI Translation box to translate it into your languages.", "polylang-ai-automatic-translation")}
              />
            )}
            <div className="pllat-grid pllat-grid-cols-1 lg:pllat-grid-cols-2 2xl:pllat-grid-cols-3 min-[1920px]:pllat-grid-cols-4 pllat-gap-6">
              {Object.entries(data.contentTypes).map(([key, contentType]) => (
                <ContentTypeCard
                  key={key}
                  name={key}
                  title={contentType.label}
                  singularLabel={contentType.singularLabel}
                  icon={contentType.icon}
                  type={contentType.type}
                  languageStats={contentType.languages}
                  targetLanguages={data.targetLanguages}
                  onStartTranslation={(options) =>
                    actions?.startContentTranslation(key, contentType, options)
                  }
                  onCancelRun={() => actions?.cancelRun(contentType.type, key)}
                  currentStatus={contentType.translationState}
                  activeRunId={contentType.runId}
                  runProgress={contentType.runProgress}
                  Actions={CardActions}
                  ConfigModal={ConfigModal}
                />
              ))}
            </div>
            </>
            )
          )}

          {ActiveTab && <ActiveTab />}

        </div>

        {/* Right: Activity panel (always visible) */}
        <div className="pllat-w-[400px] pllat-flex-shrink-0">
          <ActivityPanel hasActiveTranslations={hasActiveTranslations} />
        </div>
      </div>

      {actions?.preflightFailure && (
        <PreflightFailedDialog
          preflight={actions.preflightFailure}
          scope="bulk"
          onClose={actions.clearPreflightFailure}
          onContinueAnyway={actions.continueAnyway}
        />
      )}
    </>
  );
};

export default TranslationDashboard;
