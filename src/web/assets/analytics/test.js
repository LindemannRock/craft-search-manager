'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class FakeClassList {
	constructor(element) {
		this.element = element;
	}

	values() {
		return this.element.className.split(/\s+/).filter(Boolean);
	}

	contains(value) {
		return this.values().includes(value);
	}

	add(...values) {
		this.element.className = Array.from(new Set([...this.values(), ...values])).join(' ');
	}

	remove(...values) {
		this.element.className = this.values()
			.filter((value) => !values.includes(value))
			.join(' ');
	}
}

class FakeElement {
	constructor(tagName, id = '') {
		this.tagName = tagName.toUpperCase();
		this.id = id;
		this.className = '';
		this.classList = new FakeClassList(this);
		this.children = [];
		this.parentNode = null;
		this.parentElement = null;
		this.dataset = {};
		this.attributes = new Map();
		this.listeners = new Map();
		this.style = {};
		this.textContent = '';
		this.disabled = false;
		this.type = '';
	}

	appendChild(child) {
		child.parentNode = this;
		child.parentElement = this;
		this.children.push(child);
		return child;
	}

	prepend(child) {
		child.parentNode = this;
		child.parentElement = this;
		this.children.unshift(child);
		return child;
	}

	remove() {
		if (!this.parentNode) return;
		this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
		this.parentNode = null;
		this.parentElement = null;
	}

	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}

	getAttribute(name) {
		return this.attributes.has(name) ? this.attributes.get(name) : null;
	}

	removeAttribute(name) {
		this.attributes.delete(name);
	}

	addEventListener(type, listener) {
		const listeners = this.listeners.get(type) || [];
		listeners.push(listener);
		this.listeners.set(type, listeners);
	}

	dispatchEvent(event) {
		(this.listeners.get(event.type) || []).forEach((listener) => listener.call(this, event));
	}

	click() {
		if (!this.disabled) {
			this.dispatchEvent({type: 'click', target: this});
		}
	}

	matches(selector) {
		if (selector.startsWith('#')) return this.id === selector.substring(1);
		if (selector.startsWith('.')) return this.classList.contains(selector.substring(1));
		return this.tagName.toLowerCase() === selector.toLowerCase();
	}

	querySelector(selector) {
		return this.querySelectorAll(selector)[0] || null;
	}

	querySelectorAll(selector) {
		const matches = [];
		const visit = (element) => {
			element.children.forEach((child) => {
				if (child.matches(selector)) matches.push(child);
				visit(child);
			});
		};
		visit(this);
		return matches;
	}
}

class FakeDocument {
	constructor() {
		this.readyState = 'complete';
		this.body = new FakeElement('body');
		this.listeners = new Map();
	}

	createElement(tagName) {
		return new FakeElement(tagName);
	}

	getElementById(id) {
		if (this.body.id === id) return this.body;
		return this.body.querySelectorAll('*').find((element) => element.id === id) || this.findById(this.body, id);
	}

	findById(root, id) {
		for (const child of root.children) {
			if (child.id === id) return child;
			const nested = this.findById(child, id);
			if (nested) return nested;
		}
		return null;
	}

	querySelector(selector) {
		if (/^#\d/.test(selector)) {
			throw new SyntaxError(`${selector} is not a valid selector`);
		}
		if (selector.includes(',')) return null;
		return this.body.querySelector(selector);
	}

	querySelectorAll(selector) {
		if (selector === '*') return this.body.querySelectorAll(selector);
		return this.body.querySelectorAll(selector);
	}

	addEventListener(type, listener) {
		const listeners = this.listeners.get(type) || [];
		listeners.push(listener);
		this.listeners.set(type, listeners);
	}

	dispatchEvent(event) {
		(this.listeners.get(event.type) || []).forEach((listener) => listener(event));
	}
}

function createJquery(document, requests) {
	function wrapper(selector) {
		const elements =
			typeof selector === 'string'
				? selector.includes(' ')
					? []
					: document.querySelectorAll(selector)
				: selector
					? [selector]
					: [];
		return {
			length: elements.length,
			children: () => ({length: elements[0] ? elements[0].children.length : 0}),
			empty() {
				elements.forEach((element) => {
					element.children = [];
					element.textContent = '';
				});
				return this;
			},
			html(value) {
				elements.forEach((element) => {
					element.textContent = value || '';
				});
				return this;
			},
			text(value) {
				elements.forEach((element) => {
					element.textContent = value;
				});
				return this;
			},
			append() {
				return this;
			},
		};
	}
	wrapper.ajax = (options) => {
		requests.push(options);
		return {requestNumber: requests.length};
	};
	return wrapper;
}

function addElement(document, tagName, id, className = '') {
	const element = new FakeElement(tagName, id);
	element.className = className;
	document.body.appendChild(element);
	return element;
}

function createEnvironment(options = {}) {
	const document = new FakeDocument();
	const requests = [];
	const overview = addElement(document, 'div', 'overview', 'lr-tab-content');
	const target = new FakeElement('div', 'test-target');
	overview.appendChild(target);
	const trendTarget = new FakeElement('canvas', '404-trend-chart');
	overview.appendChild(trendTarget);
	addElement(document, 'div', 'searches', 'lr-tab-content hidden');
	addElement(document, 'div', 'content-gaps', 'lr-tab-content hidden');
	addElement(document, 'div', 'lr-analytics-content');

	const $ = createJquery(document, requests);
	const window = {
		document,
		location: {hash: ''},
		currentTab: 'overview',
		smCharts: {},
		Craft: {
			$,
			getActionUrl: (value) => `/actions/${value}`,
			escapeHtml: (value) => String(value),
		},
		Garnish: {onReady: (callback) => callback()},
	};
	window.window = window;

	const context = vm.createContext({
		window,
		document,
		Craft: window.Craft,
		Garnish: window.Garnish,
		console,
		CustomEvent: class CustomEvent {
			constructor(type, init) {
				this.type = type;
				this.detail = init ? init.detail : undefined;
			}
		},
		Chart: {
			getChart: () => null,
		},
		setTimeout,
		clearTimeout,
	});
	const sourcePath = path.join(__dirname, 'src', 'analytics.js');
	vm.runInContext(fs.readFileSync(sourcePath, 'utf8'), context, {filename: sourcePath});
	window.lrSearchAnalyticsInit({
		testMode: true,
		dateRange: options.dateRange || 'last30days',
		siteId: options.siteId || '7',
		csrfName: 'CRAFT_CSRF_TOKEN',
		csrfToken: 'csrf-value',
		endpoints: {data: 'search-manager/analytics/get-data'},
		strings: {
			analyticsLoadError: 'Analytics failed.',
			retry: 'Retry',
			loading: 'Loading...',
		},
	});

	return {document, requests, target, trendTarget, window, hooks: window.lrSearchAnalyticsTestHooks};
}

function basicRequest(consumer, overrides = {}) {
	return Object.assign(
		{
			consumer,
			family: 'overview',
			tab: 'overview',
			targets: ['#test-target'],
			data: {type: 'chart'},
			dateRange: 'last30days',
			siteId: '7',
			onFailure() {},
			onSuccess() {},
		},
		overrides,
	);
}

function errors(document) {
	return document.querySelectorAll('.lr-analytics-request-error');
}

function sharedAuthorityCounts(source) {
	return {
		rawAjax: (source.match(/\$\.ajax\s*\(/g) || []).length,
		callSites: (source.match(/analyticsRequest\s*\(\s*\{/g) || []).length,
		consumers: (source.match(/\bconsumer\s*:/g) || []).length,
	};
}

test('all 23 analytics call sites use the shared request authority', () => {
	const source = fs.readFileSync(path.join(__dirname, 'src', 'analytics.js'), 'utf8');
	assert.deepEqual(sharedAuthorityCounts(source), {rawAjax: 1, callSites: 23, consumers: 23});
	for (const type of [
		'chart',
		'query-analysis',
		'content-gaps',
		'recent-searches',
		'device-stats',
		'countries',
		'cache-stats',
		'query-rules-top',
		'promotions-top',
	]) {
		assert.match(source, new RegExp(`['"]${type}['"]`));
	}
});

test('coverage guard detects a request that bypasses the shared authority', () => {
	const source = fs.readFileSync(path.join(__dirname, 'src', 'analytics.js'), 'utf8');
	const bypassed = source.replace('analyticsRequest({', '$.ajax({');
	assert.deepEqual(sharedAuthorityCounts(bypassed), {rawAjax: 2, callSites: 22, consumers: 23});
});

test('Twig, all locales, and the generated bundle expose the failure contract', () => {
	const errorKey = 'An error occurred while loading analytics data.';
	const retryKey = 'Retry';
	const twig = fs.readFileSync(path.join(__dirname, '..', '..', '..', 'templates', 'analytics', 'index.twig'), 'utf8');
	assert.match(twig, /analyticsLoadError: "An error occurred while loading analytics data\."\|t\('search-manager'\)/);
	assert.match(twig, /retry: "Retry"\|t\('search-manager'\)/);

	for (const locale of ['ar', 'da', 'de', 'en', 'es', 'fr', 'it', 'ja', 'nl', 'no', 'pt', 'sv']) {
		const catalogue = fs.readFileSync(
			path.join(__dirname, '..', '..', '..', 'translations', locale, 'search-manager.php'),
			'utf8',
		);
		assert.match(catalogue, new RegExp(`['"]${errorKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}['"]\\s*=>`));
		assert.match(catalogue, new RegExp(`['"]${retryKey}['"]\\s*=>`));
	}

	const dist = fs.readFileSync(path.join(__dirname, 'dist', 'analytics.js'), 'utf8');
	for (const marker of ['lr-analytics-request-error', 'analyticsLoadError', 'retryRequest', 'aria-busy']) {
		assert.match(dist, new RegExp(marker));
	}
});

test('transport failure clears busy state and presents one accessible retry button', () => {
	const env = createEnvironment();
	let failures = 0;
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(
		basicRequest('transport', {
			onFailure: () => {
				failures += 1;
			},
		}),
	);
	assert.equal(env.target.getAttribute('aria-busy'), 'true');
	env.requests[0].error();
	assert.equal(failures, 1);
	assert.equal(env.target.getAttribute('aria-busy'), null);
	assert.equal(errors(env.document).length, 1);
	const error = errors(env.document)[0];
	const button = error.querySelector('button');
	assert.equal(error.getAttribute('role'), 'alert');
	assert.equal(error.getAttribute('aria-live'), 'assertive');
	assert.equal(error.querySelector('.lr-info-box__message').textContent, 'Analytics failed.');
	assert.equal(button.tagName, 'BUTTON');
	assert.equal(button.type, 'button');
	assert.equal(button.getAttribute('aria-label'), 'Retry');
});

test('loading state supports HTML ids that begin with a digit', () => {
	const env = createEnvironment();
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(basicRequest('numeric-id', {targets: ['#404-trend-chart']}));
	assert.equal(env.trendTarget.getAttribute('aria-busy'), 'true');
	env.requests[0].error();
	assert.equal(env.trendTarget.getAttribute('aria-busy'), null);
	assert.equal(errors(env.document).length, 1);
});

test('resolved success:false uses the same failure path without exposing server details', () => {
	const env = createEnvironment();
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(basicRequest('application'));
	env.requests[0].success({success: false, error: '<private exception>'});
	assert.equal(errors(env.document).length, 1);
	assert.equal(errors(env.document)[0].textContent.includes('private exception'), false);
	assert.equal(errors(env.document)[0].querySelector('.lr-info-box__message').textContent, 'Analytics failed.');
});

test('retry uses current filters, is natively activatable, and recovers after success', () => {
	const env = createEnvironment({dateRange: 'last90days', siteId: '11'});
	let successes = 0;
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(
		basicRequest('retry', {
			onSuccess: () => {
				successes += 1;
			},
		}),
	);
	env.requests[0].error();
	const button = errors(env.document)[0].querySelector('button');
	button.click();
	assert.equal(env.requests.length, 2);
	assert.equal(env.requests[1].data.dateRange, 'last90days');
	assert.equal(env.requests[1].data.siteId, '11');
	assert.equal(button.disabled, true);
	env.requests[1].success({success: true, data: {}});
	assert.equal(successes, 1);
	assert.equal(errors(env.document).length, 0);
	assert.equal(env.target.getAttribute('aria-busy'), null);
});

test('repeated failures replace state without duplicate error nodes or listeners', () => {
	const env = createEnvironment();
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(basicRequest('repeat'));
	env.requests[0].error();
	errors(env.document)[0].querySelector('button').click();
	env.requests[1].error();
	assert.equal(errors(env.document).length, 1);
	errors(env.document)[0].querySelector('button').click();
	assert.equal(env.requests.length, 3);
});

test('a sibling success does not erase an unresolved failure', () => {
	const env = createEnvironment();
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(basicRequest('failed-sibling'));
	env.hooks.analyticsRequest(basicRequest('successful-sibling'));
	env.requests[0].error();
	env.requests[1].success({success: true, data: {}});
	assert.equal(errors(env.document).length, 1);
	assert.equal(errors(env.document)[0].dataset.analyticsRequestError, 'failed-sibling');
});

test('duplicate in-flight and already-successful requests are suppressed', () => {
	const env = createEnvironment();
	let renders = 0;
	env.hooks.resetRequestGeneration();
	const options = basicRequest('duplicate', {
		onSuccess: () => {
			renders += 1;
		},
	});
	env.hooks.analyticsRequest(options);
	env.hooks.analyticsRequest(options);
	assert.equal(env.requests.length, 1);
	env.requests[0].success({success: true, data: {}});
	env.hooks.analyticsRequest(options);
	assert.equal(env.requests.length, 1);
	assert.equal(renders, 1);
});

test('a stale prior-generation completion cannot render or change current failure state', () => {
	const env = createEnvironment();
	let staleSuccesses = 0;
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(
		basicRequest('stale', {
			onSuccess: () => {
				staleSuccesses += 1;
			},
		}),
	);
	const stale = env.requests[0];
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(basicRequest('current'));
	env.requests[1].error();
	stale.success({success: true, data: {}});
	stale.error();
	assert.equal(staleSuccesses, 0);
	assert.equal(errors(env.document).length, 1);
	assert.equal(errors(env.document)[0].dataset.analyticsRequestError, 'current');
});

test('date/site reinitialization starts a new generation and retires old page requests', () => {
	const env = createEnvironment();
	env.hooks.handleAnalyticsInit({dateRange: 'last7days', siteId: '2'});
	assert.deepEqual(
		env.requests.slice(0, 2).map((request) => [request.data.type, request.data.dateRange, request.data.siteId]),
		[
			['chart', 'last7days', '2'],
			['query-analysis', 'last7days', '2'],
		],
	);
	const stale = env.requests[0];
	const oldContent = env.document.getElementById('lr-analytics-content');
	oldContent.remove();
	addElement(env.document, 'div', 'lr-analytics-content');
	env.hooks.handleAnalyticsInit({dateRange: 'last30days', siteId: '3'});
	assert.deepEqual(
		env.requests.slice(2, 4).map((request) => [request.data.type, request.data.dateRange, request.data.siteId]),
		[
			['chart', 'last30days', '3'],
			['query-analysis', 'last30days', '3'],
		],
	);
	stale.error();
	assert.equal(errors(env.document).length, 0);
});

test('initial, nested, and lazy tab paths preserve their request families', () => {
	const env = createEnvironment();
	env.hooks.handleAnalyticsInit({dateRange: 'last7days', siteId: '2'});
	const queryAnalysis = env.requests.find((request) => request.data.type === 'query-analysis');
	queryAnalysis.success({
		success: true,
		data: {queryAnalysis: {lengthDistribution: null, wordCloud: []}},
	});
	assert.deepEqual(
		env.requests.slice(2, 6).map((request) => request.data.type),
		['intent', 'source', 'hourly', 'trending'],
	);
	env.hooks.loadTabData('searches');
	assert.equal(env.requests[6].data.type, 'recent-searches');
});

test('render callback exceptions become retryable failures with loading teardown', () => {
	const env = createEnvironment();
	env.hooks.resetRequestGeneration();
	env.hooks.analyticsRequest(
		basicRequest('render-exception', {
			onSuccess: () => {
				throw new Error('render failed');
			},
		}),
	);
	env.requests[0].success({success: true, data: {}});
	assert.equal(env.target.getAttribute('aria-busy'), null);
	assert.equal(errors(env.document).length, 1);
});
