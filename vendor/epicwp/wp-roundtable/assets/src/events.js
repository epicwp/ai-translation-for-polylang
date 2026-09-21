// assets/src/events.js — match backend TurnResult::steps() (tool_step + progress only).
import { collectSteps } from './steps.js';

/**
 * Fold a turn's ordered events into a view model.
 * @param {Array<{type:string,data:Object}>} events
 * @returns {{reply:string, steps:Array, outcome:Object|null, error:boolean}}
 */
export function eventsToTurn(events) {
  const turn = { reply: '', steps: [], outcome: null, error: false };
  const list = Array.isArray(events) ? events : [];
  // Tool activity indices ("Working on it" is a placeholder frame, not a real
  // step). An assistant_text block BETWEEN tool activity is interim research
  // narration and is dropped; the opener (before any tool) and the closing
  // answer (after the last tool) are kept, joined with a blank line.
  const isTool = (ev) =>
    ev.type === 'tool_step' || (ev.type === 'progress' && ev.data?.summary !== 'Working on it');
  const toolIndices = list.flatMap((ev, i) => (isTool(ev) ? [i] : []));
  const firstTool = toolIndices.length ? toolIndices[0] : Infinity;
  const lastTool = toolIndices.length ? toolIndices[toolIndices.length - 1] : -1;
  list.forEach((ev, i) => {
    if (ev.type === 'assistant_text' && typeof ev.data?.text === 'string') {
      if (i < firstTool || i > lastTool) {
        turn.reply = turn.reply ? `${turn.reply}\n\n${ev.data.text}` : ev.data.text;
      }
    } else if (ev.type === 'error') turn.error = true;
    else if (ev.type === 'result') {
      turn.outcome = ev.data || null;
      if (ev.data?.is_error) turn.error = true;
    }
  });
  turn.steps = collectSteps(list);
  return turn;
}