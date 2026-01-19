(() => {
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
    return processedValue;
  }
  function applyStylesToElement(element, styles, theme = "light") {
    if (!styles || typeof styles !== "object")
      return;
    const isDark = theme === "dark";
    for (const [key, cssVar] of Object.entries(STYLE_MAPPINGS)) {
      const isDarkKey = key.endsWith("Dark");
      if (isDark && !isDarkKey)
        continue;
      if (!isDark && isDarkKey)
        continue;
      if (styles[key] !== void 0 && styles[key] !== null && styles[key] !== "") {
        const value = processStyleValue(key, styles[key]);
        if (value) {
          element.style.setProperty(cssVar, value);
        }
      }
    }
  }

  // src/modules/RecentSearches.js
  var MAX_RECENT_SEARCHES = 5;
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
  function saveRecentSearch(index, query, result = null) {
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
    recentSearches = recentSearches.slice(0, MAX_RECENT_SEARCHES);
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

  // src/modules/SearchService.js
  async function performSearch({ query, endpoint, indices = [], siteId = "", maxResults = 10, signal }) {
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
    return data.results || data.hits || [];
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

  // src/styles/SearchWidget.css
  var SearchWidget_default = `/**
 * Search Widget Styles
 * These styles are injected into the shadow DOM
 */

:host {
    /* Modal defaults - inline styles from config will override same-name vars */
    --sm-modal-bg: #ffffff;
    --sm-modal-border: var(--sm-modal-border-color, #e5e7eb);
    --sm-modal-border-width: 1px;
    --sm-modal-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    --sm-modal-radius: 12px;
    --sm-modal-width: 640px;
    --sm-modal-max-height: 80vh;

    /* Input defaults */
    --sm-input-bg: #ffffff;
    --sm-input-color: #111827;
    --sm-input-placeholder: #9ca3af;
    --sm-input-font-size: 16px;

    /* Text colors - map from config variable names */
    /* Color contrast ratios meet WCAG 2.1 AA (4.5:1 for normal text) */
    --sm-text-primary: var(--sm-result-text-color, #111827);
    --sm-text-secondary: var(--sm-result-desc-color, #4b5563);
    --sm-text-muted: var(--sm-result-muted-color, #6b7280);

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

    /* Trigger button */
    --sm-trigger-bg: #ffffff;
    --sm-trigger-color: var(--sm-trigger-text-color, #374151);
    --sm-trigger-border: var(--sm-trigger-border-color, #d1d5db);
    --sm-trigger-radius: 8px;
    --sm-trigger-border-width: 1px;
    --sm-trigger-px: 12px;
    --sm-trigger-py: 8px;
    --sm-trigger-font-size: 14px;

    /* Accent colors */
    --sm-accent: #3b82f6;
    --sm-accent-hover: #2563eb;

    display: inline-block;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

/* Dark theme - uses custom dark variables with fallbacks */
/* Color contrast ratios meet WCAG 2.1 AA (4.5:1 for normal text, 3:1 for large text) */
:host([data-theme="dark"]) {
    --sm-modal-bg: var(--sm-modal-bg-dark, #1f2937);
    --sm-modal-border: var(--sm-modal-border-color-dark, #374151);

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

    --sm-trigger-bg: var(--sm-trigger-bg-dark, #374151);
    --sm-trigger-color: var(--sm-trigger-text-color-dark, #e5e7eb);
    --sm-trigger-border: var(--sm-trigger-border-color-dark, #4b5563);
}

*, *::before, *::after {
    box-sizing: border-box;
}

/* Trigger button */
.sm-trigger {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: var(--sm-trigger-py) var(--sm-trigger-px);
    background: var(--sm-trigger-bg);
    border: var(--sm-trigger-border-width) solid var(--sm-trigger-border);
    border-radius: var(--sm-trigger-radius);
    color: var(--sm-trigger-color);
    font-size: var(--sm-trigger-font-size);
    cursor: pointer;
    transition: all 0.15s ease;
}

.sm-trigger:hover {
    border-color: var(--sm-accent);
    color: var(--sm-text-primary);
}

.sm-trigger-kbd {
    display: inline-flex;
    align-items: center;
    padding: 2px 6px;
    background: var(--sm-kbd-bg);
    border: 1px solid var(--sm-kbd-border);
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    font-family: inherit;
    color: var(--sm-kbd-color);
}

/* Backdrop */
.sm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 10vh;
    background: rgba(0, 0, 0, var(--sm-backdrop-opacity, 0.5));
    backdrop-filter: var(--sm-backdrop-blur, blur(4px));
    animation: sm-fade-in 0.15s ease;
}

.sm-backdrop[hidden] {
    display: none;
}

@keyframes sm-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Modal */
.sm-modal {
    width: var(--sm-modal-width);
    max-width: calc(100vw - 32px);
    max-height: var(--sm-modal-max-height);
    background: var(--sm-modal-bg);
    border: var(--sm-modal-border-width, 1px) solid var(--sm-modal-border);
    border-radius: var(--sm-modal-radius);
    box-shadow: var(--sm-modal-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: sm-slide-up 0.2s ease;
}

@keyframes sm-slide-up {
    from {
        opacity: 0;
        transform: translateY(-10px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Header */
.sm-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--sm-border-color);
}

.sm-search-icon {
    flex-shrink: 0;
    color: var(--sm-text-muted);
}

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

.sm-close {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    padding: 4px 8px;
    background: transparent;
    border: none;
    cursor: pointer;
}

.sm-close kbd {
    padding: 2px 6px;
    background: var(--sm-kbd-bg);
    border: 1px solid var(--sm-kbd-border);
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    font-family: inherit;
    color: var(--sm-kbd-color);
}

/* Results */
.sm-results {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

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

/* Result item */
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

/* Highlight - uses .sm-highlight class to work with any tag */
.sm-highlight {
    background: var(--sm-highlight-bg);
    color: var(--sm-highlight-color);
    border-radius: 2px;
    padding: 0 2px;
}

/* Empty state */
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

/* Footer */
.sm-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 16px;
    border-top: 1px solid var(--sm-border-color);
    font-size: 12px;
    color: var(--sm-text-muted);
}

.sm-footer-hints {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sm-footer-hints span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.sm-footer kbd {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    padding: 2px 4px;
    background: var(--sm-kbd-bg);
    border: 1px solid var(--sm-kbd-border);
    border-radius: var(--sm-kbd-radius);
    font-size: 10px;
    font-family: inherit;
    color: var(--sm-kbd-color);
}

.sm-footer-brand {
    color: var(--sm-text-muted);
}

.sm-footer-brand strong {
    color: var(--sm-text-secondary);
}

/* RTL support */
:host([dir="rtl"]) .sm-header,
:host([dir="rtl"]) .sm-result-item,
:host([dir="rtl"]) .sm-footer {
    direction: rtl;
}

:host([dir="rtl"]) .sm-result-arrow {
    transform: scaleX(-1);
}

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

/* Mobile */
@media (max-width: 640px) {
    .sm-backdrop {
        padding-top: 0;
        align-items: flex-end;
    }

    .sm-modal {
        max-width: 100%;
        max-height: 90vh;
        border-radius: var(--sm-modal-radius) var(--sm-modal-radius) 0 0;
    }

    .sm-trigger-text,
    .sm-footer-hints {
        display: none;
    }
}
`;

  // src/SearchWidget.js
  var SearchWidget = class extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({ mode: "open" });
      this.isOpen = false;
      this.results = [];
      this.recentSearches = [];
      this.selectedIndex = -1;
      this.loading = false;
      this.query = "";
      this.debounceTimer = null;
      this.abortController = null;
      this.listboxId = generateId("sm-listbox");
      this.inputId = generateId("sm-input");
      this.liveRegion = null;
      this.open = this.open.bind(this);
      this.close = this.close.bind(this);
      this.toggle = this.toggle.bind(this);
      this.handleKeydown = this.handleKeydown.bind(this);
      this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);
      this.handleInput = this.handleInput.bind(this);
      this.handleResultClick = this.handleResultClick.bind(this);
      this.handleBackdropClick = this.handleBackdropClick.bind(this);
    }
    // Observed attributes
    static get observedAttributes() {
      return [
        "indices",
        "placeholder",
        "endpoint",
        "theme",
        "max-results",
        "debounce",
        "min-chars",
        "show-recent",
        "group-results",
        "hotkey",
        "site-id",
        "enable-highlighting",
        "highlight-tag",
        "highlight-class",
        "backdrop-opacity",
        "enable-backdrop-blur",
        "prevent-body-scroll",
        "show-trigger",
        "trigger-selector",
        "styles"
      ];
    }
    // Parse styles JSON attribute
    get styles() {
      const stylesAttr = this.getAttribute("styles");
      if (stylesAttr) {
        try {
          return JSON.parse(stylesAttr);
        } catch (e) {
          console.warn("SearchWidget: Invalid styles JSON", e);
        }
      }
      return {};
    }
    // Configuration from attributes
    get config() {
      const indicesAttr = this.getAttribute("indices") || "";
      const indices = indicesAttr ? indicesAttr.split(",").map((s) => s.trim()).filter(Boolean) : [];
      return {
        indices,
        index: indices[0] || "",
        placeholder: this.getAttribute("placeholder") || "Search...",
        endpoint: this.getAttribute("endpoint") || "/actions/search-manager/search/query",
        theme: this.getAttribute("theme") || "light",
        maxResults: parseInt(this.getAttribute("max-results")) || 10,
        debounce: parseInt(this.getAttribute("debounce")) || 200,
        minChars: parseInt(this.getAttribute("min-chars")) || 2,
        showRecent: this.getAttribute("show-recent") !== "false",
        groupResults: this.getAttribute("group-results") !== "false",
        hotkey: this.getAttribute("hotkey") || "k",
        siteId: this.getAttribute("site-id") || "",
        analyticsEndpoint: this.getAttribute("analytics-endpoint") || "/actions/search-manager/search/track-click",
        enableHighlighting: this.getAttribute("enable-highlighting") !== "false",
        highlightTag: this.getAttribute("highlight-tag") || "mark",
        highlightClass: this.getAttribute("highlight-class") || "",
        backdropOpacity: parseInt(this.getAttribute("backdrop-opacity")) || 50,
        enableBackdropBlur: this.getAttribute("enable-backdrop-blur") !== "false",
        preventBodyScroll: this.getAttribute("prevent-body-scroll") !== "false",
        showTrigger: this.getAttribute("show-trigger") !== "false",
        triggerSelector: this.getAttribute("trigger-selector") || ""
      };
    }
    connectedCallback() {
      this.render();
      this.recentSearches = loadRecentSearches(this.config.index);
      this.attachEventListeners();
    }
    disconnectedCallback() {
      this.detachEventListeners();
    }
    attributeChangedCallback(name, oldValue, newValue) {
      if (oldValue !== newValue && this.shadowRoot.querySelector(".sm-modal")) {
        this.render();
      }
    }
    // Render the component
    render() {
      const { theme, placeholder, showTrigger } = this.config;
      this.shadowRoot.innerHTML = `
            <style>${SearchWidget_default}</style>

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
        close: this.shadowRoot.querySelector(".sm-close")
      };
      this.liveRegion = createLiveRegion(this.shadowRoot);
      this.shadowRoot.host.setAttribute("data-theme", theme);
      this.applyCustomStyles();
    }
    // Apply custom CSS properties
    applyCustomStyles() {
      const { backdropOpacity, enableBackdropBlur, theme } = this.config;
      const host = this.shadowRoot.host;
      host.style.setProperty("--sm-backdrop-opacity", backdropOpacity / 100);
      host.style.setProperty("--sm-backdrop-blur", enableBackdropBlur ? "blur(4px)" : "none");
      applyStylesToElement(host, this.styles, theme);
    }
    // Get hotkey display
    getHotkeyDisplay() {
      const isMac = navigator.platform.toUpperCase().indexOf("MAC") >= 0;
      const key = this.config.hotkey.toUpperCase();
      return isMac ? `\u2318${key}` : `Ctrl+${key}`;
    }
    // Attach event listeners
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
    // Detach event listeners
    detachEventListeners() {
      document.removeEventListener("keydown", this.handleGlobalKeydown);
      if (this.externalTrigger) {
        this.externalTrigger.removeEventListener("click", this.toggle);
        this.externalTrigger = null;
      }
    }
    // Handle global keyboard shortcuts
    handleGlobalKeydown(e) {
      const hotkey = this.config.hotkey.toLowerCase();
      const isMac = navigator.platform.toUpperCase().indexOf("MAC") >= 0;
      const modifier = isMac ? e.metaKey : e.ctrlKey;
      if (modifier && e.key.toLowerCase() === hotkey) {
        e.preventDefault();
        this.toggle();
      }
      if (e.key === "Escape" && this.isOpen) {
        e.preventDefault();
        this.close();
      }
    }
    // Handle input keydown for navigation
    handleKeydown(e) {
      const items = this.shadowRoot.querySelectorAll(".sm-result-item");
      const count = items.length;
      switch (e.key) {
        case "ArrowDown":
          e.preventDefault();
          this.selectedIndex = Math.min(this.selectedIndex + 1, count - 1);
          this.updateSelection();
          break;
        case "ArrowUp":
          e.preventDefault();
          this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
          this.updateSelection();
          break;
        case "Enter":
          e.preventDefault();
          if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
            items[this.selectedIndex].click();
          }
          break;
        case "Escape":
          e.preventDefault();
          this.close();
          break;
      }
    }
    // Update selection highlight and ARIA state
    updateSelection() {
      const items = this.shadowRoot.querySelectorAll(".sm-result-item");
      const activeId = this.selectedIndex >= 0 ? getOptionId(this.listboxId, this.selectedIndex) : null;
      updateComboboxAria(this.elements.input, {
        expanded: items.length > 0,
        activeDescendant: activeId,
        listboxId: this.listboxId
      });
      items.forEach((item, i) => {
        const isSelected = i === this.selectedIndex;
        item.classList.toggle("sm-selected", isSelected);
        item.setAttribute("aria-selected", String(isSelected));
        if (isSelected) {
          scrollIntoViewIfNeeded(item, this.elements.results);
        }
      });
    }
    // Handle backdrop click
    handleBackdropClick(e) {
      if (e.target === this.elements.backdrop) {
        this.close();
      }
    }
    // Handle input change
    handleInput(e) {
      this.query = e.target.value;
      this.selectedIndex = -1;
      if (this.debounceTimer) {
        clearTimeout(this.debounceTimer);
      }
      if (!this.query.trim()) {
        this.results = [];
        this.renderResults();
        return;
      }
      if (this.query.length < this.config.minChars) {
        return;
      }
      this.debounceTimer = setTimeout(() => {
        this.search(this.query);
      }, this.config.debounce);
    }
    // Perform search
    async search(query) {
      if (this.abortController) {
        this.abortController.abort();
      }
      this.abortController = new AbortController();
      this.loading = true;
      this.elements.loading.hidden = false;
      announce(this.liveRegion, getLoadingAnnouncement());
      try {
        this.results = await performSearch({
          query,
          endpoint: this.config.endpoint,
          indices: this.config.indices,
          siteId: this.config.siteId,
          maxResults: this.config.maxResults,
          signal: this.abortController.signal
        });
        this.renderResults();
      } catch (error) {
        if (error.name !== "AbortError") {
          console.error("Search error:", error);
          this.results = [];
          this.renderResults();
        }
      } finally {
        this.loading = false;
        this.elements.loading.hidden = true;
      }
    }
    // Render results
    renderResults() {
      const container = this.elements.results;
      const { showRecent, groupResults } = this.config;
      if (!this.query.trim() && showRecent && this.recentSearches.length > 0) {
        container.innerHTML = `
                <div class="sm-section">
                    <div class="sm-section-header">
                        <span id="${this.listboxId}-recent-label">Recent searches</span>
                        <button class="sm-clear-recent" part="clear-recent">Clear</button>
                    </div>
                    ${this.recentSearches.map((item, i) => `
                        <div class="sm-result-item sm-recent-item" id="${getOptionId(this.listboxId, i)}" role="option" aria-selected="false" data-index="${i}" data-url="${item.url || ""}" data-query="${this.escapeHtml(item.query)}">
                            <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <span class="sm-result-title">${this.escapeHtml(item.title || item.query)}</span>
                        </div>
                    `).join("")}
                </div>
            `;
        announce(this.liveRegion, getRecentSearchesAnnouncement(this.recentSearches.length));
        const clearBtn = container.querySelector(".sm-clear-recent");
        if (clearBtn) {
          clearBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            clearRecentSearches(this.config.index);
            this.recentSearches = [];
            this.renderResults();
          });
        }
        this.attachResultHandlers();
        return;
      }
      if (!this.query.trim()) {
        container.removeAttribute("role");
        container.innerHTML = `
                <div class="sm-empty" part="empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <p>Start typing to search</p>
                </div>
            `;
        updateComboboxAria(this.elements.input, {
          expanded: false,
          activeDescendant: null,
          listboxId: this.listboxId
        });
        return;
      }
      if (this.results.length === 0) {
        container.removeAttribute("role");
        container.innerHTML = `
                <div class="sm-empty" part="empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="m15 9-6 6M9 9l6 6"/>
                    </svg>
                    <p>No results for "<strong>${this.escapeHtml(this.query)}</strong>"</p>
                </div>
            `;
        announce(this.liveRegion, getResultsAnnouncement(0, this.query));
        updateComboboxAria(this.elements.input, {
          expanded: false,
          activeDescendant: null,
          listboxId: this.listboxId
        });
        return;
      }
      container.setAttribute("role", "listbox");
      if (groupResults) {
        const groups = groupResultsByType(this.results);
        let globalIndex = 0;
        container.innerHTML = Object.entries(groups).map(([type, items]) => `
                <div class="sm-section" role="group" aria-label="${this.escapeHtml(type)}">
                    <div class="sm-section-header">${this.escapeHtml(type)}</div>
                    ${items.map((result) => this.renderResultItem(result, globalIndex++)).join("")}
                </div>
            `).join("");
      } else {
        container.innerHTML = this.results.map((result, i) => this.renderResultItem(result, i)).join("");
      }
      announce(this.liveRegion, getResultsAnnouncement(this.results.length, this.query));
      updateComboboxAria(this.elements.input, {
        expanded: true,
        activeDescendant: null,
        listboxId: this.listboxId
      });
      if (this.results.length > 0) {
        this.selectedIndex = 0;
        this.updateSelection();
      }
      this.attachResultHandlers();
    }
    // Render single result item
    renderResultItem(result, index) {
      const title = result.title || result.name || "Untitled";
      const description = result.description || result.excerpt || result.snippet || "";
      const url = result.url || result.href || "#";
      const type = result.section || result.type || "";
      const optionId = getOptionId(this.listboxId, index);
      const highlightedTitle = this.highlightMatches(title, this.query);
      const highlightedDesc = description ? this.highlightMatches(description, this.query) : "";
      return `
            <a class="sm-result-item" id="${optionId}" role="option" aria-selected="false" href="${this.escapeHtml(url)}" data-index="${index}" data-id="${result.id || ""}" data-title="${this.escapeHtml(title)}">
                <div class="sm-result-content">
                    <span class="sm-result-title">${highlightedTitle}</span>
                    ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ""}
                </div>
                ${type && !this.config.groupResults ? `<span class="sm-result-type">${this.escapeHtml(type)}</span>` : ""}
                <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        `;
    }
    // Highlight matching text
    highlightMatches(text, query) {
      if (!query)
        return this.escapeHtml(text);
      const escaped = this.escapeHtml(text);
      if (!this.config.enableHighlighting) {
        return escaped;
      }
      const queryWords = query.toLowerCase().split(/\s+/).filter((w) => w.length > 0);
      const { highlightTag, highlightClass } = this.config;
      const classes = ["sm-highlight"];
      if (highlightClass) {
        classes.push(highlightClass);
      }
      const classAttr = ` class="${classes.join(" ")}"`;
      let result = escaped;
      queryWords.forEach((word) => {
        const regex = new RegExp(`\\b(${this.escapeRegex(word)})\\b`, "gi");
        result = result.replace(regex, `<${highlightTag}${classAttr}>$1</${highlightTag}>`);
      });
      return result;
    }
    // Attach handlers to result items
    attachResultHandlers() {
      const items = this.shadowRoot.querySelectorAll(".sm-result-item");
      items.forEach((item) => {
        item.addEventListener("click", (e) => this.handleResultClick(e, item));
        item.addEventListener("mouseenter", () => {
          this.selectedIndex = parseInt(item.dataset.index) || 0;
          this.updateSelection();
        });
      });
    }
    // Handle result click
    handleResultClick(e, item) {
      const url = item.getAttribute("href") || item.dataset.url;
      const title = item.dataset.title || item.querySelector(".sm-result-title")?.textContent;
      const id = item.dataset.id;
      const query = item.dataset.query || this.query;
      this.recentSearches = saveRecentSearch(this.config.index, query, { title, url });
      if (id && this.config.index) {
        trackClick({
          endpoint: this.config.analyticsEndpoint,
          elementId: id,
          query,
          index: this.config.index
        });
      }
      if (url && url !== "#") {
        this.close();
      } else if (query) {
        e.preventDefault();
        this.elements.input.value = query;
        this.query = query;
        this.search(query);
      }
    }
    // Open modal
    open() {
      this.isOpen = true;
      this.elements.backdrop.hidden = false;
      this.elements.input.value = "";
      this.query = "";
      this.results = [];
      this.selectedIndex = -1;
      this.renderResults();
      requestAnimationFrame(() => {
        this.elements.input.focus();
      });
      if (this.config.preventBodyScroll) {
        document.body.style.overflow = "hidden";
      }
      this.dispatchEvent(new CustomEvent("search-open"));
    }
    // Close modal
    close() {
      this.isOpen = false;
      this.elements.backdrop.hidden = true;
      if (this.config.preventBodyScroll) {
        document.body.style.overflow = "";
      }
      this.dispatchEvent(new CustomEvent("search-close"));
    }
    // Toggle modal
    toggle() {
      this.isOpen ? this.close() : this.open();
    }
    // Escape HTML
    escapeHtml(text) {
      const div = document.createElement("div");
      div.textContent = text;
      return div.innerHTML;
    }
    // Escape regex special characters
    escapeRegex(string) {
      return string.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    }
  };
  customElements.define("search-widget", SearchWidget);
  var SearchWidget_default2 = SearchWidget;
})();
