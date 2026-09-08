import React, { useState, useCallback, useEffect } from "react";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";

const COOLDOWN_MS = 5000;

/**
 * Get the status line based on discovery state.
 *
 * @param {Object} discoveryCheck Discovery status object.
 * @return {string} Human-readable status line.
 */
function getStatusLine(discoveryCheck) {
  if (discoveryCheck?.has_posts && discoveryCheck?.has_terms) {
    return __("Analyzing posts and categories…", "polylang-ai-automatic-translation");
  }
  if (discoveryCheck?.has_posts) {
    return __("Analyzing posts…", "polylang-ai-automatic-translation");
  }
  if (discoveryCheck?.has_terms) {
    return __("Analyzing categories…", "polylang-ai-automatic-translation");
  }
  return __("Finishing up…", "polylang-ai-automatic-translation");
}

/**
 * Discovery Overlay Component
 * Displays a centered card overlay when content scanning is needed.
 * Relies on parent dashboard polling for status updates (no separate poller).
 *
 * @param {Object} props
 * @param {Object} props.discoveryCheck - Discovery status from dashboard data.
 * @param {boolean} props.discoveryCheck.needed - Whether scanning is needed.
 * @param {boolean} props.discoveryCheck.has_posts - Whether posts need scanning.
 * @param {boolean} props.discoveryCheck.has_terms - Whether terms need scanning.
 */
export const DiscoveryOverlay = ({ discoveryCheck }) => {
  const [scanState, setScanState] = useState("idle"); // idle | scanning | cooldown
  const [visible, setVisible] = useState(!!discoveryCheck?.needed);
  const [fading, setFading] = useState(false);

  // Fade out when discovery completes.
  useEffect(() => {
    if (!discoveryCheck?.needed && visible) {
      setFading(true);
      const timer = setTimeout(() => setVisible(false), 400);
      return () => clearTimeout(timer);
    }
    if (discoveryCheck?.needed && !visible) {
      setFading(false);
      setVisible(true);
    }
  }, [discoveryCheck?.needed]);

  const handleScanNow = useCallback(async () => {
    if (scanState !== "idle") {
      return;
    }

    setScanState("scanning");

    try {
      await apiFetch({
        path: "/pllat/v1/discovery/trigger",
        method: "POST",
      });
    } catch (error) {
      // Silently handle — polling will pick up the real state.
    }

    setScanState("cooldown");
    setTimeout(() => setScanState("idle"), COOLDOWN_MS);
  }, [scanState]);

  if (!visible) {
    return null;
  }

  const isButtonDisabled = scanState !== "idle";

  return (
    <div
      className="pllat-fixed pllat-top-0 pllat-bottom-0 pllat-right-0 pllat-flex pllat-items-center pllat-justify-center pllat-transition-opacity pllat-duration-300"
      style={{
        zIndex: 100000,
        left: 0,
        opacity: fading ? 0 : 1,
      }}
    >
      {/* Backdrop — dimmed, no blur */}
      <div className="pllat-absolute pllat-inset-0 pllat-bg-black/40" />

      {/* Card */}
      <div
        className="pllat-relative pllat-bg-white pllat-rounded-xl pllat-shadow-2xl pllat-w-full pllat-mx-4"
        style={{ maxWidth: "480px", padding: "2rem 2.5rem" }}
      >
        {/* Progress bar */}
        <div
          className="pllat-rounded-full pllat-overflow-hidden pllat-mb-6"
          style={{ height: "3px", backgroundColor: "#e5e7eb" }}
        >
          <div
            className="pllat-h-full pllat-rounded-full"
            style={{
              width: "40%",
              backgroundColor: "#3b82f6",
              animation: "pllat-progress-pulse 2s ease-in-out infinite",
            }}
          />
        </div>

        {/* Heading */}
        <h2
          className="pllat-text-center"
          style={{
            fontSize: "18px",
            fontWeight: 600,
            color: "#1d2327",
            margin: "0 0 8px 0",
          }}
        >
          {__("Scanning your content", "polylang-ai-automatic-translation")}
        </h2>

        {/* Subtext */}
        <p
          className="pllat-text-center"
          style={{
            fontSize: "13px",
            lineHeight: 1.6,
            color: "#646970",
            margin: "0 0 20px 0",
          }}
        >
          {__(
            "We're checking which posts and pages need translations. This runs automatically in the background.",
            "polylang-ai-automatic-translation",
          )}
        </p>

        {/* Status line */}
        <p
          className="pllat-text-center"
          style={{
            fontSize: "12px",
            color: "#9ca3af",
            margin: "0 0 20px 0",
            minHeight: "18px",
          }}
        >
          {getStatusLine(discoveryCheck)}
        </p>

        {/* Scan now button */}
        <button
          onClick={handleScanNow}
          disabled={isButtonDisabled}
          className="button button-primary"
          style={{
            width: "100%",
            textAlign: "center",
            justifyContent: "center",
            display: "flex",
            alignItems: "center",
            gap: "6px",
            height: "36px",
          }}
        >
          {isButtonDisabled && (
            <span
              className="spinner is-active"
              style={{
                float: "none",
                width: "16px",
                height: "16px",
                minWidth: "16px",
                minHeight: "16px",
                margin: 0,
                backgroundSize: "16px 16px",
              }}
            />
          )}
          {isButtonDisabled
            ? __("Scanning…", "polylang-ai-automatic-translation")
            : __("Scan now", "polylang-ai-automatic-translation")}
        </button>

        {/* Bottom note */}
        <p
          className="pllat-text-center"
          style={{
            fontSize: "11px",
            color: "#9ca3af",
            margin: "16px 0 0 0",
          }}
        >
          {__(
            "You can safely close this page — scanning continues in the background.",
            "polylang-ai-automatic-translation",
          )}
        </p>
      </div>

      {/* Progress bar animation */}
      <style>{`
        @keyframes pllat-progress-pulse {
          0% { transform: translateX(-100%); }
          50% { transform: translateX(150%); }
          100% { transform: translateX(-100%); }
        }
      `}</style>
    </div>
  );
};
