/**
 * Search Service - API calls and search logic
 */

/**
 * @typedef {Object} SearchResponse
 * @property {Array} results - Search results array
 * @property {number} total - Total results count
 * @property {Object|null} meta - Debug metadata (timing, cache, etc.)
 * @property {string|null} error - Server error message (query too long, etc.)
 */

/**
 * Perform a search query
 * @param {Object} options - Search options
 * @param {string} options.query - The search query
 * @param {string} options.endpoint - The API endpoint URL
 * @param {Array} options.indices - Search index handles
 * @param {string} options.siteId - Optional site ID
 * @param {number} options.maxResults - Maximum results to return
 * @param {boolean} options.hideResultsWithoutUrl - Hide results without URLs
 * @param {boolean} options.debug - Request debug metadata (overrides devMode default)
 * @param {AbortSignal} options.signal - AbortController signal
 * @returns {Promise<SearchResponse>} - Search response with results and meta
 */
export async function performSearch({ query, endpoint, indices = [], siteId = '', maxResults = 10, hideResultsWithoutUrl = false, debug = false, signal }) {
    const params = new URLSearchParams({
        q: query,
        limit: maxResults.toString(),
    });

    // Pass indices as comma-separated (empty = search all)
    if (indices.length > 0) {
        params.append('indices', indices.join(','));
    }

    if (siteId) {
        params.append('siteId', siteId);
    }

    if (hideResultsWithoutUrl) {
        params.append('hideResultsWithoutUrl', '1');
    }

    // Request debug metadata explicitly (overrides server devMode default)
    if (debug) {
        params.append('debug', '1');
    }

    // Skip automatic analytics tracking for widget searches (prevents keystroke spam)
    // Widget uses explicit /track-search endpoint for intent-based tracking
    params.append('skipAnalytics', '1');

    // Check if endpoint already has query params (Craft's actionUrl includes ?p=...)
    const separator = endpoint.includes('?') ? '&' : '?';

    const response = await fetch(`${endpoint}${separator}${params}`, {
        signal,
        headers: {
            'Accept': 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error('Search failed');
    }

    const data = await response.json();

    // Log server-side errors for debugging (query too long, etc.)
    if (data.error) {
        console.warn('Search warning:', data.error);
    }

    // Return structured response with results, meta, and error
    return {
        results: data.results || data.hits || [],
        total: data.total || 0,
        meta: data.meta || null,
        error: data.error || null,
    };
}

/**
 * Track a result click for analytics
 * @param {Object} options - Tracking options
 * @param {string} options.endpoint - The analytics endpoint URL
 * @param {string} options.elementId - The clicked element ID
 * @param {string} options.query - The search query
 * @param {string} options.index - The search index
 */
export function trackClick({ endpoint, elementId, query, index }) {
    if (!elementId || !endpoint) return;

    try {
        const formData = new FormData();
        formData.append('elementId', elementId);
        formData.append('query', query);
        formData.append('index', index);

        fetch(endpoint, {
            method: 'POST',
            body: formData,
        }).catch(() => {
            // Silently fail analytics
        });
    } catch (e) {
        // Ignore analytics errors
    }
}

/**
 * Track a search query for analytics (explicit tracking)
 *
 * Called when user shows intent:
 * - Clicks a result
 * - Presses Enter
 * - Stops typing for idle timeout
 *
 * @param {Object} options - Tracking options
 * @param {string} options.endpoint - The track-search endpoint URL
 * @param {string} options.query - The search query
 * @param {Array} options.indices - Search indices
 * @param {number} options.resultsCount - Number of results
 * @param {string} options.trigger - What triggered tracking ('click', 'enter', 'idle')
 * @param {string} options.source - Source identifier (e.g., 'header-search')
 * @param {string} options.siteId - Optional site ID
 */
export function trackSearch({ endpoint, query, indices = [], resultsCount = 0, trigger = 'unknown', source = '', siteId = '' }) {
    if (!query || !endpoint) return;

    try {
        const formData = new FormData();
        formData.append('q', query);
        formData.append('indices', indices.join(','));
        formData.append('resultsCount', resultsCount.toString());
        formData.append('trigger', trigger);
        formData.append('source', source || 'frontend-widget');
        if (siteId) {
            formData.append('siteId', siteId);
        }

        fetch(endpoint, {
            method: 'POST',
            body: formData,
        }).catch(() => {
            // Silently fail analytics
        });
    } catch (e) {
        // Ignore analytics errors
    }
}

/**
 * Group results by type/section
 * @param {Array} results - Array of search results
 * @returns {Object} - Results grouped by type
 */
export function groupResultsByType(results) {
    const groups = {};
    results.forEach(result => {
        const type = result.section || result.type || 'Results';
        if (!groups[type]) {
            groups[type] = [];
        }
        groups[type].push(result);
    });
    return groups;
}
