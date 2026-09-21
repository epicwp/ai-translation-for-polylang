/** @jsx h */
import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import { fetchParticipatingCases } from './api.js';
import { filterTopics } from './list-filters.js';
import { ListToolbar } from './list-toolbar.jsx';
import { mapCaseToTopic } from './topics.js';
import { TopicRow } from './topic-list.jsx';

export function ParticipatingList({ refreshNonce = 0, onSelectTopic, onVoteChange }) {
  const [topics, setTopics] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');
  const [type, setType] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(false);
      const res = await fetchParticipatingCases();
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
  }, [refreshNonce]);

  const filtered = useMemo(() => filterTopics(topics, q, type), [topics, q, type]);

  return (
    <div class="rt-list-area">
      <ListToolbar
        placeholder="Search topics you joined…"
        q={q}
        onQChange={setQ}
        type={type}
        onTypeChange={setType}
      />
      <div class="rt-list">
        {loading && <div class="rt-list-msg">Loading your topics…</div>}
        {!loading && error && <div class="rt-list-msg rt-list-error">Could not load your topics. Try again.</div>}
        {!loading && !error && filtered.length === 0 && (
          <div class="rt-list-msg">
            {topics.length === 0
              ? 'You have not commented or voted on any topics yet.'
              : 'No matching topics in your participating list.'}
          </div>
        )}
        {!loading && !error && filtered.map((t) => (
          <TopicRow key={t.id} topic={t} onSelect={onSelectTopic} onVoteChange={onVoteChange} />
        ))}
      </div>
    </div>
  );
}