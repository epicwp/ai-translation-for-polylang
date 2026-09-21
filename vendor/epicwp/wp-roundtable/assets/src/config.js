// assets/src/config.js — the names localized by src/Admin/Page.php.

/** @returns {{agentName?:string, projectName?:string, initialMessage?:string, beta?:boolean, chatDisabled?:boolean, chatEnabled?:boolean, attribution?:string|null}} */
function cfg() {
  return (typeof window !== 'undefined' && window.RoundtableConfig) || {};
}

/**
 * The assistant's display name, as configured by the consuming plugin.
 * @returns {string}
 */
export function agentDisplayName() {
  return cfg().agentName || 'Roundtable';
}

/**
 * The consuming product's display name, or '' when it is not configured.
 * @returns {string}
 */
export function projectDisplayName() {
  return cfg().projectName || '';
}

/**
 * The vendor's configured initial chat message, or '' when it is not configured.
 * @returns {string}
 */
export function initialMessage() {
  return cfg().initialMessage || '';
}

/**
 * Read a boolean config flag. wp_localize_script stringifies scalars (true
 * becomes "1", false becomes ""), so a strict === true check silently fails
 * against a real WordPress payload; accept the stringified truthy forms too.
 * @param {unknown} value
 * @returns {boolean}
 */
function flag(value) {
  return value === true || value === 1 || value === '1' || value === 'true';
}

/**
 * Whether the consuming plugin flagged this integration as beta ("Beta" pill in the UI).
 * @returns {boolean}
 */
export function betaEnabled() {
  return flag(cfg().beta);
}

/**
 * Whether the hub reported the community chat as paused for this project.
 * @returns {boolean}
 */
export function chatDisabled() {
  return flag(cfg().chatDisabled);
}

/**
 * Whether the AI chat is enabled for this install (boot flag from Task 3's PHP
 * plumbing). False (the default) hides all chat UI and gives the topics feed the
 * full width of the container.
 * @returns {boolean}
 */
export function chatEnabled() {
  return flag(cfg().chatEnabled);
}

/**
 * The host plugin's optional one-line attribution, shown under the page
 * header, or '' when it is not configured. The SDK never supplies its own
 * text here — this is whatever the host plugin's `Config::$attribution` is.
 * @returns {string}
 */
export function attribution() {
  return cfg().attribution || '';
}
