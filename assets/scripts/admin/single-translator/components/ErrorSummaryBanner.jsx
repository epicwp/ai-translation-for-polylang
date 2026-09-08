/**
 * Component for error summary banner with expandable task details.
 */

import { useState } from '@wordpress/element';
import { Notice, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { markAsDismissed, isDismissed } from '../utils/dismissedNotifications';
import TaskDetailsModal from './TaskDetailsModal';

/**
 * Error summary banner component.
 *
 * @param {Object} props - Component props
 * @param {string} props.language - Language code
 * @param {string} props.languageName - Language name
 * @param {string} props.errorSummary - Error summary message
 * @param {number} props.jobId - Job ID for fetching tasks
 * @param {Function} props.onDismiss - Callback when dismissed
 * @returns {JSX.Element|null} The component
 */
export function ErrorSummaryBanner({ language, languageName, errorSummary, jobId, onDismiss }) {
	const [dismissed, setDismissed] = useState(false);
	const [showTaskDetails, setShowTaskDetails] = useState(false);

	const { id: contentId } = window.pllatSingleTranslator;

	// Generate notification ID (unique per language and timestamp)
	const notificationId = `pllat_notification_error_${contentId}_${language}_${Date.now()}`;

	// Check if already dismissed
	if (isDismissed(notificationId) || dismissed) {
		return null;
	}

	/**
	 * Handle dismiss.
	 */
	const handleDismiss = () => {
		markAsDismissed(notificationId);
		setDismissed(true);

		if (onDismiss) {
			onDismiss();
		}
	};

	/**
	 * Handle view details.
	 */
	const handleViewDetails = () => {
		setShowTaskDetails(true);
	};

	return (
		<>
			<Notice status="error" isDismissible={true} onRemove={handleDismiss} className="pllat-error-summary-banner">
				<div>
					<strong>
						{languageName}: {errorSummary}
					</strong>
					<div style={{ marginTop: '8px' }}>
						<Button variant="secondary" onClick={handleViewDetails} size="small">
							{__('View Error Details', 'polylang-ai-automatic-translation')}
						</Button>
					</div>
				</div>
			</Notice>

			{showTaskDetails && <TaskDetailsModal jobId={jobId} languageName={languageName} onClose={() => setShowTaskDetails(false)} />}
		</>
	);
}

export default ErrorSummaryBanner;
