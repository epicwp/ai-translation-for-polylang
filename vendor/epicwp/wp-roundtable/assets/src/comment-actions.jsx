/** @jsx h */
import { h } from 'preact';

const UP_SVG = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
    <path d="m6 15 6-6 6 6" />
  </svg>
);

/**
 * Per-comment actions. Reply is wired via `onReply` (one-level threading);
 * comment voting still needs a hub API (post-MVP, hub §16) and stays disabled.
 * @param {{onReply?:()=>void}} props
 */
export function CommentActions({ onReply }) {
  return (
    <div class="rt-cm-actions">
      <button
        type="button"
        class="rt-cm-action rt-cm-vote"
        disabled
        title="Comment voting — coming in a later release"
        aria-label="Upvote comment (coming soon)"
      >
        {UP_SVG}
      </button>
      <button
        type="button"
        class="rt-cm-action rt-cm-reply"
        disabled={!onReply}
        onClick={onReply}
      >
        Reply
      </button>
    </div>
  );
}