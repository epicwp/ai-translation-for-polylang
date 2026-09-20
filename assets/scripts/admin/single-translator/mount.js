/**
 * Calls the render callback with the single translator's container once the
 * DOM is ready. Shared by the bundle entries (index.pro.jsx, index.free.jsx).
 *
 * @param {Function} renderApp Receives the container element.
 */
export function mount(renderApp) {
	const init = () => {
		const container = document.getElementById('pllat-single-translator-root');

		if (!container) {
			return;
		}

		renderApp(container);
	};

	// Try to initialize immediately (in case DOM is already loaded)
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		// DOM already loaded
		init();
	}
}
