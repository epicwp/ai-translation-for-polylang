/** @jsx h */
import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { fetchCases } from './api.js';
import { groupRoadmapCases, mapCaseToTopic } from './topics.js';
import { TopicRow } from './topic-list.jsx';

const TYPE_SEGS = [
  { id: '', label: 'All' },
  { id: 'bug', label: 'Bugs' },
  { id: 'feature_request', label: 'Features' },
];

const STATUS_CLASS = {
  escalated: 'rt-s-plan',
  in_progress: 'rt-s-prog',
  shipped: 'rt-s-ship',
};

function RoadmapSectionHead({ section }) {
  const count = section.cases.length;
  const noun = count === 1 ? 'topic' : 'topics';
  const hint = `${count} ${noun}${section.hintSuffix}`;
  return (
    <div class="rt-sechead">
      <span class={'rt-sechead-status ' + (STATUS_CLASS[section.status] || 'rt-s-plan')}>{section.label}</span>
      <span class="rt-sechead-hint">{hint}</span>
      <span class="rt-sechead-line" />
    </div>
  );
}

export function RoadmapList({ refreshNonce = 0, onSelectTopic, onVoteChange }) {
  const [sections, setSections] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');
  const [type, setType] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(false);
      const params = { sort: 'top', limit: 100 };
      if (type) params.type = type;
      if (debouncedQ) params.q = debouncedQ;
      const res = await fetchCases(params);
      if (cancelled) return;
      setLoading(false);
      if (res.error) {
        setError(true);
        setSections([]);
        return;
      }
      setSections(groupRoadmapCases(res.cases || []));
    })();
    return () => { cancelled = true; };
  }, [type, debouncedQ, refreshNonce]);

  return (
    <div class="rt-list-area rt-roadmap-area">
      <div class="rt-toolbar">
        <div class="rt-search">
          <input
            type="search"
            placeholder="Search the roadmap…"
            value={q}
            onInput={(e) => setQ(e.currentTarget.value)}
          />
        </div>
        <div class="rt-segs">
          {TYPE_SEGS.map((s) => (
            <button
              key={s.id || 'all'}
              type="button"
              class={type === s.id ? 'on' : ''}
              onClick={() => setType(s.id)}
            >{s.label}</button>
          ))}
        </div>
      </div>
      {loading && <div class="rt-list-msg">Loading roadmap…</div>}
      {!loading && error && <div class="rt-list-msg rt-list-error">Could not load the roadmap. Try again.</div>}
      {!loading && !error && sections.map((section) => (
        <div class="rt-roadmap-block" key={section.status}>
          <RoadmapSectionHead section={section} />
          <div class="rt-list">
            {section.cases.length === 0 ? (
              <div class="rt-list-empty">{section.emptyLabel}</div>
            ) : section.cases.map((row) => {
              const topic = mapCaseToTopic(row);
              return (
                <TopicRow
                  key={topic.id}
                  topic={topic}
                  hideStatus
                  onSelect={onSelectTopic}
                  onVoteChange={onVoteChange}
                />
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}