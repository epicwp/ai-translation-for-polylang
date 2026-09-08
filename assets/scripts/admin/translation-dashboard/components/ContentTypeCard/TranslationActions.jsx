import IdleState from "./IdleState";
import ActiveState from "./ActiveState";
import RestartableState from "./RestartableState";
import AutoTranslateActive from "./AutoTranslateActive";
import AllTranslatedMessage from "./AllTranslatedMessage";

const TranslationActions = ({
  hasUntranslatedItems,
  isAutoTranslateEnabled,
  currentStatus,
  activeRunId,
  runProgress,
  isStarting,
  onConfigureTranslation,
  onCancelRun,
}) => {
  if (!hasUntranslatedItems) {
    return <AllTranslatedMessage />;
  }

  if (isAutoTranslateEnabled) {
    return <AutoTranslateActive />;
  }

  // Override status if starting
  const displayStatus = isStarting ? "pending" : currentStatus;

  return (
    <div className="pllat-space-y-2">
      {displayStatus === "idle" && (
        <IdleState onConfigureTranslation={onConfigureTranslation} />
      )}

      {(displayStatus === "pending" || displayStatus === "translating") && (
        <ActiveState
          status={displayStatus}
          runId={activeRunId}
          runProgress={runProgress}
          onCancelRun={onCancelRun}
        />
      )}

      {(displayStatus === "cancelled" || displayStatus === "failed") && (
        <RestartableState
          status={displayStatus}
          onConfigureTranslation={onConfigureTranslation}
        />
      )}
    </div>
  );
};

export default TranslationActions;
