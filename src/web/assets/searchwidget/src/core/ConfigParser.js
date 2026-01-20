/**
 * ConfigParser - Widget configuration parsing
 *
 * Parses HTML attributes into a normalized configuration object.
 * Handles type coercion, defaults, and validation for all widget types.
 *
 * @module ConfigParser
 * @author Search Manager
 * @since 5.x
 */

/**
 * @typedef {Object} BaseConfig
 * @property {Array<string>} indices - Search index handles
 * @property {string} index - Primary index (first of indices)
 * @property {string} placeholder - Input placeholder text
 * @property {string} endpoint - Search API endpoint
 * @property {string} theme - Color theme ('light' or 'dark')
 * @property {number} maxResults - Maximum results to display
 * @property {number} debounce - Debounce delay in milliseconds
 * @property {number} minChars - Minimum characters before search
 * @property {boolean} showRecent - Show recent searches
 * @property {number} maxRecentSearches - Max recent searches to store
 * @property {boolean} groupResults - Group results by type/section
 * @property {string} siteId - Site ID filter
 * @property {string} analyticsEndpoint - Analytics tracking endpoint
 * @property {boolean} enableHighlighting - Enable text highlighting
 * @property {string} highlightTag - HTML tag for highlights
 * @property {string} highlightClass - CSS class for highlights
 * @property {boolean} hideResultsWithoutUrl - Hide URL-less results
 * @property {Object} styles - Custom style values
 * @property {Object} promotions - Promotion display config
 */

/**
 * @typedef {Object} ModalConfig
 * @extends BaseConfig
 * @property {string} hotkey - Keyboard shortcut key
 * @property {boolean} showTrigger - Show trigger button
 * @property {string} triggerSelector - External trigger CSS selector
 * @property {number} backdropOpacity - Backdrop opacity (0-100)
 * @property {boolean} enableBackdropBlur - Enable backdrop blur
 * @property {boolean} preventBodyScroll - Prevent body scroll when open
 */

/**
 * @typedef {Object} PageConfig
 * @extends BaseConfig
 * @property {boolean} showFilters - Show filter sidebar
 * @property {string} paginationType - Pagination type: 'numbered', 'loadMore', 'infinite'
 * @property {number} resultsPerPage - Results per page
 * @property {boolean} updateUrl - Update URL with search state
 * @property {Array} sortOptions - Available sort options
 */

/**
 * @typedef {Object} InlineConfig
 * @extends BaseConfig
 * @property {string} dropdownPosition - Dropdown position: 'below', 'above'
 * @property {number} dropdownMaxHeight - Max dropdown height in pixels
 * @property {boolean} showOnFocus - Show dropdown on input focus
 */

/**
 * Default configuration values shared by all widget types
 */
export const BASE_DEFAULTS = {
    indices: [],
    placeholder: 'Search...',
    endpoint: '/actions/search-manager/search/query',
    theme: 'light',
    maxResults: 10,
    debounce: 200,
    minChars: 2,
    showRecent: true,
    maxRecentSearches: 5,
    groupResults: true,
    siteId: '',
    analyticsEndpoint: '/actions/search-manager/search/track-click',
    enableHighlighting: true,
    highlightTag: 'mark',
    highlightClass: '',
    hideResultsWithoutUrl: false,
    showLoadingIndicator: true,
    debug: false,
    styles: {},
    promotions: {
        showBadge: true,
        badgeText: 'Featured',
        badgePosition: 'top-right',
    },
};

/**
 * Default configuration values specific to modal widget
 */
export const MODAL_DEFAULTS = {
    hotkey: 'k',
    showTrigger: true,
    triggerSelector: '',
    backdropOpacity: 50,
    enableBackdropBlur: true,
    preventBodyScroll: true,
};

/**
 * Default configuration values specific to page widget (future)
 */
export const PAGE_DEFAULTS = {
    showFilters: true,
    paginationType: 'numbered',
    resultsPerPage: 20,
    updateUrl: true,
    sortOptions: ['relevance', 'date-desc', 'date-asc', 'title'],
};

/**
 * Default configuration values specific to inline widget (future)
 */
export const INLINE_DEFAULTS = {
    dropdownPosition: 'below',
    dropdownMaxHeight: 400,
    showOnFocus: true,
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
        page: PAGE_DEFAULTS,
        inline: INLINE_DEFAULTS,
    };

    return {
        ...BASE_DEFAULTS,
        ...(typeDefaults[widgetType] || {}),
    };
}

/**
 * Parse a boolean attribute value
 *
 * @param {string|null} value - Attribute value
 * @param {boolean} defaultValue - Default if not set
 * @returns {boolean} Parsed boolean
 */
function parseBoolean(value, defaultValue = false) {
    if (value === null || value === undefined) {
        return defaultValue;
    }
    // Attribute present without value means true
    if (value === '') {
        return true;
    }
    return value !== 'false' && value !== '0';
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
 * Parse configuration from HTML element attributes
 *
 * Reads attributes from the element and returns a typed configuration
 * object with proper defaults applied.
 *
 * @param {HTMLElement} element - The widget element
 * @param {string} widgetType - Widget type ('modal', 'page', 'inline')
 * @returns {BaseConfig|ModalConfig|PageConfig|InlineConfig} Parsed configuration
 *
 * @example
 * // Parse modal config from element
 * const config = parseConfig(element, 'modal');
 * console.log(config.hotkey); // 'k'
 *
 * @example
 * // Parse page config (future)
 * const config = parseConfig(element, 'page');
 * console.log(config.resultsPerPage); // 20
 */
export function parseConfig(element, widgetType = 'modal') {
    const defaults = getDefaultsForType(widgetType);

    // Parse indices
    const indicesAttr = element.getAttribute('indices') || '';
    const indices = parseArray(indicesAttr);

    // Build base config
    const config = {
        // Array/special parsing
        indices,
        index: indices[0] || '',

        // String attributes
        placeholder: element.getAttribute('placeholder') || defaults.placeholder,
        endpoint: element.getAttribute('endpoint') || defaults.endpoint,
        theme: element.getAttribute('theme') || defaults.theme,
        siteId: element.getAttribute('site-id') || defaults.siteId,
        analyticsEndpoint: element.getAttribute('analytics-endpoint') || defaults.analyticsEndpoint,
        highlightTag: element.getAttribute('highlight-tag') || defaults.highlightTag,
        highlightClass: element.getAttribute('highlight-class') || defaults.highlightClass,

        // Integer attributes
        maxResults: parseInt(element.getAttribute('max-results'), defaults.maxResults),
        debounce: parseInt(element.getAttribute('debounce'), defaults.debounce),
        minChars: parseInt(element.getAttribute('min-chars'), defaults.minChars),
        maxRecentSearches: parseInt(element.getAttribute('max-recent-searches'), defaults.maxRecentSearches),

        // Boolean attributes (default true - check for 'false')
        showRecent: parseBoolean(element.getAttribute('show-recent'), defaults.showRecent),
        groupResults: parseBoolean(element.getAttribute('group-results'), defaults.groupResults),
        enableHighlighting: parseBoolean(element.getAttribute('enable-highlighting'), defaults.enableHighlighting),
        showLoadingIndicator: parseBoolean(element.getAttribute('show-loading-indicator'), defaults.showLoadingIndicator),

        // Boolean attributes (default false - check for presence)
        hideResultsWithoutUrl: parseBoolean(element.getAttribute('hide-results-without-url'), defaults.hideResultsWithoutUrl),
        debug: parseBoolean(element.getAttribute('debug'), defaults.debug),

        // JSON attributes
        styles: parseJson(element.getAttribute('styles'), defaults.styles),
        promotions: parseJson(element.getAttribute('promotions'), defaults.promotions),
    };

    // Add modal-specific config
    if (widgetType === 'modal') {
        Object.assign(config, {
            hotkey: element.getAttribute('hotkey') || defaults.hotkey,
            triggerSelector: element.getAttribute('trigger-selector') || defaults.triggerSelector,
            backdropOpacity: parseInt(element.getAttribute('backdrop-opacity'), defaults.backdropOpacity),
            showTrigger: parseBoolean(element.getAttribute('show-trigger'), defaults.showTrigger),
            enableBackdropBlur: parseBoolean(element.getAttribute('enable-backdrop-blur'), defaults.enableBackdropBlur),
            preventBodyScroll: parseBoolean(element.getAttribute('prevent-body-scroll'), defaults.preventBodyScroll),
        });
    }

    // Add page-specific config (future)
    if (widgetType === 'page') {
        Object.assign(config, {
            resultsPerPage: parseInt(element.getAttribute('results-per-page'), defaults.resultsPerPage),
            paginationType: element.getAttribute('pagination-type') || defaults.paginationType,
            showFilters: parseBoolean(element.getAttribute('show-filters'), defaults.showFilters),
            updateUrl: parseBoolean(element.getAttribute('update-url'), defaults.updateUrl),
            sortOptions: parseArray(element.getAttribute('sort-options')) || defaults.sortOptions,
        });
    }

    // Add inline-specific config (future)
    if (widgetType === 'inline') {
        Object.assign(config, {
            dropdownPosition: element.getAttribute('dropdown-position') || defaults.dropdownPosition,
            dropdownMaxHeight: parseInt(element.getAttribute('dropdown-max-height'), defaults.dropdownMaxHeight),
            showOnFocus: parseBoolean(element.getAttribute('show-on-focus'), defaults.showOnFocus),
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
    const baseAttrs = [
        'indices', 'placeholder', 'endpoint', 'theme',
        'max-results', 'debounce', 'min-chars', 'show-recent',
        'max-recent-searches', 'group-results', 'site-id',
        'analytics-endpoint', 'enable-highlighting', 'highlight-tag',
        'highlight-class', 'hide-results-without-url', 'show-loading-indicator',
        'debug', 'styles', 'promotions',
    ];

    // Modal-specific attributes
    const modalAttrs = [
        'hotkey', 'show-trigger', 'trigger-selector',
        'backdrop-opacity', 'enable-backdrop-blur', 'prevent-body-scroll',
    ];

    // Page-specific attributes (future)
    const pageAttrs = [
        'show-filters', 'pagination-type', 'results-per-page',
        'update-url', 'sort-options',
    ];

    // Inline-specific attributes (future)
    const inlineAttrs = [
        'dropdown-position', 'dropdown-max-height', 'show-on-focus',
    ];

    const typeAttrs = {
        modal: modalAttrs,
        page: pageAttrs,
        inline: inlineAttrs,
    };

    return [...baseAttrs, ...(typeAttrs[widgetType] || [])];
}
