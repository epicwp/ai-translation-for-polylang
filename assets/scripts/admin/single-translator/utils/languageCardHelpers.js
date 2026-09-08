/**
 * Helper functions for language card status display and formatting.
 */

/**
 * Get status indicator and color.
 *
 * @param {string} status - Translation status
 * @returns {Object|null} Indicator emoji and color, or null if no display needed
 */
export function getStatusDisplay(status) {
	switch (status) {
		case 'translated':
			return { indicator: '✓', color: '#00a32a' }; // Green checkmark — non-emoji, matches inline glyph
		case 'completed':
			return { indicator: '✓', color: '#00a32a' }; // Green checkmark — non-emoji, matches inline glyph
		case 'pending':
			return { indicator: '⏳', color: '#f0b849' }; // Yellow hourglass emoji
		case 'outdated':
			return { indicator: '↻', color: '#996a13' }; // Amber refresh — "update available", not warning
		case 'failed':
		case 'completed_with_errors':
			return { indicator: '❌', color: '#d63638' }; // Red X emoji
		default:
			return null;
	}
}

/**
 * Get background and border color based on status.
 *
 * @param {string} status - Translation status
 * @param {boolean} selected - Whether card is selected
 * @returns {Object} Background and border colors
 */
export function getStatusColors(status, selected) {
	// Selected state overrides status colors
	if (selected) {
		return {
			backgroundColor: '#f0f6fc',
			borderColor: '#2271b1'
		};
	}

	switch (status) {
		case 'translated':
			return {
				backgroundColor: '#f0f9f4', // Light green
				borderColor: '#00a32a'      // Green
			};
		case 'completed':
			return {
				backgroundColor: '#f0f9f4', // Light green (legacy support)
				borderColor: '#00a32a'      // Green
			};
		case 'in_progress':
			return {
				backgroundColor: '#f3e8ff', // Light purple
				borderColor: '#8b5cf6'      // Purple-500
			};
		case 'queued':
			return {
				backgroundColor: '#e0f2fe', // Light blue
				borderColor: '#2271b1'      // Blue
			};
		case 'pending':
			return {
				backgroundColor: '#fef8e7', // Light yellow
				borderColor: '#f0b849'      // Yellow/orange
			};
		case 'outdated':
			return {
				backgroundColor: '#fef8e7', // Light yellow (same family as pending)
				borderColor: '#f0b849'      // Amber
			};
		case 'failed':
		case 'completed_with_errors':
			return {
				backgroundColor: '#fcf0f1', // Light red
				borderColor: '#d63638'      // Red
			};
		default:
			return {
				backgroundColor: '#fff',     // White
				borderColor: '#dcdcde'       // Gray
			};
	}
}

/**
 * Format date for display in compact format.
 *
 * @param {number|null} timestamp - Unix timestamp
 * @returns {string|null} Formatted date (e.g., "Oct 14, 16:22")
 */
export function formatDate(timestamp) {
	if (!timestamp || timestamp === 0) {
		return null;
	}

	try {
		const date = new Date(timestamp * 1000);

		// Format: "Oct 14, 16:22"
		const month = date.toLocaleString('en', { month: 'short' });
		const day = date.getDate();
		const hours = String(date.getHours()).padStart(2, '0');
		const minutes = String(date.getMinutes()).padStart(2, '0');

		return `${month} ${day}, ${hours}:${minutes}`;
	} catch (err) {
		return null;
	}
}
