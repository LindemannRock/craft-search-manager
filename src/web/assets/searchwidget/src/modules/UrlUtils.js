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

    if (isUnsafeNavigationUrl(url)) {
        return '#';
    }

    const trimmedQuery = (query || '').trim();
    if (!trimmedQuery) {
        return url;
    }
    if (!paramName) {
        return url;
    }

    // Don't mutate non-http navigations
    if (/^(mailto:|tel:)/i.test(url)) {
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

/**
 * Whether a navigation URL uses a dangerous executable scheme.
 *
 * This list mirrors UrlSafetyHelper::DANGEROUS_SCHEMES in the PHP runtime.
 * Keep the cross-runtime mirror explicit because the browser bundle cannot
 * import PHP constants.
 *
 * @param {string} url - Candidate navigation URL
 * @returns {boolean} Whether the URL must be blocked
 * @since 5.54.0
 */
export function isUnsafeNavigationUrl(url) {
    const normalized = String(url)
        .replace(/[\t\n\r]/g, '')
        .replace(/^[\u0000-\u0020]+/, '');

    return /^(javascript|data|vbscript|file):/i.test(normalized);
}
