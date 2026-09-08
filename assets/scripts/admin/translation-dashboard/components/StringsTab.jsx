import { useState, useMemo, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Tooltip } from '@wordpress/components';
import useStringsData from '../hooks/useStringsData';
import useStringsActions from '../hooks/useStringsActions';
import BulkConfigModal from './BulkConfigModal';
import InfoBox from './InfoBox';

const cellIndicator = (cell) => {
  // NOT TRANSLATED: no cell, or nothing translated and not outdated.
  if (!cell || (cell.translated === 0 && !cell.outdated)) {
    return (
      <Tooltip text={__('Not translated', 'polylang-ai-automatic-translation')}>
        <span>
          <span
            className="dashicons dashicons-minus"
            style={{ color: '#8c8f94', fontSize: '18px', width: '18px', height: '18px' }}
          />
        </span>
      </Tooltip>
    );
  }

  // OUTDATED wins over a full/partial count.
  if (cell.outdated) {
    return (
      <Tooltip text={__('Outdated — source changed', 'polylang-ai-automatic-translation')}>
        <span>
          <span
            className="dashicons dashicons-update"
            style={{ color: '#996a13', fontSize: '18px', width: '18px', height: '18px' }}
          />
        </span>
      </Tooltip>
    );
  }

  // TRANSLATED: complete.
  if (cell.total > 0 && cell.translated >= cell.total) {
    return (
      <Tooltip text={__('Translated', 'polylang-ai-automatic-translation')}>
        <span>
          <span
            className="dashicons dashicons-yes-alt"
            style={{ color: '#00a32a', fontSize: '18px', width: '18px', height: '18px' }}
          />
        </span>
      </Tooltip>
    );
  }

  // PARTIAL: some translated, not all.
  return (
    <Tooltip
      text={`${cell.translated}/${cell.total} ${__('translated', 'polylang-ai-automatic-translation')}`}
    >
      <span>
        <span
          className="dashicons dashicons-update"
          style={{ color: '#996a13', fontSize: '18px', width: '18px', height: '18px' }}
        />
        <span style={{ fontSize: '11px', color: '#8c8f94', marginLeft: '4px' }}>
          {cell.translated}/{cell.total}
        </span>
      </span>
    </Tooltip>
  );
};

const StringsTab = () => {
  const { data, loading, refresh } = useStringsData();
  const { translate, cancel } = useStringsActions(refresh);
  const [modalGroup, setModalGroup] = useState(null);

  // Stable across poll ticks — only changes when the actual slug set changes.
  const targetLanguages = useMemo(
    () => (data && data.targetLanguages) || [],
    // Stable until the actual language set changes (poll returns a new
    // array each tick but with identical content).
    [JSON.stringify(((data && data.targetLanguages) || []).map((l) => l.slug))],
  );

  // Static shape — no dynamic inputs.
  const stringsContentType = useMemo(
    () => ({
      type: 'strings',
      entity: 'strings',
      label: __('strings', 'polylang-ai-automatic-translation'),
      singularLabel: __('string group', 'polylang-ai-automatic-translation'),
      configPath: '/pllat/v1/strings/config',
    }),
    [],
  );

  // In-flight (group|lang) keys, live from Action Scheduler via status().
  const inFlight = useMemo(
    () => new Set(((data && data.inFlight) || [])),
    [JSON.stringify((data && data.inFlight) || [])],
  );

  // Error map keyed by "group|lang" from progress.errors.
  const errorMap = useMemo(() => {
    const m = new Map();
    const errs = (data && data.progress && data.progress.errors) || [];
    errs.forEach((e) => { if (e && e.unit) m.set(e.unit, e.msg || ''); });
    return m;
  }, [JSON.stringify((data && data.progress && data.progress.errors) || [])]);

  const handleModalClose = useCallback(() => setModalGroup(null), []);

  // BulkConfigModal onSubmit payload: { instructions, limit, forced,
  // languages (null = all selected), selected_fields }.
  const handleModalSubmit = useCallback(
    async (cfg) => {
      await translate({
        groups: modalGroup ? [modalGroup] : [],
        languages: cfg.languages || [],
        force: cfg.forced,
        limit: cfg.limit,
        instructions: cfg.instructions || '',
      });
      setModalGroup(null);
    },
    [translate, modalGroup],
  );

  if (loading && !data) {
    return (
      <div className="pllat-text-center pllat-py-12">
        <span className="spinner is-active" style={{ float: 'none', width: '30px', height: '30px' }} />
        <p className="pllat-text-gray-500 pllat-mt-4">
          {__('Loading strings…', 'polylang-ai-automatic-translation')}
        </p>
      </div>
    );
  }

  const groups = (data && data.groups) || [];
  const langCodes =
    groups.length > 0 ? Object.keys(groups[0].languages || {}) : [];
  const langMeta = {};
  ((data && data.targetLanguages) || []).forEach((l) => { langMeta[l.slug] = l; });

  const ringLoader = window.pllat?.assets?.icons?.ringLoader;

  // In-flight cells show a self-animating ring spinner; errored cells show a
  // warning with the error message; everything else falls back to the static
  // status indicator.
  const renderCell = (g, lc) => {
    if (inFlight.has(`${g.group}|${lc}`)) {
      return (
        <Tooltip text={__('Translating…', 'polylang-ai-automatic-translation')}>
          <span>
            <img
              src={ringLoader}
              width="16"
              height="16"
              alt=""
              style={{ filter: 'invert(44%) sepia(53%) saturate(3206%) hue-rotate(238deg) brightness(101%) contrast(92%)' }}
            />
          </span>
        </Tooltip>
      );
    }
    const errKey = `${g.group}|${lc}`;
    if (errorMap.has(errKey)) {
      return (
        <Tooltip text={errorMap.get(errKey) || __('Translation failed', 'polylang-ai-automatic-translation')}>
          <span>
            <span
              className="dashicons dashicons-warning"
              style={{ color: '#d63638', fontSize: '18px', width: '18px', height: '18px' }}
            />
            <span style={{ fontSize: '11px', color: '#d63638', marginLeft: '4px' }}>
              {__('Failed', 'polylang-ai-automatic-translation')}
            </span>
          </span>
        </Tooltip>
      );
    }
    return cellIndicator(g.languages[lc]);
  };

  return (
    <>
      <InfoBox>
        <>
          <strong>{__('Strings', 'polylang-ai-automatic-translation')}</strong>{' '}
          —{' '}
          {__(
            'Translate Polylang-registered strings (theme and plugin strings) per group.',
            'polylang-ai-automatic-translation',
          )}
        </>
      </InfoBox>

      <div className="pllat-bg-white pllat-rounded-lg pllat-overflow-x-auto" style={{ border: '1px solid #e5e7eb' }}>
        <table className="pllat-w-full pllat-text-sm">
          <thead>
            <tr className="pllat-bg-gray-50 pllat-text-left">
              <th className="pllat-px-4 pllat-py-3 pllat-font-medium">
                {__('Group', 'polylang-ai-automatic-translation')}
              </th>
              <th className="pllat-px-4 pllat-py-3" />
              {langCodes.map((lc) =>
                langMeta[lc] && langMeta[lc].flag ? (
                  <th key={lc} className="pllat-px-4 pllat-py-3 pllat-font-medium">
                    <Tooltip text={langMeta[lc].name || lc}>
                      <span style={{ display: 'inline-flex', alignItems: 'center' }}>
                        <img
                          src={langMeta[lc].flag}
                          alt={langMeta[lc].name || lc}
                          style={{ width: '16px', height: 'auto' }}
                        />
                      </span>
                    </Tooltip>
                  </th>
                ) : (
                  <th key={lc} className="pllat-px-4 pllat-py-3 pllat-font-medium pllat-uppercase">
                    {lc}
                  </th>
                ),
              )}
            </tr>
          </thead>
          <tbody>
            {groups.map((g) => {
              const rowInFlight = langCodes.some((lc) => inFlight.has(`${g.group}|${lc}`));
              return (
                <tr key={g.group} className="pllat-border-t pllat-border-gray-100">
                  <td className="pllat-px-4 pllat-py-3 pllat-font-medium">
                    <a
                      href={`${window.location.pathname}?page=mlang_strings&group=${encodeURIComponent(g.group)}`}
                      title={__('View this string group in Polylang', 'polylang-ai-automatic-translation')}
                    >
                      {g.group}
                    </a>{' '}
                    <span className="pllat-text-gray-400">({g.total})</span>
                  </td>
                  <td className="pllat-px-4 pllat-py-3">
                    {rowInFlight ? (
                      <button className="button" onClick={cancel}>
                        {__('Cancel', 'polylang-ai-automatic-translation')}
                      </button>
                    ) : (
                      <button className="button button-primary" onClick={() => setModalGroup(g.group)}>
                        {__('Translate', 'polylang-ai-automatic-translation')}
                      </button>
                    )}
                  </td>
                  {langCodes.map((lc) => (
                    <td key={lc} className="pllat-px-4 pllat-py-3">
                      {renderCell(g, lc)}
                    </td>
                  ))}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {modalGroup && (
        <BulkConfigModal
          isOpen
          onClose={handleModalClose}
          onSubmit={handleModalSubmit}
          targetLanguages={targetLanguages}
          showLimitOption
          showFieldSelector={false}
          contentType={stringsContentType}
        />
      )}
    </>
  );
};

export default StringsTab;
