// assets/src/sse.js — incremental SSE frame parsing for the streaming chat.

/**
 * Split a growing SSE buffer into decoded event objects plus the unparsed remainder.
 * @param {string} buffer Accumulated response text.
 * @returns {{events: object[], rest: string}}
 */
export function parseSseChunk(buffer) {
  const events = [];
  let rest = buffer;
  let idx;
  while ((idx = rest.indexOf('\n\n')) !== -1) {
    const frame = rest.slice(0, idx).trim();
    rest = rest.slice(idx + 2);
    if (!frame.startsWith('data:')) continue;
    try {
      events.push(JSON.parse(frame.slice(5).trim()));
    } catch {
      /* skip malformed frame */
    }
  }
  return { events, rest };
}
