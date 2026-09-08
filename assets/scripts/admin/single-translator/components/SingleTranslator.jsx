/**
 * Main Single Translator component (orchestrator).
 */

import { useEffect, useState, useCallback, useRef } from "@wordpress/element";
import { Spinner, Notice, Button } from "@wordpress/components";
import { __ } from "@wordpress/i18n";

import { useTranslationStatus } from "../hooks/useTranslationStatus";
import { useTranslationActions } from "../hooks/useTranslationActions";
import { useActiveTranslations } from "../hooks/useActiveTranslations";
import { useInitialState } from "../hooks/useInitialState";
import { useRefreshOnSave } from "../hooks/useRefreshOnSave";
import { cleanupExpired, markAsDismissed, isDismissed } from "../utils/dismissedNotifications";
import { replaceInternalLinks } from "../utils/api";

import ExclusionToggle from "./ExclusionToggle";
import LanguageSelector from "./LanguageSelector";
import InstructionsInput from "./InstructionsInput";
import ForceToggle from "./ForceToggle";
import FieldSelector from "../../components/FieldSelector";
import ActionButtons from "./ActionButtons";
import ErrorBanner from "./ErrorBanner";
import ErrorSummaryBanner from "./ErrorSummaryBanner";
import ImportingMessage from "./ImportingMessage";
import PreflightFailedDialog from "../../components/PreflightFailedDialog";
import usePreflight from "../../hooks/usePreflight";

/**
 * Single Translator main component.
 *
 * @returns {JSX.Element} The component
 */
export function SingleTranslator() {
  // Fetch translation status
  const {
    status,
    loading: statusLoading,
    error: statusError,
    refresh,
  } = useTranslationStatus();

  // Translation actions
  const {
    translate,
    toggleExclusion,
    cancel,
    loading: actionLoading,
    error: actionError,
    clearError,
    preflightFailure,
    setPreflightFailure,
    clearPreflightFailure,
  } = useTranslationActions(refresh);

  // Initial state detection
  const initialState = useInitialState(status, statusLoading);

  // Local state
  const [selectedLanguages, setSelectedLanguages] = useState([]);
  const [instructions, setInstructions] = useState("");
  const [force, setForce] = useState(false);
  const [selectedFields, setSelectedFields] = useState([]);
  const [isExcluded, setIsExcluded] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // NEW: UI state tracking
  const [uiState, setUiState] = useState('idle'); // 'idle' | 'translating' | 'queued' | 'completed' | 'failed' | 'no_work'
  const [noWorkMessage, setNoWorkMessage] = useState('');
  const noWorkRef = useRef(null);

  // Scroll the no-work notice into view when it appears so the user
  // doesn't miss why their submission was rejected.
  useEffect(() => {
    if (uiState === 'no_work' && noWorkRef.current) {
      noWorkRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, [uiState]);

  // Pre-translate preflight gate: pending invocation params for the
  // translate call that we'll fire when the user dismisses the modal
  // with "Continue anyway". null when no pending invocation.
  const { runPreflight } = usePreflight();
  const [pendingInvocation, setPendingInvocation] = useState(null);

  // Link replacement state
  const [linkResult, setLinkResult] = useState(null);
  const [linkLoading, setLinkLoading] = useState(false);

  // Success notice dismissal state (5 minute TTL)
  const [successDismissed, setSuccessDismissed] = useState(() => {
    const notificationId = 'pllat_success_notice';
    return isDismissed(notificationId, 5 * 60 * 1000); // 5 minutes
  });

  // Locks the initial-mount auto-start effect after polling has completed
  // (or been cancelled). Without this, the effect re-fires when `polling`
  // drops back to false and resurrects the poll loop — leaving Cancel
  // visible and Start Translation stuck in "Processing…".
  const autoStartedPollingRef = useRef(false);

  // Handle polling completion with state tracking.
  const handlePollingComplete = useCallback((freshData, meta = {}) => {
    autoStartedPollingRef.current = true;

    if (!freshData) {
      setUiState('idle');
      return;
    }

    // The async worker never claimed the run within the grace window — it's
    // queued in the background, not finished. Show an honest "queued" state
    // instead of a premature "completed successfully" toast. See #392.
    if (meta.neverActive) {
      setUiState('queued');
      return;
    }

    const anyFailed = freshData.has_recent_error ||
      freshData.languages.some(lang => lang.status === 'failed');
    setUiState(anyFailed ? 'failed' : 'completed');
  }, []);

  // Active translations polling (with completion callback)
  const { hasActive, polling, startPolling, stopPolling } =
    useActiveTranslations(true, refresh, handlePollingComplete);

  // Refresh on Gutenberg save
  useRefreshOnSave(refresh);

  /**
   * Clean up expired notifications on mount.
   */
  useEffect(() => {
    cleanupExpired();
  }, []);

  /**
   * Initial status fetch.
   */
  useEffect(() => {
    refresh();
  }, [refresh]);

  /**
   * Update exclusion state when status changes.
   */
  useEffect(() => {
    if (status) {
      setIsExcluded(status.is_excluded);
    }
  }, [status]);

  /**
   * Start polling once on mount if a translation was already running when
   * the page loaded. The ref guard (declared above near
   * handlePollingComplete) ensures the effect cannot fire a second time —
   * see comment on autoStartedPollingRef for why.
   */
  useEffect(() => {
    if (autoStartedPollingRef.current) {
      return;
    }
    if (initialState.hasActive && !polling) {
      autoStartedPollingRef.current = true;
      startPolling();
    }
  }, [initialState.hasActive, polling, startPolling]);

  /**
   * Force re-translation only makes sense when every selected language
   * already has a translation. The moment one selected language has no
   * translation yet, force is a no-op for that language (the normal flow
   * translates it once anyway) and showing the toggle is misleading.
   */
  const canForceRetranslate =
    selectedLanguages.length > 0 &&
    selectedLanguages.every((slug) => {
      const info = (status?.languages || []).find((l) => l.language === slug);
      return info && info.status !== null;
    });

  /**
   * Reset force when the selection no longer qualifies for force mode
   * (e.g. user added an untranslated language to the selection).
   */
  useEffect(() => {
    if (!canForceRetranslate && force) {
      setForce(false);
      setSelectedFields([]);
    }
  }, [canForceRetranslate]);

  /**
   * Fire the actual translate call. Split off from handleTranslate so the
   * pre-check path (no blocking checks) and the modal "Continue anyway"
   * path can share the same flow without duplicating the success/error
   * branches.
   */
  const fireTranslate = async (invocation) => {
    const { acknowledgePreflight } = invocation;
    setSubmitting(true);
    setUiState('translating');

    try {
      await translate(
        invocation.targetLanguages,
        invocation.force,
        invocation.instructions,
        invocation.selectedFields,
        acknowledgePreflight,
      );

      startPolling();

      // Reset form
      setSelectedLanguages([]);
      setInstructions("");
      setForce(false);
      setSelectedFields([]);
    } catch (err) {
      if (err?.code === 'pllat_nothing_to_translate') {
        setNoWorkMessage(err.message || '');
        setUiState('no_work');
      } else if (err?.code !== 'pllat_preflight_failed' && err?.code !== 'pllat_index_not_ready') {
        // Preflight 422s are already handled by useTranslationActions
        // (sets preflightFailure → modal). Other errors land on the
        // generic failed banner.
        setUiState('failed');
      }
      console.error('[SingleTranslator] Translation failed:', err);
    } finally {
      setSubmitting(false);
    }
  };

  /**
   * Handle translation start.
   *
   * Two-step flow: run preflight first; only fire the translate call when
   * no blocking issues remain (or when the user explicitly continues via
   * the modal).
   */
  const handleTranslate = async () => {
    if (selectedLanguages.length === 0) {
      return;
    }

    const invocation = {
      targetLanguages: selectedLanguages,
      force,
      instructions,
      selectedFields,
      acknowledgePreflight: false,
    };

    const preflightContext =
      window.pllatSingleTranslator?.type === 'term'
        ? { term_id: window.pllatSingleTranslator?.id }
        : { post_id: window.pllatSingleTranslator?.id };

    let preflightOutcome;
    try {
      preflightOutcome = await runPreflight('single', preflightContext);
    } catch (err) {
      // If preflight itself fails (network/permissions), proceed to the
      // translate call — the server-side gate will catch real issues
      // and the existing 422 → modal path will show them.
      console.error('[SingleTranslator] Preflight pre-check failed:', err);
      preflightOutcome = { result: null, blockingChecks: [] };
    }

    if (preflightOutcome.blockingChecks.length > 0) {
      // Open the modal and remember the request so we can resume on
      // "Continue anyway". Until then no translate fires.
      setPendingInvocation(invocation);
      setPreflightFailure(preflightOutcome.result);
      return;
    }

    await fireTranslate(invocation);
  };

  /**
   * User clicked "Continue anyway" in the preflight modal. Resume the
   * pending translate call with acknowledge_preflight=true so the
   * server-side fail-gate (if any) doesn't reject the request.
   */
  const handleContinueAnyway = async () => {
    if (!pendingInvocation) {
      clearPreflightFailure();
      return;
    }
    const invocation = { ...pendingInvocation, acknowledgePreflight: true };
    setPendingInvocation(null);
    clearPreflightFailure();
    await fireTranslate(invocation);
  };

  /**
   * Discard the pending translate request when the user closes the modal
   * without continuing.
   */
  const handlePreflightClose = () => {
    setPendingInvocation(null);
    clearPreflightFailure();
  };

  /**
   * Handle exclusion toggle.
   */
  const handleExclusionToggle = async (excluded) => {
    try {
      await toggleExclusion(excluded);
      setIsExcluded(excluded);
    } catch (err) {
      // Error is already set in useTranslationActions
      console.error("Exclusion toggle failed:", err);
    }
  };

  /**
   * Handle translation cancellation.
   */
  const handleCancel = async () => {
    try {
      await cancel();

      // Stop polling
      stopPolling();

      // Reset UI state
      setUiState('idle');
    } catch (err) {
      // Error is already set in useTranslationActions
      console.error("Cancel failed:", err);
    }
  };

  /**
   * Handle success notice dismissal.
   */
  const handleSuccessDismiss = useCallback(() => {
    const notificationId = 'pllat_success_notice';
    markAsDismissed(notificationId);
    setSuccessDismissed(true);
  }, []);

  /**
   * Handle manual link replacement.
   */
  const handleLinkReplacement = async () => {
    const { type, id } = window.pllatSingleTranslator || {};
    if (!type || !id) return;

    setLinkLoading(true);
    setLinkResult(null);
    try {
      const result = await replaceInternalLinks(type, id);
      setLinkResult(result);
    } catch (err) {
      setLinkResult({ error: err.message || __('Internal link translation failed.', 'polylang-ai-automatic-translation') });
    } finally {
      setLinkLoading(false);
    }
  };

  /**
   * Render loading state.
   */
  if (statusLoading && !status) {
    return (
      <div style={{ padding: "20px", textAlign: "center" }}>
        <Spinner />
      </div>
    );
  }

  /**
   * Render error state.
   */
  if (statusError) {
    return (
      <Notice status="error" isDismissible={false}>
        {statusError}
      </Notice>
    );
  }

  /**
   * Render when no status data.
   */
  if (!status) {
    return null;
  }

  const { system_status: systemStatus, languages } = status;

  /**
   * Render when translator API is not configured.
   */
  if (!window.pllatSingleTranslator?.translatorConfigured) {
    return (
      <Notice status="warning" isDismissible={false}>
        {__('No translator API configured', 'polylang-ai-automatic-translation')}.{' '}
        <a href={window.pllat?.adminUrl + 'admin.php?page=pllat-settings'} style={{ textDecoration: 'underline' }}>
          {__('Configure API settings', 'polylang-ai-automatic-translation')}
        </a>
      </Notice>
    );
  }

  /**
   * Render system not ready state.
   */
  if (systemStatus === "not_ready") {
    return (
      <Notice status="warning" isDismissible={false}>
        {__(
          "The translation system is not ready. Please configure your AI provider in the settings.",
          "polylang-ai-automatic-translation",
        )}
      </Notice>
    );
  }

  /**
   * Render importing state (blocks all UI).
   */
  if (systemStatus === "importing") {
    return <ImportingMessage />;
  }

  return (
    <div className="pllat-single-translator">
      {/* License warning */}
      {!window.pllat?.singleTranslatorEnabled && (
        <div style={{ marginBottom: "15px" }}>
          <Notice status="warning" isDismissible={false}>
            {__('A valid Pro license is required to use the single translator', 'polylang-ai-automatic-translation')}.{' '}
            <a href={window.pllat?.adminUrl + 'admin.php?page=pllat-settings&tab=license'} style={{ textDecoration: 'underline' }}>
              {__('Activate your license', 'polylang-ai-automatic-translation')}
            </a>
          </Notice>
        </div>
      )}

      {/* Action error banner */}
      {actionError && (
        <div style={{ marginBottom: "15px" }}>
          <Notice status="error" isDismissible={true} onRemove={clearError}>
            {actionError}
          </Notice>
        </div>
      )}

      {/* Recovery banners */}
      {initialState.hasRecentErrors && (
        <div style={{ marginBottom: "15px" }}>
          <ErrorBanner languages={languages} onDismiss={refresh} />
        </div>
      )}
      {/* Only show recovery success notice if NOT currently completing and NOT dismissed */}
      {initialState.hasRecentSuccess && !successDismissed && uiState !== 'completed' && (
        <div style={{ marginBottom: "15px" }}>
          <Notice status="success" isDismissible={true} onRemove={handleSuccessDismiss}>
            {__(
              "Recent translations completed successfully!",
              "polylang-ai-automatic-translation",
            )}
          </Notice>
        </div>
      )}

      {/* Exclusion toggle */}
      <ExclusionToggle
        excluded={isExcluded}
        onChange={handleExclusionToggle}
        loading={actionLoading}
      />

      {/* Show translation UI only when not excluded */}
      {!isExcluded && (
        <>
          {/* Starting translation notice - shows during submit and initial polling */}
          {(submitting || (polling && uiState === 'translating')) && (
            <div style={{ marginBottom: "15px" }}>
              <Notice status="info" isDismissible={false}>
                {__("Starting translation...", "polylang-ai-automatic-translation")}
              </Notice>
            </div>
          )}

          {/* Scheduler health warning - shows when actions are stuck during polling */}
          {polling && status?.scheduler_health?.has_stuck_actions && (
            <div style={{ marginBottom: "15px" }}>
              <Notice status="warning" isDismissible={false}>
                {__("Translation tasks may be delayed. Background processing isn't running properly.", "polylang-ai-automatic-translation")}{' '}
                <a href={window.pllat?.adminUrl + 'admin.php?page=pllat-settings'} style={{ textDecoration: 'underline' }}>
                  {__('Check AI Settings', 'polylang-ai-automatic-translation')}
                </a>
              </Notice>
            </div>
          )}

          {/* Success notice - after translation completes */}
          {uiState === 'completed' && (
            <div style={{ marginBottom: '15px' }}>
              <Notice
                status="success"
                isDismissible={true}
                onRemove={() => setUiState('idle')}
              >
                {__('Translations completed successfully!', 'polylang-ai-automatic-translation')}
              </Notice>
            </div>
          )}

          {/* Queued notice - run accepted but the background worker hasn't
              claimed it yet (slow WP-Cron / Action Scheduler). Honest
              alternative to a premature "completed" toast. See #392. */}
          {uiState === 'queued' && (
            <div style={{ marginBottom: '15px' }}>
              <Notice
                status="info"
                isDismissible={true}
                onRemove={() => setUiState('idle')}
              >
                {__('Translation queued. It will run in the background shortly — reopen this panel or reload the page to follow its progress.', 'polylang-ai-automatic-translation')}
              </Notice>
            </div>
          )}

          {/* Failed notice - after translation fails */}
          {uiState === 'failed' && (
            <div style={{ marginBottom: '15px' }}>
              <Notice
                status="error"
                isDismissible={true}
                onRemove={() => setUiState('idle')}
              >
                {__('Some translations failed. Check language details below.', 'polylang-ai-automatic-translation')}
              </Notice>
            </div>
          )}

          {/* Nothing-to-translate notice — server returned 409 because
              every selected language was already up to date without force.
              WP Notice with status="info" plus a class hook for the
              light-blue background (see admin-input.css). */}
          {uiState === 'no_work' && (
            <div ref={noWorkRef} style={{ marginBottom: '15px' }}>
              <Notice
                status="info"
                isDismissible={true}
                onRemove={() => setUiState('idle')}
                className="pllat-no-work-notice"
              >
                {noWorkMessage || __('All selected languages are already translated. Enable force re-translation to overwrite.', 'polylang-ai-automatic-translation')}
              </Notice>
            </div>
          )}

          {/* Error summary banners for each language */}
          {languages
            .filter((lang) => lang.error_summary)
            .map((lang) => (
              <div key={lang.language} style={{ marginBottom: "15px" }}>
                <ErrorSummaryBanner
                  language={lang.language}
                  languageName={lang.language_name}
                  errorSummary={lang.error_summary}
                  jobId={lang.job_id}
                  onDismiss={refresh}
                />
              </div>
            ))}

          {/* Translation form */}
          <div className="pllat-translation-form" style={{ marginTop: "20px" }}>
            <LanguageSelector
              languages={languages}
              selected={selectedLanguages}
              onChange={setSelectedLanguages}
              disabled={actionLoading || polling}
            />

            <InstructionsInput
              value={instructions}
              onChange={setInstructions}
              disabled={actionLoading || polling}
            />

            {canForceRetranslate && (
              <>
                <ForceToggle
                  value={force}
                  onChange={(checked) => {
                    setForce(checked);
                    if (!checked) {
                      setSelectedFields([]);
                    }
                  }}
                  disabled={actionLoading || polling}
                />

                {force && (
                  <FieldSelector
                    contentType={{ type: window.pllatSingleTranslator?.type || 'post', entity: window.pllatSingleTranslator?.entity || 'post' }}
                    contentId={window.pllatSingleTranslator?.id}
                    selectedFields={selectedFields}
                    onChange={setSelectedFields}
                  />
                )}
              </>
            )}

            <div style={{ marginTop: "12px" }}>
              <ActionButtons
                onTranslate={handleTranslate}
                onCancel={handleCancel}
                disabled={
                  selectedLanguages.length === 0 || actionLoading || polling || !window.pllat?.singleTranslatorEnabled
                }
                canCancel={polling || hasActive}
                loading={actionLoading}
                isProcessing={polling}
              />
            </div>
          </div>

          {/* Internal Link Translation */}
          <div style={{ marginTop: '20px', paddingTop: '15px', borderTop: '1px solid #ddd' }}>
            <p className="description" style={{ margin: '0 0 8px' }}>
              {__('Scan this translation for internal links pointing to the source language and replace them with their translated equivalents.', 'polylang-ai-automatic-translation')}
            </p>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <Button
                variant="secondary"
                onClick={handleLinkReplacement}
                disabled={linkLoading}
                isBusy={linkLoading}
              >
                <span className="dashicons dashicons-admin-links" style={{ marginRight: '4px', verticalAlign: 'middle' }}></span>
                {__('Translate Internal Links', 'polylang-ai-automatic-translation')}
              </Button>
              {linkResult && !linkResult.error && (
                <span style={{ color: '#00a32a', fontSize: '13px' }}>
                  {linkResult.replaced > 0
                    ? `${linkResult.replaced} ${__('link(s) translated', 'polylang-ai-automatic-translation')}`
                    : __('All links are up to date', 'polylang-ai-automatic-translation')}
                  {linkResult.unresolved > 0 && `, ${linkResult.unresolved} ${__('unresolved', 'polylang-ai-automatic-translation')}`}
                </span>
              )}
              {linkResult?.error && (
                <span style={{ color: '#d63638', fontSize: '13px' }}>{linkResult.error}</span>
              )}
            </div>
          </div>
        </>
      )}

      {preflightFailure && (
        <PreflightFailedDialog
          preflight={preflightFailure}
          scope="single"
          context={
            window.pllatSingleTranslator?.type === "term"
              ? { term_id: window.pllatSingleTranslator?.id }
              : { post_id: window.pllatSingleTranslator?.id }
          }
          onClose={handlePreflightClose}
          onContinueAnyway={handleContinueAnyway}
        />
      )}
    </div>
  );
}

export default SingleTranslator;
