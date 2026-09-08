/**
 * Utility functions for managing dismissed notifications using localStorage.
 *
 * Helps track which recovery banners/notifications have been dismissed by the user.
 */

/**
 * Generate a unique notification ID.
 *
 * @param {string} type - Notification type (e.g., 'error', 'success')
 * @param {number} contentId - Content ID
 * @param {number} timestamp - Timestamp when notification was created
 * @returns {string} Unique notification ID
 */
function getNotificationId(type, contentId, timestamp) {
    return `pllat_notification_${type}_${contentId}_${timestamp}`;
}

/**
 * Mark a notification as dismissed.
 *
 * @param {string} notificationId - Notification ID to dismiss
 * @returns {void}
 */
export function markAsDismissed(notificationId) {
    const dismissedAt = Date.now();
    try {
        localStorage.setItem(notificationId, dismissedAt.toString());
    } catch (error) {
        // localStorage might be disabled, fail silently
        console.warn('Failed to save dismissed notification:', error);
    }
}

/**
 * Check if a notification has been dismissed.
 *
 * @param {string} notificationId - Notification ID to check
 * @param {number} ttl - Time-to-live in milliseconds (default: 1 hour)
 * @returns {boolean} True if dismissed and within TTL
 */
export function isDismissed(notificationId, ttl = 3600000) {
    try {
        const dismissedAt = localStorage.getItem(notificationId);
        if (!dismissedAt) {
            return false;
        }

        const timestamp = parseInt(dismissedAt, 10);
        const now = Date.now();

        // Check if still within TTL
        if (now - timestamp > ttl) {
            // Expired, remove from storage
            localStorage.removeItem(notificationId);
            return false;
        }

        return true;
    } catch (error) {
        // localStorage might be disabled
        return false;
    }
}

/**
 * Clear all dismissed notifications for a specific content item.
 *
 * @param {number} contentId - Content ID
 * @returns {void}
 */
export function clearDismissed(contentId) {
    try {
        const prefix = `pllat_notification_`;
        const keys = [];

        // Collect keys to remove
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith(prefix) && key.includes(`_${contentId}_`)) {
                keys.push(key);
            }
        }

        // Remove collected keys
        keys.forEach(key => localStorage.removeItem(key));
    } catch (error) {
        // localStorage might be disabled, fail silently
        console.warn('Failed to clear dismissed notifications:', error);
    }
}

/**
 * Clean up expired notifications from localStorage.
 *
 * @param {number} ttl - Time-to-live in milliseconds (default: 1 hour)
 * @returns {void}
 */
export function cleanupExpired(ttl = 3600000) {
    try {
        const prefix = `pllat_notification_`;
        const now = Date.now();
        const keysToRemove = [];

        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (!key || !key.startsWith(prefix)) {
                continue;
            }

            const timestamp = parseInt(localStorage.getItem(key), 10);
            if (isNaN(timestamp) || now - timestamp > ttl) {
                keysToRemove.push(key);
            }
        }

        keysToRemove.forEach(key => localStorage.removeItem(key));
    } catch (error) {
        // localStorage might be disabled, fail silently
        console.warn('Failed to cleanup expired notifications:', error);
    }
}

export default {
    getNotificationId,
    markAsDismissed,
    isDismissed,
    clearDismissed,
    cleanupExpired,
};
