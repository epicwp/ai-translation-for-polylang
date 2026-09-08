/**
 * Component for recovery banner when errors are detected on mount.
 */

import { useState } from '@wordpress/element';
import { Notice, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { markAsDismissed, isDismissed } from '../utils/dismissedNotifications';

/**
 * Error banner component.
 *
 * @param {Object} props - Component props
 * @param {Array} props.languages - Languages with status
 * @param {Function} props.onDismiss - Callback when dismissed
 * @returns {JSX.Element|null} The component
 */
export function ErrorBanner({ languages, onDismiss }) {
	const [dismissed, setDismissed] = useState(false);

	// Find languages with errors
	const errorLanguages = languages.filter((lang) => {
		return lang.status === 'failed' || lang.status === 'completed_with_errors';
	});

	if (errorLanguages.length === 0 || dismissed) {
		return null;
	}

	// Generate stable notification ID based on failed job IDs
	// This ensures dismissal persists until the job IDs change (new translation attempt)
	const { id: contentId } = window.pllatSingleTranslator;
	const failedJobIds = errorLanguages.map(lang => lang.job_id).filter(Boolean).sort().join('_');
	const notificationId = `pllat_notification_error_${contentId}_${failedJobIds}`;

	// Check if already dismissed
	if (isDismissed(notificationId)) {
		return null;
	}

	/**
	 * Handle dismiss.
	 */
	const handleDismiss = () => {
		markAsDismissed(notificationId);
		setDismissed(true);
	};

	/**
	 * Handle refresh.
	 */
	const handleRefresh = () => {
		handleDismiss();
		if (onDismiss) {
			onDismiss();
		}
	};

	const errorCount = errorLanguages.length;
	const message =
		errorCount === 1
			? __('A translation error was detected. Please check the error details below.', 'polylang-ai-automatic-translation')
			: // translators: %d is the number of translation errors
			  __('%d translation errors were detected. Please check the error details below.', 'polylang-ai-automatic-translation').replace(
					'%d',
					errorCount,
			  );

	return (
		<Notice status="error" isDismissible={true} onRemove={handleDismiss} className="pllat-error-banner">
			<div>
				<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
					<span>{message}</span>
					<Button variant="secondary" onClick={handleRefresh} style={{ marginLeft: '10px' }}>
						{__('Refresh Status', 'polylang-ai-automatic-translation')}
					</Button>
				</div>

				{/* Show error details for each failed language */}
				{errorLanguages.length > 0 && (
					<div style={{ marginTop: '12px', paddingTop: '12px', borderTop: '1px solid #ddd' }}>
						{errorLanguages.map((lang) => (
							<div key={lang.language} style={{ marginBottom: '8px' }}>
								<strong>{lang.language_name}:</strong>{' '}
								<span style={{ fontStyle: 'italic', color: '#646970' }}>
									{lang.first_error || __('Unknown error', 'polylang-ai-automatic-translation')}
								</span>
							</div>
						))}
					</div>
				)}
			</div>
		</Notice>
	);
}

export default ErrorBanner;
