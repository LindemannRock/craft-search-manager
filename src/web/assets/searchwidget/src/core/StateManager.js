/**
 * StateManager - Reactive state management for widgets
 *
 * Provides a simple reactive state container with change notifications.
 * Tracks which keys changed for efficient partial updates.
 *
 * @module StateManager
 * @author Search Manager
 * @since 5.x
 */

/**
 * @typedef {Object} WidgetState
 * @property {boolean} isOpen - Whether widget is open (modal) or active (inline)
 * @property {string} query - Current search query
 * @property {Array} results - Current search results
 * @property {Array} recentSearches - Recent search history
 * @property {number} selectedIndex - Currently selected result index (-1 = none)
 * @property {boolean} loading - Whether search is in progress
 * @property {string|null} error - Error message if any
 * @property {Object|null} meta - Debug metadata (timing, cache status, etc.)
 */

/**
 * @callback StateChangeCallback
 * @param {WidgetState} newState - The new state after changes
 * @param {Array<string>} changedKeys - Array of keys that changed
 */

/**
 * Default initial state for widgets
 */
export const DEFAULT_STATE = {
    isOpen: false,
    query: '',
    results: [],
    recentSearches: [],
    selectedIndex: -1,
    loading: false,
    error: null,
    meta: null,
};

/**
 * Create a state manager instance
 *
 * Returns an object with methods to get, set, and subscribe to state changes.
 * The onChange callback is called whenever state changes, with the new state
 * and an array of which keys changed.
 *
 * @param {Partial<WidgetState>} initialState - Initial state values (merged with defaults)
 * @param {StateChangeCallback} onChange - Callback when state changes
 * @returns {Object} State manager instance
 *
 * @example
 * const state = createStateManager(
 *   { query: '', results: [] },
 *   (newState, changedKeys) => {
 *     if (changedKeys.includes('results')) {
 *       renderResults(newState.results);
 *     }
 *   }
 * );
 *
 * state.set({ query: 'test' }); // Triggers onChange with changedKeys: ['query']
 * state.get('query'); // Returns 'test'
 * state.getAll(); // Returns full state object
 */
export function createStateManager(initialState = {}, onChange = null) {
    // Merge with defaults
    let state = {
        ...DEFAULT_STATE,
        ...initialState,
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

            // Check which keys actually changed
            Object.keys(updates).forEach(key => {
                const oldValue = state[key];
                const newValue = updates[key];

                // Deep equality check for arrays and objects
                if (!isEqual(oldValue, newValue)) {
                    changedKeys.push(key);
                }
            });

            // Only update if something changed
            if (changedKeys.length > 0) {
                state = {
                    ...state,
                    ...updates,
                };

                // Notify listener
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
                ...newInitial,
            };

            const changedKeys = Object.keys(newState).filter(
                key => !isEqual(state[key], newState[key])
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
        },
    };
}

/**
 * Simple equality check for state values
 *
 * Handles primitives, arrays, and simple objects.
 * Not a full deep equality - sufficient for state comparison.
 *
 * @param {*} a - First value
 * @param {*} b - Second value
 * @returns {boolean} True if values are equal
 */
function isEqual(a, b) {
    // Same reference or both primitives with same value
    if (a === b) {
        return true;
    }

    // One is null/undefined and the other isn't
    if (a == null || b == null) {
        return false;
    }

    // Both are arrays
    if (Array.isArray(a) && Array.isArray(b)) {
        if (a.length !== b.length) {
            return false;
        }
        return a.every((item, index) => isEqual(item, b[index]));
    }

    // Both are objects (but not arrays)
    if (typeof a === 'object' && typeof b === 'object') {
        const keysA = Object.keys(a);
        const keysB = Object.keys(b);

        if (keysA.length !== keysB.length) {
            return false;
        }

        return keysA.every(key => isEqual(a[key], b[key]));
    }

    // Different types or different values
    return false;
}

/**
 * Create a derived state value
 *
 * Computes a value from state that updates when dependencies change.
 * Useful for computed properties that depend on multiple state values.
 *
 * @param {Object} stateManager - State manager instance
 * @param {Function} selector - Function that computes the derived value
 * @returns {Function} Getter function for the derived value
 *
 * @example
 * const hasResults = createDerivedState(state, (s) => s.results.length > 0);
 * console.log(hasResults()); // true/false based on current state
 */
export function createDerivedState(stateManager, selector) {
    return () => selector(stateManager.getAll());
}
