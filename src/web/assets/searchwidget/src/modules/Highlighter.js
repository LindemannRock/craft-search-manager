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

import { isUnsafeNavigationUrl } from './UrlUtils.js';

/**
 * @typedef {Object} HighlightOptions
 * @property {boolean} enabled - Whether highlighting is enabled
 * @property {string} tag - HTML tag to wrap matches (default: 'mark')
 * @property {string} className - Additional CSS class for highlights (optional)
 * @property {string[]} [terms] - Explicit terms to highlight (preferred over query)
 */

// The server-managed list intentionally excludes `u`; developer-authored
// widget options may use it, and both runtimes still fail closed to `mark`.
const ALLOWED_HIGHLIGHT_TAGS = new Set(['mark', 'em', 'strong', 'u', 'b', 'i', 'span']);
// Mirrors Highlighter::isValidClassTokenList() in the PHP runtime.
const CSS_CLASS_TOKEN_PATTERN = /^[A-Za-z0-9_-]+$/;
const BOOLEAN_OPERATORS = {
    en: { and: ['and'], or: ['or'], not: ['not'] },
    de: { and: ['und'], or: ['oder'], not: ['nicht'] },
    fr: { and: ['et'], or: ['ou'], not: ['sauf'] },
    es: { and: ['y'], or: ['o'], not: ['no'] },
    nl: { and: ['en'], or: ['of'], not: ['niet'] },
    it: { and: ['e'], or: ['o'], not: ['non'] },
    pt: { and: ['e'], or: ['ou'], not: ['não', 'nao'] },
    sv: { and: ['och'], or: ['eller'], not: ['inte'] },
    da: { and: ['og'], or: ['eller'], not: ['ikke'] },
    no: { and: ['og'], or: ['eller'], not: ['ikke', 'ikkje'] },
    ja: { and: ['かつ'], or: ['または', 'もしくは'], not: ['でない', 'ではない'] },
    ar: { and: ['و'], or: ['أو', 'او'], not: ['ليس', 'لا'] },
};

/**
 * Escape HTML special characters to prevent XSS
 *
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML-safe string
 *
 * @example
 * escapeHtml('<script>alert("xss")</script>')
 * // Returns: '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
 */
export function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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
/**
 * Neutralize URLs with executable schemes (mirrors the server-side
 * UrlSafetyHelper denylist). Browsers ignore control characters and
 * whitespace inside a scheme, so strip them before anchoring the check.
 *
 * @param {string} url - Candidate URL
 * @param {string} fallback - Returned for dangerous URLs (default '#')
 * @returns {string} The original URL, or the fallback when dangerous
 */
export function sanitizeUrl(url, fallback = '#') {
    if (typeof url !== 'string' || url === '') {
        return url || '';
    }

    return isUnsafeNavigationUrl(url) ? fallback : url;
}

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
 * Scope contract: unscoped terms paint both fields, scoped terms paint only
 * their matching field, and no eligible terms paint nothing.
 * Painting contract: exact and typo terms paint whole words, strict raw-query
 * prefix extensions paint only at word starts, and mid-word text never paints.
 *
 * @param {string} query - The search query to parse
 * @param {'title'|'content'|null} field - Optional display-field scope
 * @param {string} [language='en'] - Result language for localized operators
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
export function parseQueryTerms(query, field = null, language = 'en') {
    return parseQueryTermRecords(query, language)
        .filter(record => !field || !record.field || record.field === field)
        .map(record => record.term);
}

function parseQueryTermRecords(query, language = 'en') {
    if (!query) return [];
    const records = [];
    const { operators, negativeOperators } = getBooleanOperators(language);
    const tokens = query.match(/(?:[a-zA-Z]+:)?"[^"]*"|\S+/g) || [];
    let excludeNext = false;

    tokens.forEach(token => {
        let word = token;
        let termField = null;
        const fieldMatch = word.match(/^([a-zA-Z]+):(.*)$/);
        if (fieldMatch) {
            const requestedField = fieldMatch[1].toLowerCase();
            termField = requestedField === 'title' || requestedField === 'content'
                ? requestedField
                : null;
            word = fieldMatch[2];
        }
        const quoted = word.startsWith('"') && word.endsWith('"');
        word = word.replace(/^"|"$/g, '');
        const normalizedOperator = normalizeForHighlight(word);

        if (!quoted && operators.has(normalizedOperator)) {
            excludeNext = negativeOperators.has(normalizedOperator);
            return;
        }
        if (excludeNext) {
            excludeNext = false;
            return;
        }

        word = word.replace(/\*/g, '');          // wildcards
        word = word.replace(/\^\d+(\.\d+)?/, ''); // boost markers (^2, ^1.5)
        word = word.replace(/"/g, '');           // stray quotes
        if (!word) return;
        records.push({ term: word, field: termField });
    });

    // Apply camelCase splitting (existing feature)
    const withCamel = [];
    records.forEach(record => {
        withCamel.push(record);
        const parts = record.term.split(/(?<=[a-z])(?=[A-Z])/);
        if (parts.length > 1) {
            parts.forEach(part => {
                if (part.length >= 3) {
                    withCamel.push({ ...record, term: part });
                }
            });
        }
    });

    return withCamel;
}

function getBooleanOperators(language) {
    const languageCode = String(language || 'en')
        .trim()
        .toLowerCase()
        .replace(/_/g, '-')
        .split('-')[0];
    const localized = BOOLEAN_OPERATORS[languageCode] || BOOLEAN_OPERATORS.en;
    const operators = new Set([
        ...BOOLEAN_OPERATORS.en.and,
        ...BOOLEAN_OPERATORS.en.or,
        ...BOOLEAN_OPERATORS.en.not,
        ...localized.and,
        ...localized.or,
        ...localized.not,
    ].map(normalizeForHighlight));
    const negativeOperators = new Set([
        ...BOOLEAN_OPERATORS.en.not,
        ...localized.not,
    ].map(normalizeForHighlight));

    return { operators, negativeOperators };
}

/**
 * Resolve the explicit terms for one rendered hit area.
 *
 * @param {Object} hit - Search hit with the locked matched-term contract
 * @param {'title'|'heading'|'snippet'} area - Rendered result area
 * @param {string} query - Original query
 * @param {string} [displayedText] - Displayed heading text for exact projection
 * @returns {string[]} Explicit terms; an empty array means highlight nothing
 * @since 5.53.2
 */
export function getHitHighlightTerms(hit, area, query, displayedText = '') {
    if (area === 'heading') {
        return getHeadingHighlightTerms(hit, query, displayedText);
    }

    const field = area === 'snippet' ? 'content' : 'title';
    const queryTerms = parseQueryTerms(query, field, hit && hit.language);

    if (query && queryTerms.length === 0) {
        return [];
    }

    const matchedTerms = hit && hit.matchedTerms;
    const fieldTerms = matchedTerms && Array.isArray(matchedTerms[field])
        ? matchedTerms[field]
        : [];
    const phrases = hit && Array.isArray(hit.matchedPhrases) ? hit.matchedPhrases : [];
    const terms = fieldTerms.length > 0 ? fieldTerms : queryTerms;

    return [...phrases, ...terms];
}

/**
 * Project one split hit's field-level match metadata onto its displayed heading.
 * Section headings are indexed as content, but an unscoped term may also be
 * reported through the parent title. Only resolved terms that occur in the
 * displayed heading are eligible; explicit title-only queries remain excluded.
 */
function getHeadingHighlightTerms(hit, query, displayedText) {
    const queryRecords = parseQueryTermRecords(query, hit && hit.language);
    const queryTerms = queryRecords
        .filter(record => record.field !== 'title')
        .map(record => record.term);
    if (query && queryTerms.length === 0) {
        return [];
    }

    const matchedTerms = hit && hit.matchedTerms;
    const titleTerms = matchedTerms && Array.isArray(matchedTerms.title)
        ? matchedTerms.title
        : [];
    const contentTerms = matchedTerms && Array.isArray(matchedTerms.content)
        ? matchedTerms.content
        : [];
    const matchedPhrases = hit && Array.isArray(hit.matchedPhrases)
        ? hit.matchedPhrases
        : [];
    const eligiblePhrases = projectHeadingMatchedTerms(matchedPhrases, queryRecords, 'content');
    const hasMatchedTerms = titleTerms.length > 0 || contentTerms.length > 0;
    const projectedTerms = hasMatchedTerms
        ? [
            ...projectHeadingMatchedTerms(titleTerms, queryRecords, 'title'),
            ...projectHeadingMatchedTerms(contentTerms, queryRecords, 'content'),
        ]
        : queryTerms;

    return [...eligiblePhrases, ...projectedTerms]
        .filter(term => termOccursInText(displayedText, term));
}

function projectHeadingMatchedTerms(matchedTerms, queryRecords, sourceField) {
    return matchedTerms.filter(term => {
        const matchingRecords = bestMatchingQueryRecords(term, queryRecords);
        return matchingRecords.some(record => (
            sourceField === 'title' ? record.field === null : record.field !== 'title'
        ));
    });
}

function bestMatchingQueryRecords(term, queryRecords) {
    const scored = queryRecords.map(record => ({
        record,
        score: queryTermMatchScore(record.term, term),
    }));
    const bestScore = Math.max(0, ...scored.map(candidate => candidate.score));

    return scored
        .filter(candidate => candidate.score > 0 && candidate.score === bestScore)
        .map(candidate => candidate.record);
}

function queryTermMatchScore(queryTerm, matchedTerm) {
    const queryTokens = normalizedWordTokens(queryTerm);
    const matchedTokens = normalizedWordTokens(matchedTerm);
    if (queryTokens.length === 0 || matchedTokens.length === 0) return 0;

    if (queryTokens.some(queryToken => matchedTokens.includes(queryToken))) {
        return 3;
    }
    if (queryTokens.some(queryToken => matchedTokens.some(matchedToken => (
        isStrictPrefix(queryToken, matchedToken) || isStrictPrefix(matchedToken, queryToken)
    )))) {
        return 2;
    }
    if (queryTokens.length === 1 && matchedTokens.length === 1
        && isLikelyFuzzyMatch(queryTokens[0], matchedTokens[0])) {
        return 1;
    }

    return 0;
}

function isLikelyFuzzyMatch(left, right) {
    const maxLength = Math.max(Array.from(left).length, Array.from(right).length);
    if (maxLength < 4) return false;

    return editDistance(left, right) <= (maxLength >= 8 ? 2 : 1);
}

function editDistance(left, right) {
    const leftChars = Array.from(left);
    const rightChars = Array.from(right);
    let previous = rightChars.map((character, index) => index);
    previous.push(rightChars.length);

    for (let leftIndex = 1; leftIndex <= leftChars.length; leftIndex += 1) {
        const current = [leftIndex];
        for (let rightIndex = 1; rightIndex <= rightChars.length; rightIndex += 1) {
            current[rightIndex] = Math.min(
                current[rightIndex - 1] + 1,
                previous[rightIndex] + 1,
                previous[rightIndex - 1] + (leftChars[leftIndex - 1] === rightChars[rightIndex - 1] ? 0 : 1),
            );
        }
        previous = current;
    }

    return previous[rightChars.length];
}

function termOccursInText(text, term) {
    if (!text) return true;

    const words = textWords(text);
    const tokens = normalizedWordTokens(term);
    if (tokens.length === 0) return false;
    const lastStart = words.length - tokens.length;

    for (let i = 0; i <= lastStart; i += 1) {
        if (tokens.every((token, offset) => words[i + offset].normalized === token)) {
            return true;
        }
    }

    return false;
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

    const safeTag = normalizeHighlightTag(tag);
    const classTokens = normalizeClassTokens(className);

    // Build CSS class attribute
    const classes = ['sm-highlight', ...classTokens];
    const classAttr = ` class="${escapeHtml(classes.join(' '))}"`;

    const { termList, queryTerms } = buildHighlightTerms(query, terms);
    if (termList.length === 0) {
        return escapeHtml(text);
    }

    return applyHighlightRanges(text, termList, safeTag, classAttr, queryTerms);
}

export function normalizeHighlightTag(tag) {
    const normalized = String(tag || 'mark').trim().toLowerCase();

    return ALLOWED_HIGHLIGHT_TAGS.has(normalized) ? normalized : 'mark';
}

export function normalizeClassTokens(className) {
    return String(className || '')
        .trim()
        .split(/\s+/)
        .filter(token => token && CSS_CLASS_TOKEN_PATTERN.test(token));
}

function buildHighlightTerms(query, terms) {
    const termList = Array.isArray(terms)
        ? normalizeTerms(terms)
        : normalizeTerms(parseQueryTerms(query));
    const normalizedMatchedTerms = termList
        .map(term => normalizedWordTokens(term))
        .filter(tokens => tokens.length === 1)
        .map(tokens => tokens[0]);
    const queryTerms = normalizeTerms(parseQueryTerms(query)).filter(term => {
        const tokens = normalizedWordTokens(term);
        if (tokens.length !== 1) return false;

        return normalizedMatchedTerms.some(matched => (
            tokens[0] === matched || isStrictPrefix(tokens[0], matched)
        ));
    });

    return { termList, queryTerms };
}

function normalizeTerms(terms) {
    const seen = new Set();
    return terms
        .filter(w => typeof w === 'string' && w.length > 0)
        .sort((a, b) => b.length - a.length)
        .filter(w => {
            const normalized = normalizeForHighlight(w);
            if (seen.has(normalized)) return false;
            seen.add(normalized);
            return true;
        });
}

function applyHighlightRanges(text, terms, tag, classAttr, queryTerms) {
    const words = textWords(text);
    const ranges = [];
    const termTokens = terms
        .map(term => normalizedWordTokens(term))
        .filter(tokens => tokens.length > 0);
    const rawTokens = queryTerms
        .map(term => normalizedWordTokens(term))
        .filter(tokens => tokens.length === 1)
        .map(tokens => tokens[0]);
    const rawExact = new Set(rawTokens);
    const prefixExtensions = new Set();

    termTokens.forEach(tokens => {
        if (tokens.length !== 1 || rawExact.has(tokens[0])) return;
        if (rawTokens.some(rawToken => isStrictPrefix(rawToken, tokens[0]))) {
            prefixExtensions.add(tokens[0]);
        }
    });

    termTokens.forEach(tokens => {
        if (tokens.length === 1 && prefixExtensions.has(tokens[0])) return;

        const lastStart = words.length - tokens.length;
        for (let i = 0; i <= lastStart; i += 1) {
            const matches = tokens.every((token, offset) => words[i + offset].normalized === token);
            if (matches) {
                ranges.push({
                    start: words[i].start,
                    end: words[i + tokens.length - 1].end,
                    type: 'whole',
                });
            }
        }
    });

    rawTokens.forEach(rawToken => {
        words.forEach(word => {
            if (!isStrictPrefix(rawToken, word.normalized)) return;
            ranges.push({
                start: word.start,
                end: word.start + prefixCodeUnitLength(word.text, rawToken),
                type: 'prefix',
            });
        });
    });

    if (ranges.length === 0) {
        return escapeHtml(text);
    }

    ranges.sort((a, b) => {
        if (a.start !== b.start) return a.start - b.start;
        const length = (b.end - b.start) - (a.end - a.start);
        if (length !== 0) return length;
        return a.type === 'whole' ? -1 : 1;
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

function normalizeForHighlight(value) {
    return String(value || '')
        .normalize('NFKC')
        .replace(/\u0640/gu, '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/\p{M}/gu, mark => (mark === '\u3099' || mark === '\u309A' ? mark : ''))
        .normalize('NFC');
}

function normalizedWordTokens(value) {
    return normalizeForHighlight(value).match(/[\p{L}\p{N}\p{M}_]+/gu) || [];
}

function textWords(text) {
    const words = [];
    const pattern = /[\p{L}\p{N}\p{M}_]+/gu;
    let match;
    while ((match = pattern.exec(text)) !== null) {
        words.push({
            text: match[0],
            normalized: normalizeForHighlight(match[0]),
            start: match.index,
            end: match.index + match[0].length,
        });
    }
    return words;
}

function isStrictPrefix(prefix, word) {
    return word.length > prefix.length && word.startsWith(prefix);
}

function prefixCodeUnitLength(word, normalizedPrefix) {
    let normalized = '';
    let prefix = '';
    let reachedTarget = false;

    for (const character of Array.from(word)) {
        const characterNormalized = normalizeForHighlight(character);
        if (reachedTarget && characterNormalized !== '') break;

        prefix += character;
        normalized += characterNormalized;
        reachedTarget = Array.from(normalized).length >= Array.from(normalizedPrefix).length;
    }

    return prefix.length;
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
