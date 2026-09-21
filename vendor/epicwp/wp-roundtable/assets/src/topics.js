import { renderMarkdown } from './markdown.js';

/** UI labels for hub case types. */
const TYPE_LABELS = {
  question: 'Question',
  bug: 'Bug',
  feature_request: 'Feature',
};

/** UI labels for hub case statuses (escalated → Planned per spec). */
const STATUS_LABELS = {
  open: 'Open',
  escalated: 'Planned',
  in_progress: 'In progress',
  shipped: 'Shipped',
  closed: 'Closed',
  merged: 'Merged',
  chatting: 'Draft',
};

const TYPE_CLASS = {
  question: 'rt-b-q',
  bug: 'rt-b-bug',
  feature_request: 'rt-b-feat',
};

const STATUS_CLASS = {
  open: 'rt-s-open',
  escalated: 'rt-s-plan',
  in_progress: 'rt-s-prog',
  shipped: 'rt-s-ship',
  closed: 'rt-s-open',
  merged: 'rt-s-ship',
  chatting: 'rt-s-open',
};

const DRAFT_STATUS_CLASS = 'rt-s-draft';
const PENDING_STATUS_CLASS = 'rt-s-pending';

/**
 * Format an ISO timestamp as a short relative age (e.g. "3d", "2w").
 * @param {string} iso
 * @returns {string}
 */
export function formatAge(iso) {
  const then = Date.parse(iso);
  if (Number.isNaN(then)) return '';
  const days = Math.max(0, Math.floor((Date.now() - then) / 86400000));
  if (days < 1) return 'today';
  if (days < 7) return `${days}d`;
  if (days < 30) return `${Math.floor(days / 7)}w`;
  return `${Math.floor(days / 30)}mo`;
}

/**
 * Two-letter initials from a community handle.
 * @param {string} handle
 * @returns {string}
 */
export function handleInitials(handle) {
  const parts = String(handle || '').split(/[-_]/).filter(Boolean);
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
  const h = String(handle || '?');
  return h.slice(0, 2).toUpperCase();
}

/** Hub statuses shown on the Roadmap tab, in display order. */
export const ROADMAP_SECTIONS = [
  {
    status: 'escalated',
    label: 'Planned',
    hintSuffix: ' · accepted onto the roadmap',
    emptyLabel: 'No topics planned yet. Accepted topics will appear here.',
  },
  {
    status: 'in_progress',
    label: 'In progress',
    hintSuffix: '',
    emptyLabel: 'No topics in progress right now.',
  },
  {
    status: 'shipped',
    label: 'Shipped',
    hintSuffix: '',
    emptyLabel: 'No shipped topics yet.',
  },
];

const ROADMAP_STATUS_SET = new Set(ROADMAP_SECTIONS.map((s) => s.status));

/**
 * Keep only public cases that belong on the roadmap.
 * @param {Array<{status?:string}>} cases
 * @returns {Array}
 */
export function filterRoadmapCases(cases) {
  return (cases || []).filter((row) => ROADMAP_STATUS_SET.has(row.status));
}

/**
 * Group roadmap cases into Planned / In progress / Shipped sections.
 * @param {Array<{status?:string, net?:number}>} cases
 * @returns {Array<{status:string, label:string, hintSuffix:string, cases:Array}>}
 */
export function groupRoadmapCases(cases) {
  const filtered = filterRoadmapCases(cases);
  return ROADMAP_SECTIONS.map((section) => ({
    ...section,
    cases: filtered
      .filter((row) => row.status === section.status)
      .sort((a, b) => (b.net || 0) - (a.net || 0)),
  }));
}

export function mapCaseToTopic(row) {
  const type = row.type || 'question';
  const status = row.status || 'open';
  const handle = row.author_handle || '';
  const summary = row.summary || '';
  const visibility = row.visibility || 'public';
  const isDraft = visibility === 'private';
  const isPending = visibility === 'pending';
  return {
    id: row.id,
    type,
    title: row.title || '',
    snippet: summary,
    typeLabel: TYPE_LABELS[type] || type,
    typeClass: TYPE_CLASS[type] || 'rt-b-q',
    statusLabel: isDraft ? 'Draft' : (isPending ? 'Pending approval' : (STATUS_LABELS[status] || status)),
    statusClass: isDraft ? DRAFT_STATUS_CLASS : (isPending ? PENDING_STATUS_CLASS : (STATUS_CLASS[status] || 'rt-s-open')),
    handle,
    initials: handleInitials(handle),
    // Defaults to 'community' when absent so the UI works against an older
    // hub that doesn't send author_role on case responses yet.
    authorRole: row.author_role || 'community',
    net: typeof row.net === 'number' ? row.net : 0,
    age: formatAge(row.created_at),
    isDraft,
    isPending,
    visibility,
  };
}

const TOMBSTONE_LABELS = {
  deleted: 'This comment was deleted.',
  removed: 'This comment was removed.',
};

/**
 * Map a hub CommentResponse to the UI comment view model.
 * @param {object} row hub comment
 * @returns {object}
 */
/**
 * Resolve a hub author_role (or legacy subject hints) to a UI role.
 * @param {object} row
 * @returns {'community'|'assistant'|'maintainer'}
 */
export function resolveCommentRole(row) {
  const role = row.author_role;
  if (role === 'assistant' || role === 'maintainer' || role === 'community') return role;
  if (row.subject_id === 'system') return 'maintainer';
  if (typeof row.subject_id === 'string' && row.subject_id.startsWith('agent:')) return 'assistant';
  return 'community';
}

/**
 * @param {'community'|'assistant'|'maintainer'} role
 * @param {string} agentName
 * @returns {string|null}
 */
export function commentRoleBadge(role, agentName) {
  if (role === 'assistant') return agentName + ' · Assistant';
  if (role === 'maintainer') return 'Maintainer';
  return null;
}

export function mapCommentToView(row, agentName = 'Roundtable') {
  const handle = row.author_handle || '';
  const status = row.status || 'active';
  const isTombstone = status !== 'active' || row.body == null || row.body === '';
  const role = resolveCommentRole(row);
  return {
    id: row.id,
    handle,
    initials: handleInitials(handle),
    age: formatAge(row.created_at),
    role,
    isAgent: role === 'assistant',
    roleBadge: commentRoleBadge(role, agentName),
    isTombstone,
    tombstoneLabel: TOMBSTONE_LABELS[status] || 'This comment is unavailable.',
    html: isTombstone ? '' : renderMarkdown(row.body),
  };
}