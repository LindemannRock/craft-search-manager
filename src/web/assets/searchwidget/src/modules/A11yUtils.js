/**
 * Accessibility Utilities
 *
 * Helper functions for WCAG 2.1 AA compliance in the search widget.
 * Implements WAI-ARIA combobox pattern with list autocomplete.
 *
 * @see https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
 */

// Counter for generating unique IDs
let idCounter = 0;

/**
 * Generate a unique ID for ARIA references
 * @param {string} prefix - Prefix for the ID
 * @returns {string} Unique ID
 */
export function generateId(prefix = 'sm') {
    return `${prefix}-${++idCounter}-${Date.now().toString(36)}`;
}

/**
 * Create a live region element for screen reader announcements
 * @param {ShadowRoot} shadowRoot - The shadow root to append to
 * @returns {HTMLElement} The live region element
 */
export function createLiveRegion(shadowRoot) {
    const liveRegion = document.createElement('div');
    liveRegion.setAttribute('role', 'status');
    liveRegion.setAttribute('aria-live', 'polite');
    liveRegion.setAttribute('aria-atomic', 'true');
    liveRegion.className = 'sm-sr-only';
    shadowRoot.appendChild(liveRegion);
    return liveRegion;
}

/**
 * Announce a message to screen readers via live region
 * @param {HTMLElement} liveRegion - The live region element
 * @param {string} message - Message to announce
 * @param {number} delay - Delay before announcing (ms)
 */
export function announce(liveRegion, message, delay = 100) {
    if (!liveRegion) return;

    // Clear first, then set after delay to ensure announcement
    liveRegion.textContent = '';

    setTimeout(() => {
        liveRegion.textContent = message;
    }, delay);
}

/**
 * Get announcement message for search results
 * @param {number} count - Number of results
 * @param {string} query - Search query
 * @returns {string} Announcement message
 */
export function getResultsAnnouncement(count, query) {
    if (count === 0) {
        return `No results found for "${query}"`;
    }
    if (count === 1) {
        return `1 result found for "${query}"`;
    }
    return `${count} results found for "${query}"`;
}

/**
 * Get announcement for loading state
 * @returns {string} Loading announcement
 */
export function getLoadingAnnouncement() {
    return 'Searching...';
}

/**
 * Get announcement for recent searches
 * @param {number} count - Number of recent searches
 * @returns {string} Announcement message
 */
export function getRecentSearchesAnnouncement(count) {
    if (count === 0) {
        return 'No recent searches';
    }
    if (count === 1) {
        return '1 recent search available';
    }
    return `${count} recent searches available`;
}

/**
 * Update ARIA attributes on the combobox input
 * @param {HTMLInputElement} input - The input element
 * @param {Object} state - Current state
 * @param {boolean} state.expanded - Whether listbox is expanded
 * @param {string|null} state.activeDescendant - ID of active option
 * @param {string} state.listboxId - ID of the listbox
 */
export function updateComboboxAria(input, { expanded, activeDescendant, listboxId }) {
    input.setAttribute('aria-expanded', String(expanded));
    input.setAttribute('aria-controls', listboxId);

    if (activeDescendant) {
        input.setAttribute('aria-activedescendant', activeDescendant);
    } else {
        input.removeAttribute('aria-activedescendant');
    }
}

/**
 * Update ARIA attributes on listbox options
 * @param {NodeList|Array} options - Option elements
 * @param {number} selectedIndex - Currently selected index (-1 for none)
 */
export function updateOptionAria(options, selectedIndex) {
    options.forEach((option, index) => {
        const isSelected = index === selectedIndex;
        option.setAttribute('aria-selected', String(isSelected));
    });
}

/**
 * Get the ID for an option element
 * @param {string} baseId - Base ID for the listbox
 * @param {number} index - Option index
 * @returns {string} Option ID
 */
export function getOptionId(baseId, index) {
    return `${baseId}-option-${index}`;
}

/**
 * Scroll an element into view if needed
 * @param {HTMLElement} element - Element to scroll into view
 * @param {HTMLElement} container - Scrollable container
 */
export function scrollIntoViewIfNeeded(element, container) {
    if (!element || !container) return;

    const elementRect = element.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    if (elementRect.top < containerRect.top) {
        element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } else if (elementRect.bottom > containerRect.bottom) {
        element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

/**
 * Check if an element is visible (not hidden, not display:none)
 * @param {HTMLElement} element - Element to check
 * @returns {boolean} Whether element is visible
 */
export function isElementVisible(element) {
    if (!element) return false;
    return element.offsetParent !== null;
}

/**
 * Get all focusable elements within a container
 * @param {HTMLElement} container - Container element
 * @returns {HTMLElement[]} Array of focusable elements
 */
export function getFocusableElements(container) {
    const selectors = [
        'input:not([disabled]):not([type="hidden"])',
        'button:not([disabled])',
        'a[href]',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    return Array.from(container.querySelectorAll(selectors))
        .filter(isElementVisible);
}

/**
 * Trap focus within a container (for modal)
 * @param {KeyboardEvent} event - Keyboard event
 * @param {HTMLElement} container - Container to trap focus within
 * @param {ShadowRoot} shadowRoot - Shadow root for activeElement
 */
export function trapFocus(event, container, shadowRoot) {
    if (event.key !== 'Tab') return;

    const focusable = getFocusableElements(container);
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = shadowRoot.activeElement;

    if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}
