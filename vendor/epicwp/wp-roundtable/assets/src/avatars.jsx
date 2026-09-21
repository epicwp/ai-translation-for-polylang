/** @jsx h */
import { h } from 'preact';

/** Outline bot (Lucide-style) — clear at 16–18px in avatar circles. */
function AgentIcon() {
  return (
    <svg
      class="rt-agent-icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d="M12 8V4H8" />
      <rect x="4" y="8" width="16" height="12" rx="2" />
      <path d="M2 14h2" />
      <path d="M20 14h2" />
      <path d="M15 13v2" />
      <path d="M9 13v2" />
    </svg>
  );
}

/**
 * Avatar for the AI assistant.
 * @param {{class?:string, size?:'sm'|'md'|'lg'}} props
 */
export function AgentAvatar({ class: className = '', size = 'md' }) {
  return (
    <div class={'rt-ava rt-ava-agent rt-ava-' + size + (className ? ' ' + className : '')} aria-hidden="true">
      <AgentIcon />
    </div>
  );
}

/**
 * Avatar for a community member (initials).
 * @param {{initials:string, class?:string, size?:'sm'|'md'|'lg'}} props
 */
export function PersonAvatar({ initials, class: className = '', size = 'md' }) {
  return (
    <div class={'rt-ava rt-ava-person rt-ava-' + size + (className ? ' ' + className : '')} aria-hidden="true">
      {initials}
    </div>
  );
}