/** Step labels aligned with backend TurnResult::steps() (tool_step + progress). */
const STEP_TYPES = new Set(['tool_step', 'progress']);

/**
 * @param {{type:string,data?:Object}} ev
 * @returns {string}
 */
export function labelStep(ev) {
  if (!ev?.type) return 'Working…';
  if (ev.type === 'progress') return ev.data?.summary || ev.data?.text || 'Working…';
  if (ev.type === 'tool_step') return ev.data?.summary || ev.data?.name || 'Working…';
  return 'Working…';
}

/**
 * @param {Array<{type:string,data?:Object}>} events
 * @returns {Array<{type:string,data?:Object}>}
 */
export function collectSteps(events) {
  return (Array.isArray(events) ? events : []).filter((ev) => STEP_TYPES.has(ev.type));
}