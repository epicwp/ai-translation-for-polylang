/**
 * The greeting shown when the user opens the chat while browsing the community.
 * @param {string} agentName   The configured assistant name.
 * @param {string} projectName The configured product name; '' falls back to generic copy.
 * @returns {string}
 */
export function chatGreeting(agentName, projectName) {
  const subject = projectName ? `how ${projectName} works` : 'how it works';
  return `Hi — I'm ${agentName}. Ask ${subject}, report something off, or suggest an improvement. `
    + "If it's worth tracking, I'll draft a topic for you.";
}

/**
 * Resolve the chat's opening message: the vendor's configured initial message when
 * set, otherwise the generated greeting.
 * @param {string} initialMessage The configured initial message; '' when unset.
 * @param {string} agentName      The configured assistant name.
 * @param {string} projectName    The configured product name; '' falls back to generic copy.
 * @returns {string}
 */
export function resolveInitialMessage(initialMessage, agentName, projectName) {
  return initialMessage || chatGreeting(agentName, projectName);
}

/** Calm notice shown while the community chat is paused hub-side. */
export const CHAT_PAUSED_NOTICE = 'The community chat is temporarily paused.';

/** Opening copy when the user starts a new topic. */
export const NEW_TOPIC_INTRO =
  "Let's start a new topic. Tell me what's going on — a question, something that's broken, or a feature you'd like — and I'll shape it into a clear topic you can review & publish to the community.";

/** Quick-start chips under the intro message; click sends immediately. */
export const TOPIC_CHIPS = [
  { id: 'bug', label: 'Report a bug', message: "I'd like to report a bug" },
  { id: 'feature', label: 'Request a feature', message: "I'd like to request a feature" },
  { id: 'question', label: 'Ask a question', message: 'I have a question' },
];