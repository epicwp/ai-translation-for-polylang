/**
 * Build a hub-ready conversation transcript from chat turns.
 * @param {Array<{role:string, reply:string}>} turns
 * @param {string} agentName The configured assistant name, used to label its lines.
 * @returns {string}
 */
export function turnsToConversation(turns, agentName = 'Roundtable') {
  const lines = [];
  for (const turn of Array.isArray(turns) ? turns : []) {
    if (!turn?.reply?.trim()) continue;
    const who = turn.role === 'user' ? 'User' : agentName;
    lines.push(`${who}: ${turn.reply.trim()}`);
  }
  return lines.join('\n');
}

/**
 * Whether the thread has enough content to draft a case.
 * @param {Array<{role:string, reply:string}>} turns
 * @returns {boolean}
 */
export function canDraftTopic(turns) {
  const list = Array.isArray(turns) ? turns : [];
  let seenUser = false;
  for (const t of list) {
    if (t.role === 'user' && t.reply?.trim()) seenUser = true;
    else if (seenUser && t.role === 'agent' && t.reply?.trim() && !t.error) return true;
  }
  return false;
}