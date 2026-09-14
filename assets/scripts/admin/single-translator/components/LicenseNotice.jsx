/**
 * Pro-edition notice shown in place of the translator when the site has no
 * valid license. index.jsx mounts it behind `__PLLAT_EDITION__ === 'pro'`, so
 * the free bundle drops it.
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function LicenseNotice() {
	return (
		<Notice status="warning" isDismissible={false}>
			{__('A valid Pro license is required to use the single translator', 'polylang-ai-automatic-translation')}.{' '}
			<a href={window.pllat?.adminUrl + 'admin.php?page=pllat-settings&tab=license'} style={{ textDecoration: 'underline' }}>
				{__('Activate your license', 'polylang-ai-automatic-translation')}
			</a>
		</Notice>
	);
}

export default LicenseNotice;
