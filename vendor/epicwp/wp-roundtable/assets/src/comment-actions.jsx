/** @jsx h */
import { h } from 'preact';

const UP_SVG = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
    <path d="m6 15 6-6 6 6" />
  </svg>
);

/**
 * Per-comment actions from the mockup. Voting/replies need hub APIs (post-MVP, hub §16).
 */
export function CommentActions() {
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
        disabled
        title="Nested replies — coming in a later release"
      >
        Reply
      </button>
    </div>
  );
}