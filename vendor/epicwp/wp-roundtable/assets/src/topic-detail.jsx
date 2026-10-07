/** @jsx h */
import { Fragment, h } from 'preact';
import { useCallback, useEffect, useState } from 'preact/hooks';
import { fetchComments, postComment } from './api.js';
import { renderMarkdown } from './markdown.js';
import { AgentAvatar, PersonAvatar } from './avatars.jsx';
import { agentDisplayName } from './config.js';
import { buildThreadRows, mapCommentToView } from './topics.js';
import { DisabledVote, VoteControl } from './vote-control.jsx';
import { CommentEditor } from './comment-editor.jsx';
import { CommentActions } from './comment-actions.jsx';
import { htmlHasText } from './wysiwyg-editor.jsx';
import { MaintainerBadge } from './badges.jsx';

export function CommentRow({ comment, isReply = false, onReply }) {
  const Ava = comment.isAgent ? AgentAvatar : PersonAvatar;
  const avaProps = comment.isAgent ? { size: 'sm' } : { initials: comment.initials, size: 'sm' };
  return (
    <div class={'rt-cm' + (isReply ? ' rt-cm-reply-row' : '')}>
      <Ava {...avaProps} class="rt-cm-ava" />
      <div class="rt-cm-body">
        <div class="rt-cm-who">
          <b>{comment.handle}</b>
          {comment.role === 'maintainer' && <MaintainerBadge />}
          {comment.role === 'assistant' && comment.roleBadge
            ? <span class="rt-role-badge">{comment.roleBadge}</span> : null}
          {comment.age ? <span class="rt-cm-time">{comment.age}</span> : null}
        </div>
        {comment.isTombstone
          ? <p class="rt-cm-tomb">{comment.tombstoneLabel}</p>
          : <div class="rt-cm-txt rt-prose" dangerouslySetInnerHTML={{ __html: comment.html }} />}
        {!comment.isTombstone && <CommentActions onReply={onReply} />}
      </div>
    </div>
  );
}

function repliesTitle(count, loading, error) {
  if (loading) return 'Comments';
  if (error) return 'Comments';
  if (count === 1) return '1 reply';
  return count + ' replies';
}

function CommentComposer({
  caseId, onPosted, disabled = false, replyTarget, focusNonce = 0, onCancelReply,
}) {
  const [text, setText] = useState('');
  const [posting, setPosting] = useState(false);
  const [error, setError] = useState(false);
  const [resetNonce, setResetNonce] = useState(0);

  const submit = async () => {
    if (disabled || posting || !htmlHasText(text)) return;
    setPosting(true);
    setError(false);
    const res = await postComment(caseId, text.trim(), replyTarget?.parentId);
    setPosting(false);
    if (res.error) {
      setError(true);
      return;
    }
    setText('');
    setResetNonce((n) => n + 1);
    onCancelReply && onCancelReply();
    onPosted();
  };

  return (
    <CommentEditor
      value={text}
      onInput={setText}
      onSubmit={submit}
      posting={posting}
      error={error}
      submitDisabled={disabled}
      resetNonce={resetNonce}
      focusNonce={focusNonce}
      replyTarget={replyTarget}
      onCancelReply={onCancelReply}
    />
  );
}

export function TopicDetail({ topic, onBack, onVoteChange }) {
  const [comments, setComments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [refreshNonce, setRefreshNonce] = useState(0);
  const [replyTarget, setReplyTarget] = useState(null);
  const [replyNonce, setReplyNonce] = useState(0);
  const agentName = agentDisplayName();

  function startReply(target) {
    setReplyTarget(target);
    setReplyNonce((n) => n + 1);
  }
  const cancelReply = () => setReplyTarget(null);

  const loadComments = useCallback(async (cancelledRef) => {
    setLoading(true);
    setError(false);
    const res = await fetchComments(topic.id);
    if (cancelledRef.cancelled) return;
    setLoading(false);
    if (res.error) {
      setError(true);
      setComments([]);
      return;
    }
    // Kept chronological (oldest-first, the hub's own order) — groupCommentThreads
    // below both sorts top-level comments newest-first and orders each one's
    // replies oldest-first from this same order.
    setComments((res.comments || []).map((row) => mapCommentToView(row, agentName)));
  }, [topic.id, agentName]);

  useEffect(() => {
    // Pending cases 403 comments (and votes) hub-side — skip the fetch entirely
    // rather than surface an error state for a call we know will fail.
    if (topic.isPending) return undefined;
    const cancelledRef = { cancelled: false };
    loadComments(cancelledRef);
    return () => { cancelledRef.cancelled = true; };
  }, [loadComments, refreshNonce, topic.isPending]);

  return (
    <div class="rt-detail-area">
      <button type="button" class="rt-back" onClick={onBack}>← All topics</button>
      {topic.isPending && (
        <div class="rt-banner rt-banner-pending">
          <b>Pending approval.</b> Only you can see this topic until a maintainer approves it for the community.
        </div>
      )}
      <div class="rt-detail-card rt-card">
        <div class="rt-th">
          {topic.isPending
            ? <DisabledVote net={topic.net} />
            : <VoteControl caseId={topic.id} net={topic.net} onChange={onVoteChange} />}
          <div class="rt-thd">
            <h2 class="rt-dtitle">{topic.title}</h2>
            <div class="rt-cmeta">
              <span class={'rt-badge ' + topic.typeClass}>{topic.typeLabel}</span>
              <span class={'rt-status ' + topic.statusClass}>{topic.statusLabel}</span>
              <span class="rt-dot">·</span>
              <span class="rt-handle">{topic.handle}</span>
              {topic.authorRole === 'maintainer' && <MaintainerBadge />}
              {topic.age ? <span class="rt-age-wrap"><span class="rt-dot">·</span><span>{topic.age}</span></span> : null}
            </div>
          </div>
        </div>
        <div class="rt-post">
          <PersonAvatar initials={topic.initials} size="sm" class="rt-post-ava" />
          <div class="rt-post-body">
            <div class="rt-post-who">
              <b>{topic.handle}</b>
              {topic.authorRole === 'maintainer' && <MaintainerBadge />}
              {topic.age ? <span> · {topic.age}</span> : null}
            </div>
            <div class="rt-prose" dangerouslySetInnerHTML={{ __html: renderMarkdown(topic.snippet) }} />
          </div>
        </div>
      </div>
      <div class="rt-csec rt-card">
        {!topic.isPending && (
          <Fragment>
            <div class="rt-csec-head">
              <h2 class="rt-csec-title">{repliesTitle(comments.length, loading, error)}</h2>
              {!loading && !error && comments.length > 0
                ? <span class="rt-csec-sort">Newest first</span>
                : null}
            </div>
            {loading && <p class="rt-csec-cnt">Loading…</p>}
            {error && <p class="rt-csec-cnt rt-list-error">Could not load comments.</p>}
            {!loading && !error && comments.length === 0 && <p class="rt-csec-empty">No comments yet.</p>}
            {!loading && !error && comments.length > 0 && (
              <div class="rt-cm-thread">
                {buildThreadRows(comments).map((row) => (
                  <CommentRow
                    key={row.comment.id}
                    comment={row.comment}
                    isReply={row.isReply}
                    onReply={() => startReply(row.replyTarget)}
                  />
                ))}
              </div>
            )}
          </Fragment>
        )}
        {topic.isPending && (
          <p class="rt-csec-empty">Comments open once this topic is approved.</p>
        )}
        <CommentComposer
          caseId={topic.id}
          onPosted={() => {
            setRefreshNonce((n) => n + 1);
            onVoteChange && onVoteChange();
          }}
          disabled={topic.isPending}
          replyTarget={replyTarget}
          focusNonce={replyNonce}
          onCancelReply={cancelReply}
        />
      </div>
    </div>
  );
}