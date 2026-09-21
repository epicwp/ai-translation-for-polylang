/** Type segment buttons shared by list tabs. */
export const TYPE_SEGS = [
  { id: '', label: 'All' },
  { id: 'question', label: 'Questions' },
  { id: 'bug', label: 'Bugs' },
  { id: 'feature_request', label: 'Features' },
];

const TYPE_CLASS_TO_ID = {
  'rt-b-q': 'question',
  'rt-b-bug': 'bug',
  'rt-b-feat': 'feature_request',
};

/**
 * Client-side search + type filter for mapped topic rows.
 * @param {Array} topics
 * @param {string} q
 * @param {string} type
 * @returns {Array}
 */
export function filterTopics(topics, q, type) {
  const needle = q.trim().toLowerCase();
  return topics.filter((topic) => {
    if (type && TYPE_CLASS_TO_ID[topic.typeClass] !== type) return false;
    if (!needle) return true;
    const haystack = `${topic.title} ${topic.snippet}`.toLowerCase();
    return haystack.includes(needle);
  });
}