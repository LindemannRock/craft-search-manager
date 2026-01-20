/**
 * KeyboardNavigator - Arrow key navigation for result lists
 *
 * Handles arrow up/down navigation, Enter selection, Escape closing,
 * and ARIA state management for accessible keyboard navigation.
 *
 * @module KeyboardNavigator
 * @author Search Manager
 * @since 5.x
 */

import {
    updateComboboxAria,
    getOptionId,
    scrollIntoViewIfNeeded
} from './A11yUtils.js';

/**
 * @typedef {Object} NavigatorCallbacks
 * @property {Function} onSelect - Called when item is selected (Enter key)
 * @property {Function} onIndexChange - Called when selectedIndex changes
 * @property {Function} onEscape - Called when Escape is pressed
 */

/**
 * @typedef {Object} NavigatorConfig
 * @property {string} listboxId - ARIA listbox ID for accessibility
 */

/**
 * @typedef {Object} NavigatorState
 * @property {number} selectedIndex - Currently selected item index (-1 = none)
 * @property {number} itemCount - Total number of navigable items
 */

/**
 * Create a keyboard navigator instance
 *
 * Returns an object with methods for handling keyboard events
 * and managing selection state.
 *
 * @param {NavigatorCallbacks} callbacks - Event callbacks
 * @param {NavigatorConfig} config - Navigator configuration
 * @returns {Object} Navigator instance
 *
 * @example
 * const navigator = createKeyboardNavigator(
 *   {
 *     onSelect: (index) => items[index].click(),
 *     onIndexChange: (index) => updateUI(index),
 *     onEscape: () => closeModal(),
 *   },
 *   { listboxId: 'search-results' }
 * );
 *
 * input.addEventListener('keydown', (e) => {
 *   navigator.handleKeydown(e, items.length, currentIndex);
 * });
 */
export function createKeyboardNavigator(callbacks, config) {
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
                case 'ArrowDown':
                    e.preventDefault();
                    newIndex = Math.min(currentIndex + 1, itemCount - 1);
                    if (newIndex !== currentIndex && onIndexChange) {
                        onIndexChange(newIndex);
                    }
                    return newIndex;

                case 'ArrowUp':
                    e.preventDefault();
                    newIndex = Math.max(currentIndex - 1, -1);
                    if (newIndex !== currentIndex && onIndexChange) {
                        onIndexChange(newIndex);
                    }
                    return newIndex;

                case 'Enter':
                    e.preventDefault();
                    if (currentIndex >= 0 && onSelect) {
                        onSelect(currentIndex);
                    }
                    return null;

                case 'Escape':
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
        },
    };
}

/**
 * Update visual and ARIA selection state for result items
 *
 * Updates the selected item's visual appearance and ARIA attributes
 * for screen reader accessibility. Also scrolls the selected item
 * into view if needed.
 *
 * @param {NodeList|Array} items - List of navigable item elements
 * @param {number} selectedIndex - Currently selected index (-1 = none)
 * @param {Object} options - Update options
 * @param {HTMLElement} options.scrollContainer - Container to scroll within
 * @param {HTMLElement} options.inputElement - Input element for ARIA updates
 * @param {string} options.listboxId - ARIA listbox ID
 * @param {string} options.selectedClass - CSS class for selected state (default: 'sm-selected')
 *
 * @example
 * updateSelectionState(resultItems, 2, {
 *   scrollContainer: resultsDiv,
 *   inputElement: searchInput,
 *   listboxId: 'search-results',
 * });
 */
export function updateSelectionState(items, selectedIndex, options = {}) {
    const {
        scrollContainer,
        inputElement,
        listboxId,
        selectedClass = 'sm-selected',
    } = options;

    // Calculate active descendant ID
    const activeId = selectedIndex >= 0 ? getOptionId(listboxId, selectedIndex) : null;

    // Update input's ARIA attributes
    if (inputElement) {
        updateComboboxAria(inputElement, {
            expanded: items.length > 0,
            activeDescendant: activeId,
            listboxId: listboxId,
        });
    }

    // Update visual and ARIA state for each item
    items.forEach((item, i) => {
        const isSelected = i === selectedIndex;
        item.classList.toggle(selectedClass, isSelected);
        item.setAttribute('aria-selected', String(isSelected));

        // Scroll into view if selected
        if (isSelected && scrollContainer) {
            scrollIntoViewIfNeeded(item, scrollContainer);
        }
    });
}

/**
 * Attach mouse hover handlers for selection sync
 *
 * Makes items selectable via mouse hover, keeping keyboard
 * and mouse navigation in sync.
 *
 * @param {NodeList|Array} items - List of navigable item elements
 * @param {Function} onHover - Callback when item is hovered (receives index)
 *
 * @example
 * attachHoverHandlers(resultItems, (index) => {
 *   selectedIndex = index;
 *   updateSelectionState(resultItems, index, options);
 * });
 */
export function attachHoverHandlers(items, onHover) {
    items.forEach((item, index) => {
        item.addEventListener('mouseenter', () => {
            if (onHover) {
                onHover(index);
            }
        });
    });
}
