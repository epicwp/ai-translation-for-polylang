/**
 * Hook to refresh translation status when the post is saved in Gutenberg.
 */

import { useEffect, useRef } from '@wordpress/element';
import { subscribe, select } from '@wordpress/data';

/**
 * Custom hook to detect Gutenberg post saves and trigger a refresh.
 *
 * @param {Function} onSave - Callback to invoke after a save completes
 */
export function useRefreshOnSave(onSave) {
	// Track if we're in a saving state
	const wasSavingRef = useRef(false);

	useEffect(() => {
		// Check if we're in Gutenberg by seeing if core/editor store exists
		const editorStore = select('core/editor');
		if (!editorStore) {
			// Not in Gutenberg, nothing to do
			return;
		}

		const unsubscribe = subscribe(() => {
			const isSaving = editorStore.isSavingPost();
			const isAutosaving = editorStore.isAutosavingPost();

			// We only care about manual saves, not autosaves
			if (isAutosaving) {
				return;
			}

			// Detect transition from saving -> not saving (save completed)
			if (wasSavingRef.current && !isSaving) {
				// Small delay to ensure the backend has processed the save
				setTimeout(() => {
					if (onSave) {
						onSave();
					}
				}, 500);
			}

			wasSavingRef.current = isSaving;
		});

		return () => {
			unsubscribe();
		};
	}, [onSave]);
}

export default useRefreshOnSave;
