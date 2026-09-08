import { __, _n, sprintf } from "@wordpress/i18n";
import { createPortal, useEffect, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { dismiss as dismissCheck, isDismissed } from "../utils/preflightDismissed";

/**
 * Map preflight check `name` slugs to humanised titles.
 * Falls back to a humanised slug when an unknown check appears.
 */
const CHECK_TITLES = {
  action_scheduler_heartbeat: __("Background jobs", "polylang-ai-automatic-translation"),
  loopback_reachable: __("Server self-reachability", "polylang-ai-automatic-translation"),
  wp_cron: __("WordPress cron", "polylang-ai-automatic-translation"),
  php_environment: __("PHP environment", "polylang-ai-automatic-translation"),
  polylang_active: __("Polylang plugin", "polylang-ai-automatic-translation"),
  polylang_languages: __("Polylang languages", "polylang-ai-automatic-translation"),
  polylang_source_language: __("Polylang source language", "polylang-ai-automatic-translation"),
  provider_key: __("AI provider configuration", "polylang-ai-automatic-translation"),
  provider_connectivity: __("AI provider connection", "polylang-ai-automatic-translation"),
  translation_index_primed: __("Translation index", "polylang-ai-automatic-translation"),
};

const titleFor = (name) =>
  CHECK_TITLES[name] ||
  name.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

const ArrowRight = () => (
  <svg
    aria-hidden="true"
    width="14"
    height="14"
    viewBox="0 0 14 14"
    fill="none"
    className="pllat-inline-block pllat-flex-shrink-0 pllat-ml-1"
  >
    <path
      d="M3 7h8m0 0L7.5 3.5M11 7l-3.5 3.5"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </svg>
);

const ExternalIcon = () => (
  <svg
    aria-hidden="true"
    width="12"
    height="12"
    viewBox="0 0 12 12"
    fill="none"
    className="pllat-inline-block pllat-flex-shrink-0 pllat-ml-1"
  >
    <path
      d="M4.5 2.5h-2v7h7v-2M7 2.5h2.5V5M5 7l4.5-4.5"
      stroke="currentColor"
      strokeWidth="1.2"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </svg>
);

const Spinner = () => (
  <span
    aria-hidden="true"
    className="pllat-inline-block pllat-w-3.5 pllat-h-3.5 pllat-mr-2 pllat-border-2 pllat-border-current pllat-border-r-transparent pllat-rounded-full pllat-animate-spin pllat-align-[-2px]"
  />
);

const LEVEL_TONES = {
  fail: {
    stripe: "pllat-bg-red-500",
    surface: "pllat-bg-red-50/60",
    label: "pllat-text-red-700",
    labelText: __("Required", "polylang-ai-automatic-translation"),
  },
  warn: {
    stripe: "pllat-bg-amber-400",
    surface: "pllat-bg-amber-50/60",
    label: "pllat-text-amber-700",
    labelText: __("Recommended", "polylang-ai-automatic-translation"),
  },
};

const CheckCard = ({ check, index, dismissedNames, onToggleDismiss }) => {
  const tone = LEVEL_TONES[check.level] || LEVEL_TONES.fail;
  const dismissed = dismissedNames.includes(check.name);
  // Only warn-level checks may be dismissed. Fail-level remains visible
  // until the user resolves it or explicitly clicks Continue anyway.
  const canDismiss = check.level === "warn";

  return (
    <article
      className={`pllat-relative pllat-rounded pllat-overflow-hidden pllat-border pllat-border-gray-200 ${tone.surface} pllat-pl-5 pllat-pr-5 pllat-py-4 pllat-animate-pllat-rise`}
      style={{ animationDelay: `${80 + index * 60}ms` }}
    >
      <span
        aria-hidden="true"
        className={`pllat-absolute pllat-left-0 pllat-top-0 pllat-bottom-0 pllat-w-[3px] ${tone.stripe}`}
      />
      <div
        className={`pllat-text-[10px] pllat-font-semibold pllat-uppercase pllat-tracking-[0.12em] ${tone.label} pllat-mb-1`}
      >
        {tone.labelText}
      </div>
      <h3 className="pllat-text-[15px] pllat-font-semibold pllat-text-gray-900 pllat-m-0 pllat-leading-snug">
        {titleFor(check.name)}
      </h3>
      <p className="pllat-text-[14px] pllat-leading-relaxed pllat-text-gray-700 pllat-mt-2 pllat-mb-0">
        {check.message}
      </p>

      {check.actionable && (
        <div className="pllat-mt-3 pllat-pt-3 pllat-border-t pllat-border-gray-200">
          <div className="pllat-text-[11px] pllat-font-semibold pllat-uppercase pllat-tracking-[0.1em] pllat-text-gray-500 pllat-mb-1">
            {__("How to fix", "polylang-ai-automatic-translation")}
          </div>
          <p className="pllat-text-[14px] pllat-leading-relaxed pllat-text-gray-800 pllat-m-0">
            {check.actionable}
          </p>

          {(check.admin_link || check.help_url) && (
            <div className="pllat-flex pllat-flex-wrap pllat-items-center pllat-gap-3 pllat-mt-3">
              {check.admin_link && (
                <a
                  href={check.admin_link}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="button button-primary"
                >
                  {check.admin_link_label ||
                    __("Open settings", "polylang-ai-automatic-translation")}
                  <ArrowRight />
                </a>
              )}
              {check.help_url && (
                <a
                  href={check.help_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="pllat-inline-flex pllat-items-center pllat-text-[13px] pllat-font-medium"
                  style={{ color: "#2271b1" }}
                >
                  {__("Read the guide", "polylang-ai-automatic-translation")}
                  <ExternalIcon />
                </a>
              )}
            </div>
          )}
        </div>
      )}

      {canDismiss && (
        <label
          className="pllat-flex pllat-items-center pllat-gap-2 pllat-mt-3 pllat-pt-3 pllat-text-[12px] pllat-text-gray-600 pllat-cursor-pointer pllat-select-none"
          style={{ borderTop: "1px dashed #e5e7eb" }}
        >
          <input
            type="checkbox"
            checked={dismissed}
            onChange={() => onToggleDismiss(check.name)}
            className="pllat-m-0"
          />
          {__(
            "Don't show this recommendation again in this browser",
            "polylang-ai-automatic-translation",
          )}
        </label>
      )}
    </article>
  );
};

/**
 * Modal shown when create_run rejects with pllat_preflight_failed.
 *
 * Visually aligned with the rest of the dashboard: inherits WordPress
 * admin's font stack (set by WP), uses native `button button-primary`
 * for primary actions (same blue as Configure & Translate / Start
 * Translation), and matches BulkConfigModal's white frame + 2rem
 * padding + 1px gray border. The added value over a plain alert lives
 * in the structure: per-check status stripe, "How to fix" block with a
 * one-click admin deep link and a "Read the guide" link.
 */
const PreflightFailedDialog = ({
  preflight,
  onClose,
  onContinueAnyway,
  scope = "single",
  context = {},
}) => {
  // Local mirror of the preflight payload so Re-test can refresh the
  // displayed checks without unmounting/remounting the dialog. The prop
  // is the seed; subsequent re-tests overwrite this state.
  const [currentPreflight, setCurrentPreflight] = useState(preflight);
  const [isRetesting, setIsRetesting] = useState(false);
  const [retestNotice, setRetestNotice] = useState(null); // 'still-failing' | 'no-change' | null
  const [isRepriming, setIsRepriming] = useState(false);
  const [reprimeProgress, setReprimeProgress] = useState(null);
  // Dismissed names for the warn-checks shown right now. Persisted to
  // localStorage on commit (Continue anyway / Close), not on each
  // checkbox toggle — that way the user can uncheck mid-flight.
  const [pendingDismiss, setPendingDismiss] = useState(() => {
    if (!preflight || !Array.isArray(preflight.checks)) return [];
    return preflight.checks
      .filter((c) => c.level === "warn" && isDismissed(c.name))
      .map((c) => c.name);
  });

  // If the parent passes a fresh preflight payload (new run attempt), reset.
  useEffect(() => {
    setCurrentPreflight(preflight);
    setRetestNotice(null);
    if (preflight && Array.isArray(preflight.checks)) {
      setPendingDismiss(
        preflight.checks
          .filter((c) => c.level === "warn" && isDismissed(c.name))
          .map((c) => c.name),
      );
    }
  }, [preflight]);

  // Close on Escape — standard modal expectation.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === "Escape" && !isRetesting && !isRepriming) onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [isRetesting, isRepriming, onClose]);

  if (!currentPreflight || !Array.isArray(currentPreflight.checks)) {
    return null;
  }

  const sortedChecks = currentPreflight.checks
    .filter((c) => c.level === "fail" || c.level === "warn")
    .slice()
    .sort((a, b) => {
      if (a.level === b.level) return 0;
      return a.level === "fail" ? -1 : 1;
    });

  const indexCheck = sortedChecks.find(
    (c) => c.name === "translation_index_primed" && c.level === "fail"
  );
  const isIndexNotReady = !!indexCheck;

  const failCount = sortedChecks.filter((c) => c.level === "fail").length;
  const warnCount = sortedChecks.filter((c) => c.level === "warn").length;

  const toggleDismiss = (name) => {
    setPendingDismiss((prev) =>
      prev.includes(name) ? prev.filter((n) => n !== name) : [...prev, name],
    );
  };

  const persistDismissals = () => {
    pendingDismiss.forEach((name) => dismissCheck(name));
  };

  const handleContinueAnyway = () => {
    persistDismissals();
    if (onContinueAnyway) {
      onContinueAnyway({ acknowledgedFails: failCount > 0 });
    }
  };

  const handleCloseClick = () => {
    persistDismissals();
    onClose();
  };

  const handleRetest = async () => {
    setIsRetesting(true);
    setRetestNotice(null);
    try {
      const result = await apiFetch({
        path: "/pllat/v1/preflight/check?fresh=true",
        method: "POST",
        data: { scope, context },
      });

      if (result && result.overall_level === "pass") {
        // No issues — close so the run can proceed.
        onClose();
        return;
      }

      // Still failing. Refresh the displayed checks and tell the user
      // what we just did so they don't think the button is broken.
      setCurrentPreflight(result || currentPreflight);
      setRetestNotice("still-failing");
    } catch (e) {
      setRetestNotice("error");
    } finally {
      setIsRetesting(false);
    }
  };

  const handleRerunPrime = async () => {
    setIsRepriming(true);
    setReprimeProgress(0);
    try {
      await apiFetch({
        path: "/pllat/v1/admin/prime/run",
        method: "POST",
      });

      // Poll prime/status every 2s until primed=true.
      const POLL_MS = 2000;
      const MAX_POLL_ATTEMPTS = 150; // 5 minutes at 2s — matches Prime_Status_Service::STUCK_THRESHOLD_SECONDS

      let primed = false;
      let attempts = 0;
      while (!primed) {
        attempts += 1;
        await new Promise((resolve) => setTimeout(resolve, POLL_MS));
        const status = await apiFetch({ path: "/pllat/v1/admin/prime/status" });
        setReprimeProgress(status?.progress ?? 0);
        primed = !!status?.primed;
        if (!primed && attempts >= MAX_POLL_ATTEMPTS) {
          setRetestNotice("prime-failed");
          return;
        }
      }

      // Auto-retest the preflight; handleRetest closes the dialog on pass.
      await handleRetest();
    } catch (err) {
      setRetestNotice("prime-failed");
    } finally {
      setIsRepriming(false);
      setReprimeProgress(null);
    }
  };

  let noticeText = null;
  if (retestNotice === "still-failing") {
    if (failCount > 0) {
      noticeText = __(
        "Re-tested — the issues are still present.",
        "polylang-ai-automatic-translation",
      );
    } else {
      noticeText = __(
        "Re-tested — the recommendations are still present.",
        "polylang-ai-automatic-translation",
      );
    }
  } else if (retestNotice === "prime-failed") {
    noticeText = __(
      "Re-running prime did not complete. Please try again or check Tools → Scheduled Actions.",
      "polylang-ai-automatic-translation",
    );
  } else if (retestNotice === "error") {
    noticeText = __(
      "Re-test failed to run. Please try again.",
      "polylang-ai-automatic-translation",
    );
  }

  const summary =
    failCount > 0
      ? sprintf(
          /* translators: %1$d: blocking issue count, %2$s: warnings phrase or empty */
          _n(
            "%1$d blocking issue is preventing translations from starting%2$s. Each item below has a one-click fix.",
            "%1$d blocking issues are preventing translations from starting%2$s. Each item below has a one-click fix.",
            failCount,
            "polylang-ai-automatic-translation",
          ),
          failCount,
          warnCount > 0
            ? sprintf(
                /* translators: %d: warning count */
                _n(
                  ", plus %d recommendation",
                  ", plus %d recommendations",
                  warnCount,
                  "polylang-ai-automatic-translation",
                ),
                warnCount,
              )
            : "",
        )
      : __(
          "Recommendations to make translations more reliable.",
          "polylang-ai-automatic-translation",
        );

  // Render via portal so the fixed-positioned overlay escapes any
  // transformed ancestor (e.g. Gutenberg's editor pane), which would
  // otherwise clip the modal to a sub-region instead of the viewport.
  return createPortal(
    <div
      className="pllat-fixed pllat-top-0 pllat-bottom-0 pllat-right-0 pllat-left-0 pllat-flex pllat-items-center pllat-justify-center pllat-animate-pllat-fade"
      style={{ zIndex: 100000 }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="pllat-preflight-title"
    >
      <div
        className="pllat-absolute pllat-inset-0 pllat-bg-black/50 pllat-backdrop-blur-sm"
        onClick={() => {
          if (!isRetesting && !isRepriming) onClose();
        }}
      />

      <div
        className="pllat-relative pllat-bg-white pllat-rounded-lg pllat-shadow-2xl pllat-w-full pllat-mx-4 pllat-flex pllat-flex-col pllat-animate-pllat-pop"
        style={{
          maxWidth: "640px",
          maxHeight: "calc(100vh - 80px)",
          border: "1px solid #dcdcde",
        }}
      >
        <header
          className="pllat-flex pllat-items-start pllat-justify-between pllat-gap-4"
          style={{ padding: "1.75rem 2rem 1.25rem" }}
        >
          <div className="pllat-flex-1 pllat-min-w-0">
            <h2
              id="pllat-preflight-title"
              className="pllat-text-[20px] pllat-font-semibold pllat-text-gray-900 pllat-m-0 pllat-leading-tight"
            >
              {__(
                "Let's get a few things sorted first",
                "polylang-ai-automatic-translation",
              )}
            </h2>
            <p className="pllat-text-[13px] pllat-leading-relaxed pllat-text-gray-600 pllat-mt-2 pllat-mb-0">
              {summary}
            </p>
          </div>
          <button
            type="button"
            aria-label={__("Close", "polylang-ai-automatic-translation")}
            onClick={onClose}
            disabled={isRetesting || isRepriming}
            className="pllat-flex-shrink-0 pllat-w-7 pllat-h-7 pllat-rounded pllat-border-0 pllat-bg-transparent hover:pllat-bg-gray-100 pllat-flex pllat-items-center pllat-justify-center pllat-text-gray-500 hover:pllat-text-gray-900 pllat-cursor-pointer pllat-transition-colors disabled:pllat-opacity-50"
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 14 14"
              fill="none"
              aria-hidden="true"
            >
              <path
                d="M3 3l8 8M11 3l-8 8"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
              />
            </svg>
          </button>
        </header>

        <div
          className="pllat-flex-1 pllat-overflow-y-auto pllat-space-y-3"
          style={{ padding: "0 2rem 0.5rem" }}
        >
          {sortedChecks.map((check, idx) => (
            <CheckCard
              key={`${check.name}-${idx}`}
              check={check}
              index={idx}
              dismissedNames={pendingDismiss}
              onToggleDismiss={toggleDismiss}
            />
          ))}
        </div>

        <footer
          className="pllat-flex pllat-items-center pllat-justify-between pllat-gap-3"
          style={{
            padding: "1rem 2rem 1.5rem",
            borderTop: "1px solid #dcdcde",
            marginTop: "1rem",
          }}
        >
          <div className="pllat-flex pllat-flex-col pllat-gap-1 pllat-min-w-0">
            <p
              className="pllat-text-[12px] pllat-m-0 pllat-leading-snug"
              style={{
                color: noticeText ? "#b32d2e" : "#646970",
              }}
            >
              {noticeText ||
                (failCount > 0
                  ? __(
                      "Fixed something? Click Re-test to verify before starting again.",
                      "polylang-ai-automatic-translation",
                    )
                  : __(
                      "Reviewed the recommendations? Re-test to refresh, or continue anyway.",
                      "polylang-ai-automatic-translation",
                    ))}
            </p>
            <a
              href="admin.php?page=pllat-settings&tab=support"
              target="_blank"
              rel="noopener noreferrer"
              className="pllat-inline-flex pllat-items-center pllat-text-[12px] pllat-font-medium pllat-no-underline hover:pllat-underline"
              style={{ color: "#2271b1" }}
            >
              {__(
                "Stuck? Grant support access",
                "polylang-ai-automatic-translation",
              )}
              <ArrowRight />
            </a>
          </div>
          <div className="pllat-flex pllat-items-center pllat-gap-2 pllat-flex-shrink-0 pllat-flex-wrap">
            <button
              type="button"
              className="button button-secondary"
              onClick={handleCloseClick}
              disabled={isRetesting || isRepriming}
            >
              {__("Close", "polylang-ai-automatic-translation")}
            </button>
            <button
              type="button"
              className="button"
              onClick={handleRetest}
              disabled={isRetesting || isRepriming}
            >
              {isRetesting && <Spinner />}
              {isRetesting
                ? __("Re-testing…", "polylang-ai-automatic-translation")
                : __("Re-test now", "polylang-ai-automatic-translation")}
            </button>
            {isIndexNotReady && (
              <button
                type="button"
                onClick={handleRerunPrime}
                disabled={isRepriming || isRetesting}
                className="button button-primary"
              >
                {isRepriming && <Spinner />}
                {isRepriming
                  ? reprimeProgress !== null
                    ? sprintf(
                        __("Re-running prime… %d%%", "polylang-ai-automatic-translation"),
                        reprimeProgress,
                      )
                    : __("Re-running prime…", "polylang-ai-automatic-translation")
                  : __("Re-run prime", "polylang-ai-automatic-translation")}
              </button>
            )}
            {onContinueAnyway && !isIndexNotReady && (
              <button
                type="button"
                className={
                  failCount > 0
                    ? "button button-link-delete"
                    : "button button-primary"
                }
                onClick={handleContinueAnyway}
                disabled={isRetesting}
                title={
                  failCount > 0
                    ? __(
                        "The run will start despite the blocking issue. Translations may fail until you fix it.",
                        "polylang-ai-automatic-translation",
                      )
                    : __(
                        "Start the run without addressing the recommendations.",
                        "polylang-ai-automatic-translation",
                      )
                }
              >
                {failCount > 0
                  ? __(
                      "Continue at my own risk",
                      "polylang-ai-automatic-translation",
                    )
                  : __(
                      "Continue anyway",
                      "polylang-ai-automatic-translation",
                    )}
              </button>
            )}
          </div>
        </footer>
      </div>
    </div>,
    document.body,
  );
};

export default PreflightFailedDialog;
