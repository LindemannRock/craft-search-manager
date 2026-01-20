var SearchModalWidget = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/widgets/SearchModalWidget.js
  var SearchModalWidget_exports = {};
  __export(SearchModalWidget_exports, {
    default: () => SearchModalWidget_default
  });

  // src/core/ConfigParser.js
  var BASE_DEFAULTS = {
    indices: [],
    placeholder: "Search...",
    endpoint: "/actions/search-manager/search/query",
    theme: "light",
    maxResults: 10,
    debounce: 200,
    minChars: 2,
    showRecent: true,
    maxRecentSearches: 5,
    groupResults: true,
    siteId: "",
    analyticsEndpoint: "/actions/search-manager/search/track-click",
    enableHighlighting: true,
    highlightTag: "mark",
    highlightClass: "",
    hideResultsWithoutUrl: false,
    debug: false,
    styles: {},
    promotions: {
      showBadge: true,
      badgeText: "Featured",
      badgePosition: "top-right"
    }
  };
  var MODAL_DEFAULTS = {
    hotkey: "k",
    showTrigger: true,
    triggerSelector: "",
    backdropOpacity: 50,
    enableBackdropBlur: true,
    preventBodyScroll: true
  };
  var PAGE_DEFAULTS = {
    showFilters: true,
    paginationType: "numbered",
    resultsPerPage: 20,
    updateUrl: true,
    sortOptions: ["relevance", "date-desc", "date-asc", "title"]
  };
  var INLINE_DEFAULTS = {
    dropdownPosition: "below",
    dropdownMaxHeight: 400,
    showOnFocus: true
  };
  function getDefaultsForType(widgetType) {
    const typeDefaults = {
      modal: MODAL_DEFAULTS,
      page: PAGE_DEFAULTS,
      inline: INLINE_DEFAULTS
    };
    return {
      ...BASE_DEFAULTS,
      ...typeDefaults[widgetType] || {}
    };
  }
  function parseBoolean(value, defaultValue = false) {
    if (value === null || value === void 0) {
      return defaultValue;
    }
    if (value === "") {
      return true;
    }
    return value !== "false" && value !== "0";
  }
  function parseInt(value, defaultValue = 0) {
    if (value === null || value === void 0) {
      return defaultValue;
    }
    const parsed = Number.parseInt(value, 10);
    return Number.isNaN(parsed) ? defaultValue : parsed;
  }
  function parseJson(value, defaultValue = {}) {
    if (!value) {
      return defaultValue;
    }
    try {
      return JSON.parse(value);
    } catch (e) {
      console.warn("SearchWidget: Invalid JSON attribute", e);
      return defaultValue;
    }
  }
  function parseArray(value) {
    if (!value) {
      return [];
    }
    return value.split(",").map((s) => s.trim()).filter(Boolean);
  }
  function parseConfig(element, widgetType = "modal") {
    const defaults = getDefaultsForType(widgetType);
    const indicesAttr = element.getAttribute("indices") || "";
    const indices = parseArray(indicesAttr);
    const config = {
      // Array/special parsing
      indices,
      index: indices[0] || "",
      // String attributes
      placeholder: element.getAttribute("placeholder") || defaults.placeholder,
      endpoint: element.getAttribute("endpoint") || defaults.endpoint,
      theme: element.getAttribute("theme") || defaults.theme,
      siteId: element.getAttribute("site-id") || defaults.siteId,
      analyticsEndpoint: element.getAttribute("analytics-endpoint") || defaults.analyticsEndpoint,
      highlightTag: element.getAttribute("highlight-tag") || defaults.highlightTag,
      highlightClass: element.getAttribute("highlight-class") || defaults.highlightClass,
      // Integer attributes
      maxResults: parseInt(element.getAttribute("max-results"), defaults.maxResults),
      debounce: parseInt(element.getAttribute("debounce"), defaults.debounce),
      minChars: parseInt(element.getAttribute("min-chars"), defaults.minChars),
      maxRecentSearches: parseInt(element.getAttribute("max-recent-searches"), defaults.maxRecentSearches),
      // Boolean attributes (default true - check for 'false')
      showRecent: parseBoolean(element.getAttribute("show-recent"), defaults.showRecent),
      groupResults: parseBoolean(element.getAttribute("group-results"), defaults.groupResults),
      enableHighlighting: parseBoolean(element.getAttribute("enable-highlighting"), defaults.enableHighlighting),
      // Boolean attributes (default false - check for presence)
      hideResultsWithoutUrl: parseBoolean(element.getAttribute("hide-results-without-url"), defaults.hideResultsWithoutUrl),
      debug: parseBoolean(element.getAttribute("debug"), defaults.debug),
      // JSON attributes
      styles: parseJson(element.getAttribute("styles"), defaults.styles),
      promotions: parseJson(element.getAttribute("promotions"), defaults.promotions)
    };
    if (widgetType === "modal") {
      Object.assign(config, {
        hotkey: element.getAttribute("hotkey") || defaults.hotkey,
        triggerSelector: element.getAttribute("trigger-selector") || defaults.triggerSelector,
        backdropOpacity: parseInt(element.getAttribute("backdrop-opacity"), defaults.backdropOpacity),
        showTrigger: parseBoolean(element.getAttribute("show-trigger"), defaults.showTrigger),
        enableBackdropBlur: parseBoolean(element.getAttribute("enable-backdrop-blur"), defaults.enableBackdropBlur),
        preventBodyScroll: parseBoolean(element.getAttribute("prevent-body-scroll"), defaults.preventBodyScroll)
      });
    }
    if (widgetType === "page") {
      Object.assign(config, {
        resultsPerPage: parseInt(element.getAttribute("results-per-page"), defaults.resultsPerPage),
        paginationType: element.getAttribute("pagination-type") || defaults.paginationType,
        showFilters: parseBoolean(element.getAttribute("show-filters"), defaults.showFilters),
        updateUrl: parseBoolean(element.getAttribute("update-url"), defaults.updateUrl),
        sortOptions: parseArray(element.getAttribute("sort-options")) || defaults.sortOptions
      });
    }
    if (widgetType === "inline") {
      Object.assign(config, {
        dropdownPosition: element.getAttribute("dropdown-position") || defaults.dropdownPosition,
        dropdownMaxHeight: parseInt(element.getAttribute("dropdown-max-height"), defaults.dropdownMaxHeight),
        showOnFocus: parseBoolean(element.getAttribute("show-on-focus"), defaults.showOnFocus)
      });
    }
    return config;
  }
  function getObservedAttributes(widgetType = "modal") {
    const baseAttrs = [
      "indices",
      "placeholder",
      "endpoint",
      "theme",
      "max-results",
      "debounce",
      "min-chars",
      "show-recent",
      "max-recent-searches",
      "group-results",
      "site-id",
      "analytics-endpoint",
      "enable-highlighting",
      "highlight-tag",
      "highlight-class",
      "hide-results-without-url",
      "debug",
      "styles",
      "promotions"
    ];
    const modalAttrs = [
      "hotkey",
      "show-trigger",
      "trigger-selector",
      "backdrop-opacity",
      "enable-backdrop-blur",
      "prevent-body-scroll"
    ];
    const pageAttrs = [
      "show-filters",
      "pagination-type",
      "results-per-page",
      "update-url",
      "sort-options"
    ];
    const inlineAttrs = [
      "dropdown-position",
      "dropdown-max-height",
      "show-on-focus"
    ];
    const typeAttrs = {
      modal: modalAttrs,
      page: pageAttrs,
      inline: inlineAttrs
    };
    return [...baseAttrs, ...typeAttrs[widgetType] || []];
  }

  // src/core/StateManager.js
  var DEFAULT_STATE = {
    isOpen: false,
    query: "",
    results: [],
    recentSearches: [],
    selectedIndex: -1,
    loading: false,
    error: null,
    meta: null
  };
  function createStateManager(initialState = {}, onChange = null) {
    let state = {
      ...DEFAULT_STATE,
      ...initialState
    };
    return {
      /**
       * Get a single state value
       *
       * @param {string} key - State key to retrieve
       * @returns {*} State value
       *
       * @example
       * const query = state.get('query');
       */
      get(key) {
        return state[key];
      },
      /**
       * Get the full state object
       *
       * Returns a shallow copy to prevent direct mutation.
       *
       * @returns {WidgetState} Full state object
       *
       * @example
       * const { query, results, loading } = state.getAll();
       */
      getAll() {
        return { ...state };
      },
      /**
       * Set state values
       *
       * Merges the provided values with current state.
       * Only triggers onChange if values actually changed.
       *
       * @param {Partial<WidgetState>} updates - State updates to apply
       * @returns {Array<string>} Array of keys that changed
       *
       * @example
       * // Single update
       * state.set({ query: 'test' });
       *
       * // Multiple updates
       * state.set({
       *   loading: true,
       *   results: [],
       *   error: null,
       * });
       */
      set(updates) {
        const changedKeys = [];
        Object.keys(updates).forEach((key) => {
          const oldValue = state[key];
          const newValue = updates[key];
          if (!isEqual(oldValue, newValue)) {
            changedKeys.push(key);
          }
        });
        if (changedKeys.length > 0) {
          state = {
            ...state,
            ...updates
          };
          if (onChange) {
            onChange(state, changedKeys);
          }
        }
        return changedKeys;
      },
      /**
       * Reset state to initial values
       *
       * @param {Partial<WidgetState>} [newInitial] - Optional new initial values
       *
       * @example
       * state.reset(); // Reset to original initial state
       * state.reset({ query: 'default' }); // Reset with new defaults
       */
      reset(newInitial = initialState) {
        const newState = {
          ...DEFAULT_STATE,
          ...newInitial
        };
        const changedKeys = Object.keys(newState).filter(
          (key) => !isEqual(state[key], newState[key])
        );
        if (changedKeys.length > 0) {
          state = newState;
          if (onChange) {
            onChange(state, changedKeys);
          }
        }
      },
      /**
       * Check if a specific state value matches
       *
       * @param {string} key - State key to check
       * @param {*} value - Value to compare against
       * @returns {boolean} True if values match
       *
       * @example
       * if (state.is('loading', true)) {
       *   showSpinner();
       * }
       */
      is(key, value) {
        return state[key] === value;
      },
      /**
       * Toggle a boolean state value
       *
       * @param {string} key - State key to toggle
       * @returns {boolean} New value after toggle
       *
       * @example
       * state.toggle('isOpen'); // Toggles between true/false
       */
      toggle(key) {
        const newValue = !state[key];
        this.set({ [key]: newValue });
        return newValue;
      }
    };
  }
  function isEqual(a, b) {
    if (a === b) {
      return true;
    }
    if (a == null || b == null) {
      return false;
    }
    if (Array.isArray(a) && Array.isArray(b)) {
      if (a.length !== b.length) {
        return false;
      }
      return a.every((item, index) => isEqual(item, b[index]));
    }
    if (typeof a === "object" && typeof b === "object") {
      const keysA = Object.keys(a);
      const keysB = Object.keys(b);
      if (keysA.length !== keysB.length) {
        return false;
      }
      return keysA.every((key) => isEqual(a[key], b[key]));
    }
    return false;
  }

  // src/modules/SearchService.js
  async function performSearch({ query, endpoint, indices = [], siteId = "", maxResults = 10, hideResultsWithoutUrl = false, signal }) {
    const params = new URLSearchParams({
      q: query,
      limit: maxResults.toString()
    });
    if (indices.length > 0) {
      params.append("indices", indices.join(","));
    }
    if (siteId) {
      params.append("siteId", siteId);
    }
    if (hideResultsWithoutUrl) {
      params.append("hideResultsWithoutUrl", "1");
    }
    const separator = endpoint.includes("?") ? "&" : "?";
    const response = await fetch(`${endpoint}${separator}${params}`, {
      signal,
      headers: {
        "Accept": "application/json"
      }
    });
    if (!response.ok) {
      throw new Error("Search failed");
    }
    const data = await response.json();
    return {
      results: data.results || data.hits || [],
      total: data.total || 0,
      meta: data.meta || null
    };
  }
  function trackClick({ endpoint, elementId, query, index }) {
    if (!elementId || !endpoint)
      return;
    try {
      const formData = new FormData();
      formData.append("elementId", elementId);
      formData.append("query", query);
      formData.append("index", index);
      fetch(endpoint, {
        method: "POST",
        body: formData
      }).catch(() => {
      });
    } catch (e) {
    }
  }
  function groupResultsByType(results) {
    const groups = {};
    results.forEach((result) => {
      const type = result.section || result.type || "Results";
      if (!groups[type]) {
        groups[type] = [];
      }
      groups[type].push(result);
    });
    return groups;
  }

  // src/modules/RecentSearches.js
  var DEFAULT_MAX_RECENT_SEARCHES = 5;
  var STORAGE_PREFIX = "sm-recent-";
  function getStorageKey(index) {
    return `${STORAGE_PREFIX}${index || "default"}`;
  }
  function loadRecentSearches(index) {
    try {
      const key = getStorageKey(index);
      const stored = localStorage.getItem(key);
      return stored ? JSON.parse(stored) : [];
    } catch (e) {
      return [];
    }
  }
  function saveRecentSearch(index, query, result = null, maxRecent = DEFAULT_MAX_RECENT_SEARCHES) {
    if (!query || !query.trim())
      return loadRecentSearches(index);
    const key = getStorageKey(index);
    const entry = {
      query: query.trim(),
      title: result?.title || query,
      url: result?.url || null,
      timestamp: Date.now()
    };
    let recentSearches = loadRecentSearches(index);
    recentSearches = recentSearches.filter((s) => s.query !== entry.query);
    recentSearches.unshift(entry);
    recentSearches = recentSearches.slice(0, maxRecent);
    try {
      localStorage.setItem(key, JSON.stringify(recentSearches));
    } catch (e) {
    }
    return recentSearches;
  }
  function clearRecentSearches(index) {
    try {
      const key = getStorageKey(index);
      localStorage.removeItem(key);
    } catch (e) {
    }
  }

  // ../../../config/style-defaults.json
  var style_defaults_default = {
    modalBg: "#ffffff",
    modalBgDark: "#1f2937",
    modalBorderRadius: "12",
    modalBorderWidth: "1",
    modalBorderColor: "#e5e7eb",
    modalBorderColorDark: "#374151",
    modalShadow: "0 25px 50px -12px rgba(0, 0, 0, 0.25)",
    modalShadowDark: "0 25px 50px -12px rgba(0, 0, 0, 0.5)",
    modalMaxWidth: "640",
    modalMaxHeight: "80",
    inputBg: "#ffffff",
    inputBgDark: "#1f2937",
    inputTextColor: "#111827",
    inputTextColorDark: "#f9fafb",
    inputPlaceholderColor: "#9ca3af",
    inputPlaceholderColorDark: "#9ca3af",
    inputBorderColor: "#e5e7eb",
    inputBorderColorDark: "#374151",
    inputFontSize: "16",
    resultBg: "transparent",
    resultBgDark: "transparent",
    resultHoverBg: "#f3f4f6",
    resultHoverBgDark: "#374151",
    resultActiveBg: "#e5e7eb",
    resultActiveBgDark: "#4b5563",
    resultTextColor: "#111827",
    resultTextColorDark: "#f9fafb",
    resultDescColor: "#4b5563",
    resultDescColorDark: "#d1d5db",
    resultMutedColor: "#6b7280",
    resultMutedColorDark: "#d1d5db",
    resultBorderRadius: "8",
    triggerBg: "#ffffff",
    triggerBgDark: "#374151",
    triggerTextColor: "#374151",
    triggerTextColorDark: "#d1d5db",
    triggerBorderRadius: "8",
    triggerBorderWidth: "1",
    triggerBorderColor: "#d1d5db",
    triggerBorderColorDark: "#4b5563",
    triggerPaddingX: "12",
    triggerPaddingY: "8",
    triggerFontSize: "14",
    kbdBg: "#f3f4f6",
    kbdBgDark: "#4b5563",
    kbdTextColor: "#4b5563",
    kbdTextColorDark: "#e5e7eb",
    kbdBorderRadius: "4"
  };

  // src/modules/StyleConfig.js
  var STYLE_MAPPINGS = {
    // Modal
    modalBg: "--sm-modal-bg",
    modalBgDark: "--sm-modal-bg-dark",
    modalBorderRadius: "--sm-modal-radius",
    modalBorderWidth: "--sm-modal-border-width",
    modalBorderColor: "--sm-modal-border-color",
    modalBorderColorDark: "--sm-modal-border-color-dark",
    modalShadow: "--sm-modal-shadow",
    modalShadowDark: "--sm-modal-shadow-dark",
    modalMaxWidth: "--sm-modal-width",
    modalMaxHeight: "--sm-modal-max-height",
    // Input
    inputBg: "--sm-input-bg",
    inputBgDark: "--sm-input-bg-dark",
    inputTextColor: "--sm-input-color",
    inputTextColorDark: "--sm-input-color-dark",
    inputPlaceholderColor: "--sm-input-placeholder",
    inputPlaceholderColorDark: "--sm-input-placeholder-dark",
    inputBorderColor: "--sm-input-border-color",
    inputBorderColorDark: "--sm-input-border-color-dark",
    inputFontSize: "--sm-input-font-size",
    // Results
    resultBg: "--sm-result-bg",
    resultBgDark: "--sm-result-bg-dark",
    resultHoverBg: "--sm-result-hover-bg",
    resultHoverBgDark: "--sm-result-hover-bg-dark",
    resultActiveBg: "--sm-result-active-bg",
    resultActiveBgDark: "--sm-result-active-bg-dark",
    resultTextColor: "--sm-result-text-color",
    resultTextColorDark: "--sm-result-text-color-dark",
    resultDescColor: "--sm-result-desc-color",
    resultDescColorDark: "--sm-result-desc-color-dark",
    resultMutedColor: "--sm-result-muted-color",
    resultMutedColorDark: "--sm-result-muted-color-dark",
    resultBorderRadius: "--sm-result-radius",
    // Trigger
    triggerBg: "--sm-trigger-bg",
    triggerBgDark: "--sm-trigger-bg-dark",
    triggerTextColor: "--sm-trigger-text-color",
    triggerTextColorDark: "--sm-trigger-text-color-dark",
    triggerBorderRadius: "--sm-trigger-radius",
    triggerBorderWidth: "--sm-trigger-border-width",
    triggerBorderColor: "--sm-trigger-border-color",
    triggerBorderColorDark: "--sm-trigger-border-color-dark",
    triggerPaddingX: "--sm-trigger-px",
    triggerPaddingY: "--sm-trigger-py",
    triggerFontSize: "--sm-trigger-font-size",
    // Keyboard badge
    kbdBg: "--sm-kbd-bg",
    kbdBgDark: "--sm-kbd-bg-dark",
    kbdTextColor: "--sm-kbd-text-color",
    kbdTextColorDark: "--sm-kbd-text-color-dark",
    kbdBorderRadius: "--sm-kbd-radius",
    // Highlighting
    highlightBgLight: "--sm-highlight-bg",
    highlightColorLight: "--sm-highlight-color",
    highlightBgDark: "--sm-highlight-bg-dark",
    highlightColorDark: "--sm-highlight-color-dark"
  };
  var NUMERIC_KEYS = [
    "modalBorderRadius",
    "modalBorderWidth",
    "modalMaxWidth",
    "inputFontSize",
    "resultBorderRadius",
    "triggerBorderRadius",
    "triggerBorderWidth",
    "triggerPaddingX",
    "triggerPaddingY",
    "triggerFontSize",
    "kbdBorderRadius"
  ];
  var VH_KEYS = [
    "modalMaxHeight"
  ];
  var COLOR_KEYS = [
    "modalBg",
    "modalBgDark",
    "modalBorderColor",
    "modalBorderColorDark",
    "inputBg",
    "inputBgDark",
    "inputTextColor",
    "inputTextColorDark",
    "inputPlaceholderColor",
    "inputPlaceholderColorDark",
    "inputBorderColor",
    "inputBorderColorDark",
    "resultBg",
    "resultBgDark",
    "resultHoverBg",
    "resultHoverBgDark",
    "resultActiveBg",
    "resultActiveBgDark",
    "resultTextColor",
    "resultTextColorDark",
    "resultDescColor",
    "resultDescColorDark",
    "resultMutedColor",
    "resultMutedColorDark",
    "triggerBg",
    "triggerBgDark",
    "triggerTextColor",
    "triggerTextColorDark",
    "triggerBorderColor",
    "triggerBorderColorDark",
    "kbdBg",
    "kbdBgDark",
    "kbdTextColor",
    "kbdTextColorDark",
    "highlightBgLight",
    "highlightColorLight",
    "highlightBgDark",
    "highlightColorDark"
  ];
  var DEFAULT_STYLES = {
    ...style_defaults_default,
    // Highlighting (from highlighting settings, not styles config)
    highlightBgLight: "#fef08a",
    highlightColorLight: "#854d0e",
    highlightBgDark: "#854d0e",
    highlightColorDark: "#fef08a"
  };

  // src/modules/StyleUtils.js
  function isHexColor(value) {
    return /^[0-9a-fA-F]{6}$/.test(value);
  }
  function processStyleValue(key, value) {
    if (value === void 0 || value === null || value === "") {
      return null;
    }
    let processedValue = String(value);
    if (COLOR_KEYS.includes(key) && isHexColor(processedValue)) {
      processedValue = "#" + processedValue;
    }
    if (NUMERIC_KEYS.includes(key)) {
      processedValue = processedValue + "px";
    }
    if (VH_KEYS.includes(key)) {
      processedValue = processedValue + "vh";
    }
    return processedValue;
  }
  function applyStylesToElement(element, styles2, theme = "light") {
    if (!styles2 || typeof styles2 !== "object")
      return;
    const isDark = theme === "dark";
    for (const [key, cssVar] of Object.entries(STYLE_MAPPINGS)) {
      const isDarkKey = key.endsWith("Dark");
      if (isDark && !isDarkKey)
        continue;
      if (!isDark && isDarkKey)
        continue;
      if (styles2[key] !== void 0 && styles2[key] !== null && styles2[key] !== "") {
        const value = processStyleValue(key, styles2[key]);
        if (value) {
          element.style.setProperty(cssVar, value);
        }
      }
    }
  }

  // src/modules/Highlighter.js
  function escapeHtml(text) {
    if (!text)
      return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
  function escapeRegex(string) {
    if (!string)
      return "";
    return string.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }
  function highlightMatches(text, query, options = {}) {
    const {
      enabled = true,
      tag = "mark",
      className = ""
    } = options;
    const escaped = escapeHtml(text);
    if (!enabled || !query) {
      return escaped;
    }
    const queryWords = query.toLowerCase().split(/\s+/).filter((w) => w.length > 0);
    if (queryWords.length === 0) {
      return escaped;
    }
    const classes = ["sm-highlight"];
    if (className) {
      classes.push(className);
    }
    const classAttr = ` class="${classes.join(" ")}"`;
    let result = escaped;
    queryWords.forEach((word) => {
      const regex = new RegExp(`\\b(${escapeRegex(word)})\\b`, "gi");
      result = result.replace(regex, `<${tag}${classAttr}>$1</${tag}>`);
    });
    return result;
  }

  // src/modules/A11yUtils.js
  var idCounter = 0;
  function generateId(prefix = "sm") {
    return `${prefix}-${++idCounter}-${Date.now().toString(36)}`;
  }
  function createLiveRegion(shadowRoot) {
    const liveRegion = document.createElement("div");
    liveRegion.setAttribute("role", "status");
    liveRegion.setAttribute("aria-live", "polite");
    liveRegion.setAttribute("aria-atomic", "true");
    liveRegion.className = "sm-sr-only";
    shadowRoot.appendChild(liveRegion);
    return liveRegion;
  }
  function announce(liveRegion, message, delay = 100) {
    if (!liveRegion)
      return;
    liveRegion.textContent = "";
    setTimeout(() => {
      liveRegion.textContent = message;
    }, delay);
  }
  function getResultsAnnouncement(count, query) {
    if (count === 0) {
      return `No results found for "${query}"`;
    }
    if (count === 1) {
      return `1 result found for "${query}"`;
    }
    return `${count} results found for "${query}"`;
  }
  function getLoadingAnnouncement() {
    return "Searching...";
  }
  function getRecentSearchesAnnouncement(count) {
    if (count === 0) {
      return "No recent searches";
    }
    if (count === 1) {
      return "1 recent search available";
    }
    return `${count} recent searches available`;
  }
  function updateComboboxAria(input, { expanded, activeDescendant, listboxId }) {
    input.setAttribute("aria-expanded", String(expanded));
    input.setAttribute("aria-controls", listboxId);
    if (activeDescendant) {
      input.setAttribute("aria-activedescendant", activeDescendant);
    } else {
      input.removeAttribute("aria-activedescendant");
    }
  }
  function getOptionId(baseId, index) {
    return `${baseId}-option-${index}`;
  }
  function scrollIntoViewIfNeeded(element, container) {
    if (!element || !container)
      return;
    const elementRect = element.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();
    if (elementRect.top < containerRect.top) {
      element.scrollIntoView({ block: "nearest", behavior: "smooth" });
    } else if (elementRect.bottom > containerRect.bottom) {
      element.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
  }

  // src/modules/ResultRenderer.js
  function renderResults(results, query, options = {}) {
    const { groupResults = false, listboxId } = options;
    if (!results || results.length === 0) {
      return "";
    }
    if (groupResults) {
      const groups = groupResultsByType(results);
      let globalIndex = 0;
      return Object.entries(groups).map(([type, items]) => `
            <div class="sm-section" role="group" aria-label="${escapeHtml(type)}">
                <div class="sm-section-header">${escapeHtml(type)}</div>
                ${items.map((result) => renderResultItem(result, globalIndex++, query, options)).join("")}
            </div>
        `).join("");
    }
    return results.map((result, i) => renderResultItem(result, i, query, options)).join("");
  }
  function renderResultItem(result, index, query, options = {}) {
    const {
      listboxId,
      enableHighlighting = true,
      highlightTag = "mark",
      highlightClass = "",
      groupResults = false,
      promotions = {},
      debug = false
    } = options;
    const title = result.title || result.name || "Untitled";
    const description = result.description || result.excerpt || result.snippet || "";
    const url = result.url || result.href || "#";
    const type = result.section || result.type || "";
    const optionId = getOptionId(listboxId, index);
    const isPromoted = result.promoted === true;
    const highlightOptions = {
      enabled: enableHighlighting,
      tag: highlightTag,
      className: highlightClass
    };
    const highlightedTitle = highlightMatches(title, query, highlightOptions);
    const highlightedDesc = description ? highlightMatches(description, query, highlightOptions) : "";
    const promotedBadge = renderPromotedBadge(result, promotions);
    const promotedClass = isPromoted ? " sm-promoted" : "";
    const typeBadge = type && !groupResults ? `<span class="sm-result-type">${escapeHtml(type)}</span>` : "";
    const debugInfo = debug ? renderDebugInfo(result) : "";
    if (debug) {
      return `
            <a class="sm-result-item sm-debug-enabled${promotedClass}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-id="${result.id || ""}" data-title="${escapeHtml(title)}">
                <div class="sm-result-main">
                    ${promotedBadge}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${highlightedTitle}</span>
                        ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ""}
                    </div>
                    ${typeBadge}
                    <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
                ${debugInfo}
            </a>
        `;
    }
    return `
        <a class="sm-result-item${promotedClass}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-id="${result.id || ""}" data-title="${escapeHtml(title)}">
            ${promotedBadge}
            <div class="sm-result-content">
                <span class="sm-result-title">${highlightedTitle}</span>
                ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ""}
            </div>
            ${typeBadge}
            <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </a>
    `;
  }
  function renderDebugInfo(result) {
    const debugItems = [];
    const backendValue = result.backend ? result.backend.toLowerCase() : "";
    if (result._index || result.index) {
      debugItems.push(debugItem("index", result._index || result.index, "index"));
    }
    if (result.backend) {
      debugItems.push(debugItem("backend", backendValue, "backend", backendValue));
    }
    if (result.id) {
      debugItems.push(debugItem("id", result.id, "generic"));
    }
    if (result.score !== void 0 && result.score !== null) {
      const scoreDisplay = typeof result.score === "number" ? result.score.toFixed(2) : result.score;
      debugItems.push(debugItem("score", scoreDisplay, "score"));
    }
    if (result.site) {
      debugItems.push(debugItem("site", result.site, "generic"));
    }
    if (result.language) {
      debugItems.push(debugItem("lang", result.language, "generic"));
    }
    if (debugItems.length === 0) {
      return "";
    }
    return `<div class="sm-debug-info">${debugItems.join("")}</div>`;
  }
  function debugItem(label, value, type, backendType = "") {
    const backendAttr = backendType ? ` data-backend="${escapeHtml(backendType)}"` : "";
    return `<span class="sm-debug-item"><span class="sm-debug-label">${escapeHtml(label)}</span><span class="sm-debug-value" data-type="${escapeHtml(type)}"${backendAttr}>${escapeHtml(String(value))}</span></span>`;
  }
  function renderPromotedBadge(result, config = {}) {
    const {
      showBadge = true,
      badgeText = "Featured",
      badgePosition = "top-right"
    } = config;
    if (!result.promoted || !showBadge) {
      return "";
    }
    const positionClass = `sm-promoted-badge--${badgePosition}`;
    return `<span class="sm-promoted-badge ${positionClass}">${escapeHtml(badgeText)}</span>`;
  }
  function renderRecentSearches(recentSearches, listboxId) {
    if (!recentSearches || recentSearches.length === 0) {
      return "";
    }
    return `
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${listboxId}-recent-label">Recent searches</span>
                <button class="sm-clear-recent" part="clear-recent">Clear</button>
            </div>
            ${recentSearches.map((item, i) => `
                <div class="sm-result-item sm-recent-item" id="${getOptionId(listboxId, i)}" role="option" aria-selected="false" data-index="${i}" data-url="${item.url || ""}" data-query="${escapeHtml(item.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${escapeHtml(item.title || item.query)}</span>
                    <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            `).join("")}
        </div>
    `;
  }
  function renderEmptyState(query) {
    if (!query || !query.trim()) {
      return `
            <div class="sm-empty" part="empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <p>Start typing to search</p>
            </div>
        `;
    }
    return `
        <div class="sm-empty" part="empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <path d="m15 9-6 6M9 9l6 6"/>
            </svg>
            <p>No results for "<strong>${escapeHtml(query)}</strong>"</p>
        </div>
    `;
  }
  function renderLoadingState() {
    return `
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>Searching...</p>
        </div>
    `;
  }
  function getContentToRender(state, options) {
    const { query, results, recentSearches, loading, showRecent } = state;
    const hasQuery = query && query.trim();
    if (loading) {
      return {
        html: renderLoadingState(),
        hasResults: false,
        showListbox: false
      };
    }
    if (!hasQuery) {
      if (showRecent && recentSearches && recentSearches.length > 0) {
        return {
          html: renderRecentSearches(recentSearches, options.listboxId),
          hasResults: true,
          showListbox: true
        };
      }
      return {
        html: renderEmptyState(""),
        hasResults: false,
        showListbox: false
      };
    }
    if (!results || results.length === 0) {
      return {
        html: renderEmptyState(query),
        hasResults: false,
        showListbox: false
      };
    }
    return {
      html: renderResults(results, query, options),
      hasResults: true,
      showListbox: true
    };
  }

  // src/modules/DebugToolbar.js
  function renderDebugToolbarContent(meta, totalResults, collapsed = false) {
    if (!meta) {
      return "";
    }
    const items = [];
    items.push(toolbarItem("results", totalResults, "generic"));
    if (meta.took !== void 0) {
      const timeDisplay = meta.took < 1 ? "<1ms" : `${Math.round(meta.took)}ms`;
      items.push(toolbarItem("time", timeDisplay, "time"));
    }
    if (meta.cacheEnabled !== void 0) {
      if (!meta.cacheEnabled) {
        items.push(toolbarItem("cache", "off", "cache-off"));
      } else if (meta.cached) {
        items.push(toolbarItem("cache", "hit", "cache-hit"));
      } else {
        items.push(toolbarItem("cache", "miss", "cache-miss"));
      }
    }
    if (meta.cacheDriver) {
      items.push(toolbarItem("storage", meta.cacheDriver, "cache-driver", meta.cacheDriver));
    }
    if (meta.indices && meta.indices.length > 0) {
      const indicesDisplay = meta.indices.length > 2 ? `${meta.indices.length} indices` : meta.indices.join(", ");
      items.push(toolbarItem("indices", indicesDisplay, "generic"));
    }
    if (meta.synonymsExpanded) {
      const synonymCount = meta.expandedQueries ? meta.expandedQueries.length - 1 : 0;
      items.push(toolbarItem("synonyms", `+${synonymCount}`, "synonyms"));
    }
    const rulesCount = meta.rulesMatched?.length || 0;
    items.push(toolbarItem("rules", rulesCount, rulesCount > 0 ? "rules" : "generic"));
    const promotedCount = meta.promotionsMatched?.length || 0;
    items.push(toolbarItem("promoted", promotedCount, promotedCount > 0 ? "promotions" : "generic"));
    const toggleIcon = collapsed ? '<path d="M6 9l6 6 6-6"/>' : '<path d="M18 15l-6-6-6 6"/>';
    const toggleSvg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${toggleIcon}</svg>`;
    if (collapsed) {
      return `<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">Debug</span>${toggleSvg}</div>`;
    }
    return `<div class="sm-toolbar-content">${items.join("")}</div><button class="sm-toolbar-toggle" aria-label="Collapse debug panel" aria-expanded="true">${toggleSvg}</button>`;
  }
  function toolbarItem(label, value, type, backendType = "") {
    const backendAttr = backendType ? ` data-backend="${escapeHtml(backendType)}"` : "";
    return `<span class="sm-toolbar-item"><span class="sm-toolbar-label">${escapeHtml(label)}</span><span class="sm-toolbar-value" data-type="${escapeHtml(type)}"${backendAttr}>${escapeHtml(String(value))}</span></span>`;
  }

  // src/modules/KeyboardNavigator.js
  function createKeyboardNavigator(callbacks, config) {
    const { onSelect, onIndexChange, onEscape } = callbacks;
    const { listboxId } = config;
    return {
      /**
       * Handle keyboard events for navigation
       *
       * @param {KeyboardEvent} e - Keyboard event
       * @param {number} itemCount - Total number of navigable items
       * @param {number} currentIndex - Current selected index
       * @returns {number|null} New index if changed, null if no change
       */
      handleKeydown(e, itemCount, currentIndex) {
        let newIndex = currentIndex;
        switch (e.key) {
          case "ArrowDown":
            e.preventDefault();
            newIndex = Math.min(currentIndex + 1, itemCount - 1);
            if (newIndex !== currentIndex && onIndexChange) {
              onIndexChange(newIndex);
            }
            return newIndex;
          case "ArrowUp":
            e.preventDefault();
            newIndex = Math.max(currentIndex - 1, -1);
            if (newIndex !== currentIndex && onIndexChange) {
              onIndexChange(newIndex);
            }
            return newIndex;
          case "Enter":
            e.preventDefault();
            if (currentIndex >= 0 && onSelect) {
              onSelect(currentIndex);
            }
            return null;
          case "Escape":
            e.preventDefault();
            if (onEscape) {
              onEscape();
            }
            return null;
          default:
            return null;
        }
      },
      /**
       * Get the listbox ID for this navigator
       * @returns {string} Listbox ID
       */
      getListboxId() {
        return listboxId;
      }
    };
  }
  function updateSelectionState(items, selectedIndex, options = {}) {
    const {
      scrollContainer,
      inputElement,
      listboxId,
      selectedClass = "sm-selected"
    } = options;
    const activeId = selectedIndex >= 0 ? getOptionId(listboxId, selectedIndex) : null;
    if (inputElement) {
      updateComboboxAria(inputElement, {
        expanded: items.length > 0,
        activeDescendant: activeId,
        listboxId
      });
    }
    items.forEach((item, i) => {
      const isSelected = i === selectedIndex;
      item.classList.toggle(selectedClass, isSelected);
      item.setAttribute("aria-selected", String(isSelected));
      if (isSelected && scrollContainer) {
        scrollIntoViewIfNeeded(item, scrollContainer);
      }
    });
  }
  function attachHoverHandlers(items, onHover) {
    items.forEach((item, index) => {
      item.addEventListener("mouseenter", () => {
        if (onHover) {
          onHover(index);
        }
      });
    });
  }

  // src/core/SearchWidgetBase.js
  var SearchWidgetBase = class extends HTMLElement {
    /**
     * Initialize the base widget
     *
     * Sets up shadow DOM, state management, unique IDs, and binds methods.
     * Subclasses should call super() in their constructor.
     */
    constructor() {
      super();
      this.attachShadow({ mode: "open" });
      this.config = null;
      this.state = createStateManager(
        { ...DEFAULT_STATE },
        this.handleStateChange.bind(this)
      );
      this.abortController = null;
      this.debounceTimer = null;
      this.listboxId = generateId("sm-listbox");
      this.inputId = generateId("sm-input");
      this.liveRegion = null;
      this.keyboardNavigator = null;
      this.elements = {};
      this.handleInput = this.handleInput.bind(this);
      this.handleKeydown = this.handleKeydown.bind(this);
      this.handleResultClick = this.handleResultClick.bind(this);
    }
    // =========================================================================
    // ABSTRACT METHODS - Must be implemented by subclasses
    // =========================================================================
    /**
     * Get the widget type identifier
     *
     * @abstract
     * @returns {string} Widget type: 'modal', 'page', or 'inline'
     * @throws {Error} If not implemented by subclass
     *
     * @example
     * get widgetType() {
     *   return 'modal';
     * }
     */
    get widgetType() {
      throw new Error("Subclass must implement widgetType getter");
    }
    /**
     * Render the widget HTML structure
     *
     * Subclasses must implement this to render their specific UI.
     * Should set this.elements with references to key DOM elements.
     *
     * @abstract
     * @throws {Error} If not implemented by subclass
     *
     * @example
     * render() {
     *   this.shadowRoot.innerHTML = `<style>${styles}</style><div>...</div>`;
     *   this.elements = {
     *     input: this.shadowRoot.querySelector('.sm-input'),
     *     results: this.shadowRoot.querySelector('.sm-results'),
     *   };
     * }
     */
    render() {
      throw new Error("Subclass must implement render()");
    }
    /**
     * Get the results container element
     *
     * @abstract
     * @returns {HTMLElement} Results container
     * @throws {Error} If not implemented by subclass
     */
    getResultsContainer() {
      throw new Error("Subclass must implement getResultsContainer()");
    }
    /**
     * Get the search input element
     *
     * @abstract
     * @returns {HTMLInputElement} Search input
     * @throws {Error} If not implemented by subclass
     */
    getInputElement() {
      throw new Error("Subclass must implement getInputElement()");
    }
    /**
     * Get the loading indicator element (optional)
     *
     * @returns {HTMLElement|null} Loading element or null
     */
    getLoadingElement() {
      return this.elements.loading || null;
    }
    /**
     * Get the debug toolbar element (optional)
     *
     * Subclasses can override to provide a dedicated debug toolbar container.
     *
     * @returns {HTMLElement|null} Debug toolbar element or null
     */
    getDebugToolbarElement() {
      return this.elements.debugToolbar || null;
    }
    // =========================================================================
    // LIFECYCLE METHODS
    // =========================================================================
    /**
     * Called when element is added to the DOM
     *
     * Subclasses should call super.connectedCallback() first,
     * then perform their own initialization.
     */
    connectedCallback() {
      this.config = parseConfig(this, this.widgetType);
      this.state.set({
        recentSearches: loadRecentSearches(this.config.index)
      });
      this.keyboardNavigator = createKeyboardNavigator(
        {
          onSelect: (index) => this.selectResultAtIndex(index),
          onIndexChange: (index) => this.state.set({ selectedIndex: index }),
          onEscape: () => this.handleEscape()
        },
        { listboxId: this.listboxId }
      );
    }
    /**
     * Called when element is removed from the DOM
     *
     * Subclasses should call super.disconnectedCallback() to ensure cleanup.
     */
    disconnectedCallback() {
      if (this.abortController) {
        this.abortController.abort();
        this.abortController = null;
      }
      if (this.debounceTimer) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = null;
      }
    }
    /**
     * Called when an observed attribute changes
     *
     * Triggers re-render if the widget has been rendered.
     *
     * @param {string} name - Attribute name
     * @param {string|null} oldValue - Previous value
     * @param {string|null} newValue - New value
     */
    attributeChangedCallback(name, oldValue, newValue) {
      if (oldValue !== newValue && this.shadowRoot.children.length > 0) {
        this.config = parseConfig(this, this.widgetType);
        this.render();
        this.applyCustomStyles();
      }
    }
    // =========================================================================
    // STATE CHANGE HANDLING
    // =========================================================================
    /**
     * Handle state changes
     *
     * Called automatically when state.set() is used.
     * Subclasses can override to handle additional state changes.
     *
     * @protected
     * @param {Object} newState - The new state object
     * @param {Array<string>} changedKeys - Keys that changed
     */
    handleStateChange(newState, changedKeys) {
      if (changedKeys.includes("results") || changedKeys.includes("query") || changedKeys.includes("recentSearches")) {
        this.renderResultsContent();
      }
      if (changedKeys.includes("results") || changedKeys.includes("meta")) {
        this.updateDebugToolbar();
      }
      if (changedKeys.includes("selectedIndex")) {
        this.updateSelectionVisual();
      }
      if (changedKeys.includes("loading")) {
        this.updateLoadingVisual();
      }
    }
    // =========================================================================
    // SEARCH FUNCTIONALITY
    // =========================================================================
    /**
     * Handle input change with debouncing
     *
     * @param {Event} e - Input event
     */
    handleInput(e) {
      const query = e.target.value;
      this.state.set({
        query,
        selectedIndex: -1
      });
      if (this.debounceTimer) {
        clearTimeout(this.debounceTimer);
      }
      if (!query.trim()) {
        this.state.set({ results: [] });
        return;
      }
      if (query.length < this.config.minChars) {
        return;
      }
      this.debounceTimer = setTimeout(() => {
        this.executeSearch(query);
      }, this.config.debounce);
    }
    /**
     * Execute a search query
     *
     * @param {string} query - Search query
     */
    async executeSearch(query) {
      if (this.abortController) {
        this.abortController.abort();
      }
      this.abortController = new AbortController();
      this.state.set({ loading: true, error: null });
      if (this.liveRegion) {
        announce(this.liveRegion, getLoadingAnnouncement());
      }
      try {
        const { results, meta } = await performSearch({
          query,
          endpoint: this.config.endpoint,
          indices: this.config.indices,
          siteId: this.config.siteId,
          maxResults: this.config.maxResults,
          hideResultsWithoutUrl: this.config.hideResultsWithoutUrl,
          signal: this.abortController.signal
        });
        this.state.set({
          results,
          meta,
          loading: false,
          selectedIndex: results.length > 0 ? 0 : -1
        });
        if (this.liveRegion) {
          announce(this.liveRegion, getResultsAnnouncement(results.length, query));
        }
        this.dispatchWidgetEvent("search", { query, results, meta });
      } catch (error) {
        if (error.name === "AbortError") {
          return;
        }
        console.error("Search error:", error);
        this.state.set({
          results: [],
          loading: false,
          error: error.message
        });
        this.dispatchWidgetEvent("error", { query, error: error.message });
      }
    }
    // =========================================================================
    // RESULT RENDERING
    // =========================================================================
    /**
     * Render results content into the results container
     *
     * Determines what to show based on current state and renders it.
     */
    renderResultsContent() {
      const container = this.getResultsContainer();
      if (!container)
        return;
      const state = this.state.getAll();
      const { showRecent, groupResults, enableHighlighting, highlightTag, highlightClass, debug } = this.config;
      const { html, hasResults, showListbox } = getContentToRender(
        {
          query: state.query,
          results: state.results,
          recentSearches: state.recentSearches,
          loading: state.loading,
          showRecent
        },
        {
          listboxId: this.listboxId,
          groupResults,
          enableHighlighting,
          highlightTag,
          highlightClass,
          debug,
          promotions: this.config.promotions
        }
      );
      container.innerHTML = html;
      if (showListbox) {
        container.setAttribute("role", "listbox");
      } else {
        container.removeAttribute("role");
      }
      const input = this.getInputElement();
      if (input) {
        updateComboboxAria(input, {
          expanded: hasResults,
          activeDescendant: null,
          listboxId: this.listboxId
        });
      }
      if (this.liveRegion && !state.loading) {
        if (state.query && state.results.length === 0) {
          announce(this.liveRegion, getResultsAnnouncement(0, state.query));
        } else if (!state.query && state.recentSearches.length > 0 && showRecent) {
          announce(this.liveRegion, getRecentSearchesAnnouncement(state.recentSearches.length));
        }
      }
      this.attachResultHandlers();
      const clearBtn = container.querySelector(".sm-clear-recent");
      if (clearBtn) {
        clearBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          clearRecentSearches(this.config.index);
          this.state.set({ recentSearches: [] });
        });
      }
      if (hasResults && state.results.length > 0) {
        this.state.set({ selectedIndex: 0 });
      }
    }
    /**
     * Attach event handlers to result items
     *
     * Called after rendering results to wire up click and hover handlers.
     */
    attachResultHandlers() {
      const container = this.getResultsContainer();
      if (!container)
        return;
      const items = container.querySelectorAll(".sm-result-item");
      items.forEach((item) => {
        item.addEventListener("click", (e) => this.handleResultClick(e, item));
      });
      attachHoverHandlers(items, (index) => {
        this.state.set({ selectedIndex: index });
      });
    }
    // =========================================================================
    // SELECTION AND NAVIGATION
    // =========================================================================
    /**
     * Update visual selection state
     *
     * Highlights the selected item and updates ARIA attributes.
     */
    updateSelectionVisual() {
      const container = this.getResultsContainer();
      const input = this.getInputElement();
      if (!container)
        return;
      const items = container.querySelectorAll(".sm-result-item");
      const selectedIndex = this.state.get("selectedIndex");
      updateSelectionState(items, selectedIndex, {
        scrollContainer: container,
        inputElement: input,
        listboxId: this.listboxId
      });
    }
    /**
     * Handle keyboard navigation
     *
     * @param {KeyboardEvent} e - Keyboard event
     */
    handleKeydown(e) {
      const container = this.getResultsContainer();
      if (!container)
        return;
      const items = container.querySelectorAll(".sm-result-item");
      const currentIndex = this.state.get("selectedIndex");
      this.keyboardNavigator.handleKeydown(e, items.length, currentIndex);
    }
    /**
     * Select and activate the result at the given index
     *
     * @param {number} index - Index of result to select
     */
    selectResultAtIndex(index) {
      const container = this.getResultsContainer();
      if (!container)
        return;
      const items = container.querySelectorAll(".sm-result-item");
      if (index >= 0 && items[index]) {
        items[index].click();
      }
    }
    /**
     * Handle Escape key press
     *
     * Subclasses should override to implement close behavior.
     * @protected
     */
    handleEscape() {
    }
    // =========================================================================
    // RESULT CLICK HANDLING
    // =========================================================================
    /**
     * Handle result item click
     *
     * Handles navigation, recent search saving, and analytics tracking.
     *
     * @param {Event} e - Click event
     * @param {HTMLElement} item - Clicked item element
     */
    handleResultClick(e, item) {
      const href = item.getAttribute("href");
      const dataUrl = item.dataset.url;
      const url = href || dataUrl;
      const title = item.dataset.title || item.querySelector(".sm-result-title")?.textContent;
      const id = item.dataset.id;
      const query = item.dataset.query || this.state.get("query");
      const isRecentItem = item.classList.contains("sm-recent-item");
      if (!isRecentItem && query) {
        const updatedRecent = saveRecentSearch(
          this.config.index,
          query,
          { title, url },
          this.config.maxRecentSearches
        );
        this.state.set({ recentSearches: updatedRecent });
      }
      if (id && this.config.index) {
        trackClick({
          endpoint: this.config.analyticsEndpoint,
          elementId: id,
          query,
          index: this.config.index
        });
      }
      this.dispatchWidgetEvent("result-click", {
        id,
        title,
        url,
        query,
        isRecent: isRecentItem
      });
      if (url && url !== "#") {
        if (isRecentItem) {
          e.preventDefault();
          window.location.href = url;
        }
        this.onResultSelected(url, title, id);
      } else if (query) {
        e.preventDefault();
        const input = this.getInputElement();
        if (input) {
          input.value = query;
          this.state.set({ query });
          this.executeSearch(query);
        }
      }
    }
    /**
     * Called when a result with a URL is selected
     *
     * Subclasses can override to perform actions like closing the modal.
     *
     * @protected
     * @param {string} url - Result URL
     * @param {string} title - Result title
     * @param {string|number} id - Result ID
     */
    onResultSelected(url, title, id) {
    }
    // =========================================================================
    // LOADING STATE
    // =========================================================================
    /**
     * Update loading indicator visibility
     */
    updateLoadingVisual() {
      const loading = this.getLoadingElement();
      if (loading) {
        loading.hidden = !this.state.get("loading");
      }
    }
    /**
     * Update the debug toolbar
     *
     * Shows/hides and populates the debug toolbar based on state.
     */
    updateDebugToolbar() {
      const toolbar = this.getDebugToolbarElement();
      if (!toolbar)
        return;
      const { debug } = this.config;
      const state = this.state.getAll();
      if (!debug || !state.meta || state.results.length === 0) {
        toolbar.hidden = true;
        return;
      }
      const isCollapsed = toolbar.classList.contains("sm-collapsed");
      toolbar.innerHTML = renderDebugToolbarContent(state.meta, state.results.length, isCollapsed);
      toolbar.hidden = false;
      if (isCollapsed) {
        toolbar.classList.add("sm-collapsed");
      }
      this.attachDebugToolbarHandlers(toolbar);
    }
    /**
     * Attach click handlers to debug toolbar elements
     */
    attachDebugToolbarHandlers(toolbar) {
      const toggleBtn = toolbar.querySelector(".sm-toolbar-toggle");
      if (toggleBtn) {
        toggleBtn.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          this.toggleDebugToolbar();
        });
      }
      const collapsedBar = toolbar.querySelector(".sm-toolbar-collapsed-bar");
      if (collapsedBar) {
        collapsedBar.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          this.toggleDebugToolbar();
        });
      }
    }
    /**
     * Toggle debug toolbar collapsed state
     */
    toggleDebugToolbar() {
      const toolbar = this.getDebugToolbarElement();
      if (!toolbar)
        return;
      const isCollapsed = toolbar.classList.toggle("sm-collapsed");
      const state = this.state.getAll();
      toolbar.innerHTML = renderDebugToolbarContent(state.meta, state.results.length, isCollapsed);
      if (isCollapsed) {
        toolbar.classList.add("sm-collapsed");
      }
      this.attachDebugToolbarHandlers(toolbar);
    }
    // =========================================================================
    // STYLING
    // =========================================================================
    /**
     * Apply custom styles from config
     *
     * Applies CSS custom properties to the host element.
     * Subclasses can override to add additional styling logic.
     */
    applyCustomStyles() {
      if (!this.config)
        return;
      const host = this.shadowRoot.host;
      const { theme, styles: styles2 } = this.config;
      applyStylesToElement(host, styles2, theme);
    }
    // =========================================================================
    // ACCESSIBILITY
    // =========================================================================
    /**
     * Initialize the live region for screen reader announcements
     *
     * Call this from subclass render() after setting up the DOM.
     */
    initializeLiveRegion() {
      this.liveRegion = createLiveRegion(this.shadowRoot);
    }
    // =========================================================================
    // EVENT DISPATCHING
    // =========================================================================
    /**
     * Dispatch a custom widget event
     *
     * Events are prefixed with 'search-' and bubble up the DOM.
     *
     * @param {string} name - Event name (without 'search-' prefix)
     * @param {Object} detail - Event detail data
     *
     * @example
     * this.dispatchWidgetEvent('open', { source: 'hotkey' });
     * // Dispatches 'search-open' event
     */
    dispatchWidgetEvent(name, detail = {}) {
      this.dispatchEvent(new CustomEvent(`search-${name}`, {
        bubbles: true,
        composed: true,
        detail
      }));
    }
  };
  var SearchWidgetBase_default = SearchWidgetBase;

  // src/styles/base.css
  var base_default = `/**
 * Search Widget Base Styles
 *
 * Shared styles used by all widget types (modal, page, inline).
 * These styles handle results display, highlighting, loading states,
 * and accessibility utilities.
 *
 * @module styles/base
 * @author Search Manager
 * @since 5.x
 */

/* =========================================================================
   HOST & RESET
   ========================================================================= */

:host {
    /* Text colors - semantic naming with config variable mapping */
    /* Color contrast ratios meet WCAG 2.1 AA (4.5:1 for normal text) */
    --sm-text-primary: var(--sm-result-text-color, #111827);
    --sm-text-secondary: var(--sm-result-desc-color, #4b5563);
    --sm-text-muted: var(--sm-result-muted-color, #6b7280);

    /* Input defaults */
    --sm-input-bg: #ffffff;
    --sm-input-color: #111827;
    --sm-input-placeholder: #9ca3af;
    --sm-input-font-size: 16px;

    /* Borders and backgrounds - map from config variable names */
    --sm-border-color: var(--sm-input-border-color, #e5e7eb);
    --sm-hover-bg: var(--sm-result-hover-bg, #f3f4f6);
    --sm-selected-bg: var(--sm-result-active-bg, #e5e7eb);
    --sm-selected-border: #3b82f6;
    --sm-result-radius: 8px;

    /* Highlighting */
    --sm-highlight-bg: #fef08a;
    --sm-highlight-color: #854d0e;

    /* Kbd / keyboard shortcuts - 4.5:1 contrast ratio minimum */
    --sm-kbd-bg: #f3f4f6;
    --sm-kbd-border: #d1d5db;
    --sm-kbd-color: var(--sm-kbd-text-color, #4b5563);
    --sm-kbd-radius: 4px;

    /* Accent colors */
    --sm-accent: #3b82f6;
    --sm-accent-hover: #2563eb;

    display: inline-block;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

/* Dark theme base variables */
/* Color contrast ratios meet WCAG 2.1 AA (4.5:1 for normal text, 3:1 for large text) */
:host([data-theme="dark"]) {
    --sm-input-bg: var(--sm-input-bg-dark, #1f2937);
    --sm-input-color: var(--sm-input-color-dark, #f9fafb);
    --sm-input-placeholder: var(--sm-input-placeholder-dark, #9ca3af);

    --sm-text-primary: var(--sm-result-text-color-dark, #f9fafb);
    --sm-text-secondary: var(--sm-result-desc-color-dark, #d1d5db);
    --sm-text-muted: var(--sm-result-muted-color-dark, #9ca3af);

    --sm-border-color: var(--sm-input-border-color-dark, #374151);
    --sm-hover-bg: var(--sm-result-hover-bg-dark, #374151);
    --sm-selected-bg: var(--sm-result-active-bg-dark, #4b5563);
    --sm-selected-border: #3b82f6;

    --sm-highlight-bg: var(--sm-highlight-bg-dark, #854d0e);
    --sm-highlight-color: var(--sm-highlight-color-dark, #fef08a);

    --sm-kbd-bg: var(--sm-kbd-bg-dark, #374151);
    --sm-kbd-border: #4b5563;
    --sm-kbd-color: var(--sm-kbd-text-color-dark, #e5e7eb);
}

*, *::before, *::after {
    box-sizing: border-box;
}

/* =========================================================================
   INPUT
   ========================================================================= */

.sm-input {
    flex: 1;
    border: none;
    background: var(--sm-input-bg);
    color: var(--sm-input-color);
    font-size: var(--sm-input-font-size);
    outline: none;
}

.sm-input::placeholder {
    color: var(--sm-input-placeholder);
}

/* =========================================================================
   LOADING STATE
   ========================================================================= */

.sm-loading {
    flex-shrink: 0;
}

.sm-spinner {
    animation: sm-spin 1s linear infinite;
    color: var(--sm-accent);
}

@keyframes sm-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.sm-loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 48px 24px;
    color: var(--sm-text-muted);
    text-align: center;
}

.sm-loading-state p {
    margin: 0;
    font-size: 14px;
}

/* =========================================================================
   RESULTS CONTAINER
   ========================================================================= */

.sm-results {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

/* =========================================================================
   SECTION GROUPING
   ========================================================================= */

.sm-section {
    margin-bottom: 8px;
}

.sm-section:last-child {
    margin-bottom: 0;
}

.sm-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 600;
    color: var(--sm-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sm-clear-recent {
    padding: 2px 8px;
    background: transparent;
    border: none;
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    color: var(--sm-text-muted);
    cursor: pointer;
    text-transform: none;
    letter-spacing: normal;
}

.sm-clear-recent:hover {
    background: var(--sm-hover-bg);
    color: var(--sm-text-secondary);
}

/* =========================================================================
   RESULT ITEM
   ========================================================================= */

.sm-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: var(--sm-result-radius);
    color: var(--sm-text-primary);
    text-decoration: none;
    cursor: pointer;
    transition: background 0.1s ease;
}

.sm-result-item:hover,
.sm-result-item.sm-selected {
    background: var(--sm-hover-bg);
}

.sm-result-item.sm-selected {
    background: var(--sm-selected-bg);
    outline: 2px solid var(--sm-selected-border);
    outline-offset: -2px;
}

.sm-result-icon {
    flex-shrink: 0;
    color: var(--sm-text-muted);
}

.sm-result-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.sm-result-title {
    font-size: 14px;
    font-weight: 500;
    color: var(--sm-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sm-result-desc {
    font-size: 13px;
    color: var(--sm-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sm-result-type {
    flex-shrink: 0;
    padding: 2px 8px;
    background: var(--sm-kbd-bg);
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    color: var(--sm-text-muted);
}

.sm-result-arrow {
    flex-shrink: 0;
    color: var(--sm-text-muted);
    opacity: 0;
    transition: opacity 0.1s ease;
}

.sm-result-item:hover .sm-result-arrow,
.sm-result-item.sm-selected .sm-result-arrow {
    opacity: 1;
}

/* =========================================================================
   PROMOTED RESULTS
   ========================================================================= */

.sm-result-item.sm-promoted {
    position: relative;
}

.sm-promoted-badge {
    position: absolute;
    padding: 2px 6px;
    background: var(--sm-accent);
    color: #ffffff;
    font-size: 10px;
    font-weight: 600;
    border-radius: var(--sm-kbd-radius);
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.sm-promoted-badge--top-right {
    top: 4px;
    right: 4px;
}

.sm-promoted-badge--top-left {
    top: 4px;
    left: 4px;
}

.sm-promoted-badge--inline {
    position: static;
    margin-left: 8px;
}

/* =========================================================================
   HIGHLIGHTING
   ========================================================================= */

/* Uses .sm-highlight class to work with any tag (mark, span, etc.) */
.sm-highlight {
    background: var(--sm-highlight-bg);
    color: var(--sm-highlight-color);
    border-radius: 2px;
    padding: 0 2px;
}

/* =========================================================================
   EMPTY STATE
   ========================================================================= */

.sm-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 48px 24px;
    color: var(--sm-text-muted);
    text-align: center;
}

.sm-empty p {
    margin: 0;
    font-size: 14px;
}

.sm-empty strong {
    color: var(--sm-text-secondary);
}

/* =========================================================================
   ACCESSIBILITY
   ========================================================================= */

/* Screen reader only - visually hidden but accessible */
.sm-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* =========================================================================
   RTL SUPPORT (BASE)
   ========================================================================= */

:host([dir="rtl"]) .sm-result-item {
    direction: rtl;
}

:host([dir="rtl"]) .sm-result-arrow {
    transform: scaleX(-1);
}

/* =========================================================================
   DEBUG MODE - Developer Tools Panel
   Extensible key:value format with labels for clarity
   ========================================================================= */

/* Result item with debug enabled - column layout for full-width debug bar */
.sm-result-item.sm-debug-enabled {
    flex-direction: column;
    padding: 0;
    gap: 0;
}

/* Main content wrapper (icon, content, arrow) - flex row like normal result */
.sm-result-main {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    width: 100%;
}

/* Debug info bar - full width at bottom of result */
.sm-debug-info {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px 10px;
    width: 100%;
    padding: 6px 12px;
    background: #f1f5f9;
    border-top: 1px solid #e2e8f0;
    border-radius: 0 0 var(--sm-result-radius) var(--sm-result-radius);
    font-size: 10px;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Monaco, Consolas, monospace;
    line-height: 1.5;
    /* Debug info is always LTR - technical English labels/values */
    direction: ltr;
    text-align: left;
}

:host([data-theme="dark"]) .sm-debug-info {
    background: rgba(15, 23, 42, 0.6);
    border-top-color: #334155;
}

/* Each debug item: label + value pair */
.sm-debug-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}

/* Labels - dimmed, uppercase */
.sm-debug-label {
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.03em;
}

:host([data-theme="dark"]) .sm-debug-label {
    color: #94a3b8;
}

/* Values - base style */
.sm-debug-value {
    padding: 1px 5px;
    border-radius: 3px;
    font-weight: 500;
}

/* Backend values - color coded */
.sm-debug-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.15);
    color: #92400e;
}
.sm-debug-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.12);
    color: #991b1b;
}
.sm-debug-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.12);
    color: #6d28d9;
}
.sm-debug-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.12);
    color: #0e7490;
}
.sm-debug-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.12);
    color: #9d174d;
}
.sm-debug-value[data-backend="file"] {
    background: rgba(107, 114, 128, 0.12);
    color: #4b5563;
}
.sm-debug-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}
.sm-debug-value[data-backend="elasticsearch"] {
    background: rgba(254, 197, 20, 0.15);
    color: #a16207;
}

/* Index value - outlined style */
.sm-debug-value[data-type="index"] {
    background: transparent;
    border: 1px solid #cbd5e1;
    color: #475569;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="index"] {
    border-color: #475569;
    color: #94a3b8;
}

/* Generic values - subtle */
.sm-debug-value[data-type="generic"] {
    background: rgba(100, 116, 139, 0.1);
    color: #475569;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="generic"] {
    background: rgba(148, 163, 184, 0.15);
    color: #cbd5e1;
}

/* Score value - highlighted */
.sm-debug-value[data-type="score"] {
    background: rgba(34, 197, 94, 0.1);
    color: #166534;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="score"] {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
}

/* Dark mode backend colors */
:host([data-theme="dark"]) .sm-debug-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.2);
    color: #fca5a5;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.2);
    color: #c4b5fd;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.2);
    color: #67e8f9;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="file"] {
    background: rgba(156, 163, 175, 0.2);
    color: #d1d5db;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="elasticsearch"] {
    background: rgba(254, 197, 20, 0.2);
    color: #fde047;
}

/* =========================================================================
   DEBUG TOOLBAR - Floating search summary panel
   ========================================================================= */

.sm-debug-toolbar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    justify-content: center;
    gap: 0;
    padding: 0;
    background: linear-gradient(to bottom, #f8fafc, #f1f5f9);
    border-top: 1px solid #e2e8f0;
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.04);
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Monaco, Consolas, monospace;
    direction: ltr;
    text-align: center;
    flex-shrink: 0;
    overflow: hidden;
}

:host([data-theme="dark"]) .sm-debug-toolbar {
    background: linear-gradient(to bottom, #1e293b, #0f172a);
    border-top-color: #334155;
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.2);
}

/* Toggle button (right side when expanded) */
.sm-toolbar-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    padding: 0;
    background: transparent;
    border: none;
    border-left: 1px solid #e2e8f0;
    color: #94a3b8;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    flex-shrink: 0;
}

.sm-toolbar-toggle:hover {
    background: rgba(0, 0, 0, 0.05);
    color: #475569;
}

:host([data-theme="dark"]) .sm-toolbar-toggle {
    border-left-color: #334155;
    color: #64748b;
}

:host([data-theme="dark"]) .sm-toolbar-toggle:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #94a3b8;
}

/* Content wrapper */
.sm-toolbar-content {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    flex: 1;
    overflow-x: auto;
}

/* Collapsed bar - entire bar is clickable */
.sm-toolbar-collapsed-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.15s;
}

.sm-toolbar-collapsed-bar:hover {
    background: rgba(0, 0, 0, 0.03);
}

:host([data-theme="dark"]) .sm-toolbar-collapsed-bar:hover {
    background: rgba(255, 255, 255, 0.03);
}

.sm-toolbar-collapsed-label {
    color: #64748b;
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

:host([data-theme="dark"]) .sm-toolbar-collapsed-label {
    color: #94a3b8;
}

.sm-toolbar-collapsed-bar svg {
    color: #94a3b8;
}

:host([data-theme="dark"]) .sm-toolbar-collapsed-bar svg {
    color: #64748b;
}

/* Toolbar item - stacked vertically (value on top, label below) */
.sm-toolbar-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 8px 14px;
    border-right: 1px solid #e2e8f0;
}

.sm-toolbar-item:last-child {
    border-right: none;
}

:host([data-theme="dark"]) .sm-toolbar-item {
    border-right-color: #334155;
}

/* Label below value - small uppercase */
.sm-toolbar-label {
    order: 2; /* Put below value */
    color: #475569;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 8px;
    letter-spacing: 0.05em;
}

:host([data-theme="dark"]) .sm-toolbar-label {
    color: #94a3b8;
}

.sm-toolbar-value {
    padding: 2px 6px;
    border-radius: 3px;
    font-weight: 600;
}

/* Generic values */
.sm-toolbar-value[data-type="generic"] {
    background: rgba(100, 116, 139, 0.12);
    color: #334155;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="generic"] {
    background: rgba(148, 163, 184, 0.15);
    color: #e2e8f0;
}

/* Time - blue tint */
.sm-toolbar-value[data-type="time"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="time"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}

/* Cache hit - green */
.sm-toolbar-value[data-type="cache-hit"] {
    background: rgba(34, 197, 94, 0.15);
    color: #166534;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-hit"] {
    background: rgba(34, 197, 94, 0.2);
    color: #86efac;
}

/* Cache miss - amber */
.sm-toolbar-value[data-type="cache-miss"] {
    background: rgba(245, 158, 11, 0.15);
    color: #92400e;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-miss"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}

/* Cache off - gray */
.sm-toolbar-value[data-type="cache-off"] {
    background: rgba(107, 114, 128, 0.12);
    color: #6b7280;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-off"] {
    background: rgba(107, 114, 128, 0.2);
    color: #9ca3af;
}

/* Cache driver types */
.sm-toolbar-value[data-type="cache-driver"][data-backend="redis"] {
    background: rgba(220, 38, 38, 0.12);
    color: #b91c1c;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="file"] {
    background: rgba(107, 114, 128, 0.12);
    color: #4b5563;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="memcached"] {
    background: rgba(34, 197, 94, 0.12);
    color: #166534;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="database"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="apcu"] {
    background: rgba(168, 85, 247, 0.12);
    color: #7c3aed;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="redis"] {
    background: rgba(220, 38, 38, 0.2);
    color: #fca5a5;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="file"] {
    background: rgba(156, 163, 175, 0.2);
    color: #d1d5db;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="memcached"] {
    background: rgba(34, 197, 94, 0.2);
    color: #86efac;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="database"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="apcu"] {
    background: rgba(168, 85, 247, 0.2);
    color: #c4b5fd;
}

/* Synonyms - purple */
.sm-toolbar-value[data-type="synonyms"] {
    background: rgba(139, 92, 246, 0.12);
    color: #6d28d9;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="synonyms"] {
    background: rgba(139, 92, 246, 0.2);
    color: #c4b5fd;
}

/* Rules - cyan */
.sm-toolbar-value[data-type="rules"] {
    background: rgba(6, 182, 212, 0.12);
    color: #0e7490;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="rules"] {
    background: rgba(6, 182, 212, 0.2);
    color: #67e8f9;
}

/* Promotions - pink */
.sm-toolbar-value[data-type="promotions"] {
    background: rgba(236, 72, 153, 0.12);
    color: #9d174d;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="promotions"] {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
}

/* Backend values - reuse colors from debug info */
.sm-toolbar-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.15);
    color: #92400e;
}
.sm-toolbar-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.12);
    color: #991b1b;
}
.sm-toolbar-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.12);
    color: #6d28d9;
}
.sm-toolbar-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.12);
    color: #0e7490;
}
.sm-toolbar-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.12);
    color: #9d174d;
}
.sm-toolbar-value[data-backend="file"] {
    background: rgba(107, 114, 128, 0.12);
    color: #4b5563;
}
.sm-toolbar-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.2);
    color: #fca5a5;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.2);
    color: #c4b5fd;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.2);
    color: #67e8f9;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="file"] {
    background: rgba(156, 163, 175, 0.2);
    color: #d1d5db;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}
`;

  // src/styles/modal.css
  var modal_default = '/**\n * Search Widget Modal Styles\n *\n * Styles specific to the modal widget variant.\n * Includes backdrop, modal container, trigger button,\n * header, footer, and mobile responsive behavior.\n *\n * @module styles/modal\n * @author Search Manager\n * @since 5.x\n */\n\n/* =========================================================================\n   MODAL-SPECIFIC HOST VARIABLES\n   ========================================================================= */\n\n:host {\n    /* Modal container */\n    --sm-modal-bg: #ffffff;\n    --sm-modal-border: var(--sm-modal-border-color, #e5e7eb);\n    --sm-modal-border-width: 1px;\n    --sm-modal-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);\n    --sm-modal-radius: 12px;\n    --sm-modal-width: 640px;\n    --sm-modal-max-height: 80vh;\n\n    /* Trigger button */\n    --sm-trigger-bg: #ffffff;\n    --sm-trigger-color: var(--sm-trigger-text-color, #374151);\n    --sm-trigger-border: var(--sm-trigger-border-color, #d1d5db);\n    --sm-trigger-radius: 8px;\n    --sm-trigger-border-width: 1px;\n    --sm-trigger-px: 12px;\n    --sm-trigger-py: 8px;\n    --sm-trigger-font-size: 14px;\n}\n\n/* Dark theme - modal-specific overrides */\n:host([data-theme="dark"]) {\n    --sm-modal-bg: var(--sm-modal-bg-dark, #1f2937);\n    --sm-modal-border: var(--sm-modal-border-color-dark, #374151);\n\n    --sm-trigger-bg: var(--sm-trigger-bg-dark, #374151);\n    --sm-trigger-color: var(--sm-trigger-text-color-dark, #e5e7eb);\n    --sm-trigger-border: var(--sm-trigger-border-color-dark, #4b5563);\n}\n\n/* =========================================================================\n   TRIGGER BUTTON\n   ========================================================================= */\n\n.sm-trigger {\n    display: inline-flex;\n    align-items: center;\n    gap: 8px;\n    padding: var(--sm-trigger-py) var(--sm-trigger-px);\n    background: var(--sm-trigger-bg);\n    border: var(--sm-trigger-border-width) solid var(--sm-trigger-border);\n    border-radius: var(--sm-trigger-radius);\n    color: var(--sm-trigger-color);\n    font-size: var(--sm-trigger-font-size);\n    cursor: pointer;\n    transition: all 0.15s ease;\n}\n\n.sm-trigger:hover {\n    border-color: var(--sm-accent);\n    color: var(--sm-text-primary);\n}\n\n.sm-trigger-text {\n    /* Text shown next to search icon */\n}\n\n.sm-trigger-kbd {\n    display: inline-flex;\n    align-items: center;\n    padding: 2px 6px;\n    background: var(--sm-kbd-bg);\n    border: 1px solid var(--sm-kbd-border);\n    border-radius: var(--sm-kbd-radius);\n    font-size: 11px;\n    font-family: inherit;\n    color: var(--sm-kbd-color);\n}\n\n/* =========================================================================\n   BACKDROP\n   ========================================================================= */\n\n.sm-backdrop {\n    position: fixed;\n    inset: 0;\n    z-index: 99999;\n    display: flex;\n    align-items: flex-start;\n    justify-content: center;\n    padding-top: 10vh;\n    background: rgba(0, 0, 0, var(--sm-backdrop-opacity, 0.5));\n    backdrop-filter: var(--sm-backdrop-blur, blur(4px));\n    animation: sm-fade-in 0.15s ease;\n}\n\n.sm-backdrop[hidden] {\n    display: none;\n}\n\n@keyframes sm-fade-in {\n    from { opacity: 0; }\n    to { opacity: 1; }\n}\n\n/* =========================================================================\n   MODAL CONTAINER\n   ========================================================================= */\n\n.sm-modal {\n    width: var(--sm-modal-width);\n    max-width: calc(100vw - 32px);\n    max-height: var(--sm-modal-max-height);\n    background: var(--sm-modal-bg);\n    border: var(--sm-modal-border-width, 1px) solid var(--sm-modal-border);\n    border-radius: var(--sm-modal-radius);\n    box-shadow: var(--sm-modal-shadow);\n    display: flex;\n    flex-direction: column;\n    overflow: hidden;\n    animation: sm-slide-up 0.2s ease;\n}\n\n@keyframes sm-slide-up {\n    from {\n        opacity: 0;\n        transform: translateY(-10px) scale(0.98);\n    }\n    to {\n        opacity: 1;\n        transform: translateY(0) scale(1);\n    }\n}\n\n/* =========================================================================\n   MODAL HEADER\n   ========================================================================= */\n\n.sm-header {\n    display: flex;\n    align-items: center;\n    gap: 12px;\n    padding: 16px;\n    border-bottom: 1px solid var(--sm-border-color);\n}\n\n.sm-search-icon {\n    flex-shrink: 0;\n    color: var(--sm-text-muted);\n}\n\n.sm-close {\n    flex-shrink: 0;\n    display: flex;\n    align-items: center;\n    padding: 4px 8px;\n    background: transparent;\n    border: none;\n    cursor: pointer;\n}\n\n.sm-close kbd {\n    padding: 2px 6px;\n    background: var(--sm-kbd-bg);\n    border: 1px solid var(--sm-kbd-border);\n    border-radius: var(--sm-kbd-radius);\n    font-size: 11px;\n    font-family: inherit;\n    color: var(--sm-kbd-color);\n}\n\n/* =========================================================================\n   MODAL FOOTER\n   ========================================================================= */\n\n.sm-footer {\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n    gap: 16px;\n    padding: 12px 16px;\n    border-top: 1px solid var(--sm-border-color);\n    font-size: 12px;\n    color: var(--sm-text-muted);\n}\n\n.sm-footer-hints {\n    display: flex;\n    align-items: center;\n    gap: 12px;\n}\n\n.sm-footer-hints span {\n    display: flex;\n    align-items: center;\n    gap: 4px;\n}\n\n.sm-footer kbd {\n    display: inline-flex;\n    align-items: center;\n    justify-content: center;\n    min-width: 20px;\n    padding: 2px 4px;\n    background: var(--sm-kbd-bg);\n    border: 1px solid var(--sm-kbd-border);\n    border-radius: var(--sm-kbd-radius);\n    font-size: 10px;\n    font-family: inherit;\n    color: var(--sm-kbd-color);\n}\n\n.sm-footer-brand {\n    color: var(--sm-text-muted);\n}\n\n.sm-footer-brand strong {\n    color: var(--sm-text-secondary);\n}\n\n/* =========================================================================\n   RTL SUPPORT (MODAL-SPECIFIC)\n   ========================================================================= */\n\n:host([dir="rtl"]) .sm-header,\n:host([dir="rtl"]) .sm-footer {\n    direction: rtl;\n}\n\n/* =========================================================================\n   MOBILE RESPONSIVE\n   ========================================================================= */\n\n@media (max-width: 640px) {\n    .sm-backdrop {\n        padding-top: 0;\n        align-items: flex-end;\n    }\n\n    .sm-modal {\n        max-width: 100%;\n        max-height: 90vh;\n        border-radius: var(--sm-modal-radius) var(--sm-modal-radius) 0 0;\n    }\n\n    .sm-trigger-text,\n    .sm-footer-hints {\n        display: none;\n    }\n}\n';

  // src/widgets/SearchModalWidget.js
  var styles = base_default + "\n" + modal_default;
  var SearchModalWidget = class extends SearchWidgetBase_default {
    /**
     * Initialize modal widget
     */
    constructor() {
      super();
      this.externalTrigger = null;
      this.open = this.open.bind(this);
      this.close = this.close.bind(this);
      this.toggle = this.toggle.bind(this);
      this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);
      this.handleBackdropClick = this.handleBackdropClick.bind(this);
    }
    /**
     * Widget type identifier
     * @returns {string} 'modal'
     */
    get widgetType() {
      return "modal";
    }
    /**
     * Observed attributes for this widget type
     * @returns {Array<string>} Attribute names
     */
    static get observedAttributes() {
      return getObservedAttributes("modal");
    }
    // =========================================================================
    // LIFECYCLE
    // =========================================================================
    /**
     * Called when element is added to DOM
     */
    connectedCallback() {
      super.connectedCallback();
      this.render();
      this.attachEventListeners();
    }
    /**
     * Called when element is removed from DOM
     */
    disconnectedCallback() {
      super.disconnectedCallback();
      this.detachEventListeners();
    }
    // =========================================================================
    // RENDERING
    // =========================================================================
    /**
     * Render the modal HTML structure
     */
    render() {
      const { theme, placeholder, showTrigger } = this.config;
      this.shadowRoot.innerHTML = `
            <style>${styles}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="Open search" ${showTrigger ? "" : 'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">Search</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${this.getHotkeyDisplay()}</kbd>
            </button>

            <!-- Modal backdrop -->
            <div class="sm-backdrop" part="backdrop" hidden>
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="Search">
                    <!-- Search input -->
                    <div class="sm-header" part="header">
                        <svg class="sm-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input
                            type="text"
                            id="${this.inputId}"
                            class="sm-input"
                            part="input"
                            placeholder="${placeholder}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-controls="${this.listboxId}"
                        />
                        <div class="sm-loading" part="loading" hidden>
                            <svg class="sm-spinner" width="20" height="20" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <button class="sm-close" part="close" aria-label="Close search">
                            <kbd>esc</kbd>
                        </button>
                    </div>

                    <!-- Results -->
                    <div class="sm-results" part="results" id="${this.listboxId}" role="listbox" aria-label="Search results"></div>

                    <!-- Debug toolbar (sticky at bottom) -->
                    <div class="sm-debug-toolbar" part="debug-toolbar" hidden></div>

                    <!-- Footer -->
                    <div class="sm-footer" part="footer">
                        <div class="sm-footer-hints">
                            <span><kbd>\u2191</kbd><kbd>\u2193</kbd> navigate</span>
                            <span><kbd>\u21B5</kbd> select</span>
                            <span><kbd>esc</kbd> close</span>
                        </div>
                        <div class="sm-footer-brand">
                            Powered by <strong>Search Manager</strong>
                        </div>
                    </div>
                </div>
            </div>
        `;
      this.elements = {
        trigger: this.shadowRoot.querySelector(".sm-trigger"),
        backdrop: this.shadowRoot.querySelector(".sm-backdrop"),
        modal: this.shadowRoot.querySelector(".sm-modal"),
        input: this.shadowRoot.querySelector(".sm-input"),
        results: this.shadowRoot.querySelector(".sm-results"),
        loading: this.shadowRoot.querySelector(".sm-loading"),
        close: this.shadowRoot.querySelector(".sm-close"),
        debugToolbar: this.shadowRoot.querySelector(".sm-debug-toolbar")
      };
      this.initializeLiveRegion();
      this.shadowRoot.host.setAttribute("data-theme", theme);
      this.applyCustomStyles();
    }
    /**
     * Get results container element
     * @returns {HTMLElement} Results container
     */
    getResultsContainer() {
      return this.elements.results;
    }
    /**
     * Get search input element
     * @returns {HTMLInputElement} Search input
     */
    getInputElement() {
      return this.elements.input;
    }
    /**
     * Get loading indicator element
     * @returns {HTMLElement} Loading element
     */
    getLoadingElement() {
      return this.elements.loading;
    }
    // =========================================================================
    // CUSTOM STYLES (MODAL-SPECIFIC)
    // =========================================================================
    /**
     * Apply custom styles including modal-specific backdrop settings
     */
    applyCustomStyles() {
      super.applyCustomStyles();
      if (!this.config)
        return;
      const { backdropOpacity, enableBackdropBlur } = this.config;
      const host = this.shadowRoot.host;
      host.style.setProperty("--sm-backdrop-opacity", backdropOpacity / 100);
      host.style.setProperty("--sm-backdrop-blur", enableBackdropBlur ? "blur(4px)" : "none");
    }
    // =========================================================================
    // EVENT LISTENERS
    // =========================================================================
    /**
     * Attach modal-specific event listeners
     */
    attachEventListeners() {
      this.elements.trigger.addEventListener("click", this.toggle);
      this.elements.close.addEventListener("click", this.close);
      this.elements.backdrop.addEventListener("click", this.handleBackdropClick);
      this.elements.input.addEventListener("input", this.handleInput);
      this.elements.input.addEventListener("keydown", this.handleKeydown);
      document.addEventListener("keydown", this.handleGlobalKeydown);
      const { triggerSelector } = this.config;
      if (triggerSelector) {
        this.externalTrigger = document.querySelector(triggerSelector);
        if (this.externalTrigger) {
          this.externalTrigger.addEventListener("click", this.toggle);
        }
      }
    }
    /**
     * Detach modal-specific event listeners
     */
    detachEventListeners() {
      document.removeEventListener("keydown", this.handleGlobalKeydown);
      if (this.externalTrigger) {
        this.externalTrigger.removeEventListener("click", this.toggle);
        this.externalTrigger = null;
      }
    }
    // =========================================================================
    // MODAL OPEN/CLOSE
    // =========================================================================
    /**
     * Open the modal
     */
    open() {
      this.state.set({ isOpen: true });
      this.elements.backdrop.hidden = false;
      this.elements.input.value = "";
      this.state.set({
        query: "",
        results: [],
        selectedIndex: -1
      });
      this.renderResultsContent();
      requestAnimationFrame(() => {
        this.elements.input.focus();
      });
      if (this.config.preventBodyScroll) {
        document.body.style.overflow = "hidden";
      }
      this.dispatchWidgetEvent("open", { source: "programmatic" });
    }
    /**
     * Close the modal
     */
    close() {
      this.state.set({ isOpen: false });
      this.elements.backdrop.hidden = true;
      if (this.config.preventBodyScroll) {
        document.body.style.overflow = "";
      }
      this.dispatchWidgetEvent("close");
    }
    /**
     * Toggle modal open/close
     */
    toggle() {
      if (this.state.get("isOpen")) {
        this.close();
      } else {
        this.open();
      }
    }
    // =========================================================================
    // KEYBOARD HANDLING
    // =========================================================================
    /**
     * Handle global keyboard shortcuts
     *
     * @param {KeyboardEvent} e - Keyboard event
     */
    handleGlobalKeydown(e) {
      const hotkey = this.config.hotkey.toLowerCase();
      const isMac = navigator.platform.toUpperCase().indexOf("MAC") >= 0;
      const modifier = isMac ? e.metaKey : e.ctrlKey;
      if (modifier && e.key.toLowerCase() === hotkey) {
        e.preventDefault();
        this.toggle();
      }
      if (e.key === "Escape" && this.state.get("isOpen")) {
        e.preventDefault();
        this.close();
      }
    }
    /**
     * Handle Escape key from keyboard navigator
     * @protected
     */
    handleEscape() {
      this.close();
    }
    /**
     * Handle backdrop click
     *
     * @param {Event} e - Click event
     */
    handleBackdropClick(e) {
      if (e.target === this.elements.backdrop) {
        this.close();
      }
    }
    // =========================================================================
    // RESULT SELECTION HANDLING
    // =========================================================================
    /**
     * Called when a result with URL is selected
     *
     * Closes the modal after selection.
     *
     * @protected
     * @param {string} url - Result URL
     * @param {string} title - Result title
     * @param {string|number} id - Result ID
     */
    onResultSelected(url, title, id) {
      this.close();
    }
    // =========================================================================
    // HELPERS
    // =========================================================================
    /**
     * Get hotkey display string
     *
     * @returns {string} Formatted hotkey (e.g., "⌘K" or "Ctrl+K")
     */
    getHotkeyDisplay() {
      const isMac = navigator.platform.toUpperCase().indexOf("MAC") >= 0;
      const key = this.config.hotkey.toUpperCase();
      return isMac ? `\u2318${key}` : `Ctrl+${key}`;
    }
  };
  var SearchModalWidget_default = SearchModalWidget;
  return __toCommonJS(SearchModalWidget_exports);
})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
