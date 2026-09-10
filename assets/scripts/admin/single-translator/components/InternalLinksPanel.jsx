/**
 * Internal link translation panel (Pro).
 *
 * SingleTranslator renders it behind the `__PLLAT_EDITION__` gate, so this
 * component and the internal-links REST call never reach the free bundle.
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { replaceInternalLinks } from '../utils/api';

/**
 * Internal links panel component.
 *
 * @returns {JSX.Element} The component
 */
function InternalLinksPanel() {
  const [linkResult, setLinkResult] = useState(null);
  const [linkLoading, setLinkLoading] = useState(false);

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

  return (
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
  );
}

export default InternalLinksPanel;
