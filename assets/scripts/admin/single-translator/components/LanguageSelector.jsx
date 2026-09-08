/**
 * Component for language selection.
 */

import { __ } from '@wordpress/i18n';
import LanguageCard from './LanguageCard';

/**
 * Language selector component.
 *
 * @param {Object} props - Component props
 * @param {Array} props.languages - Available languages with status
 * @param {Array<string>} props.selected - Selected language codes
 * @param {Function} props.onChange - Callback when selection changes
 * @param {boolean} props.disabled - Whether selector is disabled
 * @param {Array<string>} props.runningLanguages - Languages currently being translated
 * @returns {JSX.Element} The component
 */
export function LanguageSelector({
	languages,
	selected,
	onChange,
	disabled,
	runningLanguages = []
}) {
	const enabled = window.pllat?.singleTranslatorEnabled || false;
	const maxLanguages = enabled ? Infinity : 3;

	/**
	 * Toggle language selection.
	 *
	 * @param {string} langCode - Language code to toggle
	 */
	const toggleLanguage = (langCode) => {
		if (disabled) {
			return;
		}

		if (selected.includes(langCode)) {
			onChange(selected.filter((code) => code !== langCode));
		} else {
			onChange([...selected, langCode]);
		}
	};

	// Show all languages (no filtering) so users can see inline progress
	if (languages.length === 0) {
		return (
			<div className="pllat-language-selector" style={{ marginBottom: '15px' }}>
				<p>{__('No languages available for translation.', 'polylang-ai-automatic-translation')}</p>
			</div>
		);
	}

	return (
		<div className="pllat-language-selector" style={{ marginBottom: '15px' }}>
			<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
				<label className="components-base-control__label" style={{ fontWeight: '600', marginBottom: 0 }}>
					{__('Select target languages', 'polylang-ai-automatic-translation')}
				</label>

				{/* Select All / Deselect All */}
				<div style={{ display: 'flex', gap: '8px' }}>
					<button
						type="button"
						className="button button-small"
						onClick={() => {
							const selectableLanguages = languages
								.filter(lang => !runningLanguages.includes(lang.language))
								.map(lang => lang.language);
							onChange(selectableLanguages);
						}}
						disabled={disabled || languages.every(lang =>
							selected.includes(lang.language) || runningLanguages.includes(lang.language)
						)}
					>
						{__('Select All', 'polylang-ai-automatic-translation')}
					</button>

					<button
						type="button"
						className="button button-small"
						onClick={() => onChange([])}
						disabled={disabled || selected.length === 0}
					>
						{__('Deselect All', 'polylang-ai-automatic-translation')}
					</button>
				</div>
			</div>
			<div
				className="pllat-language-cards"
				style={{
					display: 'grid',
					gap: '10px',
					gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
				}}
			>
				{languages.map((lang, index) => {
					const isRunning = runningLanguages.includes(lang.language);
					const exceedsLimit = !enabled && index >= maxLanguages;

					return (
						<LanguageCard
							key={lang.language}
							language={lang.language}
							languageName={lang.language_name}
							status={lang.status}
							translatedAt={lang.translated_at || null}
							progress={lang.progress || null}
							isRunning={isRunning}
							selected={selected.includes(lang.language)}
							onToggle={toggleLanguage}
							disabled={disabled || isRunning || exceedsLimit}
							exceedsLimit={exceedsLimit}
						/>
					);
				})}
			</div>
		</div>
	);
}

export default LanguageSelector;
