/** @jsx h */
import { Fragment, h } from 'preact';
import { useState } from 'preact/hooks';
import { renderMarkdown } from './markdown.js';
import { htmlHasText, uniqueEditorId, WysiwygEditor } from './wysiwyg-editor.jsx';

const TYPES = [
  { id: 'question', label: 'Question', badgeClass: 'rt-b-q' },
  { id: 'bug', label: 'Bug', badgeClass: 'rt-b-bug' },
  { id: 'feature_request', label: 'Feature', badgeClass: 'rt-b-feat' },
];

function normalizeType(raw) {
  const t = String(raw || 'question');
  return TYPES.some((x) => x.id === t) ? t : 'question';
}

const MODERATION_NOTICE = 'Your topic is reviewed by the team before it becomes visible to the community.';

/**
 * Human-readable message for a failed direct-submit response, keyed by error kind.
 * @param {string|undefined} kind
 * @returns {string}
 */
function directErrorText(kind) {
  if (kind === 'licence_invalid' || kind === 'http_402') {
    return 'Your product licence could not be verified. Check your licence in the plugin settings, then try again.';
  }
  if (kind === 'over_quota' || kind === 'http_429') {
    return "You've reached the usage limit for now. Please try again in a little while.";
  }
  return 'Something went wrong. Please try again.';
}

/**
 * Review-and-publish dialog. `variant="review"` (the default) edits an AI-drafted
 * Case and publishes it (existing chat flow). `variant="direct"` is an empty
 * form (no pre-filled type, title, or body) for the chat-disabled "+ New topic"
 * flow: it shows the moderation notice above the submit button and surfaces
 * `errorKind` (from the `/topics` proxy) instead of a markdown preview toggle.
 * @param {{draft?:object, variant?:'review'|'direct', busy:boolean, errorKind?:string|null, onCancel:()=>void, onPublish:(p:{title:string,summary:string,type:string})=>void}} props
 */
export function PublishDialog({
  draft, variant = 'review', busy, errorKind, onCancel, onPublish,
}) {
  const isDirect = variant === 'direct';
  const [title, setTitle] = useState(draft?.title || '');
  const [summary, setSummary] = useState(draft?.summary || '');
  const [type, setType] = useState(isDirect ? '' : normalizeType(draft?.type));
  const [mode, setMode] = useState('write');
  const [editorId] = useState(() => uniqueEditorId('rt-topic-body'));
  const activeType = TYPES.find((t) => t.id === type) || TYPES[0];
  const canSubmit = isDirect
    ? Boolean(type) && title.trim() && htmlHasText(summary)
    : title.trim() && summary.trim() && mode === 'write';

  return (
    <div
      class="rt-modal-backdrop rt-pub-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label={isDirect ? 'New topic' : 'Review and publish topic'}
      onClick={(e) => { if (e.target === e.currentTarget && !busy) onCancel(); }}
    >
      <div class="rt-modal rt-pub-modal">
        <div class="rt-pub-head">
          <div>
            <h2 class="rt-modal-title">{isDirect ? 'New topic' : 'Review & publish'}</h2>
            {!isDirect && <p class="rt-modal-sub">Edit the draft below. After you submit, a maintainer reviews it before it appears in the public list.</p>}
          </div>
          {!isDirect && <span class={'rt-badge ' + activeType.badgeClass}>{activeType.label}</span>}
          <button
            type="button"
            class="rt-modal-close"
            aria-label="Close"
            disabled={busy}
            onClick={onCancel}
          >×</button>
        </div>
        <div class="rt-pub-types" role="group" aria-label="Topic type">
          {TYPES.map((t) => (
            <button
              key={t.id}
              type="button"
              class={'rt-pub-type' + (type === t.id ? ' on' : '')}
              disabled={busy}
              onClick={() => setType(t.id)}
            >
              {t.label}
            </button>
          ))}
        </div>
        <label class="rt-field rt-pub-field">
          <span>Title</span>
          <input type="text" value={title} disabled={busy} onInput={(e) => setTitle(e.currentTarget.value)} />
        </label>
        <div class="rt-pub-body">
          {isDirect ? (
            <WysiwygEditor
              id={editorId}
              value={summary}
              onInput={setSummary}
              disabled={busy}
              toolbar1="formatselect bold italic bullist numlist link code"
              blockFormats="Paragraph=p;Heading=h3;Subheading=h4"
              height={220}
              rows={14}
              placeholder="Describe your question, bug, or feature idea…"
              className="rt-pub-textarea"
            />
          ) : (
            <Fragment>
              <div class="rt-ed-top rt-pub-ed-top">
                <div class="rt-ed-tabs">
                  <button type="button" class={'rt-ed-tab' + (mode === 'write' ? ' on' : '')} onClick={() => setMode('write')}>Write</button>
                  <button type="button" class={'rt-ed-tab' + (mode === 'preview' ? ' on' : '')} onClick={() => setMode('preview')}>Preview</button>
                </div>
              </div>
              {mode === 'write' ? (
                <textarea
                  class="rt-pub-textarea"
                  rows="14"
                  value={summary}
                  disabled={busy}
                  placeholder="Describe the issue clearly so others can understand it at a glance…"
                  onInput={(e) => setSummary(e.currentTarget.value)}
                />
              ) : (
                <div
                  class="rt-pub-preview rt-prose"
                  dangerouslySetInnerHTML={{ __html: summary.trim() ? renderMarkdown(summary) : '<p class="rt-ed-preview-empty">Nothing to preview yet.</p>' }}
                />
              )}
            </Fragment>
          )}
        </div>
        {isDirect && <div class="rt-pub-notice">{MODERATION_NOTICE}</div>}
        {isDirect && errorKind && <div class="rt-pub-notice rt-pub-notice-error">{directErrorText(errorKind)}</div>}
        <div class="rt-modal-actions">
          <button type="button" class="rt-btn-secondary" disabled={busy} onClick={onCancel}>Cancel</button>
          <button
            type="button"
            class="rt-btn-primary"
            disabled={busy || !canSubmit}
            onClick={() => onPublish({ title: title.trim(), summary: summary.trim(), type })}
          >
            {isDirect ? (busy ? 'Submitting…' : 'Submit topic') : (busy ? 'Publishing…' : 'Publish topic')}
          </button>
        </div>
      </div>
    </div>
  );
}