/**
 * Style Configuration - Single source of truth for CSS variable mappings
 *
 * This module defines the mapping between style property names (from PHP/database)
 * and CSS custom property names used in the widget.
 */

// CSS variable mappings: PHP style key → CSS variable name
export const STYLE_MAPPINGS = {
    // Modal
    modalBg: '--sm-modal-bg',
    modalBgDark: '--sm-modal-bg-dark',
    modalBorderRadius: '--sm-modal-radius',
    modalBorderWidth: '--sm-modal-border-width',
    modalBorderColor: '--sm-modal-border-color',
    modalBorderColorDark: '--sm-modal-border-color-dark',
    modalShadow: '--sm-modal-shadow',
    modalMaxWidth: '--sm-modal-width',

    // Input
    inputBg: '--sm-input-bg',
    inputBgDark: '--sm-input-bg-dark',
    inputTextColor: '--sm-input-color',
    inputTextColorDark: '--sm-input-color-dark',
    inputPlaceholderColor: '--sm-input-placeholder',
    inputPlaceholderColorDark: '--sm-input-placeholder-dark',
    inputBorderColor: '--sm-input-border-color',
    inputBorderColorDark: '--sm-input-border-color-dark',
    inputFontSize: '--sm-input-font-size',

    // Results
    resultBg: '--sm-result-bg',
    resultBgDark: '--sm-result-bg-dark',
    resultHoverBg: '--sm-result-hover-bg',
    resultHoverBgDark: '--sm-result-hover-bg-dark',
    resultActiveBg: '--sm-result-active-bg',
    resultActiveBgDark: '--sm-result-active-bg-dark',
    resultTextColor: '--sm-result-text-color',
    resultTextColorDark: '--sm-result-text-color-dark',
    resultDescColor: '--sm-result-desc-color',
    resultDescColorDark: '--sm-result-desc-color-dark',
    resultBorderRadius: '--sm-result-radius',

    // Trigger
    triggerBg: '--sm-trigger-bg',
    triggerBgDark: '--sm-trigger-bg-dark',
    triggerTextColor: '--sm-trigger-text-color',
    triggerTextColorDark: '--sm-trigger-text-color-dark',
    triggerBorderRadius: '--sm-trigger-radius',
    triggerBorderWidth: '--sm-trigger-border-width',
    triggerBorderColor: '--sm-trigger-border-color',
    triggerBorderColorDark: '--sm-trigger-border-color-dark',
    triggerPaddingX: '--sm-trigger-px',
    triggerPaddingY: '--sm-trigger-py',
    triggerFontSize: '--sm-trigger-font-size',

    // Keyboard badge
    kbdBg: '--sm-kbd-bg',
    kbdBgDark: '--sm-kbd-bg-dark',
    kbdTextColor: '--sm-kbd-text-color',
    kbdTextColorDark: '--sm-kbd-text-color-dark',
    kbdBorderRadius: '--sm-kbd-radius',

    // Highlighting
    highlightBgLight: '--sm-highlight-bg',
    highlightColorLight: '--sm-highlight-color',
    highlightBgDark: '--sm-highlight-bg-dark',
    highlightColorDark: '--sm-highlight-color-dark',
};

// Keys that require 'px' suffix
export const NUMERIC_KEYS = [
    'modalBorderRadius',
    'modalBorderWidth',
    'modalMaxWidth',
    'inputFontSize',
    'resultBorderRadius',
    'triggerBorderRadius',
    'triggerBorderWidth',
    'triggerPaddingX',
    'triggerPaddingY',
    'triggerFontSize',
    'kbdBorderRadius',
];

// Keys that are color values (need # prefix if missing)
export const COLOR_KEYS = [
    'modalBg', 'modalBgDark', 'modalBorderColor', 'modalBorderColorDark',
    'inputBg', 'inputBgDark', 'inputTextColor', 'inputTextColorDark',
    'inputPlaceholderColor', 'inputPlaceholderColorDark', 'inputBorderColor', 'inputBorderColorDark',
    'resultBg', 'resultBgDark', 'resultHoverBg', 'resultHoverBgDark',
    'resultActiveBg', 'resultActiveBgDark', 'resultTextColor', 'resultTextColorDark',
    'resultDescColor', 'resultDescColorDark',
    'triggerBg', 'triggerBgDark', 'triggerTextColor', 'triggerTextColorDark',
    'triggerBorderColor', 'triggerBorderColorDark',
    'kbdBg', 'kbdBgDark', 'kbdTextColor', 'kbdTextColorDark',
    'highlightBgLight', 'highlightColorLight', 'highlightBgDark', 'highlightColorDark',
];

// Default style values (light mode)
export const DEFAULT_STYLES = {
    // Modal
    modalBg: '#ffffff',
    modalBgDark: '#1f2937',
    modalBorderRadius: '12',
    modalBorderWidth: '1',
    modalBorderColor: '#e5e7eb',
    modalBorderColorDark: '#374151',
    modalShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
    modalMaxWidth: '640',

    // Input
    inputBg: '#ffffff',
    inputBgDark: '#1f2937',
    inputTextColor: '#111827',
    inputTextColorDark: '#f9fafb',
    inputPlaceholderColor: '#9ca3af',
    inputPlaceholderColorDark: '#6b7280',
    inputBorderColor: '#e5e7eb',
    inputBorderColorDark: '#374151',
    inputFontSize: '16',

    // Results
    resultHoverBg: '#f3f4f6',
    resultHoverBgDark: '#374151',
    resultActiveBg: '#e5e7eb',
    resultActiveBgDark: '#4b5563',
    resultTextColor: '#111827',
    resultTextColorDark: '#f9fafb',
    resultDescColor: '#6b7280',
    resultDescColorDark: '#9ca3af',
    resultBorderRadius: '8',

    // Trigger
    triggerBg: '#ffffff',
    triggerBgDark: '#374151',
    triggerTextColor: '#374151',
    triggerTextColorDark: '#d1d5db',
    triggerBorderRadius: '8',
    triggerBorderWidth: '1',
    triggerBorderColor: '#d1d5db',
    triggerBorderColorDark: '#4b5563',
    triggerPaddingX: '12',
    triggerPaddingY: '8',
    triggerFontSize: '14',

    // Keyboard badge
    kbdBg: '#f3f4f6',
    kbdBgDark: '#4b5563',
    kbdTextColor: '#6b7280',
    kbdTextColorDark: '#9ca3af',
    kbdBorderRadius: '4',

    // Highlighting
    highlightBgLight: '#fef08a',
    highlightColorLight: '#854d0e',
    highlightBgDark: '#854d0e',
    highlightColorDark: '#fef08a',
};
