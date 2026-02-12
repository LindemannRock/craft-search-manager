/**
 * Search Manager Widget Preview
 *
 * Shared preview engine for widget and style edit pages.
 * Provides live preview updates, color scheme toggle, and
 * tab-based preview visibility.
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */
window.SearchManagerPreview = (function() {
	'use strict';

	/**
	 * Preview mapping: bare style keys → preview update rules.
	 *
	 * mode: 'light' = light preview only, 'dark' = dark only, 'both' = both.
	 * At init time, mode is resolved to actual DOM container references.
	 */
	var PREVIEW_MAP = {
		// --- Modal ---
		modalBg:              [{ mode: 'light', selector: '.preview-modal', prop: 'backgroundColor' }],
		modalBgDark:          [{ mode: 'dark', selector: '.preview-modal', prop: 'backgroundColor' }],
		modalBorderColor:     [{ mode: 'light', selector: '.preview-modal', prop: 'borderColor' }],
		modalBorderColorDark: [{ mode: 'dark', selector: '.preview-modal', prop: 'borderColor' }],
		modalBorderRadius:    [{ mode: 'both', selector: '.preview-modal', prop: 'borderRadius', unit: 'px' }],
		modalBorderWidth:     [{ mode: 'both', selector: '.preview-modal', prop: 'borderWidth', unit: 'px' }],
		modalPaddingX: [
			{ mode: 'both', selector: '.preview-modal', prop: 'paddingLeft', unit: 'px' },
			{ mode: 'both', selector: '.preview-modal', prop: 'paddingRight', unit: 'px' }
		],
		modalPaddingY: [
			{ mode: 'both', selector: '.preview-modal', prop: 'paddingTop', unit: 'px' },
			{ mode: 'both', selector: '.preview-modal', prop: 'paddingBottom', unit: 'px' }
		],
		modalShadow:     [{ mode: 'light', selector: '.preview-modal', prop: 'boxShadow' }],
		modalShadowDark: [{ mode: 'dark', selector: '.preview-modal', prop: 'boxShadow' }],
		resultMutedColor: [
			{ mode: 'light', selector: '.preview-result-arrow', prop: 'stroke', all: true },
			{ mode: 'light', cssVar: '--preview-connector-color' }
		],
		resultMutedColorDark: [
			{ mode: 'dark', selector: '.preview-result-arrow', prop: 'stroke', all: true },
			{ mode: 'dark', cssVar: '--preview-connector-color' }
		],

		// --- Input ---
		inputBg:                   [{ mode: 'light', selector: '.preview-input', prop: 'backgroundColor' }],
		inputBgDark:               [{ mode: 'dark', selector: '.preview-input', prop: 'backgroundColor' }],
		inputTextColor:            [{ mode: 'light', selector: '.preview-search-icon', prop: 'stroke' }],
		inputTextColorDark:        [{ mode: 'dark', selector: '.preview-search-icon', prop: 'stroke' }],
		inputPlaceholderColor:     [{ mode: 'light', selector: '.preview-placeholder', prop: 'color' }],
		inputPlaceholderColorDark: [{ mode: 'dark', selector: '.preview-placeholder', prop: 'color' }],
		inputBorderColor:          [{ mode: 'light', selector: '.preview-input', prop: 'borderColor' }],
		inputBorderColorDark:      [{ mode: 'dark', selector: '.preview-input', prop: 'borderColor' }],
		inputFontSize:             [{ mode: 'both', selector: '.preview-placeholder', prop: 'fontSize', unit: 'px' }],

		// --- Results: Base ---
		resultBg:              [{ mode: 'light', selector: '.preview-result', prop: 'backgroundColor', index: 0 }],
		resultBgDark:          [{ mode: 'dark', selector: '.preview-result', prop: 'backgroundColor', index: 0 }],
		resultTextColor:       [{ mode: 'light', selector: '.preview-result-title', prop: 'color', all: true }],
		resultTextColorDark:   [{ mode: 'dark', selector: '.preview-result-title', prop: 'color', all: true }],
		resultDescColor:       [{ mode: 'light', selector: '.preview-result-desc', prop: 'color', all: true }],
		resultDescColorDark:   [{ mode: 'dark', selector: '.preview-result-desc', prop: 'color', all: true }],
		resultBorderColor:     [{ mode: 'light', selector: '.preview-result', prop: 'borderColor', all: true }],
		resultBorderColorDark: [{ mode: 'dark', selector: '.preview-result', prop: 'borderColor', all: true }],

		// --- Results: Hover ---
		resultHoverBg:              [{ mode: 'light', selector: '.preview-result', prop: 'backgroundColor', index: 2 }],
		resultHoverBgDark:          [{ mode: 'dark', selector: '.preview-result', prop: 'backgroundColor', index: 2 }],
		resultHoverTextColor:       [{ mode: 'light', selector: '.preview-result-title', prop: 'color', index: 2 }],
		resultHoverTextColorDark:   [{ mode: 'dark', selector: '.preview-result-title', prop: 'color', index: 2 }],
		resultHoverDescColor:       [{ mode: 'light', selector: '.preview-result-desc', prop: 'color', index: 2 }],
		resultHoverDescColorDark:   [{ mode: 'dark', selector: '.preview-result-desc', prop: 'color', index: 2 }],
		resultHoverMutedColor:      [{ mode: 'light', selector: '.preview-result-arrow', prop: 'stroke', index: 2 }],
		resultHoverMutedColorDark:  [{ mode: 'dark', selector: '.preview-result-arrow', prop: 'stroke', index: 2 }],
		resultHoverBorderColor:     [{ mode: 'light', selector: '.preview-result', prop: 'borderColor', index: 2 }],
		resultHoverBorderColorDark: [{ mode: 'dark', selector: '.preview-result', prop: 'borderColor', index: 2 }],

		// --- Results: Active ---
		resultActiveBg:              [{ mode: 'light', selector: '.preview-result', prop: 'backgroundColor', index: 1 }],
		resultActiveBgDark:          [{ mode: 'dark', selector: '.preview-result', prop: 'backgroundColor', index: 1 }],
		resultActiveTextColor:       [{ mode: 'light', selector: '.preview-result-title', prop: 'color', index: 1 }],
		resultActiveTextColorDark:   [{ mode: 'dark', selector: '.preview-result-title', prop: 'color', index: 1 }],
		resultActiveDescColor:       [{ mode: 'light', selector: '.preview-result-desc', prop: 'color', index: 1 }],
		resultActiveDescColorDark:   [{ mode: 'dark', selector: '.preview-result-desc', prop: 'color', index: 1 }],
		resultActiveMutedColor:      [{ mode: 'light', selector: '.preview-result-arrow', prop: 'stroke', index: 1 }],
		resultActiveMutedColorDark:  [{ mode: 'dark', selector: '.preview-result-arrow', prop: 'stroke', index: 1 }],
		resultActiveBorderColor:     [{ mode: 'light', selector: '.preview-result', prop: 'borderColor', index: 1 }],
		resultActiveBorderColorDark: [{ mode: 'dark', selector: '.preview-result', prop: 'borderColor', index: 1 }],

		// --- Results: Dimensions ---
		resultGap:          [{ mode: 'both', selector: '.preview-result', prop: 'marginBottom', unit: 'px', all: true }],
		resultBorderRadius: [{ mode: 'both', selector: '.preview-result', prop: 'borderRadius', unit: 'px', all: true }],
		resultBorderWidth:  [{ mode: 'both', selector: '.preview-result', prop: 'borderWidth', unit: 'px', all: true }],
		resultPaddingX: [
			{ mode: 'both', selector: '.preview-result', prop: 'paddingLeft', unit: 'px', all: true },
			{ mode: 'both', selector: '.preview-result', prop: 'paddingRight', unit: 'px', all: true }
		],
		resultPaddingY: [
			{ mode: 'both', selector: '.preview-result', prop: 'paddingTop', unit: 'px', all: true },
			{ mode: 'both', selector: '.preview-result', prop: 'paddingBottom', unit: 'px', all: true }
		],

		// --- Trigger ---
		triggerBg:              [{ mode: 'light', selector: '.preview-trigger', prop: 'backgroundColor' }],
		triggerBgDark:          [{ mode: 'dark', selector: '.preview-trigger', prop: 'backgroundColor' }],
		triggerTextColor:       [{ mode: 'light', selector: '.preview-trigger', prop: 'color' }],
		triggerTextColorDark:   [{ mode: 'dark', selector: '.preview-trigger', prop: 'color' }],
		triggerBorderColor:     [{ mode: 'light', selector: '.preview-trigger', prop: 'borderColor' }],
		triggerBorderColorDark: [{ mode: 'dark', selector: '.preview-trigger', prop: 'borderColor' }],
		triggerBorderRadius:    [{ mode: 'both', selector: '.preview-trigger', prop: 'borderRadius', unit: 'px' }],
		triggerBorderWidth:     [{ mode: 'both', selector: '.preview-trigger', prop: 'borderWidth', unit: 'px' }],
		triggerPaddingX: [
			{ mode: 'both', selector: '.preview-trigger', prop: 'paddingLeft', unit: 'px' },
			{ mode: 'both', selector: '.preview-trigger', prop: 'paddingRight', unit: 'px' }
		],
		triggerPaddingY: [
			{ mode: 'both', selector: '.preview-trigger', prop: 'paddingTop', unit: 'px' },
			{ mode: 'both', selector: '.preview-trigger', prop: 'paddingBottom', unit: 'px' }
		],
		triggerFontSize: [{ mode: 'both', selector: '.preview-trigger', prop: 'fontSize', unit: 'px' }],

		// --- Keyboard Badge ---
		kbdBg: [
			{ mode: 'light', selector: '.preview-kbd', prop: 'backgroundColor' },
			{ mode: 'light', selector: '.preview-trigger-kbd', prop: 'backgroundColor' }
		],
		kbdBgDark: [
			{ mode: 'dark', selector: '.preview-kbd', prop: 'backgroundColor' },
			{ mode: 'dark', selector: '.preview-trigger-kbd', prop: 'backgroundColor' }
		],
		kbdTextColor: [
			{ mode: 'light', selector: '.preview-kbd', prop: 'color' },
			{ mode: 'light', selector: '.preview-trigger-kbd', prop: 'color' }
		],
		kbdTextColorDark: [
			{ mode: 'dark', selector: '.preview-kbd', prop: 'color' },
			{ mode: 'dark', selector: '.preview-trigger-kbd', prop: 'color' }
		],
		kbdBorderRadius: [
			{ mode: 'both', selector: '.preview-kbd', prop: 'borderRadius', unit: 'px' },
			{ mode: 'both', selector: '.preview-trigger-kbd', prop: 'borderRadius', unit: 'px' }
		],

		// --- Spinner ---
		spinnerColor:     [{ mode: 'light', selector: '.preview-spinner', prop: 'color' }],
		spinnerColorDark: [{ mode: 'dark', selector: '.preview-spinner', prop: 'color' }]
	};

	/**
	 * Expand mode-based rules into container-resolved rules.
	 * 'both' becomes two entries (one per container).
	 */
	function expandRules(rules, lightContainer, darkContainer) {
		var expanded = [];
		rules.forEach(function(rule) {
			if (rule.mode === 'both') {
				expanded.push(assign(rule, { container: lightContainer }));
				expanded.push(assign(rule, { container: darkContainer }));
			} else {
				expanded.push(assign(rule, {
					container: rule.mode === 'dark' ? darkContainer : lightContainer
				}));
			}
		});
		return expanded;
	}

	/**
	 * Shallow clone + merge (avoids Object.assign for broader compat).
	 */
	function assign(source, overrides) {
		var result = {};
		for (var k in source) {
			if (source.hasOwnProperty(k)) {
				result[k] = source[k];
			}
		}
		for (var k2 in overrides) {
			if (overrides.hasOwnProperty(k2)) {
				result[k2] = overrides[k2];
			}
		}
		return result;
	}

	/**
	 * Build the full preview config from the map + optional extra entries.
	 *
	 * @param {string} inputPrefix  e.g. 'styles' or 'settings[styles]'
	 * @param {Element} lightContainer
	 * @param {Element} darkContainer
	 * @param {Object|null} extraConfig  Additional entries with full input names as keys
	 * @returns {Object} inputName → resolved rules array
	 */
	function buildConfig(inputPrefix, lightContainer, darkContainer, extraConfig) {
		var config = {};

		Object.keys(PREVIEW_MAP).forEach(function(key) {
			var inputName = inputPrefix + '[' + key + ']';
			config[inputName] = expandRules(PREVIEW_MAP[key], lightContainer, darkContainer);
		});

		if (extraConfig) {
			Object.keys(extraConfig).forEach(function(inputName) {
				config[inputName] = expandRules(extraConfig[inputName], lightContainer, darkContainer);
			});
		}

		return config;
	}

	function isHexColor(value) {
		return /^[0-9a-fA-F]{6}$/.test(value);
	}

	/**
	 * Apply a value to all preview elements for a given config entry.
	 */
	function applyValue(rules, value) {
		rules.forEach(function(rule) {
			var finalValue = value;

			if (isHexColor(value)) {
				finalValue = '#' + value;
			}

			if (rule.unit) {
				finalValue = value + rule.unit;
			}

			if (rule.cssVar) {
				var target = rule.selector ? rule.container.querySelector(rule.selector) : rule.container;
				if (target) {
					target.style.setProperty(rule.cssVar, finalValue);
				}
			} else if (rule.index !== undefined) {
				var elements = rule.container.querySelectorAll(rule.selector);
				if (elements[rule.index]) {
					elements[rule.index].style[rule.prop] = finalValue;
				}
			} else if (rule.all) {
				rule.container.querySelectorAll(rule.selector).forEach(function(el) {
					el.style[rule.prop] = finalValue;
				});
			} else {
				var el = rule.container.querySelector(rule.selector);
				if (el) {
					el.style[rule.prop] = finalValue;
				}
			}
		});
	}

	// ---------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------

	return {
		/**
		 * Initialize live preview updates.
		 *
		 * @param {string} inputPrefix  Input name prefix, e.g. 'styles' or 'settings[styles]'
		 * @param {Object|null} extraConfig  Extra input→rule mappings (full input names as keys,
		 *                                   rules use mode: 'light'/'dark'/'both')
		 */
		initPreview: function(inputPrefix, extraConfig) {
			var lightPreview = document.querySelector('.widget-preview-light');
			var darkPreview = document.querySelector('.widget-preview-dark');
			if (!lightPreview || !darkPreview) return;

			var config = buildConfig(inputPrefix, lightPreview, darkPreview, extraConfig || null);
			var lastValues = {};

			function syncAll() {
				var form = document.querySelector('form');
				if (!form) return;

				form.querySelectorAll('input').forEach(function(input) {
					var name = input.name;
					var value = input.value;

					if (config[name] && value && value !== lastValues[name]) {
						lastValues[name] = value;
						applyValue(config[name], value);
					}
				});
			}

			// Initial sync
			syncAll();

			// Live updates on input
			var form = document.querySelector('form');
			if (form) {
				form.addEventListener('input', function(e) {
					var name = e.target.name;
					var value = e.target.value;
					if (config[name] && value !== lastValues[name]) {
						lastValues[name] = value;
						applyValue(config[name], value);
					}
				});
			}

			// Poll for color picker changes (they don't always fire input events)
			setInterval(syncAll, 100);
		},

		/**
		 * Initialize the Light/Dark color scheme toggle.
		 * Syncs form section visibility with preview mode.
		 */
		initColorSchemeToggle: function() {
			var toggleContainer = document.getElementById('color-scheme-toggle');
			var toggleButtons = toggleContainer ? toggleContainer.querySelectorAll('button[data-color-scheme]') : [];
			var colorSections = document.querySelectorAll('.color-scheme-section');
			var lightPreview = document.querySelector('.widget-preview-light');
			var darkPreview = document.querySelector('.widget-preview-dark');
			var previewToggleButtons = document.querySelectorAll('.widget-preview-toggle .btn');

			// Nothing to toggle at all
			if (!toggleContainer && !previewToggleButtons.length) return;

			function switchScheme(scheme) {
				toggleButtons.forEach(function(btn) {
					var isActive = btn.getAttribute('data-color-scheme') === scheme;
					btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
					btn.classList.toggle('secondary', isActive);
				});

				colorSections.forEach(function(section) {
					section.classList.toggle('hidden', section.getAttribute('data-color-scheme') !== scheme);
				});

				if (lightPreview && darkPreview) {
					lightPreview.style.display = scheme === 'light' ? 'block' : 'none';
					darkPreview.style.display = scheme === 'dark' ? 'block' : 'none';

					previewToggleButtons.forEach(function(btn) {
						var isActive = btn.getAttribute('data-preview-mode') === scheme;
						btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
						btn.classList.toggle('secondary', isActive);
					});
				}
			}

			toggleButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					switchScheme(this.getAttribute('data-color-scheme'));
				});
			});

			previewToggleButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					switchScheme(this.getAttribute('data-preview-mode'));
				});
			});
		},

		/**
		 * Apply style values directly to the preview (without polling form inputs).
		 *
		 * @param {Object} values  Style key → value pairs, e.g. { modalBg: 'ffffff', inputBorderColor: 'cccccc' }
		 */
		applyStyles: function(values) {
			var lightPreview = document.querySelector('.widget-preview-light');
			var darkPreview = document.querySelector('.widget-preview-dark');
			if (!lightPreview || !darkPreview) return;

			Object.keys(values).forEach(function(key) {
				var rules = PREVIEW_MAP[key];
				if (rules && values[key]) {
					var expanded = expandRules(rules, lightPreview, darkPreview);
					applyValue(expanded, values[key]);
				}
			});
		},

		/**
		 * Show/hide the preview wrapper based on which tab is active.
		 *
		 * @param {string} tabHash  The hash of the tab that shows the preview, e.g. '#appearance'
		 */
		initPreviewVisibility: function(tabHash) {
			var previewWrapper = document.getElementById('widget-preview-wrapper');
			if (!previewWrapper) return;

			function update() {
				var hash = window.location.hash || '#settings';
				previewWrapper.style.display = hash === tabHash ? 'block' : 'none';
			}

			update();
			window.addEventListener('hashchange', update);

			document.querySelectorAll('#tabs a').forEach(function(tab) {
				tab.addEventListener('click', function() {
					setTimeout(update, 10);
				});
			});
		}
	};
})();
