import { useState, useEffect, memo } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { CheckboxControl, TextareaControl, Tooltip } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";
import FieldSelector from "../../components/FieldSelector";

/**
 * Bulk translation configuration modal.
 * Unified header design: "Translate [all posts] from [🇬🇧 English] to [🇩🇪🇪🇸🇳🇱 +19]"
 */
export const BulkConfigModal = ({
  isOpen,
  onClose,
  contentType,
  targetLanguages = [],
  onSubmit,
  showLimitOption = true,
  showFieldSelector = true,
}) => {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [instructions, setInstructions] = useState("");
  const [hasLimit, setHasLimit] = useState(true);
  const [limit, setLimit] = useState(3);
  const [forceRetranslate, setForceRetranslate] = useState(false);
  const [selectedFields, setSelectedFields] = useState([]);
  const [selectedLanguages, setSelectedLanguages] = useState([]);
  const [showAdvanced, setShowAdvanced] = useState(false);

  // Load saved config when modal opens
  useEffect(() => {
    if (!isOpen) return;

    const loadConfig = async () => {
      try {
        setLoading(true);
        setError(null);

        // Use custom config path or fall back to dashboard config endpoint.
        const configPath = contentType.configPath
          || `/pllat/v1/bulk/config/${contentType.type}/${contentType.entity}`;

        const response = await apiFetch({
          path: configPath,
        });

        setInstructions(response.instructions || "");

        const hasLimitConfigured = response.limit !== null && response.limit !== undefined;
        if (!hasLimitConfigured) {
          setHasLimit(false);
          setLimit(3);
        } else {
          setHasLimit(true);
          setLimit(response.limit);
        }

        // Reset language selection on open (default: all languages selected).
        setSelectedLanguages(targetLanguages.map(lang => lang.slug));

        // Auto-expand Advanced when any setting it contains is non-default,
        // so users don't miss persisted config (e.g. a saved item limit).
        const hasInstructions = response.instructions && response.instructions.trim() !== "";
        setShowAdvanced(hasLimitConfigured || hasInstructions);
      } catch (err) {
        // Fall back to defaults on config load error (e.g., for string groups).
        console.warn("Config load failed, using defaults:", err);
        setInstructions("");
        setHasLimit(false);
        setLimit(3);
        setSelectedLanguages(targetLanguages.map(lang => lang.slug));
        setShowAdvanced(false);
      } finally {
        setLoading(false);
      }
    };

    loadConfig();
  }, [isOpen, contentType.entity, contentType.type, contentType.configPath, targetLanguages]);

  const handleSubmit = (e) => {
    e.preventDefault();

    // Validate limit if enabled and visible.
    if (showLimitOption && hasLimit && (limit < 1 || !Number.isInteger(Number(limit)))) {
      setError(__("Limit must be a positive integer", "polylang-ai-automatic-translation"));
      return;
    }

    // Validate at least one language selected.
    if (selectedLanguages.length === 0) {
      setError(__("Please select at least one target language", "polylang-ai-automatic-translation"));
      return;
    }

    // Only send languages if not all selected (for backward compat).
    const allSelected = selectedLanguages.length === targetLanguages.length;

    onSubmit({
      instructions,
      limit: showLimitOption && hasLimit ? Number(limit) : null,
      forced: forceRetranslate,
      languages: allSelected ? null : selectedLanguages,
      selected_fields: forceRetranslate ? selectedFields : [],
    });
  };

  const handleLanguageToggle = (slug, checked) => {
    setSelectedLanguages(prev =>
      checked
        ? [...prev, slug]
        : prev.filter(s => s !== slug)
    );
  };

  const allLanguagesSelected = selectedLanguages.length === targetLanguages.length;

  const getPreviewText = () => {
    const langCount = selectedLanguages.length;
    const langText = langCount === targetLanguages.length
      ? __("all languages", "polylang-ai-automatic-translation")
      : `${langCount} ${langCount === 1 ? __("language", "polylang-ai-automatic-translation") : __("languages", "polylang-ai-automatic-translation")}`;

    const actionText = __("translate", "polylang-ai-automatic-translation");

    // Use lowercase for inline labels
    const labelLower = contentType.label?.toLowerCase() || contentType.entity;
    const singularLabelLower = contentType.singularLabel?.toLowerCase() || contentType.entity;

    if (!showLimitOption || !hasLimit) {
      return `You are about to ${actionText} all ${labelLower} to ${langText}`;
    }
    const labelText = Number(limit) === 1 ? singularLabelLower : labelLower;
    return `You are about to ${actionText} max ${limit} ${labelText} to ${langText}`;
  };

  if (!isOpen) return null;

  return (
    <div
      className="pllat-bulk-config-modal pllat-fixed pllat-top-0 pllat-bottom-0 pllat-right-0 pllat-flex pllat-items-center pllat-justify-center"
      style={{ zIndex: 100000 }}
    >
      {/* Backdrop */}
      <div
        className="pllat-absolute pllat-inset-0 pllat-bg-black/50 pllat-backdrop-blur-sm"
        onClick={onClose}
      />

      {/* Modal Container */}
      <div
        className="pllat-relative pllat-bg-white pllat-rounded-lg pllat-shadow-2xl pllat-w-full pllat-mx-4"
        style={{
          maxWidth: "600px",
          maxHeight: "calc(100vh - 80px)",
          display: "flex",
          flexDirection: "column",
          padding: "2rem",
          border: "1px solid #dcdcde"
        }}
      >
        {loading ? (
          <div style={{ padding: "20px", textAlign: "center" }}>
            <span
              className="spinner is-active"
              style={{
                float: "none",
                width: "30px",
                height: "30px",
                margin: "0 auto 16px",
              }}
            />
            <p style={{ margin: 0, color: "#50575e" }}>
              {__("Loading configuration...", "polylang-ai-automatic-translation")}
            </p>
          </div>
        ) : (
          <>
            {/* Header */}
            <div className="pllat-flex pllat-justify-between pllat-items-start" style={{ marginBottom: "24px" }}>
              <h2
                style={{
                  fontSize: "20px",
                  fontWeight: "600",
                  color: "#1d2327",
                  margin: 0,
                }}
              >
                {__("Bulk translate", "polylang-ai-automatic-translation")}{" "}
                <span
                  style={{
                    display: "inline-block",
                    padding: "2px 10px",
                    backgroundColor: "#f0f0f1",
                    borderRadius: "4px",
                    fontSize: "16px",
                    fontWeight: "500",
                  }}
                >
                  {contentType.label}
                </span>
              </h2>
              <button
                type="button"
                onClick={onClose}
                style={{
                  background: "none",
                  border: "none",
                  cursor: "pointer",
                  padding: "4px",
                  color: "#646970",
                }}
                aria-label={__("Close", "polylang-ai-automatic-translation")}
              >
                <span className="dashicons dashicons-no-alt" style={{ fontSize: "24px", width: "24px", height: "24px" }} />
              </button>
            </div>

            <form onSubmit={handleSubmit} style={{ overflowY: "auto", minHeight: 0 }}>
              {error && (
                <div
                  style={{
                    backgroundColor: "#fcf0f1",
                    border: "1px solid #f0b8b8",
                    borderRadius: "4px",
                    padding: "12px 16px",
                    marginBottom: "20px",
                    color: "#cc1818",
                    fontSize: "13px",
                  }}
                >
                  {error}
                </div>
              )}

              {/* Language Selection */}
              <div style={{ marginBottom: "20px" }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "10px" }}>
                  <span style={{ fontSize: "13px", color: "#1d2327", fontWeight: "500" }}>
                    {__("Translate to", "polylang-ai-automatic-translation")}
                  </span>
                  <button
                    type="button"
                    onClick={() => setSelectedLanguages(
                      allLanguagesSelected ? [] : targetLanguages.map(l => l.slug)
                    )}
                    style={{
                      background: "none",
                      border: "none",
                      color: "#2271b1",
                      cursor: "pointer",
                      padding: 0,
                      fontSize: "12px",
                    }}
                  >
                    {allLanguagesSelected
                      ? __("Deselect all", "polylang-ai-automatic-translation")
                      : __("Select all", "polylang-ai-automatic-translation")}
                  </button>
                </div>
                <div
                  style={{
                    display: "flex",
                    flexWrap: "wrap",
                    gap: "6px",
                    maxHeight: "180px",
                    overflowY: "auto",
                  }}
                >
                  {targetLanguages.map((lang) => {
                    const isSelected = selectedLanguages.includes(lang.slug);
                    return (
                      <button
                        key={lang.slug}
                        type="button"
                        onClick={() => handleLanguageToggle(lang.slug, !isSelected)}
                        style={{
                          display: "inline-flex",
                          alignItems: "center",
                          gap: "5px",
                          padding: "4px 10px",
                          borderRadius: "12px",
                          border: "none",
                          backgroundColor: isSelected ? "#e8f4fc" : "#f0f0f1",
                          color: isSelected ? "#0a4b78" : "#50575e",
                          cursor: "pointer",
                          fontSize: "12px",
                          transition: "all 0.15s ease",
                        }}
                      >
                        {lang.flag && (
                          <img src={lang.flag} alt="" style={{ width: "14px", height: "auto" }} />
                        )}
                        <span>{lang.name}</span>
                        {isSelected && (
                          <span
                            className="dashicons dashicons-yes-alt"
                            style={{ fontSize: "14px", width: "14px", height: "14px" }}
                          />
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Advanced Options (collapsible) */}
              <div style={{ marginBottom: "20px" }}>
                <button
                  type="button"
                  onClick={() => setShowAdvanced(!showAdvanced)}
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: "4px",
                    background: "none",
                    border: "none",
                    padding: 0,
                    cursor: "pointer",
                    fontSize: "13px",
                    color: "#646970",
                  }}
                >
                  <span className={`dashicons dashicons-arrow-${showAdvanced ? "down" : "right"}-alt2`} style={{ fontSize: "16px", width: "16px", height: "16px" }} />
                  {__("Advanced Options", "polylang-ai-automatic-translation")}
                </button>

                {showAdvanced && (
                  <div style={{ marginTop: "12px", paddingLeft: "20px" }}>
                    {/* Limit translations */}
                    {showLimitOption && (
                    <div style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "12px" }}>
                      <CheckboxControl
                        label={__("Limit number of translations", "polylang-ai-automatic-translation")}
                        checked={hasLimit}
                        onChange={setHasLimit}
                        __nextHasNoMarginBottom
                      />
                      {hasLimit && (
                        <input
                          type="number"
                          value={limit}
                          onChange={(e) => setLimit(e.target.value)}
                          min={1}
                          step={1}
                          style={{
                            width: "60px",
                            padding: "2px 6px",
                            border: "1px solid #8c8f94",
                            borderRadius: "4px",
                            fontSize: "13px",
                          }}
                        />
                      )}
                      <Tooltip text={__("Limit the number of items to translate in this batch", "polylang-ai-automatic-translation")}>
                        <span className="dashicons dashicons-info" style={{ color: "#646970", cursor: "help", fontSize: "16px" }} />
                      </Tooltip>
                    </div>
                    )}

                    {/* Force Re-translation */}
                    <div style={{ marginBottom: "16px" }}>
                      <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
                        <CheckboxControl
                          label={__("Force re-translation", "polylang-ai-automatic-translation")}
                          checked={forceRetranslate}
                          onChange={(checked) => {
                            setForceRetranslate(checked);
                            if (!checked) {
                              setSelectedFields([]);
                            }
                          }}
                          __nextHasNoMarginBottom
                        />
                        <Tooltip text={__("Re-translate even if a translation already exists", "polylang-ai-automatic-translation")}>
                          <span className="dashicons dashicons-info" style={{ color: "#646970", cursor: "help", fontSize: "16px" }} />
                        </Tooltip>
                      </div>

                      {showFieldSelector && forceRetranslate && (
                        <FieldSelector
                          contentType={contentType}
                          selectedFields={selectedFields}
                          onChange={setSelectedFields}
                        />
                      )}
                    </div>

                    {/* Custom Instructions */}
                    <div style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "8px" }}>
                      <span style={{ fontSize: "13px", color: "#1d2327" }}>
                        {__("Custom AI Instructions", "polylang-ai-automatic-translation")}
                      </span>
                      <Tooltip text={__("Provide custom instructions for the AI translator", "polylang-ai-automatic-translation")}>
                        <span className="dashicons dashicons-info" style={{ color: "#646970", cursor: "help", fontSize: "16px" }} />
                      </Tooltip>
                    </div>
                    <TextareaControl
                      value={instructions}
                      onChange={setInstructions}
                      placeholder={__("e.g., Use formal tone, preserve technical terms...", "polylang-ai-automatic-translation")}
                      rows={3}
                      __nextHasNoMarginBottom
                    />
                  </div>
                )}
              </div>

              {/* Footer: Preview text left, buttons right */}
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "center",
                  gap: "16px",
                  paddingTop: "16px",
                  borderTop: "1px solid #dcdcde",
                }}
              >
                <span style={{ fontSize: "12px", color: "#646970" }}>
                  {getPreviewText()}
                </span>
                <div style={{ display: "flex", gap: "12px", flexShrink: 0 }}>
                  <button
                    type="button"
                    className="button button-secondary"
                    onClick={onClose}
                  >
                    {__("Cancel", "polylang-ai-automatic-translation")}
                  </button>
                  <button
                    type="submit"
                    className="button button-primary"
                  >
                    {__("Start Translation", "polylang-ai-automatic-translation")}
                  </button>
                </div>
              </div>
            </form>
          </>
        )}
      </div>
    </div>
  );
};

export default memo(BulkConfigModal);
