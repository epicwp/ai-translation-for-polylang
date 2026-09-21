/** @jsx h */
import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { fetchCases } from './api.js';
import { markdownToPlainText } from './markdown.js';
import { mapCaseToTopic } from './topics.js';
import { DisabledVote, VoteControl } from './vote-control.jsx';
import { MaintainerBadge } from './badges.jsx';

export function TopicRow({ topic, onSelect, hideStatus = false, onVoteChange }) {
  const open = () => onSelect && onSelect(topic);
  return (
    <div class="rt-case">
      {topic.isPending
        ? <DisabledVote net={topic.net} />
        : <VoteControl caseId={topic.id} net={topic.net} onChange={onVoteChange} />}
      <div
        class="rt-cmain rt-case-click"
        role="button"
        tabIndex={0}
        onClick={open}
        onKeyDown={(e) => {
          if (!onSelect) return;
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            open();
          }
        }}
      >
        <span class="rt-ctitle">{topic.title}</span>
        <div class="rt-csnip">{markdownToPlainText(topic.snippet)}</div>
        <div class="rt-cmeta">
          <span class={'rt-badge ' + topic.typeClass}>{topic.typeLabel}</span>
          {!hideStatus && <span class={'rt-status ' + topic.statusClass}>{topic.statusLabel}</span>}
          <span class="rt-dot">·</span>
          <span class="rt-handle">{topic.handle}</span>
          {topic.authorRole === 'maintainer' && <MaintainerBadge />}
          {topic.age ? <span class="rt-age-wrap"><span class="rt-dot">·</span><span>{topic.age}</span></span> : null}
        </div>
      </div>
    </div>
  );
}

const SORTS = [
  { id: 'top', label: 'Top' },
  { id: 'newest', label: 'Newest' },
  { id: 'trending', label: 'Trending' },
];

const TYPE_SEGS = [
  { id: '', label: 'All' },
  { id: 'question', label: 'Questions' },
  { id: 'bug', label: 'Bugs' },
  { id: 'feature_request', label: 'Features' },
];

export function TopicList({ refreshNonce = 0, onSelectTopic, onVoteChange }) {
  const [topics, setTopics] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');
  const [type, setType] = useState('');
  const [sort, setSort] = useState('top');

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(false);
      const params = { sort, limit: 50 };
      if (type) params.type = type;
      if (debouncedQ) params.q = debouncedQ;
      const res = await fetchCases(params);
      if (cancelled) return;
      setLoading(false);
      if (res.error) {
        setError(true);
        setTopics([]);
        return;
      }
      setTopics((res.cases || []).map(mapCaseToTopic));
    })();
    return () => { cancelled = true; };
  }, [type, sort, debouncedQ, refreshNonce]);

  return (
    <div class="rt-list-area">
      <div class="rt-toolbar">
        <div class="rt-search">
          <input
            type="search"
            placeholder="Search discussions…"
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
        <span class="rt-spacer" />
        <label class="rt-sortsel">
          Sort:
          <select value={sort} onChange={(e) => setSort(e.currentTarget.value)}>
            {SORTS.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
          </select>
        </label>
      </div>
      <div class="rt-list">
        {loading && <div class="rt-list-msg">Loading topics…</div>}
        {!loading && error && <div class="rt-list-msg rt-list-error">Could not load topics. Try again.</div>}
        {!loading && !error && topics.length === 0 && <div class="rt-list-msg">No topics yet.</div>}
        {!loading && !error && topics.map((t) => (
          <TopicRow key={t.id} topic={t} onSelect={onSelectTopic} onVoteChange={onVoteChange} />
        ))}
      </div>
    </div>
  );
}