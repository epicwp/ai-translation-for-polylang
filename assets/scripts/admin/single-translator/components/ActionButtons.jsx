/**
 * Component for action buttons.
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Action buttons component.
 *
 * @param {Object} props - Component props
 * @param {Function} props.onTranslate - Callback when translate is clicked
 * @param {Function} props.onCancel - Callback when cancel is clicked
 * @param {boolean} props.disabled - Whether translate button is disabled
 * @param {boolean} props.canCancel - Whether cancel button should be shown
 * @param {boolean} props.loading - Whether action is loading
 * @param {boolean} props.isProcessing - Whether translations are currently processing
 * @returns {JSX.Element} The component
 */
export function ActionButtons({ onTranslate, onCancel, disabled, canCancel, loading, isProcessing }) {
	const buttonText = isProcessing
		? __('Processing...', 'polylang-ai-automatic-translation')
		: __('Start Translation', 'polylang-ai-automatic-translation');

	return (
		<div className="pllat-action-buttons" style={{ display: 'flex', gap: '10px' }}>
			<Button variant="primary" onClick={onTranslate} disabled={disabled} isBusy={loading}>
				{buttonText}
			</Button>
			{canCancel && (
				<Button variant="secondary" isDestructive onClick={onCancel} disabled={loading}>
					{__('Cancel Translation', 'polylang-ai-automatic-translation')}
				</Button>
			)}
		</div>
	);
}

export default ActionButtons;
