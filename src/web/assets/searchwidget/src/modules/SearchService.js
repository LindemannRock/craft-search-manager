/**
 * Search Service - API calls and search logic
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
 * @param {AbortSignal} options.signal - AbortController signal
 * @returns {Promise<Array>} - Array of search results
 */
export async function performSearch({ query, endpoint, indices = [], siteId = '', maxResults = 10, hideResultsWithoutUrl = false, signal }) {
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
    return data.results || data.hits || [];
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
