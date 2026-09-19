/**
 * Entry point for Single Translator React app.
 */

import { render } from '@wordpress/element';
import SingleTranslator from './components/SingleTranslator';

// Pro-only module, required inside the compile-time branch so the free build
// never resolves it and the free zip ships the sources without it.
const TranslatorDisabledNotice = __PLLAT_EDITION__ === 'pro' ? require('./components/TranslatorDisabledNotice').default : null;

/**
 * Initialize the Single Translator app.
 */
function initSingleTranslator() {
	const container = document.getElementById('pllat-single-translator-root');

	if (!container) {
		return;
	}

	// Compile-time edition branch: while the pro edition has not enabled the
	// single translator its routes answer 403, so the translator (which fetches
	// its status on mount) never mounts and the notice takes its place. See #532.
	if (__PLLAT_EDITION__ === 'pro' && !window.pllat?.singleTranslatorEnabled) {
		render(<TranslatorDisabledNotice />, container);
		return;
	}

	// Render React app
	render(<SingleTranslator />, container);
}

// Try to initialize immediately (in case DOM is already loaded)
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initSingleTranslator);
} else {
	// DOM already loaded
	initSingleTranslator();
}
