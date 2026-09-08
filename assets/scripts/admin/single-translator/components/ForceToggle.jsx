/**
 * Component for force re-translation toggle.
 */

import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Force toggle component.
 *
 * @param {Object} props - Component props
 * @param {boolean} props.value - Force value
 * @param {Function} props.onChange - Callback when force changes
 * @param {boolean} props.disabled - Whether toggle is disabled
 * @returns {JSX.Element} The component
 */
export function ForceToggle({ value, onChange, disabled }) {
	return (
		<div className="pllat-force-toggle" style={{ marginBottom: '15px' }}>
			<CheckboxControl
				label={__('Force re-translation', 'polylang-ai-automatic-translation')}
				help={__('Re-translate even if a translation already exists.', 'polylang-ai-automatic-translation')}
				checked={value}
				onChange={onChange}
				disabled={disabled}
			/>
		</div>
	);
}

export default ForceToggle;
