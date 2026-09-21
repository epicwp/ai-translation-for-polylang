/** @jsx h */
import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { castVote, fetchVoteTally, retractVote } from './api.js';

/**
 * Interactive up/down vote control for a public topic.
 * @param {{caseId:string, net:number, onChange?:(tally:object)=>void}} props
 */
export function VoteControl({ caseId, net: initialNet, onChange }) {
  const [net, setNet] = useState(initialNet);
  const [myVote, setMyVote] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setNet(initialNet);
    setError(false);
    (async () => {
      const res = await fetchVoteTally(caseId);
      if (cancelled) return;
      if (res.tally) {
        setNet(res.tally.net);
        setMyVote(res.tally.my_vote ?? null);
        return;
      }
      setMyVote(null);
    })();
    return () => { cancelled = true; };
  }, [caseId, initialNet]);

  async function apply(value) {
    if (busy) return;
    setBusy(true);
    setError(false);
    const res = myVote === value
      ? await retractVote(caseId)
      : await castVote(caseId, value);
    setBusy(false);
    if (res.error || !res.tally) {
      setError(true);
      return;
    }
    setNet(res.tally.net);
    setMyVote(res.tally.my_vote ?? null);
    onChange && onChange(res.tally);
  }

  return (
    <div
      class="rt-vote"
      onClick={(e) => e.stopPropagation()}
      onKeyDown={(e) => e.stopPropagation()}
    >
      <button
        type="button"
        class={'rt-vote-btn' + (myVote === 1 ? ' on-up' : '')}
        disabled={busy}
        aria-label="Upvote"
        aria-pressed={myVote === 1}
        onClick={() => apply(1)}
      >▲</button>
      <span class="rt-vote-n" title={error ? 'Vote failed' : undefined}>{net}</span>
      <button
        type="button"
        class={'rt-vote-btn' + (myVote === -1 ? ' on-dn' : '')}
        disabled={busy}
        aria-label="Downvote"
        aria-pressed={myVote === -1}
        onClick={() => apply(-1)}
      >▼</button>
    </div>
  );
}

/**
 * Same up/down/count visual as VoteControl, but fully inert: disabled
 * buttons, no click handlers, and no fetchVoteTally() call. Used in
 * VoteControl's place for a case the hub won't accept votes on (pending
 * review), so pending rows look structurally identical to published ones —
 * just non-interactive — instead of a bare number.
 * @param {{net:number}} props
 */
export function DisabledVote({ net }) {
  return (
    <div class="rt-vote">
      <button type="button" class="rt-vote-btn" disabled aria-disabled="true" aria-label="Upvote">▲</button>
      <span class="rt-vote-n">{net}</span>
      <button type="button" class="rt-vote-btn" disabled aria-disabled="true" aria-label="Downvote">▼</button>
    </div>
  );
}