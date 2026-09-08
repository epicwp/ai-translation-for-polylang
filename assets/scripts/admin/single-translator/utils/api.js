/**
 * API utility functions for single translator.
 *
 * Handles all REST API communication with the backend using WordPress native apiFetch.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Initialize API with nonce middleware.
 * Called once when the module loads.
 */
const { nonce } = window.pllatSingleTranslator || {};
if (nonce) {
    apiFetch.use(apiFetch.createNonceMiddleware(nonce));
}

/**
 * Get translation status for a content item.
 *
 * @param {string} type - Content type (post or term)
 * @param {number} id - Content ID
 * @returns {Promise<Object>} Status data
 */
export async function getStatus(type, id) {
    return apiFetch({
        path: `/pllat/v1/single-translator/status/${type}/${id}`,
        method: 'GET',
    });
}

/**
 * Start translation for a content item.
 *
 * @param {string} type - Content type (post or term)
 * @param {number} id - Content ID
 * @param {Array<string>} targetLanguages - Target language codes
 * @param {boolean} force - Force re-translation
 * @param {string} instructions - Custom AI instructions
 * @returns {Promise<Object>} Response with run_id
 */
export async function startTranslation(
    type,
    id,
    targetLanguages,
    force = false,
    instructions = '',
    selectedFields = [],
    acknowledgePreflight = false,
) {
    return apiFetch({
        path: `/pllat/v1/single-translator/translate/${type}/${id}`,
        method: 'POST',
        data: {
            target_languages: targetLanguages,
            force,
            instructions,
            selected_fields: selectedFields,
            acknowledge_preflight: acknowledgePreflight,
        },
    });
}

/**
 * Set exclusion status for a content item.
 *
 * @param {string} type - Content type (post or term)
 * @param {number} id - Content ID
 * @param {boolean} excluded - Whether to exclude from translation
 * @returns {Promise<Object>} Response message
 */
export async function setExclusion(type, id, excluded) {
    return apiFetch({
        path: `/pllat/v1/single-translator/exclusion/${type}/${id}`,
        method: 'POST',
        data: {
            excluded,
        },
    });
}

/**
 * Get task details for a job (for error inspection).
 *
 * @param {number} jobId - Job ID
 * @returns {Promise<Object>} Task data
 */
export async function getJobTasks(jobId) {
    return apiFetch({
        path: `/pllat/v1/single-translator/job/${jobId}/tasks`,
        method: 'GET',
    });
}

/**
 * Cancel active translation for a content item.
 *
 * @param {string} type - Content type (post or term)
 * @param {number} id - Content ID
 * @returns {Promise<Object>} Response with run_id
 */
export async function cancelTranslation(type, id) {
    return apiFetch({
        path: `/pllat/v1/single-translator/cancel/${type}/${id}`,
        method: 'POST',
    });
}

/**
 * Trigger internal link replacement for a content item.
 *
 * @param {string} type - Content type (post or term)
 * @param {number} id - Content ID
 * @returns {Promise<Object>} Response with replaced/unresolved counts
 */
export async function replaceInternalLinks(type, id) {
    return apiFetch({
        path: `/pllat/v1/internal-links/process/${type}/${id}`,
        method: 'POST',
    });
}

export default {
    getStatus,
    startTranslation,
    setExclusion,
    getJobTasks,
    cancelTranslation,
    replaceInternalLinks,
};
