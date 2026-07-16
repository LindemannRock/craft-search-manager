/**
 * Style Configuration - Single source of truth for CSS variable mappings
 *
 * This module defines the mapping between style property names (from PHP/database)
 * and CSS custom property names used in the widget.
 *
 * Style defaults are loaded from a shared JSON file (src/config/style-defaults.json)
 * which is also read by PHP WidgetConfig.php - this ensures DRY.
 */

// Import style defaults from shared JSON (single source of truth)
import styleDefaults from '../../../../../config/style-defaults.json';

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
    modalShadowDark: '--sm-modal-shadow-dark',
    modalMaxWidth: '--sm-modal-width',
    modalMaxHeight: '--sm-modal-max-height',
    modalPaddingX: '--sm-modal-px',
    modalPaddingY: '--sm-modal-py',

    // Search Header (.sm-header container)
    headerBg: '--sm-header-bg',
    headerBgDark: '--sm-header-bg-dark',
    headerBorderColor: '--sm-header-border-color',
    headerBorderColorDark: '--sm-header-border-color-dark',
    headerBorderWidth: '--sm-header-border-width',
    headerBorderRadius: '--sm-header-radius',
    headerPaddingX: '--sm-header-px',
    headerPaddingY: '--sm-header-py',

    // Search Input (.sm-input element)
    inputBg: '--sm-input-bg',
    inputBgDark: '--sm-input-bg-dark',
    inputTextColor: '--sm-input-color',
    inputTextColorDark: '--sm-input-color-dark',
    inputPlaceholderColor: '--sm-input-placeholder',
    inputPlaceholderColorDark: '--sm-input-placeholder-dark',
    inputBorderColor: '--sm-input-border-color',
    inputBorderColorDark: '--sm-input-border-color-dark',
    inputFontSize: '--sm-input-font-size',
    inputBorderRadius: '--sm-input-radius',
    inputBorderWidth: '--sm-input-border-width',
    inputPaddingX: '--sm-input-px',
    inputPaddingY: '--sm-input-py',

    // Results
    resultBg: '--sm-result-bg',
    resultBgDark: '--sm-result-bg-dark',
    resultBorderColor: '--sm-result-border-color',
    resultBorderColorDark: '--sm-result-border-color-dark',
    resultActiveBg: '--sm-result-active-bg',
    resultActiveBgDark: '--sm-result-active-bg-dark',
    resultActiveBorderColor: '--sm-result-active-border-color',
    resultActiveBorderColorDark: '--sm-result-active-border-color-dark',
    resultActiveTextColor: '--sm-result-active-text-color',
    resultActiveTextColorDark: '--sm-result-active-text-color-dark',
    resultActiveDescColor: '--sm-result-active-desc-color',
    resultActiveDescColorDark: '--sm-result-active-desc-color-dark',
    resultActiveMutedColor: '--sm-result-active-muted-color',
    resultActiveMutedColorDark: '--sm-result-active-muted-color-dark',
    resultTextColor: '--sm-result-text-color',
    resultTextColorDark: '--sm-result-text-color-dark',
    resultDescColor: '--sm-result-desc-color',
    resultDescColorDark: '--sm-result-desc-color-dark',
    resultMutedColor: '--sm-result-muted-color',
    resultMutedColorDark: '--sm-result-muted-color-dark',
    resultGap: '--sm-result-gap',
    resultBorderWidth: '--sm-result-border-width',
    resultPaddingX: '--sm-result-px',
    resultPaddingY: '--sm-result-py',
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
    triggerHoverBg: '--sm-trigger-hover-bg',
    triggerHoverBgDark: '--sm-trigger-hover-bg-dark',
    triggerHoverTextColor: '--sm-trigger-hover-text-color',
    triggerHoverTextColorDark: '--sm-trigger-hover-text-color-dark',
    triggerHoverBorderColor: '--sm-trigger-hover-border-color',
    triggerHoverBorderColorDark: '--sm-trigger-hover-border-color-dark',
    triggerPaddingX: '--sm-trigger-px',
    triggerPaddingY: '--sm-trigger-py',
    triggerFontSize: '--sm-trigger-font-size',

    // Keyboard badge
    kbdBg: '--sm-kbd-bg',
    kbdBgDark: '--sm-kbd-bg-dark',
    kbdTextColor: '--sm-kbd-text-color',
    kbdTextColorDark: '--sm-kbd-text-color-dark',
    kbdBorderRadius: '--sm-kbd-radius',

    // Icon color (hierarchy page icon)
    iconColor: '--sm-icon-color',
    iconColorDark: '--sm-icon-color-dark',

    // Search icon (input magnifier; unset = follows the muted color)
    searchIconColor: '--sm-search-icon-color',
    searchIconColorDark: '--sm-search-icon-color-dark',

    // Clear icon (input clear button; unset = follows the muted color)
    clearIconColor: '--sm-clear-icon-color',
    clearIconColorDark: '--sm-clear-icon-color-dark',

    // Result row icons (e.g. recent-search clock; unset = follows the muted color)
    resultIconColor: '--sm-result-icon-color',
    resultIconColorDark: '--sm-result-icon-color-dark',

    // Result arrow (unset = follows the active/hover muted color)
    arrowColor: '--sm-arrow-color',
    arrowColorDark: '--sm-arrow-color-dark',

    // Active/hover glyph variants (unset = follow their base color)
    iconActiveColor: '--sm-icon-active-color',
    iconActiveColorDark: '--sm-icon-active-color-dark',
    resultIconActiveColor: '--sm-result-icon-active-color',
    resultIconActiveColorDark: '--sm-result-icon-active-color-dark',
    hierarchyConnectorActiveColor: '--sm-hierarchy-connector-active-color',
    hierarchyConnectorActiveColorDark: '--sm-hierarchy-connector-active-color-dark',

    // Hierarchy connector lines (unset = follows the muted color)
    hierarchyConnectorColor: '--sm-hierarchy-connector-color',
    hierarchyConnectorColorDark: '--sm-hierarchy-connector-color-dark',

    // Highlighting
    highlightBgLight: '--sm-highlight-bg',
    highlightColorLight: '--sm-highlight-color',
    highlightBgDark: '--sm-highlight-bg-dark',
    highlightColorDark: '--sm-highlight-color-dark',

    // Highlighting on the active/hovered row (unset = follows the base highlight)
    highlightActiveBgLight: '--sm-highlight-active-bg',
    highlightActiveColorLight: '--sm-highlight-active-color',
    highlightActiveBgDark: '--sm-highlight-active-bg-dark',
    highlightActiveColorDark: '--sm-highlight-active-color-dark',

    // Promoted badge
    promotedBg: '--sm-promoted-bg',
    promotedBgDark: '--sm-promoted-bg-dark',
    promotedColor: '--sm-promoted-color',
    promotedColorDark: '--sm-promoted-color-dark',

    // Spinner
    spinnerColor: '--sm-spinner-color-light',
    spinnerColorDark: '--sm-spinner-color-dark',

    // Results scrollbar thumb (unset = semi-transparent muted)
    scrollbarColor: '--sm-scrollbar-color',
    scrollbarColorDark: '--sm-scrollbar-color-dark',

    // Footer (unset bg = matches the modal; unset text = muted chain)
    footerBg: '--sm-footer-bg',
    footerBgDark: '--sm-footer-bg-dark',
    footerTextColor: '--sm-footer-text-color',
    footerTextColorDark: '--sm-footer-text-color-dark',
    footerPaddingX: '--sm-footer-px',
    footerPaddingY: '--sm-footer-py',
};

// Keys that require 'px' suffix
export const NUMERIC_KEYS = [
    'modalBorderRadius',
    'modalBorderWidth',
    'modalMaxWidth',
    'modalPaddingX',
    'modalPaddingY',
    'headerBorderWidth',
    'headerBorderRadius',
    'headerPaddingX',
    'headerPaddingY',
    'inputFontSize',
    'inputBorderRadius',
    'inputBorderWidth',
    'inputPaddingX',
    'inputPaddingY',
    'resultGap',
    'resultBorderWidth',
    'resultPaddingX',
    'resultPaddingY',
    'resultBorderRadius',
    'triggerBorderRadius',
    'triggerBorderWidth',
    'triggerPaddingX',
    'triggerPaddingY',
    'triggerFontSize',
    'kbdBorderRadius',
    'footerPaddingX',
    'footerPaddingY',
];

// Keys that require 'vh' suffix
export const VH_KEYS = [
    'modalMaxHeight',
];

// Keys that are color values (need # prefix if missing)
export const COLOR_KEYS = [
    'modalBg', 'modalBgDark', 'modalBorderColor', 'modalBorderColorDark',
    'headerBg', 'headerBgDark', 'headerBorderColor', 'headerBorderColorDark',
    'inputBg', 'inputBgDark', 'inputTextColor', 'inputTextColorDark',
    'inputPlaceholderColor', 'inputPlaceholderColorDark', 'inputBorderColor', 'inputBorderColorDark',
    'resultBg', 'resultBgDark',
    'resultBorderColor', 'resultBorderColorDark',
    'resultActiveBg', 'resultActiveBgDark', 'resultActiveBorderColor', 'resultActiveBorderColorDark',
    'resultTextColor', 'resultTextColorDark',
    'resultActiveTextColor', 'resultActiveTextColorDark', 'resultActiveDescColor', 'resultActiveDescColorDark',
    'resultActiveMutedColor', 'resultActiveMutedColorDark',
    'resultDescColor', 'resultDescColorDark', 'resultMutedColor', 'resultMutedColorDark',
    'triggerBg', 'triggerBgDark', 'triggerTextColor', 'triggerTextColorDark',
    'triggerBorderColor', 'triggerBorderColorDark',
    'triggerHoverBg', 'triggerHoverBgDark', 'triggerHoverTextColor', 'triggerHoverTextColorDark',
    'triggerHoverBorderColor', 'triggerHoverBorderColorDark',
    'kbdBg', 'kbdBgDark', 'kbdTextColor', 'kbdTextColorDark',
    'iconColor', 'iconColorDark',
    'searchIconColor', 'searchIconColorDark',
    'clearIconColor', 'clearIconColorDark',
    'resultIconColor', 'resultIconColorDark',
    'arrowColor', 'arrowColorDark',
    'iconActiveColor', 'iconActiveColorDark',
    'resultIconActiveColor', 'resultIconActiveColorDark',
    'hierarchyConnectorActiveColor', 'hierarchyConnectorActiveColorDark',
    'hierarchyConnectorColor', 'hierarchyConnectorColorDark',
    'highlightBgLight', 'highlightColorLight', 'highlightBgDark', 'highlightColorDark',
    'highlightActiveBgLight', 'highlightActiveColorLight', 'highlightActiveBgDark', 'highlightActiveColorDark',
    'promotedBg', 'promotedBgDark', 'promotedColor', 'promotedColorDark',
    'spinnerColor', 'spinnerColorDark',
    'scrollbarColor', 'scrollbarColorDark',
    'footerBg', 'footerBgDark', 'footerTextColor', 'footerTextColorDark',
];

// Default style values - loaded from shared JSON config
// Highlighting defaults are added here since they come from highlighting settings, not styles
export const DEFAULT_STYLES = {
    ...styleDefaults,
    // Highlighting (from highlighting settings, not styles config)
    highlightBgLight: '#fef08a',
    highlightColorLight: '#854d0e',
    highlightBgDark: '#854d0e',
    highlightColorDark: '#fef08a',
};
