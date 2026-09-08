/**
 * Component for custom AI instructions input.
 */

import { TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Instructions input component.
 *
 * @param {Object} props - Component props
 * @param {string} props.value - Instructions value
 * @param {Function} props.onChange - Callback when instructions change
 * @param {boolean} props.disabled - Whether input is disabled
 * @returns {JSX.Element} The component
 */
export function InstructionsInput({ value, onChange, disabled }) {
	return (
		<div className="pllat-instructions-input" style={{ marginBottom: '15px' }}>
			<TextareaControl
				label={__('Custom AI Instructions (optional)', 'polylang-ai-automatic-translation')}
				help={__('Provide specific instructions for the AI translator, such as tone, style, or terminology preferences.', 'polylang-ai-automatic-translation')}
				value={value}
				onChange={onChange}
				disabled={disabled}
				rows={3}
			/>
		</div>
	);
}

export default InstructionsInput;
