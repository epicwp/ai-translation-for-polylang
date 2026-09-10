/**
 * Free-edition notice on posts whose layout a page builder owns: the layout
 * is copied as-is, translating it in place is Pro. SingleTranslator renders
 * it behind `__PLLAT_EDITION__ !== 'pro'`, so the pro bundle drops it.
 */

import { useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { markAsDismissed, isDismissed } from '../utils/dismissedNotifications';

const BUILDER_NAMES = { elementor: 'Elementor', bricks: 'Bricks' };

// Outside the `pllat_notification_` prefix: cleanupExpired() wipes those after an hour.
const NOTIFICATION_ID = 'pllat_builder_notice';
const DISMISS_TTL = 30 * 24 * 60 * 60 * 1000;

export function BuilderNotice() {
	const builder = window.pllatSingleTranslator?.builder;
	const [dismissed, setDismissed] = useState(() => isDismissed(NOTIFICATION_ID, DISMISS_TTL));

	if (!BUILDER_NAMES[builder] || dismissed) {
		return null;
	}

	const handleDismiss = () => {
		markAsDismissed(NOTIFICATION_ID);
		setDismissed(true);
	};

	return (
		<div style={{ marginBottom: '15px' }}>
			<Notice status="info" isDismissible={true} onRemove={handleDismiss}>
				{sprintf(
					/* translators: %s: page builder name (Elementor or Bricks) */
					__('This page is built with %s. The free edition copies the layout into the translation unchanged; translating %s content in place is a Pro feature.', 'polylang-ai-automatic-translation'),
					BUILDER_NAMES[builder],
					BUILDER_NAMES[builder],
				)}{' '}
				<a href={window.pllatSingleTranslator?.upgradeUrl} target="_blank" rel="noopener noreferrer" style={{ textDecoration: 'underline' }}>
					{__('Learn more', 'polylang-ai-automatic-translation')}
				</a>
			</Notice>
		</div>
	);
}

export default BuilderNotice;
