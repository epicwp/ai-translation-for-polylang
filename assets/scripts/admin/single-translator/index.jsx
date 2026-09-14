/**
 * Entry point for Single Translator React app.
 */

import { render } from '@wordpress/element';
import SingleTranslator from './components/SingleTranslator';
import LicenseNotice from './components/LicenseNotice';

/**
 * Initialize the Single Translator app.
 */
function initSingleTranslator() {
	const container = document.getElementById('pllat-single-translator-root');

	if (!container) {
		return;
	}

	// Compile-time edition branch: without a valid license the single-translator
	// routes answer 403, so the translator (which fetches its status on mount)
	// never mounts and the notice takes its place. See #532.
	if (__PLLAT_EDITION__ === 'pro' && !window.pllat?.singleTranslatorEnabled) {
		render(<LicenseNotice />, container);
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
