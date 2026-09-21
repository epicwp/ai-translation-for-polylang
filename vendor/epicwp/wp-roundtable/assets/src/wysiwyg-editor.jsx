/** @jsx h */
import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';

let editorIdCounter = 0;

/**
 * A unique DOM id for a TinyMCE instance, so multiple editors (e.g. the
 * New-topic dialog and a comment composer) can mount and initialize at the
 * same time without id collisions.
 * @param {string} prefix
 * @returns {string}
 */
export function uniqueEditorId(prefix) {
  editorIdCounter += 1;
  return `${prefix}-${editorIdCounter}`;
}

/**
 * Whether an HTML string (as returned by TinyMCE's getContent()) has any
 * visible text. An "empty" TinyMCE document is usually `<p></p>` or
 * `<p>&nbsp;</p>`, not `''`, so a plain truthiness/`.trim()` check on the raw
 * HTML would leave a submit button permanently enabled.
 * @param {string} html
 * @returns {boolean}
 */
export function htmlHasText(html) {
  return String(html || '')
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .trim().length > 0;
}

/**
 * WordPress's bundled TinyMCE (`wp.editor`, available via the host page's
 * `wp_enqueue_editor()` call), wrapped to match the plain-textarea
 * `value`/`onInput` contract used elsewhere in this app — except the value is
 * HTML, not markdown.
 *
 * Uncontrolled after mount, like any native rich-text widget: `value` seeds
 * the editor's *initial* content only (re-feeding it into TinyMCE on every
 * keystroke would fight its own cursor/undo state). Every edit is reported
 * back via `onInput(html)`. Pass a changing `resetNonce` to clear the editor
 * in place (e.g. after a successful submit) without unmounting it.
 *
 * Falls back to a plain, fully-controlled `<textarea>` when `window.wp.editor`
 * isn't available (defensive — the host page's `wp_enqueue_editor()` call
 * should always have made it available).
 *
 * @param {{id:string, value:string, onInput:(html:string)=>void, disabled?:boolean,
 *   toolbar1:string, blockFormats?:string, height?:number, rows?:number,
 *   placeholder?:string, resetNonce?:number, className?:string}} props
 */
export function WysiwygEditor({
  id, value, onInput, disabled = false, toolbar1, blockFormats, height = 220,
  rows = 8, placeholder, resetNonce = 0, className,
}) {
  const onInputRef = useRef(onInput);
  onInputRef.current = onInput;
  const areaRef = useRef(null);
  const available = typeof window !== 'undefined' && !!window.wp && !!window.wp.editor;

  useEffect(() => {
    if (!available) return undefined;
    window.wp.editor.initialize(id, {
      tinymce: {
        wpautop: true,
        menubar: false,
        statusbar: false,
        toolbar1,
        toolbar2: '',
        block_formats: blockFormats,
        height,
        setup(ed) {
          ed.on('change input undo redo', () => onInputRef.current(ed.getContent()));
        },
      },
      quicktags: false,
      mediaButtons: false,
    });
    return () => window.wp.editor.remove(id);
    // Mount once per id — toolbar/height/blockFormats are fixed per call site.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [available, id]);

  useEffect(() => {
    if (!available || resetNonce <= 0) return;
    const ed = window.tinymce && window.tinymce.get(id);
    if (!ed) return;
    ed.setContent('');
    onInputRef.current('');
  }, [resetNonce]);

  if (!available) {
    return (
      <textarea
        ref={areaRef}
        class={className}
        rows={rows}
        value={value}
        disabled={disabled}
        placeholder={placeholder}
        onInput={(e) => onInput(e.currentTarget.value)}
      />
    );
  }

  // The seed textarea wp.editor.initialize() reads its initial content from,
  // and hides once TinyMCE takes over.
  return <textarea id={id} defaultValue={value} />;
}
