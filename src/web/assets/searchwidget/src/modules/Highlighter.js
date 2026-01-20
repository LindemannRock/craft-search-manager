/**
 * Highlighter - Text highlighting utilities
 *
 * Highlights search query matches in text with configurable
 * HTML tags and CSS classes. Includes HTML and regex escaping utilities.
 *
 * @module Highlighter
 * @author Search Manager
 * @since 5.x
 */

/**
 * @typedef {Object} HighlightOptions
 * @property {boolean} enabled - Whether highlighting is enabled
 * @property {string} tag - HTML tag to wrap matches (default: 'mark')
 * @property {string} className - Additional CSS class for highlights (optional)
 */

/**
 * Escape HTML special characters to prevent XSS
 *
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML-safe string
 *
 * @example
 * escapeHtml('<script>alert("xss")</script>')
 * // Returns: '&lt;script&gt;alert("xss")&lt;/script&gt;'
 */
export function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Escape regex special characters for safe use in RegExp
 *
 * @param {string} string - String to escape
 * @returns {string} Escaped regex-safe pattern
 *
 * @example
 * escapeRegex('test (value)')
 * // Returns: 'test \\(value\\)'
 */
export function escapeRegex(string) {
    if (!string) return '';
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Highlight matching text in a string
 *
 * Wraps words from the query that appear in the text with the specified
 * HTML tag and CSS class. Uses word boundary matching for accuracy.
 *
 * @param {string} text - The text to highlight
 * @param {string} query - The search query (space-separated words)
 * @param {HighlightOptions} options - Highlighting options
 * @returns {string} HTML string with highlighted matches
 *
 * @example
 * // Basic usage
 * highlightMatches('Hello World', 'world', { enabled: true, tag: 'mark' })
 * // Returns: 'Hello <mark class="sm-highlight">World</mark>'
 *
 * @example
 * // With custom class
 * highlightMatches('Search results', 'search', {
 *   enabled: true,
 *   tag: 'span',
 *   className: 'custom-highlight'
 * })
 * // Returns: 'Search <span class="sm-highlight custom-highlight">results</span>'
 *
 * @example
 * // Disabled highlighting
 * highlightMatches('Hello World', 'world', { enabled: false })
 * // Returns: 'Hello World' (escaped but not highlighted)
 */
export function highlightMatches(text, query, options = {}) {
    const {
        enabled = true,
        tag = 'mark',
        className = '',
    } = options;

    // Always escape HTML first for security
    const escaped = escapeHtml(text);

    // Return escaped text if highlighting disabled or no query
    if (!enabled || !query) {
        return escaped;
    }

    // Split query into words, filter empty strings
    const queryWords = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);

    if (queryWords.length === 0) {
        return escaped;
    }

    // Build CSS class attribute
    const classes = ['sm-highlight'];
    if (className) {
        classes.push(className);
    }
    const classAttr = ` class="${classes.join(' ')}"`;

    // Highlight each query word using word boundary matching
    let result = escaped;
    queryWords.forEach(word => {
        const regex = new RegExp(`\\b(${escapeRegex(word)})\\b`, 'gi');
        result = result.replace(regex, `<${tag}${classAttr}>$1</${tag}>`);
    });

    return result;
}

/**
 * Create a highlighter function with preset options
 *
 * Useful for creating a configured highlighter that can be reused
 * across multiple highlight operations with the same settings.
 *
 * @param {HighlightOptions} options - Default highlighting options
 * @returns {Function} Configured highlighter function (text, query) => string
 *
 * @example
 * const highlight = createHighlighter({ enabled: true, tag: 'mark' });
 * const result1 = highlight('Hello World', 'world');
 * const result2 = highlight('Another text', 'another');
 */
export function createHighlighter(options = {}) {
    return (text, query) => highlightMatches(text, query, options);
}
