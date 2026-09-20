/**
 * Pro-edition notice shown in place of the translator when the site has no
 * valid license. The pro entry (index.pro.jsx) mounts it instead of the
 * translator (#532).
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function TranslatorDisabledNotice() {
	return (
		<Notice status="warning" isDismissible={false}>
			{__('A valid Pro license is required to use the single translator', 'polylang-ai-automatic-translation')}.{' '}
			<a href={window.pllat?.adminUrl + 'admin.php?page=pllat-settings&tab=license'} style={{ textDecoration: 'underline' }}>
				{__('Activate your license', 'polylang-ai-automatic-translation')}
			</a>
		</Notice>
	);
}

export default TranslatorDisabledNotice;
