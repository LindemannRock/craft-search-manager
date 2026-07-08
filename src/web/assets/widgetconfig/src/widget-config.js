/**
 * Search Manager Widget Config
 *
 * Keeps widget index choices aligned with the selected public API key.
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */
(function() {
	'use strict';

	function parseJsonScript(id, fallback) {
		var node = document.getElementById(id);
		if (!node) {
			return fallback;
		}
		try {
			return JSON.parse(node.textContent || '');
		} catch (e) {
			return fallback;
		}
	}

	function allowedValues(selectedKeyHandle, scopes, allOptions) {
		var scope = scopes[selectedKeyHandle] || '*';
		if (scope === '*') {
			return allOptions.map(function(option) {
				return option.value;
			});
		}
		return Array.isArray(scope) ? scope : [];
	}

	function selectedValues(fieldset) {
		return Array.prototype.slice.call(fieldset.querySelectorAll('input[type="checkbox"]:checked')).map(function(input) {
			return input.value;
		});
	}

	function renderOptions(fieldset, inputName, options, selected) {
		Array.prototype.slice.call(fieldset.querySelectorAll('.checkbox-select-item')).forEach(function(item) {
			item.parentNode.removeChild(item);
		});

		options.forEach(function(option, index) {
			var wrapper = document.createElement('div');
			var input = document.createElement('input');
			var label = document.createElement('label');
			var id = fieldset.id + '-dynamic-' + index;

			wrapper.className = 'checkbox-select-item';
			input.type = 'checkbox';
			input.id = id;
			input.className = 'checkbox';
			input.name = inputName + '[]';
			input.value = option.value;
			input.checked = selected.indexOf(option.value) !== -1;

			label.setAttribute('for', id);
			label.textContent = option.label;

			wrapper.appendChild(input);
			wrapper.appendChild(label);
			fieldset.appendChild(wrapper);
		});
	}

	function init() {
		var select = document.getElementById('settings-apiKeyHandle');
		var fieldset = document.getElementById('search-indexHandles');
		if (!select || !fieldset) {
			return;
		}

		var allOptions = parseJsonScript('search-manager-widget-index-options', []);
		var scopes = parseJsonScript('search-manager-widget-api-key-scopes', {});
		var inputName = fieldset.getAttribute('data-input-name') || 'settings[search][indexHandles]';

		select.addEventListener('change', function() {
			var allowed = allowedValues(select.value, scopes, allOptions);
			var visibleOptions = allOptions.filter(function(option) {
				return allowed.indexOf(option.value) !== -1;
			});
			renderOptions(fieldset, inputName, visibleOptions, selectedValues(fieldset));
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
