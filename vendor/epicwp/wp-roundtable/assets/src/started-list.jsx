/** @jsx h */
import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import { fetchMyCases } from './api.js';
import { filterTopics } from './list-filters.js';
import { ListToolbar } from './list-toolbar.jsx';
import { mapCaseToTopic } from './topics.js';
import { TopicRow } from './topic-list.jsx';
import { agentDisplayName, chatEnabled } from './config.js';

function DraftRow({ topic, onReview }) {
  return (
    <div class="rt-draft">
      <div class="rt-draft-icon" aria-hidden="true">✎</div>
      <div class="rt-draft-main">
        <div class="rt-draft-title">{topic.title}</div>
        <div class="rt-draft-meta">
          <span class="rt-badge rt-b-draft">Draft</span>
          <span class="rt-dot">·</span>
          <span>Only you can see this</span>
          {topic.age ? <span><span class="rt-dot">·</span>{topic.age}</span> : null}
        </div>
      </div>
      <div class="rt-draft-actions">
        <button type="button" class="rt-btn-sm rt-btn-pub" onClick={() => onReview(topic)}>Review &amp; publish</button>
      </div>
    </div>
  );
}

/**
 * The started-list's empty-state message: points at the chat panel when chat is
 * enabled, or the "+ New topic" button when it's the only way to start a topic.
 * @param {boolean} chatOn
 * @param {string} agentName
 * @returns {string}
 */
export function emptyStateMessage(chatOn, agentName) {
  return chatOn
    ? `No topics started yet. Use ${agentName} on the right to draft one.`
    : 'No topics started yet. Click "+ New topic" to submit one.';
}

function SectionHead({ title, hint }) {
  return (
    <div class="rt-sechead">
      <h2 class="rt-sechead-title">{title}</h2>
      {hint ? <span class="rt-sechead-hint">{hint}</span> : null}
      <span class="rt-sechead-line" />
    </div>
  );
}

export function StartedList({ refreshNonce = 0, onSelectTopic, onReviewDraft, onVoteChange }) {
  const [drafts, setDrafts] = useState([]);
  const [pending, setPending] = useState([]);
  const [published, setPublished] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');
  const [type, setType] = useState('');
  const agentName = agentDisplayName();
  const chatOn = chatEnabled();

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(false);
      const res = await fetchMyCases();
      if (cancelled) return;
      setLoading(false);
      if (res.error) {
        setError(true);
        setDrafts([]);
        setPending([]);
        setPublished([]);
        return;
      }
      const topics = (res.cases || []).map(mapCaseToTopic);
      setDrafts(topics.filter((t) => t.isDraft));
      setPublished(topics.filter((t) => !t.isDraft && !t.isPending));
      setPending(topics.filter((t) => t.isPending));
    })();
    return () => { cancelled = true; };
  }, [refreshNonce]);

  const filteredDrafts = useMemo(() => filterTopics(drafts, q, type), [drafts, q, type]);
  const filteredPending = useMemo(() => filterTopics(pending, q, type), [pending, q, type]);
  const filteredPublished = useMemo(() => filterTopics(published, q, type), [published, q, type]);
  const total = drafts.length + pending.length + published.length;
  const filteredTotal = filteredDrafts.length + filteredPending.length + filteredPublished.length;

  return (
    <div class="rt-list-area rt-started-area">
      <ListToolbar
        placeholder="Search your topics…"
        q={q}
        onQChange={setQ}
        type={type}
        onTypeChange={setType}
      />
      {loading && <div class="rt-list-msg">Loading your topics…</div>}
      {!loading && error && <div class="rt-list-msg rt-list-error">Could not load your topics. Try again.</div>}
      {!loading && !error && total === 0 && (
        <div class="rt-list-msg">{emptyStateMessage(chatOn, agentName)}</div>
      )}
      {!loading && !error && total > 0 && filteredTotal === 0 && (
        <div class="rt-list-msg">No matching topics in your started list.</div>
      )}
      {!loading && !error && filteredDrafts.length > 0 && (
        <div class="rt-started-block">
          <SectionHead title="Drafts" hint={`${filteredDrafts.length} · only you can see these`} />
          <div class="rt-list">
            {filteredDrafts.map((t) => <DraftRow key={t.id} topic={t} onReview={onReviewDraft} />)}
          </div>
        </div>
      )}
      {!loading && !error && filteredPending.length > 0 && (
        <div class="rt-started-block">
          <SectionHead title="Pending approval" hint={`${filteredPending.length} · awaiting maintainer review`} />
          <div class="rt-list">
            {filteredPending.map((t) => (
              <TopicRow key={t.id} topic={t} onSelect={onSelectTopic} onVoteChange={onVoteChange} />
            ))}
          </div>
        </div>
      )}
      {!loading && !error && filteredPublished.length > 0 && (
        <div class="rt-started-block">
          <SectionHead title="Started by me" hint={`${filteredPublished.length} published`} />
          <div class="rt-list">
            {filteredPublished.map((t) => (
              <TopicRow key={t.id} topic={t} onSelect={onSelectTopic} onVoteChange={onVoteChange} />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}