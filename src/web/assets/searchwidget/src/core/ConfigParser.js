/**
 * ConfigParser - Widget configuration parsing
 *
 * Parses HTML attributes into a normalized configuration object.
 * Handles type coercion, defaults, and validation for all widget types.
 *
 * @module ConfigParser
 * @author Search Manager
 * @since 5.32.0
 */

/**
 * @typedef {Object} BaseConfig
 * @property {Array<string>} indexHandles - Search index handles
 * @property {string} placeholder - Input placeholder text
 * @property {string} theme - Color theme ('light' or 'dark')
 * @property {number} resultsLimit - Maximum results to display
 * @property {number} searchDebounceMs - Debounce delay in milliseconds
 * @property {number} searchMinChars - Minimum characters before search
 * @property {boolean} recentlyViewedEnabled - Show recent searches
 * @property {number} recentlyViewedLimit - Max recent searches to store
 * @property {boolean} resultsGroupingEnabled - Group flat results by source, Entry section, or type
 * @property {string} siteId - Site ID filter
 * @property {string} searchEndpoint - Search API endpoint (internal)
 * @property {string} trackClickEndpoint - Click tracking endpoint (internal)
 * @property {string} trackSearchEndpoint - Search tracking endpoint (internal)
 * @property {number} analyticsIdleTimeoutMs - Idle timeout for analytics tracking (ms)
 * @property {string} analyticsSource - Analytics source identifier
 * @property {boolean} highlightResultsEnabled - Enable result text highlighting
 * @property {string} highlightTag - HTML tag for highlights
 * @property {string} highlightClass - CSS class for highlights
 * @property {boolean} resultsRequireUrl - Hide URL-less results
 * @property {boolean} snippetIncludeCodeBlocks - Allow block-level code in page or section snippets
 * @property {string} snippetMode - Snippet mode: early | balanced | deep
 * @property {number} snippetMaxLength - Max snippet length
 * @property {boolean} snippetCleanMarkdown - Clean Markdown markers before displaying snippets
 * @property {Object} styles - Custom style values
 * @property {Object} promotionBadge - Promotion badge display config
 * @property {Object} translations - Widget UI translations injected by Craft
 */

/**
 * @typedef {Object} ModalConfig
 * @extends BaseConfig
 * @property {string} triggerHotkey - Keyboard shortcut key
 * @property {boolean} triggerEnabled - Show trigger button
 * @property {string} triggerLabel - Trigger button label
 * @property {string} triggerSelector - External trigger CSS selector
 * @property {number} modalBackdropOpacity - Backdrop opacity (0-100)
 * @property {boolean} modalBackdropBlurEnabled - Enable backdrop blur
 * @property {boolean} modalPreventBodyScroll - Prevent body scroll when open
 */

/**
 * Default configuration values shared by all widget types
 */
export const BASE_DEFAULTS = {
    indexHandles: [],
    placeholder: 'Search...',
    theme: 'light',
    resultsLimit: 20,
    searchDebounceMs: 200,
    searchMinChars: 2,
    recentlyViewedEnabled: true,
    recentlyViewedLimit: 5,
    resultsGroupingEnabled: true,
    siteId: '',
    // Public API key, sent as X-Search-Manager-Key (required when requireApiKey is on)
    apiKey: '',
    // Internal endpoints (not user-configurable)
    searchEndpoint: '/actions/search-manager/api/search',
    trackClickEndpoint: '/actions/search-manager/search/track-click',
    trackSearchEndpoint: '/actions/search-manager/search/track-search',
    // Analytics settings (user-configurable)
    analyticsIdleTimeoutMs: 1500, // Track search after 1.5s idle (0 = disabled)
    analyticsSource: '', // Custom source identifier (empty = 'frontend-widget')
    highlightResultsEnabled: true,
    highlightTag: 'mark',
    highlightClass: '',
    resultsRequireUrl: false,
    snippetIncludeCodeBlocks: false,
    snippetMode: 'balanced',
    loadingIndicatorEnabled: true,
    debugEnabled: false,
    resultsTitleLines: 1,
    resultsDescriptionLines: 1,
    snippetMaxLength: 150,
    snippetCleanMarkdown: false,
    highlightDestinationPersistQuery: true,
    highlightDestinationQueryParam: 'smq',
    highlightDestinationEnabled: true,
    highlightDestinationContentSelector: 'main, article, [data-search-content]',
    // Hierarchical result display for parent results and split section hits
    resultsLayout: 'default', // 'default' | 'hierarchical'
    hierarchyGroupBy: '',    // Field to group by (e.g., 'source', 'entrySection', 'docCategory', 'categoryGroup')
    hierarchyStyle: 'tree',  // 'tree' (indented + connectors) | 'flat' (same depth + connectors) | 'none' (same depth, no connectors)
    hierarchyDisplay: 'individual', // 'individual' (each parent is its own card) | 'unified' (page block + headings share one card)
    hierarchyMaxHeadings: 3, // Max heading children per page block
    styles: {},
    translations: {},
    promotionBadge: {
        showBadge: true,
        badgeText: 'Featured',
        badgePosition: 'top-right',
    },
};

/**
 * Default configuration values specific to modal widget
 */
export const MODAL_DEFAULTS = {
    triggerHotkey: 'k',
    triggerEnabled: true,
    triggerLabel: 'Search',
    triggerSelector: '',
    modalBackdropOpacity: 50,
    modalBackdropBlurEnabled: true,
    modalPreventBodyScroll: true,
};

/**
 * Get widget-specific defaults based on type
 *
 * @param {string} widgetType - Widget type ('modal', 'page', 'inline')
 * @returns {Object} Combined defaults for the widget type
 */
export function getDefaultsForType(widgetType) {
    const typeDefaults = {
        modal: MODAL_DEFAULTS,
    };

    return {
        ...BASE_DEFAULTS,
        ...(typeDefaults[widgetType] || {}),
    };
}

/**
 * Parse a boolean attribute value
 *
 * @param {string|number|boolean|null} value - Attribute value
 * @param {boolean} defaultValue - Default if not set
 * @returns {boolean} Parsed boolean
 */
function parseBoolean(value, defaultValue = false) {
    if (value === null || value === undefined) {
        return defaultValue;
    }
    if (typeof value === 'boolean') {
        return value;
    }
    if (typeof value === 'number') {
        return value !== 0;
    }
    // Attribute present without value means true
    if (value === '') {
        return true;
    }
    const normalized = String(value).trim().toLowerCase();
    if (['1', 'true', 'on', 'yes'].includes(normalized)) {
        return true;
    }
    if (['0', 'false', 'off', 'no'].includes(normalized)) {
        return false;
    }
    return defaultValue;
}

/**
 * Parse an integer attribute value
 *
 * @param {string|null} value - Attribute value
 * @param {number} defaultValue - Default if not set or invalid
 * @returns {number} Parsed integer
 */
function parseInt(value, defaultValue = 0) {
    if (value === null || value === undefined) {
        return defaultValue;
    }
    const parsed = Number.parseInt(value, 10);
    return Number.isNaN(parsed) ? defaultValue : parsed;
}

/**
 * Parse JSON attribute value
 *
 * @param {string|null} value - JSON string
 * @param {*} defaultValue - Default if not set or invalid
 * @returns {*} Parsed value
 */
function parseJson(value, defaultValue = {}) {
    if (!value) {
        return defaultValue;
    }
    try {
        return JSON.parse(value);
    } catch (e) {
        console.warn('SearchWidget: Invalid JSON attribute', e);
        return defaultValue;
    }
}

/**
 * Parse comma-separated string into array
 *
 * @param {string|null} value - Comma-separated string
 * @returns {Array<string>} Array of trimmed strings
 */
function parseArray(value) {
    if (!value) {
        return [];
    }
    return value.split(',').map(s => s.trim()).filter(Boolean);
}

/**
 * Return the internal storage key for this search scope.
 *
 * @param {BaseConfig} config - Parsed widget config
 * @returns {string} Stable scope key for local widget state
 */
export function getSearchScopeKey(config) {
    return config.indexHandles.length > 0 ? config.indexHandles.join(',') : 'all';
}

/**
 * Return the single configured index when the scope is exactly one index.
 *
 * @param {BaseConfig} config - Parsed widget config
 * @returns {string} Index handle, or an empty string for all/multi-index scopes
 */
export function getSingleConfiguredIndex(config) {
    return config.indexHandles.length === 1 ? config.indexHandles[0] : '';
}

/**
 * Parse configuration from HTML element attributes
 *
 * Reads attributes from the element and returns a typed configuration
 * object with proper defaults applied.
 *
 * @param {HTMLElement} element - The widget element
 * @param {string} widgetType - Widget type ('modal', 'page', 'inline')
 * @returns {BaseConfig|ModalConfig} Parsed configuration
 *
 * @example
 * // Parse modal config from element
 * const config = parseConfig(element, 'modal');
 * console.log(config.triggerHotkey); // 'k'
 */
export function parseConfig(element, widgetType = 'modal') {
    const emittedSnippetDefaults = parseJson(element.getAttribute('snippet-defaults'), {});
    const defaults = {
        ...getDefaultsForType(widgetType),
        ...Object.fromEntries(
            Object.entries(emittedSnippetDefaults).filter(([key]) => [
                'snippetIncludeCodeBlocks',
                'snippetMode',
                'snippetMaxLength',
                'snippetCleanMarkdown',
                'minSnippetLength',
                'maxSnippetLength',
                'snippetModes',
            ].includes(key)),
        ),
    };
    const snippetModes = Array.isArray(defaults.snippetModes) ? defaults.snippetModes : ['early', 'balanced', 'deep'];
    const snippetMin = Number.isFinite(Number(defaults.minSnippetLength)) ? Number(defaults.minSnippetLength) : 50;
    const snippetMax = Number.isFinite(Number(defaults.maxSnippetLength)) ? Number(defaults.maxSnippetLength) : 1000;
    const snippetMaxLength = Math.min(snippetMax, Math.max(snippetMin, parseInt(element.getAttribute('snippet-max-length'), defaults.snippetMaxLength)));
    const snippetMode = element.getAttribute('snippet-mode') || defaults.snippetMode;

    // Parse index handles
    const indexHandlesAttr = element.getAttribute('index-handles') || '';
    const indexHandles = parseArray(indexHandlesAttr);

    // Build base config
    const config = {
        // Array/special parsing
        indexHandles,

        // String attributes (user-configurable)
        placeholder: element.getAttribute('placeholder') || defaults.placeholder,
        theme: element.getAttribute('theme') || defaults.theme,
        siteId: element.getAttribute('site-id') || defaults.siteId,
        apiKey: element.getAttribute('api-key') || defaults.apiKey,
        analyticsSource: element.getAttribute('analytics-source') || defaults.analyticsSource,
        highlightTag: element.getAttribute('highlight-tag') || defaults.highlightTag,
        highlightClass: element.getAttribute('highlight-class') || defaults.highlightClass,

        // Internal endpoints (not user-configurable, use defaults)
        searchEndpoint: defaults.searchEndpoint,
        trackClickEndpoint: defaults.trackClickEndpoint,
        trackSearchEndpoint: defaults.trackSearchEndpoint,

        // Integer attributes
        resultsLimit: parseInt(element.getAttribute('results-limit'), defaults.resultsLimit),
        searchDebounceMs: parseInt(element.getAttribute('search-debounce-ms'), defaults.searchDebounceMs),
        searchMinChars: parseInt(element.getAttribute('search-min-chars'), defaults.searchMinChars),
        recentlyViewedLimit: parseInt(element.getAttribute('recently-viewed-limit'), defaults.recentlyViewedLimit),
        analyticsIdleTimeoutMs: parseInt(element.getAttribute('analytics-idle-timeout-ms'), defaults.analyticsIdleTimeoutMs),

        // Boolean attributes (default true - check for 'false')
        recentlyViewedEnabled: parseBoolean(element.getAttribute('recently-viewed-enabled'), defaults.recentlyViewedEnabled),
        resultsGroupingEnabled: parseBoolean(element.getAttribute('results-grouping-enabled'), defaults.resultsGroupingEnabled),
        highlightResultsEnabled: parseBoolean(element.getAttribute('highlight-results-enabled'), defaults.highlightResultsEnabled),
        loadingIndicatorEnabled: parseBoolean(element.getAttribute('loading-indicator-enabled'), defaults.loadingIndicatorEnabled),

        // Boolean attributes (default false - check for presence)
        resultsRequireUrl: parseBoolean(element.getAttribute('results-require-url'), defaults.resultsRequireUrl),
        snippetIncludeCodeBlocks: parseBoolean(element.getAttribute('snippet-include-code-blocks'), defaults.snippetIncludeCodeBlocks),
        debugEnabled: parseBoolean(element.getAttribute('debug-enabled'), defaults.debugEnabled),
        snippetMode: snippetModes.includes(snippetMode) ? snippetMode : defaults.snippetMode,
        snippetMaxLength,
        snippetCleanMarkdown: parseBoolean(element.getAttribute('snippet-clean-markdown'), defaults.snippetCleanMarkdown),
        highlightDestinationPersistQuery: parseBoolean(element.getAttribute('highlight-destination-persist-query'), defaults.highlightDestinationPersistQuery),
        highlightDestinationEnabled: parseBoolean(element.getAttribute('highlight-destination-enabled'), defaults.highlightDestinationEnabled),

        // Result line clamping
        resultsTitleLines: parseInt(element.getAttribute('results-title-lines'), defaults.resultsTitleLines),
        resultsDescriptionLines: parseInt(element.getAttribute('results-description-lines'), defaults.resultsDescriptionLines),
        highlightDestinationQueryParam: element.getAttribute('highlight-destination-query-param') || defaults.highlightDestinationQueryParam,
        highlightDestinationContentSelector: element.getAttribute('highlight-destination-content-selector') || defaults.highlightDestinationContentSelector,

        // Hierarchical result display
        resultsLayout: element.getAttribute('results-layout') || defaults.resultsLayout,
        hierarchyGroupBy: element.getAttribute('hierarchy-group-by') || defaults.hierarchyGroupBy,
        hierarchyStyle: element.getAttribute('hierarchy-style') || defaults.hierarchyStyle,
        hierarchyDisplay: element.getAttribute('hierarchy-display') || defaults.hierarchyDisplay,
        hierarchyMaxHeadings: parseInt(element.getAttribute('hierarchy-max-headings'), defaults.hierarchyMaxHeadings),

        // JSON attributes
        styles: parseJson(element.getAttribute('styles'), defaults.styles),
        translations: parseJson(element.getAttribute('translations'), defaults.translations),
        promotionBadge: parseJson(element.getAttribute('promotion-badge'), defaults.promotionBadge),
    };

    // Add modal-specific config
    if (widgetType === 'modal') {
        Object.assign(config, {
            triggerHotkey: element.getAttribute('trigger-hotkey') || defaults.triggerHotkey,
            triggerLabel: element.getAttribute('trigger-label') || defaults.triggerLabel,
            triggerSelector: element.getAttribute('trigger-selector') || defaults.triggerSelector,
            modalBackdropOpacity: parseInt(element.getAttribute('modal-backdrop-opacity'), defaults.modalBackdropOpacity),
            triggerEnabled: parseBoolean(element.getAttribute('trigger-enabled'), defaults.triggerEnabled),
            modalBackdropBlurEnabled: parseBoolean(element.getAttribute('modal-backdrop-blur-enabled'), defaults.modalBackdropBlurEnabled),
            modalPreventBodyScroll: parseBoolean(element.getAttribute('modal-prevent-body-scroll'), defaults.modalPreventBodyScroll),
        });
    }

    return config;
}

/**
 * Get list of observed attributes for a widget type
 *
 * Returns the attribute names that should be watched for changes.
 *
 * @param {string} widgetType - Widget type ('modal', 'page', 'inline')
 * @returns {Array<string>} List of attribute names
 */
export function getObservedAttributes(widgetType = 'modal') {
    // Base attributes (all widget types)
    // Note: endpoint attributes are internal and not included here
    const baseAttrs = [
        'index-handles', 'placeholder', 'theme',
        'results-limit', 'search-debounce-ms', 'search-min-chars', 'recently-viewed-enabled',
        'recently-viewed-limit', 'results-grouping-enabled', 'site-id',
        'analytics-idle-timeout-ms', 'analytics-source',
        'highlight-results-enabled', 'highlight-tag',
        'highlight-class', 'results-require-url', 'snippet-include-code-blocks', 'snippet-mode', 'loading-indicator-enabled',
        'debug-enabled', 'styles', 'translations', 'promotion-badge',
        'results-layout', 'hierarchy-group-by', 'hierarchy-style', 'hierarchy-display', 'hierarchy-max-headings',
        'results-title-lines', 'results-description-lines', 'snippet-max-length', 'snippet-clean-markdown',
        'highlight-destination-persist-query', 'highlight-destination-query-param', 'highlight-destination-enabled', 'highlight-destination-content-selector',
    ];

    // Modal-specific attributes
    const modalAttrs = [
        'trigger-hotkey', 'trigger-enabled', 'trigger-label', 'trigger-selector',
        'modal-backdrop-opacity', 'modal-backdrop-blur-enabled', 'modal-prevent-body-scroll',
    ];

    const typeAttrs = {
        modal: modalAttrs,
    };

    return [...baseAttrs, ...(typeAttrs[widgetType] || [])];
}
