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
 * @param {boolean} options.showCodeSnippets - Allow code in result snippets
 * @param {string} options.snippetMode - Snippet mode: early | balanced | deep
 * @param {number} options.snippetLength - Max snippet length
 * @param {boolean} options.parseMarkdownSnippets - Parse markdown before snippets
 * @param {boolean} options.debug - Request debug metadata (overrides devMode default)
 * @param {string} options.apiKey - Public API key sent as X-Search-Manager-Key (required when requireApiKey is on)
 * @param {AbortSignal} options.signal - AbortController signal
 * @returns {Promise<SearchResponse>} - Search response with results and meta
 */
export async function performSearch({ query, endpoint, indices = [], siteId = '', maxResults = 10, hideResultsWithoutUrl = false, showCodeSnippets = false, snippetMode = '', snippetLength = 0, parseMarkdownSnippets = false, debug = false, apiKey = '', signal }) {
    const params = new URLSearchParams({
        q: query,
        hitsPerPage: maxResults.toString(),
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

    if (showCodeSnippets) {
        params.append('showCodeSnippets', '1');
    }

    if (snippetMode) {
        params.append('snippetMode', snippetMode);
    }

    if (snippetLength) {
        params.append('snippetLength', String(snippetLength));
    }

    if (parseMarkdownSnippets) {
        params.append('parseMarkdownSnippets', '1');
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

    const headers = { 'Accept': 'application/json' };
    if (apiKey) {
        headers['X-Search-Manager-Key'] = apiKey;
    }

    const response = await fetch(`${endpoint}${separator}${params}`, {
        signal,
        headers,
    });

    if (!response.ok) {
        throw new Error(await getSearchErrorMessage(response));
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
 * Convert an API error response into a useful widget-facing message.
 *
 * @param {Response} response - Fetch response object
 * @returns {Promise<string>} Error message
 */
async function getSearchErrorMessage(response) {
    const serverMessage = await readServerError(response);

    if (response.status === 401) {
        return serverMessage || 'Search requires an API key.';
    }
    if (response.status === 403) {
        return serverMessage || 'This API key cannot access this search.';
    }
    if (response.status === 429) {
        return serverMessage || 'Search rate limit exceeded. Try again in a moment.';
    }

    return serverMessage || 'Search failed.';
}

/**
 * Read a JSON error body without making error handling depend on it.
 *
 * Non-JSON responses are intentionally ignored so public widgets do not render
 * framework HTML error pages or proxy text bodies as user-facing messages.
 *
 * @param {Response} response - Fetch response object
 * @returns {Promise<string>} Server-provided error message, if available
 */
async function readServerError(response) {
    try {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            const data = await response.json();
            const message = data.error || data.message || '';
            return typeof message === 'string' ? message.slice(0, 240) : '';
        }
    } catch (e) {
        return '';
    }

    return '';
}

/**
 * Track a result click for analytics
 * @param {Object} options - Tracking options
 * @param {string} options.endpoint - The analytics endpoint URL
 * @param {string} options.elementId - The clicked element ID
 * @param {string} options.query - The search query
 * @param {string} options.index - The search index
 * @param {string} options.apiKey - Public API key sent as X-Search-Manager-Key (required when requireApiKey is on)
 */
export function trackClick({ endpoint, elementId, query, index, apiKey = '' }) {
    if (!elementId || !endpoint) return;

    try {
        const formData = new FormData();
        formData.append('elementId', elementId);
        formData.append('query', query);
        formData.append('index', index);

        const headers = { 'Accept': 'application/json' };
        if (apiKey) {
            headers['X-Search-Manager-Key'] = apiKey;
        }

        fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers,
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
 * Optionally forwards cache telemetry from the final search response so the
 * server can record an accurate executionTime (0 for cache hit, took ms for
 * miss). When omitted, the server records executionTime = NULL and the row
 * is excluded from cache stats — preserving legacy behaviour.
 *
 * @param {Object} options - Tracking options
 * @param {string} options.endpoint - The track-search endpoint URL
 * @param {string} options.query - The search query
 * @param {Array} options.indices - Search indices
 * @param {number} options.resultsCount - Number of results
 * @param {string} options.trigger - What triggered tracking ('click', 'enter', 'idle')
 * @param {string} options.source - Source identifier (e.g., 'header-search')
 * @param {string} options.siteId - Optional site ID
 * @param {boolean} [options.cached] - Whether the final search response was served from cache
 * @param {number} [options.took] - Backend execution time in ms (from response meta.took)
 * @param {string} [options.apiKey] - Public API key sent as X-Search-Manager-Key (required when requireApiKey is on)
 */
export function trackSearch({ endpoint, query, indices = [], resultsCount = 0, trigger = 'unknown', source = '', siteId = '', cached, took, apiKey = '' }) {
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
        // Cache telemetry — server clamps and validates; only send when we have it.
        if (typeof cached === 'boolean') {
            formData.append('cached', cached ? '1' : '0');
        }
        if (typeof took === 'number' && Number.isFinite(took) && took >= 0) {
            formData.append('took', took.toString());
        }

        const headers = { 'Accept': 'application/json' };
        if (apiKey) {
            headers['X-Search-Manager-Key'] = apiKey;
        }

        fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers,
        }).catch(() => {
            // Silently fail analytics
        });
    } catch (e) {
        // Ignore analytics errors
    }
}

/**
 * Group results by source, entry section, or type
 * @param {Array} results - Array of search results
 * @returns {Object} - Results grouped by type
 */
export function groupResultsByType(results) {
    const groups = {};
    results.forEach(result => {
        const type = result.source || result.entrySection || result.type || 'Results';
        if (!groups[type]) {
            groups[type] = [];
        }
        groups[type].push(result);
    });
    return groups;
}

/**
 * Group results by a configurable field
 * @param {Array} results - Array of search results
 * @param {string} field - Field name to group by (e.g., 'source', 'entrySection', 'docCategory', 'categoryGroup')
 * @returns {Object} - Results grouped by field value
 */
export function groupResultsByField(results, field) {
    const groups = {};
    results.forEach(result => {
        const key = (field ? result[field] : null) || result.source || result.entrySection || result.type || 'Results';
        if (!groups[key]) {
            groups[key] = [];
        }
        groups[key].push(result);
    });
    return groups;
}
