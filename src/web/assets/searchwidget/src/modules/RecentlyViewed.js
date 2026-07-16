/**
 * Recent Searches - localStorage handling for search history
 */

const DEFAULT_MAX_RECENT_SEARCHES = 5;
const STORAGE_PREFIX = 'sm-recently-viewed-';

/**
 * Get the storage key for an index
 * @param {string} index
 * @returns {string}
 */
function getStorageKey(index) {
    return `${STORAGE_PREFIX}${index || 'default'}`;
}

/**
 * Load recent searches from localStorage
 * @param {string} index - The search index identifier
 * @returns {Array} - Array of recent search objects
 */
export function loadRecentlyViewed(index) {
    try {
        const key = getStorageKey(index);
        const stored = localStorage.getItem(key);
        return stored ? JSON.parse(stored) : [];
    } catch (e) {
        return [];
    }
}

/**
 * Save a search to recent searches
 * @param {string} index - The search index identifier
 * @param {string} query - The search query
 * @param {Object} result - Optional result object with title and url
 * @param {number} maxRecent - Maximum number of recent searches to store
 * @returns {Array} - Updated array of recent searches
 */
export function saveRecentlyViewed(index, query, result = null, maxRecent = DEFAULT_MAX_RECENT_SEARCHES) {
    if (!query || !query.trim()) return loadRecentlyViewed(index);

    const key = getStorageKey(index);
    const entry = {
        query: query.trim(),
        title: result?.title || query,
        url: result?.url || null,
        timestamp: Date.now(),
    };

    let recentlyViewed = loadRecentlyViewed(index);

    // Remove duplicates and add to front
    recentlyViewed = recentlyViewed.filter(s => s.query !== entry.query);
    recentlyViewed.unshift(entry);
    recentlyViewed = recentlyViewed.slice(0, maxRecent);

    try {
        localStorage.setItem(key, JSON.stringify(recentlyViewed));
    } catch (e) {
        // localStorage full or unavailable
    }

    return recentlyViewed;
}

/**
 * Clear all recent searches for an index
 * @param {string} index - The search index identifier
 */
export function clearRecentlyViewed(index) {
    try {
        const key = getStorageKey(index);
        localStorage.removeItem(key);
    } catch (e) {
        // Ignore errors
    }
}
