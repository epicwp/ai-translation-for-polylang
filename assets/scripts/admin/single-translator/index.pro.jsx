/**
 * Entry point for the Single Translator React app (pro edition).
 */

import { render } from '@wordpress/element';
import SingleTranslator from './components/SingleTranslator';
import InternalLinksPanel from './components/InternalLinksPanel';
import TranslatorDisabledNotice from './components/TranslatorDisabledNotice';
import { mount } from './mount';

mount((container) => {
	// While the pro edition has not enabled the single translator its routes
	// answer 403, so the translator (which fetches its status on mount) never
	// mounts and the notice takes its place. See #532.
	if (!window.pllat?.singleTranslatorEnabled) {
		render(<TranslatorDisabledNotice />, container);
		return;
	}

	render(<SingleTranslator extraPanel={<InternalLinksPanel />} />, container);
});
