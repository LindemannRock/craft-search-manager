/**
 * Recent Searches - localStorage handling for search history
 */

const MAX_RECENT_SEARCHES = 5;
const STORAGE_PREFIX = 'sm-recent-';

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
export function loadRecentSearches(index) {
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
 * @returns {Array} - Updated array of recent searches
 */
export function saveRecentSearch(index, query, result = null) {
    if (!query || !query.trim()) return loadRecentSearches(index);

    const key = getStorageKey(index);
    const entry = {
        query: query.trim(),
        title: result?.title || query,
        url: result?.url || null,
        timestamp: Date.now(),
    };

    let recentSearches = loadRecentSearches(index);

    // Remove duplicates and add to front
    recentSearches = recentSearches.filter(s => s.query !== entry.query);
    recentSearches.unshift(entry);
    recentSearches = recentSearches.slice(0, MAX_RECENT_SEARCHES);

    try {
        localStorage.setItem(key, JSON.stringify(recentSearches));
    } catch (e) {
        // localStorage full or unavailable
    }

    return recentSearches;
}

/**
 * Clear all recent searches for an index
 * @param {string} index - The search index identifier
 */
export function clearRecentSearches(index) {
    try {
        const key = getStorageKey(index);
        localStorage.removeItem(key);
    } catch (e) {
        // Ignore errors
    }
}
