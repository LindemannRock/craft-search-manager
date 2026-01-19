/**
 * Style Utilities - Color normalization and CSS helpers
 */

import { STYLE_MAPPINGS, NUMERIC_KEYS, VH_KEYS, COLOR_KEYS } from './StyleConfig.js';

/**
 * Check if a value is a 6-character hex color without # prefix
 * @param {string} value
 * @returns {boolean}
 */
export function isHexColor(value) {
    return /^[0-9a-fA-F]{6}$/.test(value);
}

/**
 * Normalize a color value by adding # prefix if needed
 * @param {string} value
 * @returns {string}
 */
export function normalizeColor(value) {
    if (!value) return value;
    const str = String(value);
    if (isHexColor(str)) {
        return '#' + str;
    }
    return str;
}

/**
 * Process a style value based on its key type
 * - Adds # prefix for color values
 * - Adds px suffix for numeric values
 * @param {string} key - The style property key
 * @param {string|number} value - The raw value
 * @returns {string} - The processed CSS value
 */
export function processStyleValue(key, value) {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    let processedValue = String(value);

    // Add # prefix for hex colors
    if (COLOR_KEYS.includes(key) && isHexColor(processedValue)) {
        processedValue = '#' + processedValue;
    }

    // Add px suffix for numeric values
    if (NUMERIC_KEYS.includes(key)) {
        processedValue = processedValue + 'px';
    }

    // Add vh suffix for viewport height values
    if (VH_KEYS.includes(key)) {
        processedValue = processedValue + 'vh';
    }

    return processedValue;
}

/**
 * Apply styles object to an element as CSS custom properties
 * @param {HTMLElement} element - The element to apply styles to
 * @param {Object} styles - The styles object from config
 * @param {string} theme - Current theme ('light' or 'dark')
 */
export function applyStylesToElement(element, styles, theme = 'light') {
    if (!styles || typeof styles !== 'object') return;

    const isDark = theme === 'dark';

    for (const [key, cssVar] of Object.entries(STYLE_MAPPINGS)) {
        // Only set variables appropriate for the current theme
        // Dark theme: only set *Dark variables
        // Light theme: only set non-Dark variables
        const isDarkKey = key.endsWith('Dark');

        if (isDark && !isDarkKey) continue;
        if (!isDark && isDarkKey) continue;

        if (styles[key] !== undefined && styles[key] !== null && styles[key] !== '') {
            const value = processStyleValue(key, styles[key]);
            if (value) {
                element.style.setProperty(cssVar, value);
            }
        }
    }
}

/**
 * Get CSS variable name for a style key
 * @param {string} key - The style property key
 * @returns {string|null} - The CSS variable name or null
 */
export function getCssVarName(key) {
    return STYLE_MAPPINGS[key] || null;
}
