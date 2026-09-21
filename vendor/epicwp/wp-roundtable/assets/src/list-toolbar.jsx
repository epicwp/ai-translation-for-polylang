/** @jsx h */
import { h } from 'preact';
import { TYPE_SEGS } from './list-filters.js';

export function ListToolbar({ placeholder, q, onQChange, type, onTypeChange }) {
  return (
    <div class="rt-toolbar">
      <div class="rt-search">
        <input
          type="search"
          placeholder={placeholder}
          value={q}
          onInput={(e) => onQChange(e.currentTarget.value)}
        />
      </div>
      <div class="rt-segs">
        {TYPE_SEGS.map((s) => (
          <button
            key={s.id || 'all'}
            type="button"
            class={type === s.id ? 'on' : ''}
            onClick={() => onTypeChange(s.id)}
          >{s.label}</button>
        ))}
      </div>
    </div>
  );
}