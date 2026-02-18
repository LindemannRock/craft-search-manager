/**
 * URL utilities for result navigation
 *
 * Adds the search query to destination URLs so pages can restore
 * context (for example, destination-page highlighting).
 *
 * @module UrlUtils
 * @author Search Manager
 * @since 5.39.0
 */

/**
 * Append or replace a query parameter in a URL string.
 *
 * Works with relative, absolute, and hash URLs while preserving existing
 * query params and fragment.
 *
 * @param {string} url - Destination URL
 * @param {string} query - Search query value
 * @param {string} paramName - Query parameter name (default: smq)
 * @returns {string} URL with appended query param, or original URL if unchanged
 */
export function appendQueryParam(url, query, paramName = 'smq') {
    if (!url || url === '#') {
        return url;
    }

    const trimmedQuery = (query || '').trim();
    if (!trimmedQuery) {
        return url;
    }
    if (!paramName) {
        return url;
    }

    // Don't mutate non-http navigations
    if (/^(mailto:|tel:|javascript:)/i.test(url)) {
        return url;
    }

    const [beforeHash, hashFragment] = url.split('#', 2);
    const [path, rawSearch] = beforeHash.split('?', 2);
    const params = new URLSearchParams(rawSearch || '');
    params.set(paramName, trimmedQuery);

    const queryString = params.toString();
    const hash = hashFragment ? `#${hashFragment}` : '';

    return `${path}${queryString ? `?${queryString}` : ''}${hash}`;
}
