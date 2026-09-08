import { useState, useEffect, memo } from "@wordpress/element";
import CardHeader from "./ContentTypeCard/CardHeader";
import LanguageProgressList from "./ContentTypeCard/LanguageProgressList";
import TranslationActions from "./ContentTypeCard/TranslationActions";
import BulkConfigModal from "./BulkConfigModal";
import ProCard from "./ProCard";

const ContentTypeCard = ({
  name,
  title,
  singularLabel,
  icon,
  type,
  languageStats,
  targetLanguages = [],
  onStartTranslation,
  onCancelRun,
  currentStatus = "idle",
  activeRunId = null,
  runProgress = null,
  isAutoTranslateEnabled = false,
  configPath = null,
}) => {
  const [showConfigModal, setShowConfigModal] = useState(false);
  const [isStarting, setIsStarting] = useState(false);

  // Reset isStarting when currentStatus changes (API response received)
  useEffect(() => {
    if (currentStatus !== "idle" && isStarting) {
      setIsStarting(false);
    }
  }, [currentStatus, isStarting]);

  const stats = Object.values(languageStats);
  const totalTranslated = stats.reduce((sum, lang) => sum + lang.translated, 0);
  const totalItems = stats.reduce((sum, lang) => sum + lang.total, 0);
  const completionPercentage =
    totalItems > 0 ? Math.round((totalTranslated / totalItems) * 100) : 0;
  const hasUntranslatedItems = stats.some(
    (lang) => lang.translated < lang.total
  );

  const handleConfigureClick = () => {
    console.log("Configure button clicked, opening modal for:", name);
    setShowConfigModal(true);
  };

  const handleConfigSubmit = async (config) => {
    // Close modal immediately
    setShowConfigModal(false);

    // Show starting state immediately
    setIsStarting(true);

    // Start translation in background
    try {
      await onStartTranslation({ config });
    } catch (error) {
      console.error("Translation start failed:", error);
    } finally {
      setIsStarting(false);
    }
  };

  return (
    <>
      <div
        className="pllat-bg-white pllat-p-5 pllat-rounded-lg hover:pllat-shadow-lg pllat-transition-shadow pllat-justify-between pllat-flex-col pllat-flex"
        style={{ border: '1px solid #e5e7eb', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}
      >
        <div>
          <CardHeader
            icon={icon}
            title={title}
            name={name}
            completionPercentage={completionPercentage}
          />
          <LanguageProgressList languageStats={languageStats} />
        </div>

        {/* Compile-time edition branch: the free bundle keeps ProCard only. */}
        {__PLLAT_EDITION__ === 'pro' ? (
          <TranslationActions
            hasUntranslatedItems={hasUntranslatedItems}
            isAutoTranslateEnabled={isAutoTranslateEnabled}
            currentStatus={currentStatus}
            activeRunId={activeRunId}
            runProgress={runProgress}
            isStarting={isStarting}
            onConfigureTranslation={handleConfigureClick}
            onCancelRun={onCancelRun}
          />
        ) : (
          <ProCard />
        )}
      </div>

      {__PLLAT_EDITION__ === 'pro' && (
        <BulkConfigModal
          isOpen={showConfigModal}
          onClose={() => setShowConfigModal(false)}
          contentType={{
            type: type,
            entity: name,
            label: title,
            singularLabel: singularLabel,
            icon: icon,
            configPath: configPath,
          }}
          targetLanguages={targetLanguages}
          onSubmit={handleConfigSubmit}
        />
      )}
    </>
  );
};

// Custom comparison: only compare data props, ignore callback prop changes
const arePropsEqual = (prevProps, nextProps) => {
  // Ignore callback functions (onStartTranslation, onCancelRun)
  // Only compare data that affects rendering
  return (
    prevProps.name === nextProps.name &&
    prevProps.title === nextProps.title &&
    prevProps.singularLabel === nextProps.singularLabel &&
    prevProps.icon === nextProps.icon &&
    prevProps.type === nextProps.type &&
    prevProps.languageStats === nextProps.languageStats && // Object reference (hash-based)
    prevProps.targetLanguages === nextProps.targetLanguages && // Object reference
    prevProps.currentStatus === nextProps.currentStatus &&
    prevProps.activeRunId === nextProps.activeRunId &&
    prevProps.runProgress === nextProps.runProgress && // Object reference
    prevProps.isAutoTranslateEnabled === nextProps.isAutoTranslateEnabled
  );
};

export default memo(ContentTypeCard, arePropsEqual);
