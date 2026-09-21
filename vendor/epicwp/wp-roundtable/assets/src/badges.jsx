/** @jsx h */
import { h } from 'preact';

/**
 * The "Maintainer" badge, rendered next to an author's handle wherever
 * `author_role === 'maintainer'` — topic rows, the topic detail header and
 * post, and comments. One shared component/class so every surface renders
 * the exact same badge, instead of each place inventing its own look.
 */
export function MaintainerBadge() {
  return <span class="rt-badge rt-b-maintainer">Maintainer</span>;
}
