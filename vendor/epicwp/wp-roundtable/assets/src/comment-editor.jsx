/** @jsx h */
import { h } from 'preact';
import { useState } from 'preact/hooks';
import { htmlHasText, uniqueEditorId, WysiwygEditor } from './wysiwyg-editor.jsx';

/**
 * Comment composer built on WordPress's bundled TinyMCE (via `wp.editor`),
 * restricted to bold/italic/lists/link — no headings or block-format select
 * (see WysiwygEditor). Falls back to a plain textarea when TinyMCE isn't
 * available.
 * @param {{value:string, onInput:(v:string)=>void, onSubmit:()=>void, posting:boolean, error:boolean, submitDisabled?:boolean, resetNonce?:number}} props
 */
export function CommentEditor({
  value, onInput, onSubmit, posting, error, submitDisabled = false, resetNonce = 0,
}) {
  const [editorId] = useState(() => uniqueEditorId('rt-comment'));
  const canSubmit = htmlHasText(value);

  return (
    <div class="rt-cm-composer">
      <div class="rt-editor rt-mdeditor">
        <WysiwygEditor
          id={editorId}
          value={value}
          onInput={onInput}
          disabled={posting}
          toolbar1="bold italic bullist numlist link"
          height={160}
          rows={4}
          placeholder="Add a comment…"
          className="rt-ed-input"
          resetNonce={resetNonce}
        />
        <div class="rt-ed-foot">
          {error ? <span class="rt-ed-error">Could not post. Try again.</span> : <span class="rt-ed-spacer" />}
          <button type="button" class="rt-post-btn" disabled={posting || !canSubmit || submitDisabled} onClick={onSubmit}>
            {posting ? 'Posting…' : 'Comment'}
          </button>
        </div>
      </div>
    </div>
  );
}
