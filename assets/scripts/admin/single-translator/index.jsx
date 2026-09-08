/**
 * Entry point for Single Translator React app.
 */

import { render } from '@wordpress/element';
import SingleTranslator from './components/SingleTranslator';

/**
 * Initialize the Single Translator app.
 */
function initSingleTranslator() {
	const container = document.getElementById('pllat-single-translator-root');

	if (!container) {
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
