/** @jsx h */
import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { renderMarkdown } from './markdown.js';
import {
  streamMessage, sendMessage, resetChat, createDraft, publishCase, fetchHistory,
} from './api.js';
import { eventsToTurn } from './events.js';
import { canDraftTopic, turnsToConversation } from './conversation.js';
import { rehydratedTopicWorthy, rehydratedTurns } from './rehydrate.js';
import { PublishDialog } from './publish-dialog.jsx';
import { AgentAvatar } from './avatars.jsx';
import { agentDisplayName, betaEnabled, chatDisabled, initialMessage, projectDisplayName } from './config.js';
import { labelStep } from './steps.js';
import { CHAT_PAUSED_NOTICE, NEW_TOPIC_INTRO, resolveInitialMessage, TOPIC_CHIPS } from './chat-copy.js';

const STREAM_FIRST_EVENT_TIMEOUT_MS = 15000;

/**
 * Send one turn: start the stream, and fall back to the buffered endpoint if
 * onError fires before any stream event arrives, or nothing arrives within
 * `timeoutMs`. Once at least one event has streamed in, a later error just
 * ends the turn in an error state — the user already saw partial output, so
 * no fallback and no double-render.
 * @param {string} text
 * @param {{setTurns:Function, setBusy:Function, streamFn?:Function, sendFn?:Function, timeoutMs?:number, trigger?:string, onSignal?:(ev:object)=>void}} opts
 */
/**
 * Human-readable message for a failed turn, keyed by the error kind when known.
 * @param {string|undefined} kind
 * @returns {string}
 */
function errorText(kind) {
  if (kind === 'chat_disabled') {
    return CHAT_PAUSED_NOTICE;
  }
  if (kind === 'licence_invalid' || kind === 'http_402') {
    return 'Your product licence could not be verified. Check your licence in the plugin settings, then try again.';
  }
  if (kind === 'over_quota' || kind === 'http_429') {
    return "You've reached the usage limit for now. Please try again in a little while.";
  }
  return 'Something went wrong. Please try again.';
}

export async function sendTurn(text, {

  setTurns, setBusy, streamFn = streamMessage, sendFn = sendMessage, timeoutMs = STREAM_FIRST_EVENT_TIMEOUT_MS,
  trigger, onSignal,
}) {
  if (!trigger) {
    setTurns((t) => [...t, { role: 'user', reply: text, steps: [], error: false, at: Date.now() }]);
  }
  setTurns((t) => [...t, {
    role: 'agent', reply: '', steps: [], done: false, error: false, at: Date.now(),
  }]);
  setBusy(true);

  let streamed = false;
  const controller = new AbortController();
  const timer = setTimeout(() => { if (!streamed) controller.abort(); }, timeoutMs);

  async function fallback() {
    try {
      const res = await sendFn(text, trigger);
      if (res.error) {
        setTurns((t) => {
          const next = t.slice();
          const i = next.length - 1;
          next[i] = { ...next[i], error: true, errorKind: res.error.kind, done: true };
          return next;
        });
        return;
      }
      for (const ev of res.events || []) {
        if (ev.type === 'topic_worthy' || ev.type === 'topic_drafted') onSignal?.(ev);
      }
      const turn = eventsToTurn(res.events);
      setTurns((t) => {
        const next = t.slice();
        const i = next.length - 1;
        next[i] = {
          ...next[i],
          reply: turn.reply,
          steps: turn.steps.map((s) => ({ summary: labelStep(s) })),
          error: turn.error,
          done: true,
        };
        return next;
      });
    } catch (e) {
      setTurns((t) => {
        const next = t.slice();
        const i = next.length - 1;
        next[i] = { ...next[i], error: true, done: true };
        return next;
      });
    } finally {
      setBusy(false);
    }
  }

  await streamFn(text, {
    signal: controller.signal,
    trigger,
    onEvent: (ev) => {
      streamed = true;
      clearTimeout(timer);
      if (ev.type === 'topic_worthy' || ev.type === 'topic_drafted') { onSignal?.(ev); return; }
      const now = Date.now();
      setTurns((t) => applyStreamEvent(t, ev, now));
    },
    onError: async () => {
      clearTimeout(timer);
      if (streamed) {
        setTurns((t) => applyStreamEvent(t, { type: 'error' }, Date.now()));
        setBusy(false);
      } else {
        await fallback();
      }
    },
    onDone: () => {
      clearTimeout(timer);
      // Belt-and-suspenders: if the stream ended without a `result` event,
      // applyStreamEvent never got a chance to fall back to the captured
      // assistant_text blocks (e.g. a refusal). Reconcile here so the turn
      // still shows something instead of rendering empty.
      setTurns((t) => {
        if (!t.length) return t;
        const last = t[t.length - 1];
        const composed = composeFinal(last);
        if (composed && composed.length > (last.reply || '').length) {
          const next = t.slice();
          next[next.length - 1] = { ...last, reply: composed, done: true };
          return next;
        }
        return t;
      });
      setBusy(false);
    },
  });
}

/**
 * Compose kept text segments plus the still-open one into the rendered reply.
 * @param {Array<string>} kept Closed segments that survived (openers).
 * @param {{text:string}|null} curSeg The segment currently accumulating, if any.
 * @returns {string}
 */
function renderedReply(kept, curSeg) {
  return [...kept, curSeg ? curSeg.text : ''].filter(Boolean).join('\n\n');
}

/**
 * Compose the reply from a turn's complete `assistant_text` blocks, dropping
 * interim ones. A block is interim when real steps happened both before it
 * (`startStep > 0`) and after it (more steps than when it started) — that's
 * research narration, which the agent's identity bar bans from replies. The
 * opener (a block before any real step, e.g. an empathy acknowledgement) and
 * the closing answer are kept.
 * @param {{finalBlocks?:Array<{text:string,startStep:number}>, steps:Array}} turn
 * @returns {string}
 */
export function composeFinal(turn) {
  const total = visibleSteps(turn.steps).length;
  return (turn.finalBlocks || [])
    .filter((b) => b.startStep === 0 || b.startStep >= total)
    .map((b) => b.text)
    .filter(Boolean)
    .join('\n\n');
}

/**
 * Fold one streamed hub event onto the last (in-flight agent) turn.
 * guarded_text_delta accumulates into the current text segment (kept
 * accumulating even after `result` — the guard flushes a trailing delta).
 * A `progress` step closes the current segment: an opener (text streamed
 * before any real step) is kept, a middle segment (thinking-out-loud between
 * research steps) is dropped from the rendered reply. assistant_text blocks
 * are collected as `finalBlocks` without touching `reply`; on `result`, if no
 * deltas arrived (`reply` is empty) the same opener/closer composition of
 * those blocks becomes the reply — e.g. an off-topic refusal, or a turn the
 * model didn't stream partial-message deltas for.
 * @param {Array} turns
 * @param {{type:string, text?:string, summary?:string}} ev
 * @param {number} now current time (ms), used to stamp step/completion timestamps
 * @returns {Array} new turns array
 */
export function applyStreamEvent(turns, ev, now) {
  if (!turns.length) return turns;
  const i = turns.length - 1;
  const turn = turns[i];
  const kept = turn.keptSegs || [];
  let patch;
  if (ev.type === 'guarded_text_delta') {
    const curSeg = turn.curSeg
      ? { ...turn.curSeg, text: turn.curSeg.text + (ev.text || '') }
      : { text: ev.text || '', startStep: visibleSteps(turn.steps).length };
    patch = { curSeg, reply: renderedReply(kept, curSeg) };
  } else if (ev.type === 'assistant_text') {
    patch = {
      finalBlocks: [
        ...(turn.finalBlocks || []),
        { text: ev.text, startStep: visibleSteps(turn.steps).length },
      ],
    };
  } else if (ev.type === 'progress') {
    patch = { steps: [...turn.steps, { summary: ev.summary, at: now }] };
    if (turn.curSeg) {
      const keepIt = turn.curSeg.startStep === 0;
      patch.keptSegs = keepIt ? [...kept, turn.curSeg.text] : kept;
      patch.curSeg = null;
      patch.reply = renderedReply(patch.keptSegs, null);
    }
  } else if (ev.type === 'result') {
    const base = ev.is_error === true
      ? { done: true, error: true, doneAt: now }
      : { done: true, doneAt: now };
    const fallback = turn.reply === '' ? composeFinal(turn) : '';
    patch = fallback ? { ...base, reply: fallback } : base;
  } else if (ev.type === 'error') patch = { error: true, errorKind: ev.message };
  else return turns;
  const next = turns.slice();
  next[i] = { ...turn, ...patch };
  return next;
}

/**
 * Reduce one thread scroll event onto the stick-to-bottom state.
 *
 * Scroll events fire asynchronously, after paint: when streamed content grows
 * the thread between our own programmatic bottom-scroll and that scroll's
 * event, a naive "near the bottom?" recompute reads the already-grown height
 * and wrongly concludes the user scrolled away — freezing follow for the rest
 * of the turn. Programmatic scrolls are therefore counted (`pending`) and
 * their events consumed as "still sticking"; only genuinely user-initiated
 * scroll events recompute stickiness from the distance to the bottom.
 *
 * @param {{pending:number, stick:boolean}} state Current stick state.
 * @param {number} distanceFromBottom scrollHeight - scrollTop - clientHeight at event time.
 * @returns {{pending:number, stick:boolean}} The next state.
 */
export function reduceScrollEvent(state, distanceFromBottom) {
  if (state.pending > 0) return { pending: state.pending - 1, stick: true };
  return { pending: 0, stick: distanceFromBottom < 80 };
}

/**
 * Steps shown to the user: drops the "Working on it" placeholder frame, which
 * only exists to drive the immediate thinking indicator (see Bubble) and is
 * not a real step.
 * @param {Array<{summary:string, at?:number}>} steps
 * @returns {Array}
 */
export function visibleSteps(steps) {
  return (steps || []).filter((s) => s.summary !== 'Working on it');
}

/**
 * Format the time a step took: (next step's timestamp, or the turn's
 * completion timestamp) minus this step's timestamp.
 * @param {{at?:number}} step
 * @param {number} [nextAt]
 * @returns {string|null} e.g. "1.2s" or "80ms", or null if not measurable
 */
export function stepDuration(step, nextAt) {
  if (step?.at == null || nextAt == null) return null;
  const ms = nextAt - step.at;
  if (ms < 0) return null;
  return ms < 1000 ? `${Math.round(ms)}ms` : `${(ms / 1000).toFixed(1)}s`;
}

function TopicChips({ onSend, disabled }) {
  return (
    <div class="rt-chips">
      {TOPIC_CHIPS.map((c) => (
        <button key={c.id} type="button" class="rt-chip" disabled={disabled} onClick={() => onSend(c.message)}>
          {c.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Local HH:MM for a message timestamp.
 * @param {number} ms
 * @returns {string}
 */
function formatTime(ms) {
  return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function ThinkingDots() {
  return (
    <span class="rt-thinking" aria-hidden="true">
      <span class="rt-thinking-dot" />
      <span class="rt-thinking-dot" />
      <span class="rt-thinking-dot" />
    </span>
  );
}

// Steady typing pace, in characters/second — reads like fast, smooth typing.
const TYPEWRITER_BASE_CPS = 100;
// Catch-up window: the reveal rate speeds up so at most this many seconds of
// backlog is ever pending, so a big chunk landing speeds things up smoothly
// instead of snapping.
const TYPEWRITER_MAX_LAG_SECONDS = 0.7;

/**
 * Advance a "shown length" toward text.length using a requestAnimationFrame
 * delta-time accumulator, so the reveal pace is frame-rate independent and
 * doesn't jump on every streamed chunk. A turn that starts active (a real
 * streamed reply) animates from 0 and keeps animating — even after `active`
 * flips to false when the stream ends — until it catches up to the text, so
 * there's no snap-to-full at completion. A turn that starts inactive (the
 * greeting, a buffered-fallback reply) shows the full text immediately.
 * @param {string} text
 * @param {boolean} active
 * @returns {number} shown length
 */
function useTypewriter(text, active) {
  const animateRef = useRef(active);
  const [shown, setShown] = useState(() => (animateRef.current ? 0 : text.length));
  const shownRef = useRef(shown);
  const textRef = useRef(text);
  const rafRef = useRef(null);
  textRef.current = text;

  useEffect(() => {
    if (!animateRef.current) {
      // A non-animating bubble must always show its full text. When the panel
      // swaps the text on a reused component (the "+ New topic" reset replaces
      // the greeting in place), the stale `shown` from the previous text would
      // otherwise clip the new one mid-sentence.
      if (shownRef.current !== text.length) {
        shownRef.current = text.length;
        setShown(text.length);
      }
      return undefined;
    }
    if (rafRef.current != null || shownRef.current >= text.length) return undefined;

    let lastTime = null;
    const tick = (time) => {
      if (lastTime === null) lastTime = time;
      const dt = (time - lastTime) / 1000;
      lastTime = time;

      const total = textRef.current.length;
      const backlog = total - shownRef.current;
      const cps = Math.max(TYPEWRITER_BASE_CPS, backlog / TYPEWRITER_MAX_LAG_SECONDS);
      const next = Math.min(total, shownRef.current + cps * dt);
      shownRef.current = next;
      setShown(next);
      rafRef.current = next < textRef.current.length ? requestAnimationFrame(tick) : null;
    };
    rafRef.current = requestAnimationFrame(tick);

    return () => {
      if (rafRef.current != null) cancelAnimationFrame(rafRef.current);
      rafRef.current = null;
    };
  }, [text]);

  return Math.floor(Math.min(shown, text.length));
}

/**
 * The reply bubble content. Types out `turn.reply` character-by-character
 * for a turn that started streaming — and keeps typing it in until caught
 * up, even after the stream itself has finished (see useTypewriter); a turn
 * that never streamed renders the full text immediately.
 */
function TypedBubble({ turn, streaming }) {
  const shown = useTypewriter(turn.reply, streaming);
  const text = turn.reply.slice(0, shown);
  // A paused chat is not the user's fault: keep the calm agent-bubble look,
  // not the error styling.
  const paused = turn.errorKind === 'chat_disabled';
  return (
    <div class={'rt-ab' + (turn.error && !paused ? ' rt-ab-error' : '')}
         // eslint-disable-next-line react/no-danger
         dangerouslySetInnerHTML={{ __html: turn.error ? errorText(turn.errorKind) : renderMarkdown(text) }} />
  );
}

function Bubble({ turn, onChipSend, chipDisabled }) {
  if (turn.role === 'user') {
    return (
      <div class="rt-user-wrap">
        <div class="rt-user">{turn.reply}</div>
        {turn.at && <div class="rt-time">{formatTime(turn.at)}</div>}
      </div>
    );
  }
  const agentName = agentDisplayName();
  const steps = turn.steps || [];
  const vsteps = visibleSteps(steps);
  // Only the turn sendTurn is currently streaming carries done:false explicitly
  // (static turns — greeting, buffered-fallback replies, ... — leave done unset).
  const streaming = turn.done === false && !turn.error;
  const thinking = streaming && turn.reply === '';
  return (
    <div class="rt-agent-turn">
      <AgentAvatar size="sm" class="rt-agent-ava" />
      <div class="rt-agent-body">
        <span class="rt-name">{agentName}</span>
        {thinking ? (
          <div class="rt-ab rt-ab-thinking">
            <ThinkingDots />
          </div>
        ) : (
          <TypedBubble turn={turn} streaming={streaming} />
        )}
        {turn.introChips && <TopicChips disabled={chipDisabled} onSend={onChipSend} />}
        {vsteps.length > 0 && !turn.done && (
          <div class="rt-activity-live">⏺ {vsteps[vsteps.length - 1].summary}</div>
        )}
        {turn.done && vsteps.length > 0 && (
          <details class="rt-activity">
            <summary>Searched the code · {vsteps.length} {vsteps.length === 1 ? 'step' : 'steps'}</summary>
            <ul>
              {vsteps.map((s, i) => {
                const nextAt = i + 1 < vsteps.length ? vsteps[i + 1].at : turn.doneAt;
                const dur = stepDuration(s, nextAt);
                return <li key={i}>{dur ? `${s.summary} · ${dur}` : s.summary}</li>;
              })}
            </ul>
          </details>
        )}
        {turn.at && <div class="rt-time">{formatTime(turn.at)}</div>}
      </div>
    </div>
  );
}

function OutcomeCard({ topic, onReview, onDiscard, busy }) {
  return (
    <div class="rt-outcome">
      <div class="rt-outcome-icon">✓</div>
      <div class="rt-outcome-body">
        <b>Drafted a topic</b>
        <div class="rt-outcome-title">{topic.title}</div>
        <div class="rt-outcome-sub">Private for now — review &amp; publish, or discard.</div>
        <div class="rt-outcome-actions">
          <button type="button" class="rt-btn-pub-sm" disabled={busy} onClick={onReview}>Review &amp; publish</button>
          <button type="button" class="rt-btn-ghost" disabled={busy} onClick={onDiscard}>Discard</button>
        </div>
      </div>
    </div>
  );
}

function DraftPrompt({ busy, drafting, onDraft }) {
  return (
    <div class="rt-draft-prompt">
      <p>Ready to share this with the community?</p>
      <button type="button" class="rt-btn-pub-sm" disabled={busy || drafting} onClick={onDraft}>
        {drafting ? 'Drafting…' : 'Turn into topic'}
      </button>
    </div>
  );
}

export function Thread({
  turns, onChipSend, chipDisabled, threadRef, bottomRef,
}) {
  return (
    <div class="rt-thread" ref={threadRef}>
      {turns.map((t, i) => <Bubble key={i} turn={t} onChipSend={onChipSend} chipDisabled={chipDisabled} />)}
      <div ref={bottomRef} aria-hidden="true" />
    </div>
  );
}

/**
 * Pure reducer: fold one hub `topic_worthy`/`topic_drafted` signal onto
 * ChatPanel's ordinary-chat affordance state. `topic_worthy` flips the
 * "Create topic" affordance on; `topic_drafted` flips it back off and fills
 * the draft outcome. Any other event type is a no-op (state passed through
 * unchanged) — in practice `handleSignal` is only ever called with these two
 * types (see sendTurn's onEvent short-circuit).
 *
 * Reads both `ev.data?.*` and a bare `ev.*` fallback for the drafted fields,
 * because the SDK's two transports use different wire shapes for the same
 * hub event: streaming spreads the event's fields onto the top level, the
 * buffered fallback nests them under `data` (see sendTurn's fallback()).
 * @param {{topicWorthy:boolean, topicDraft:object|null}} state Prior affordance state.
 * @param {{type:string, data?:object, case_id?:string, title?:string, summary?:string, case_type?:string}} ev
 * @returns {{topicWorthy:boolean, topicDraft:object|null}} Next affordance state.
 */
export function reduceSignal(state, ev) {
  if (ev.type === 'topic_worthy') return { ...state, topicWorthy: true };
  if (ev.type === 'topic_drafted') {
    return {
      topicWorthy: false,
      topicDraft: {
        id: ev.data?.case_id ?? ev.case_id,
        title: ev.data?.title ?? ev.title,
        summary: ev.data?.summary ?? ev.summary,
        type: ev.data?.case_type ?? ev.case_type,
      },
    };
  }
  return state;
}

/**
 * Whether the "Create topic" / "Turn into topic" affordance (DraftPrompt)
 * should be visible. An existing draft always hides it. Otherwise, the
 * "+ New topic" flow (`newTopicMode`) keeps its pre-E12 client-only
 * `canDraftTopic()` heuristic over the visible turns, unchanged; ordinary
 * chat is instead gated by the hub-driven `topicWorthy` signal (see
 * `reduceSignal`).
 * @param {{topicDraft:object|null, newTopicMode:boolean, topicWorthy:boolean, turns:Array}} state
 * @returns {boolean}
 */
export function computeShowDraftPrompt({
  topicDraft, newTopicMode, topicWorthy, turns,
}) {
  return !topicDraft && (newTopicMode ? canDraftTopic(turns) : topicWorthy);
}

export function ChatPanel({ resetNonce = 0, onTopicPublished }) {
  // Config-driven pause: the hub's /project/config said the chat is off. Show
  // the calm notice as the only bubble and keep the composer inert.
  const paused = chatDisabled();
  const [newTopicMode, setNewTopicMode] = useState(false);
  const [turns, setTurns] = useState([paused
    ? { role: 'agent', reply: CHAT_PAUSED_NOTICE, steps: [], error: false }
    : {
      role: 'agent', reply: resolveInitialMessage(initialMessage(), agentDisplayName(), projectDisplayName()), steps: [], error: false, introChips: true,
    }]);
  const [composer, setComposer] = useState('');
  const [busy, setBusy] = useState(false);
  const [topicDraft, setTopicDraft] = useState(null);
  const [topicWorthy, setTopicWorthy] = useState(false);
  const [showPublish, setShowPublish] = useState(false);
  const [draftBusy, setDraftBusy] = useState(false);
  const composerRef = useRef(null);
  const threadRef = useRef(null);
  const stickToBottomRef = useRef({ pending: 0, stick: true });
  const bottomRef = useRef(null);
  const userInteractedRef = useRef(false);

  useEffect(() => {
    if (resetNonce > 0) newTopic();
  }, [resetNonce]);

  useEffect(() => {
    const el = composerRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  }, [composer]);

  // Track whether the user is parked near the bottom, via real scroll events
  // rather than recomputing from post-update scrollHeight (which would
  // already reflect newly streamed content and read as "far from bottom").
  // Programmatic bottom-scrolls are counted and consumed by reduceScrollEvent
  // so a delta rendering between our scroll and its (async) event can't read
  // as "the user scrolled away" and freeze follow mid-turn.
  useEffect(() => {
    const el = threadRef.current;
    if (!el) return undefined;
    const onScroll = () => {
      stickToBottomRef.current = reduceScrollEvent(
        stickToBottomRef.current,
        el.scrollHeight - el.scrollTop - el.clientHeight,
      );
    };
    // A wheel gesture is unambiguously the user: drop queued programmatic
    // consumptions so the scroll event that follows recomputes stickiness.
    const onWheel = () => {
      stickToBottomRef.current = { ...stickToBottomRef.current, pending: 0 };
    };
    el.addEventListener('scroll', onScroll);
    el.addEventListener('wheel', onWheel, { passive: true });
    return () => {
      el.removeEventListener('scroll', onScroll);
      el.removeEventListener('wheel', onWheel);
    };
  }, []);

  // Stick to the bottom as new turns/deltas arrive, unless the user scrolled
  // up to read history.
  useEffect(() => {
    const el = threadRef.current;
    if (!el || !stickToBottomRef.current.stick) return;
    const target = el.scrollHeight - el.clientHeight;
    if (target - el.scrollTop > 1) {
      stickToBottomRef.current = {
        ...stickToBottomRef.current,
        pending: stickToBottomRef.current.pending + 1,
      };
      el.scrollTop = target;
    }
    // The thread may not be the scrolling ancestor (e.g. the admin page body
    // scrolls instead); a sentinel scrollIntoView follows whichever it is.
    bottomRef.current?.scrollIntoView({ block: 'nearest' });
  }, [turns]);

  useEffect(() => {
    if (paused) return undefined;
    let cancelled = false;
    (async () => {
      const res = await fetchHistory();
      if (cancelled) return;
      if (userInteractedRef.current) return;
      setTurns((cur) => {
        const next = rehydratedTurns(cur[0], res);
        return next ?? cur;
      });
      if (rehydratedTopicWorthy(res)) setTopicWorthy(true);
    })();
    return () => { cancelled = true; };
  }, []);

  function handleSignal(ev) {
    const next = reduceSignal({ topicWorthy, topicDraft }, ev);
    setTopicWorthy(next.topicWorthy);
    setTopicDraft(next.topicDraft);
  }

  async function send(textOverride) {
    userInteractedRef.current = true;
    const text = (textOverride ?? composer).trim();
    if (!text || busy || draftBusy || paused) return;
    // Sending a message is an unambiguous "I want to see the reply": re-stick
    // regardless of any stale unstick from an earlier turn's scrolling.
    stickToBottomRef.current = { pending: 0, stick: true };
    setComposer('');
    await sendTurn(text, { setTurns, setBusy, onSignal: handleSignal });
  }

  async function newTopic() {
    userInteractedRef.current = true;
    if (busy || draftBusy || paused) return;
    stickToBottomRef.current = { pending: 0, stick: true };
    setBusy(true);
    const res = await resetChat();
    setBusy(false);
    if (res.error) {
      setTurns((t) => [...t, { role: 'agent', reply: '', steps: [], error: true }]);
      return;
    }
    setNewTopicMode(true);
    setTopicDraft(null);
    setTopicWorthy(false);
    setShowPublish(false);
    setTurns([{ role: 'agent', reply: NEW_TOPIC_INTRO, steps: [], error: false, introChips: true }]);
  }

  async function turnIntoTopic() {
    if (busy || draftBusy || topicDraft) return;
    const conversation = turnsToConversation(turns, agentDisplayName());
    if (!conversation) return;
    setDraftBusy(true);
    const res = await createDraft({ conversation });
    setDraftBusy(false);
    if (res.error) return;
    setTopicDraft(res.case);
  }

  async function createTopic() {
    if (busy || draftBusy || topicDraft) return;
    await sendTurn('', {
      setTurns, setBusy: setDraftBusy, trigger: 'create_topic', onSignal: handleSignal,
    });
  }

  async function confirmPublish({ title, summary, type }) {
    if (!topicDraft?.id || draftBusy) return;
    setDraftBusy(true);
    const res = await publishCase({ case_id: topicDraft.id, title, summary, type });
    setDraftBusy(false);
    if (res.error) return;
    setShowPublish(false);
    setTopicDraft(null);
    setTurns((t) => [...t, {
      role: 'agent',
      reply: `Submitted **${res.case.title}** for approval. You can track it under Started → Pending approval.`,
      steps: [],
      error: false,
    }]);
    onTopicPublished?.();
  }

  const agentName = agentDisplayName();
  const composerPlaceholder = newTopicMode ? 'Describe your topic…' : `Message ${agentName}…`;
  const showDraftPrompt = computeShowDraftPrompt({
    topicDraft, newTopicMode, topicWorthy, turns,
  });

  return (
    <div class="rt-panel">
      <div class="rt-head">
        <AgentAvatar class="rt-avatar" />
        <div class="rt-head-text">
          <b>{agentName}{betaEnabled() && <span class="rt-beta-pill">Beta</span>}</b>
          <span>Community assistant</span>
        </div>
        <span class="rt-head-grow" />
        <button class="rt-iconbtn" type="button" onClick={newTopic} title="New conversation" aria-label="New conversation">+</button>
      </div>
      <Thread turns={turns} chipDisabled={busy || draftBusy} onChipSend={(msg) => send(msg)} threadRef={threadRef} bottomRef={bottomRef} />
      {topicDraft && (
        <div class="rt-thread-extras">
          <OutcomeCard
            topic={topicDraft}
            busy={draftBusy}
            onReview={() => setShowPublish(true)}
            onDiscard={() => { setTopicDraft(null); setShowPublish(false); }}
          />
        </div>
      )}
      {showDraftPrompt && (
        <DraftPrompt busy={busy} drafting={draftBusy} onDraft={newTopicMode ? turnIntoTopic : createTopic} />
      )}
      <div class="rt-composer">
        <div class="rt-cbox">
          <textarea ref={composerRef} rows="1" placeholder={composerPlaceholder} value={composer} disabled={paused}
            onInput={(e) => setComposer(e.currentTarget.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }} />
          <button class="rt-send-icon" type="button" disabled={busy || draftBusy || paused} onClick={() => send()} aria-label="Send">➤</button>
        </div>
        <div class="rt-chint">Enter to send · Shift+Enter for a new line</div>
      </div>
      {showPublish && topicDraft && (
        <PublishDialog
          draft={topicDraft}
          busy={draftBusy}
          onCancel={() => setShowPublish(false)}
          onPublish={confirmPublish}
        />
      )}
    </div>
  );
}