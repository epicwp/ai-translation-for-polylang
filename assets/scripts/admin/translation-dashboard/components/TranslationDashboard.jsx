import { useState } from "@wordpress/element";
import { __ } from '@wordpress/i18n';
import { useDashboardPolling } from "../hooks/useDashboardPolling";
import { useDashboardActions } from "../hooks/useDashboardActions";
import TabNavigation from "./TabNavigation";
import StringsTab from "./StringsTab";
import ContentTypeCard from "./ContentTypeCard";
import ActivityPanel from "./ActivityPanel";
import DashboardHeader from "./DashboardHeader";
import { DiscoveryOverlay } from "./DiscoveryOverlay";
import InfoBox from "./InfoBox";
import PreflightFailedDialog from "../../components/PreflightFailedDialog";

// `__PLLAT_EDITION__` is a compile-time constant (webpack DefinePlugin). The
// pro branches below are dead code in the free build, so the bulk actions,
// the Strings tab and their hooks never reach the free bundle.
const TranslationDashboard = () => {
  const { data, isFetching, isPolling, hasActiveTranslations, refetch } = useDashboardPolling();
  // __PLLAT_EDITION__ is a build-time constant (webpack DefinePlugin), so the hook
  // order is fixed per bundle and the rules of hooks hold for each build.
  const actions = __PLLAT_EDITION__ === 'pro' ? useDashboardActions(refetch) : null;
  const [activeTab, setActiveTab] = useState("content");

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

          {__PLLAT_EDITION__ === 'pro' && (
            <TabNavigation activeTab={activeTab} onTabChange={setActiveTab} />
          )}

          {activeTab === "content" && (
            isFetching && Object.keys(data.contentTypes).length === 0 ? (
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
                />
              ))}
            </div>
            </>
            )
          )}

          {__PLLAT_EDITION__ === 'pro' && activeTab === "strings" && <StringsTab />}

        </div>

        {/* Right: Activity panel (always visible) */}
        <div className="pllat-w-[400px] pllat-flex-shrink-0">
          <ActivityPanel hasActiveTranslations={hasActiveTranslations} />
        </div>
      </div>

      {__PLLAT_EDITION__ === 'pro' && actions.preflightFailure && (
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
