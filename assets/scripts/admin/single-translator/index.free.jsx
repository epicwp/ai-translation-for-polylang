/**
 * Entry point for the Single Translator React app (free edition).
 */

import { render } from '@wordpress/element';
import SingleTranslator from './components/SingleTranslator';
import BuilderNotice from './components/BuilderNotice';
import { mount } from './mount';

mount((container) => {
	render(<SingleTranslator notice={<BuilderNotice />} />, container);
});
