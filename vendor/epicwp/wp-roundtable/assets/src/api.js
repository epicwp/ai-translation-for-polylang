// assets/src/api.js
import { parseSseChunk } from './sse.js';

function cfg() {
  return window.RoundtableConfig || { restUrl: '', nonce: '' };
}

function restBase() {
  return cfg().restUrl.replace(/\/$/, '');
}

async function parseJson(res) {
  const json = await res.json().catch(() => ({ error: { kind: 'bad_response' } }));
  if (!res.ok && !json.error) return { error: { kind: 'http_' + res.status } };
  return json;
}

async function post(path, body) {
  const res = await fetch(restBase() + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg().nonce },
    body: JSON.stringify(body || {}),
  });
  return parseJson(res);
}

async function del(path) {
  const res = await fetch(restBase() + path, {
    method: 'DELETE',
    headers: { 'X-WP-Nonce': cfg().nonce },
  });
  return parseJson(res);
}

async function get(path, query) {
  const qs = new URLSearchParams();
  if (query) {
    Object.entries(query).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
  }
  const suffix = qs.toString() ? '?' + qs.toString() : '';
  const res = await fetch(restBase() + path + suffix, {
    headers: { 'X-WP-Nonce': cfg().nonce },
  });
  return parseJson(res);
}

/**
 * Send one chat message.
 * @param {string} text
 * @param {string} [trigger] E12: 'create_topic' for the "Create topic" click; omitted for an
 *   ordinary message.
 * @returns {Promise<{events:Array}|{error:{kind:string}}>}
 */
export function sendMessage(text, trigger) {
  return post('/message', trigger ? { message: text, trigger } : { message: text });
}

/** Start a fresh chat (clears server-side session). */
export function resetChat() {
  return post('/reset', {});
}

/**
 * Fetch the current chat's stored history for rehydrate.
 * @returns {Promise<{events:Array}|{error:{kind:string}}>}
 */
export function fetchHistory() {
  return get('/history');
}

/**
 * Stream a chat turn. Calls onEvent per decoded event; onError on failure; onDone at end.
 * @param {string} text
 * @param {{onEvent:(e:object)=>void, onError:()=>void, onDone:()=>void, signal?:AbortSignal, trigger?:string}} handlers
 */
export async function streamMessage(text, {
  onEvent, onError, onDone, signal, trigger,
}) {
  let res;
  try {
    res = await fetch(restBase() + '/message/stream', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg().nonce },
      body: JSON.stringify(trigger ? { message: text, trigger } : { message: text }),
      signal,
    });
  } catch { onError(); return; }
  if (!res.ok || !res.body) { onError(); return; }
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const parsed = parseSseChunk(buffer);
      buffer = parsed.rest;
      for (const ev of parsed.events) onEvent(ev);
    }
    onDone();
  } catch { onError(); }
}

/**
 * List public topics (hub cases).
 * @param {{type?:string,q?:string,sort?:string,limit?:number,offset?:number}} params
 * @returns {Promise<{cases:Array}|{error:{kind:string}}>}
 */
export function fetchCases(params) {
  return get('/cases', params);
}

/**
 * List topics started by the current user (drafts + published).
 * @returns {Promise<{cases:Array}|{error:{kind:string}}>}
 */
export function fetchMyCases() {
  return get('/cases/mine');
}

/**
 * List topics the current user commented on or voted on.
 * @returns {Promise<{cases:Array}|{error:{kind:string}}>}
 */
export function fetchParticipatingCases() {
  return get('/cases/participating');
}

/**
 * Fetch the vote tally for a public topic, including the current user's vote.
 * @param {string} caseId
 * @returns {Promise<{tally:object}|{error:{kind:string}}>}
 */
export function fetchVoteTally(caseId) {
  return get('/cases/' + encodeURIComponent(caseId) + '/votes');
}

/**
 * Cast or change a vote on a public topic.
 * @param {string} caseId
 * @param {1|-1} value
 * @returns {Promise<{tally:object}|{error:{kind:string}}>}
 */
export function castVote(caseId, value) {
  return post('/cases/' + encodeURIComponent(caseId) + '/votes', { value });
}

/**
 * Retract the current user's vote on a public topic.
 * @param {string} caseId
 * @returns {Promise<{tally:object}|{error:{kind:string}}>}
 */
export function retractVote(caseId) {
  return del('/cases/' + encodeURIComponent(caseId) + '/votes');
}

/**
 * Draft a Case from the chat transcript.
 * @param {{conversation:string, title?:string, summary?:string, type?:string}} body
 */
export function createDraft(body) {
  return post('/case', body);
}

/**
 * Publish a drafted Case.
 * @param {{case_id:string, title?:string, summary?:string}} body
 */
export function publishCase(body) {
  return post('/publish', body);
}

/**
 * Submit a topic directly to hub moderation, bypassing chat/AI drafting.
 * @param {{type:string, title:string, body:string}} payload
 * @returns {Promise<{case:object}|{error:{kind:string}}>}
 */
export function submitTopic(payload) {
  return post('/topics', payload);
}

/**
 * List comments on a public topic.
 * @param {string} caseId
 * @returns {Promise<{comments:Array}|{error:{kind:string}}>}
 */
export function fetchComments(caseId) {
  return get('/cases/' + encodeURIComponent(caseId) + '/comments');
}

/**
 * Post a comment on a public topic.
 * @param {string} caseId
 * @param {string} body HTML comment body (the proxy converts it to markdown server-side)
 * @returns {Promise<{comment:object}|{error:{kind:string}}>}
 */
export function postComment(caseId, body) {
  return post('/cases/' + encodeURIComponent(caseId) + '/comments', { body });
}
