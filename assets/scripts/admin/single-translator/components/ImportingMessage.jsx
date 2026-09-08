/**
 * Component for importing message.
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Importing message component.
 *
 * @returns {JSX.Element} The component
 */
export function ImportingMessage() {
	return (
		<Notice status="info" isDismissible={false}>
			{__('Translations are currently being imported. Please wait...', 'polylang-ai-automatic-translation')}
		</Notice>
	);
}

export default ImportingMessage;
