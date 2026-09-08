/**
 * Component for individual language card.
 */

import { CheckboxControl, Tooltip } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { getLanguageData } from '../../shared/utils/languages';
import { getStatusDisplay, getStatusColors, formatDate } from '../utils/languageCardHelpers';

/**
 * Get inline status display (icon + text).
 *
 * @param {string} status - Translation status (unified from backend)
 * @param {Object|null} progress - Progress data {completed: int, fields: string[]} for active claims
 * @param {number|null} translatedAt - Translation timestamp (unified from backend)
 * @param {boolean} isRunning - Whether this language is currently being translated by user
 * @returns {JSX.Element|null} Status display
 */
function getInlineStatus(status, progress, translatedAt, isRunning) {
	const dotsLoader = window.pllat?.assets?.icons?.dotsLoader;
	const ringLoader = window.pllat?.assets?.icons?.ringLoader;

	// Priority 1: Translated state (unified! - plugin or manual translation)
	if (status === 'translated') {
		const formattedDate = translatedAt && translatedAt > 0 ? formatDate(translatedAt) : null;
		return (
			<div style={{fontSize: '12px', color: '#00a32a', marginTop: '4px'}}>
				✓ {__('Translated', 'polylang-ai-automatic-translation')}
				{formattedDate && ` • ${formattedDate}`}
			</div>
		);
	}

	// Priority 2: Queued state - show ALWAYS (not just when isRunning)
	// This ensures "In Queue" is visible even after page refresh
	if (status === 'queued') {
		return (
			<div style={{
				display: 'flex',
				alignItems: 'center',
				gap: '6px',
				marginTop: '4px',
				fontSize: '12px',
				color: '#2271b1'
			}}>
				<img
					src={dotsLoader}
					width="16"
					height="16"
					alt=""
					style={{
						filter: 'invert(31%) sepia(100%) saturate(2080%) hue-rotate(197deg) brightness(96%) contrast(93%)'
					}}
				/>
				<span>{__('In Queue...', 'polylang-ai-automatic-translation')}</span>
			</div>
		);
	}

	// Priority 3: In-progress state — show ALWAYS (not just when isRunning) so
	// progress is visible after page refresh. Single-line: counter inline,
	// completed field list in a hover tooltip.
	if (status === 'in_progress') {
		const completed = progress?.completed ?? 0;
		const fields    = progress?.fields ?? [];
		const label     = completed === 0
			? __('Translating…', 'polylang-ai-automatic-translation')
			: sprintf(
				/* translators: %d: number of fields completed so far */
				_n('%d field translated', '%d fields translated', completed, 'polylang-ai-automatic-translation'),
				completed,
			);

		const inline = (
			<div style={{
				display: 'flex',
				alignItems: 'center',
				gap: '6px',
				marginTop: '4px',
				fontSize: '12px',
				color: '#8b5cf6', // Purple to match border
			}}>
				<img
					src={ringLoader}
					width="16"
					height="16"
					alt=""
					style={{
						filter: 'invert(44%) sepia(53%) saturate(3206%) hue-rotate(238deg) brightness(101%) contrast(92%)',
					}}
				/>
				<span style={{
					whiteSpace: 'nowrap',
					overflow: 'hidden',
					textOverflow: 'ellipsis',
					flex: 1,
					minWidth: 0,
				}}>
					{label}
				</span>
			</div>
		);

		if (completed > 0 && fields.length > 0) {
			const tooltipText = sprintf(
				/* translators: %s: comma-separated list of field references */
				__('Completed: %s', 'polylang-ai-automatic-translation'),
				fields.join(', '),
			);
			return <Tooltip text={tooltipText}>{inline}</Tooltip>;
		}
		return inline;
	}

	// Priority 4: Pending
	if (status === 'pending') {
		return (
			<div style={{fontSize: '12px', color: '#646970', marginTop: '4px'}}>
				{__('Pending', 'polylang-ai-automatic-translation')}
			</div>
		);
	}

	// Priority 5: Outdated — source content changed after a prior translation.
	if (status === 'outdated') {
		return (
			<div style={{
				display: 'flex',
				alignItems: 'center',
				gap: '6px',
				marginTop: '4px',
				fontSize: '12px',
				color: '#996a13', // Darker amber so text reads on the light yellow card
			}}>
				<span aria-hidden="true" style={{ fontSize: '14px', lineHeight: 1 }}>↻</span>
				<span style={{
					whiteSpace: 'nowrap',
					overflow: 'hidden',
					textOverflow: 'ellipsis',
					flex: 1,
					minWidth: 0,
				}}>
					{__('Update available', 'polylang-ai-automatic-translation')}
				</span>
			</div>
		);
	}

	// Priority 6: Failed state
	if (status === 'failed') {
		return (
			<div style={{fontSize: '12px', color: '#d63638', marginTop: '4px'}}>
				⚠ {__('Translation failed', 'polylang-ai-automatic-translation')}
			</div>
		);
	}

	// Priority 7: Fallback - not yet translated
	return (
		<div style={{fontSize: '12px', color: '#646970', marginTop: '4px'}}>
			{__('Not yet translated', 'polylang-ai-automatic-translation')}
		</div>
	);
}

/**
 * Language card component.
 *
 * @param {Object} props - Component props
 * @param {string} props.language - Language code
 * @param {string} props.languageName - Language name
 * @param {string} props.status - Translation status (unified from backend)
 * @param {number|null} props.translatedAt - Translation timestamp (unified from backend)
 * @param {Object|null} props.progress - Progress data {completed: int, fields: string[]} for active claims
 * @param {boolean} props.isRunning - Whether this language is currently being translated
 * @param {boolean} props.selected - Whether language is selected
 * @param {Function} props.onToggle - Callback when card is toggled
 * @param {boolean} props.disabled - Whether card is disabled
 * @param {boolean} props.exceedsLimit - Whether this language exceeds free tier limit
 * @returns {JSX.Element} The component
 */
export function LanguageCard({
	language,
	languageName,
	status,
	translatedAt = null,
	progress = null,
	isRunning = false,
	selected,
	onToggle,
	disabled,
	exceedsLimit = false
}) {
	const statusDisplay = getStatusDisplay(status);
	const statusColors = getStatusColors(status, selected);

	// Get language data including flag from global pllat object.
	const langData = getLanguageData(language);
	const flagUrl = langData?.flag;

	return (
		<div
			className="pllat-language-card"
			style={{
				border: `1px solid ${exceedsLimit ? '#dcdcde' : statusColors.borderColor}`,
				borderRadius: '4px',
				padding: '12px 16px',
				backgroundColor: exceedsLimit ? '#f6f7f7' : statusColors.backgroundColor,
				cursor: disabled ? 'not-allowed' : 'pointer',
				transition: 'all 0.2s ease',
				opacity: exceedsLimit ? 0.7 : 1,
			}}
			onClick={() => !disabled && onToggle(language)}
			onMouseEnter={(e) => {
				if (!disabled) {
					// Keep status border color, add shadow in same color
					e.currentTarget.style.boxShadow = `0 0 0 1px ${statusColors.borderColor}`;
				}
			}}
			onMouseLeave={(e) => {
				e.currentTarget.style.boxShadow = 'none';
			}}
		>
			<div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
				{/* Checkbox */}
				<div style={{ flexShrink: 0, alignSelf: 'center', marginBottom: 0 }}>
					<CheckboxControl
						checked={selected}
						onChange={() => onToggle(language)}
						disabled={disabled}
						style={{ marginBottom: 0 }}
						__nextHasNoMarginBottom
					/>
				</div>

				{/* Language Info */}
				<div style={{ flex: 1, minWidth: 0 }}>
					<div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
						{flagUrl && (
							<img
								src={flagUrl}
								alt={languageName}
								style={{
									width: '16px',
									height: 'auto',
									flexShrink: 0,
									opacity: exceedsLimit ? 0.5 : 1,
								}}
							/>
						)}
						<div style={{ fontWeight: '500', fontSize: '14px', color: exceedsLimit ? '#646970' : '#1d2327' }}>{languageName}</div>
					</div>

					{/* Inline status display */}
					{exceedsLimit ? (
						<div style={{fontSize: '12px', color: '#d63638', marginTop: '4px'}}>
							🔒 {__('Upgrade to Pro for unlimited languages', 'polylang-ai-automatic-translation')}
						</div>
					) : (
						getInlineStatus(status, progress, translatedAt, isRunning)
					)}
				</div>

				{/* Status Indicator */}
				{statusDisplay && (
					<div
						style={{
							flexShrink: 0,
							fontSize: '18px',
							color: statusDisplay.color,
							lineHeight: 1,
						}}
					>
						{statusDisplay.indicator}
					</div>
				)}
			</div>
		</div>
	);
}

export default LanguageCard;
