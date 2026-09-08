/**
 * Component for toggling exclusion status.
 */

import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Exclusion toggle component.
 *
 * @param {Object} props - Component props
 * @param {boolean} props.excluded - Whether item is excluded
 * @param {Function} props.onChange - Callback when exclusion changes
 * @param {boolean} props.loading - Whether action is loading
 * @returns {JSX.Element} The component
 */
export function ExclusionToggle({ excluded, onChange, loading }) {
	return (
		<div className="pllat-exclusion-toggle" style={{ marginBottom: '15px' }}>
			<ToggleControl
				label={__('Exclude from automatic translation', 'polylang-ai-automatic-translation')}
				help={
					excluded
						? __('This item will not be automatically translated.', 'polylang-ai-automatic-translation')
						: __('This item can be automatically translated.', 'polylang-ai-automatic-translation')
				}
				checked={excluded}
				onChange={onChange}
				disabled={loading}
			/>
		</div>
	);
}

export default ExclusionToggle;
