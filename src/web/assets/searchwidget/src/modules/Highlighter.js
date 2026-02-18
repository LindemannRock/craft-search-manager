/**
 * Highlighter - Text highlighting utilities
 *
 * Highlights search query matches in text with configurable
 * HTML tags and CSS classes. Includes HTML and regex escaping utilities.
 *
 * @module Highlighter
 * @author Search Manager
 * @since 5.32.0
 */

/**
 * @typedef {Object} HighlightOptions
 * @property {boolean} enabled - Whether highlighting is enabled
 * @property {string} tag - HTML tag to wrap matches (default: 'mark')
 * @property {string} className - Additional CSS class for highlights (optional)
 * @property {string[]} [terms] - Explicit terms to highlight (preferred over query)
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
 * Parse a search query string into highlight-ready terms
 *
 * Extracts meaningful terms from a query that may contain quoted phrases,
 * boolean operators (AND/OR/NOT in multiple languages), field prefixes,
 * wildcards, and boost markers. This mirrors the backend QueryParser logic
 * for use when explicit matched terms are not available.
 *
 * @param {string} query - The search query to parse
 * @returns {string[]} Array of terms suitable for highlighting
 *
 * @example
 * parseQueryTerms('"craft cms" OR templates NOT draft')
 * // Returns: ['craft cms', 'templates']
 *
 * @example
 * parseQueryTerms('title:blog test^2 search*')
 * // Returns: ['blog', 'test', 'search']
 */
export function parseQueryTerms(query) {
    if (!query) return [];
    const terms = [];

    // 1. Extract quoted phrases: "craft cms" → single term
    const phraseRegex = /"([^"]+)"/g;
    let match;
    while ((match = phraseRegex.exec(query)) !== null) {
        if (match[1].trim()) terms.push(match[1].trim());
    }

    // 2. Remove quoted phrases from remaining text
    const remaining = query.replace(/"[^"]*"/g, '');

    // 3. Split on whitespace, clean each token
    const operators = new Set([
        'and', 'or', 'not',           // English
        'und', 'oder', 'nicht',        // German
        'et', 'ou', 'sauf',           // French
        'y', 'o', 'no',              // Spanish
    ]);

    remaining.split(/\s+/).filter(w => w.length > 0).forEach(word => {
        word = word.replace(/^[a-zA-Z]+:/, ''); // field prefix (title:, content:)
        word = word.replace(/\*/g, '');          // wildcards
        word = word.replace(/\^\d+(\.\d+)?/, ''); // boost markers (^2, ^1.5)
        word = word.replace(/"/g, '');           // stray quotes
        if (!word || operators.has(word.toLowerCase())) return;
        terms.push(word);
    });

    // 4. Apply camelCase splitting (existing feature)
    const withCamel = [];
    terms.forEach(word => {
        withCamel.push(word);
        const parts = word.split(/(?<=[a-z])(?=[A-Z])/);
        if (parts.length > 1) {
            parts.forEach(p => { if (p.length >= 3) withCamel.push(p); });
        }
    });

    return withCamel;
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
        terms = null,
    } = options;

    if (!enabled) {
        return escapeHtml(text);
    }

    // Build CSS class attribute
    const classes = ['sm-highlight'];
    if (className) {
        classes.push(className);
    }
    const classAttr = ` class="${classes.join(' ')}"`;

    const termList = buildHighlightTerms(query, terms);
    if (termList.length === 0) {
        return escapeHtml(text);
    }

    return applyHighlightRanges(text, termList, tag, classAttr);
}

function buildHighlightTerms(query, terms) {
    if (Array.isArray(terms) && terms.length > 0) {
        return normalizeTerms(terms);
    }

    if (!query) {
        return [];
    }

    return normalizeTerms(parseQueryTerms(query));
}

function normalizeTerms(terms) {
    const seen = new Set();
    return terms
        .filter(w => typeof w === 'string' && w.length > 0)
        .sort((a, b) => b.length - a.length)
        .filter(w => {
            const lower = w.toLowerCase();
            if (seen.has(lower)) return false;
            seen.add(lower);
            return true;
        });
}

function applyHighlightRanges(text, terms, tag, classAttr) {
    const lowerText = text.toLowerCase();
    const ranges = [];

    terms.forEach(term => {
        const lowerTerm = term.toLowerCase();
        if (!lowerTerm) return;

        let start = 0;
        while (start < lowerText.length) {
            const index = lowerText.indexOf(lowerTerm, start);
            if (index === -1) break;
            ranges.push({ start: index, end: index + lowerTerm.length });
            start = index + lowerTerm.length;
        }
    });

    if (ranges.length === 0) {
        return escapeHtml(text);
    }

    ranges.sort((a, b) => {
        if (a.start !== b.start) return a.start - b.start;
        return (b.end - b.start) - (a.end - a.start);
    });

    const merged = [];
    let lastEnd = -1;
    ranges.forEach(range => {
        if (range.start >= lastEnd) {
            merged.push(range);
            lastEnd = range.end;
        }
    });

    let result = '';
    let cursor = 0;
    merged.forEach(range => {
        if (cursor < range.start) {
            result += escapeHtml(text.slice(cursor, range.start));
        }
        result += `<${tag}${classAttr}>${escapeHtml(text.slice(range.start, range.end))}</${tag}>`;
        cursor = range.end;
    });
    if (cursor < text.length) {
        result += escapeHtml(text.slice(cursor));
    }

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
