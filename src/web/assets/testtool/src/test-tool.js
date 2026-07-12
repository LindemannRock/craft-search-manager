(function(window, document) {
    'use strict';

    function escapeDisplay(value) {
        return Craft.escapeHtml(String(value === undefined || value === null ? '' : value));
    }

    function safeUrlAttribute(value) {
        const raw = String(value === undefined || value === null ? '' : value).trim();
        if (!raw) {
            return null;
        }

        const schemeProbe = raw.replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();
        if (/^(javascript|data|vbscript):/.test(schemeProbe)) {
            return null;
        }

        const schemeMatch = raw.match(/^([a-z][a-z0-9+.-]*):/i);
        if (schemeMatch && !['http', 'https'].includes(schemeMatch[1].toLowerCase())) {
            return null;
        }
        if (schemeMatch && !/^https?:\/\//i.test(raw)) {
            return null;
        }

        if (raw.startsWith('//') || raw.includes('\\')) {
            return null;
        }

        try {
            if (schemeMatch) {
                const parsed = new URL(raw);
                if (!['http:', 'https:'].includes(parsed.protocol)) {
                    return null;
                }
            } else {
                new URL(raw, window.location.origin);
            }
        } catch (e) {
            return null;
        }

        return Craft.escapeHtml(raw);
    }

    function truncateDisplay(value, maxLength) {
        const raw = String(value === undefined || value === null ? '' : value);
        const limit = maxLength || 80;

        return raw.length > limit ? raw.substring(0, limit - 3) + '...' : raw;
    }

    function postJson(url, csrfToken, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(body),
        });
    }

    window.lrSearchManagerTestToolInit = function(config) {
        function init() {
            const T = config.translations;
            const urls = config.urls;
            const csrfToken = config.csrfToken;
            const testButton = document.getElementById('testButton');
            const clearCacheButton = document.getElementById('clearCacheButton');
            const clearAutocompleteCacheButton = document.getElementById('clearAutocompleteCacheButton');
            const cacheStatus = document.getElementById('cacheStatus');
            const testQueryInput = document.getElementById('testQuery');
            const testIndexSelect = document.getElementById('testIndex');
            const testResults = document.getElementById('testResults');
            const resultsTitle = document.getElementById('resultsTitle');
            const resultsContent = document.getElementById('resultsContent');
            const autocompleteDropdown = document.getElementById('autocomplete-dropdown');
            const autocompleteSection = document.getElementById('autocomplete-section');
            const promotionsSection = document.getElementById('promotions-section');
            const queryrulesSection = document.getElementById('queryrules-section');
            const showAutocomplete = document.getElementById('showAutocomplete');
            const showPromotions = document.getElementById('showPromotions');
            const showQueryRules = document.getElementById('showQueryRules');
            const showHighlighting = document.getElementById('showHighlighting');
            const enableLiveComparison = document.getElementById('enableLiveComparison');
            const autocompleteMinLength = config.autocompleteMinLength || 2;
            const indexSiteIds = config.indexSiteIds || {};
            const snippetOptions = config.snippetOptions || {};
            const minSnippetLength = Number.isFinite(Number(snippetOptions.minSnippetLength)) ? Number(snippetOptions.minSnippetLength) : 50;
            const maxSnippetLength = Number.isFinite(Number(snippetOptions.maxSnippetLength)) ? Number(snippetOptions.maxSnippetLength) : 1000;
            const defaultSnippetLength = Number.isFinite(Number(snippetOptions.snippetMaxLength)) ? Number(snippetOptions.snippetMaxLength) : 150;
            let autocompleteTimer;
            let autocompleteTerms = [];
            let lastSearchData = null;
            let lastSearchQuery = null;
            let lastQueryRulesData = null;

            function getIndexSiteId() {
                return indexSiteIds[testIndexSelect.value] || null;
            }

            function setMessageState(element, state) {
                if (!element) {
                    return;
                }

                element.classList.remove('sm-test-message-success', 'sm-test-message-error');
                if (state) {
                    element.classList.add(`sm-test-message-${state}`);
                }
            }

            function updateSectionVisibility() {
                autocompleteSection.hidden = !showAutocomplete.checked;
                promotionsSection.hidden = !showPromotions.checked;
                queryrulesSection.hidden = !showQueryRules.checked;
            }

            showAutocomplete.addEventListener('change', updateSectionVisibility);
            showPromotions.addEventListener('change', updateSectionVisibility);
            showQueryRules.addEventListener('change', updateSectionVisibility);

            showHighlighting.addEventListener('change', function() {
                if (lastSearchData && lastSearchQuery) {
                    displaySearchResults(lastSearchData, lastSearchQuery, lastQueryRulesData);
                }
            });

            document.querySelectorAll('.quick-test').forEach(btn => {
                btn.addEventListener('click', function() {
                    testQueryInput.value = this.dataset.query;
                    testButton.click();
                });
            });

            if (clearCacheButton) {
                clearCacheButton.addEventListener('click', function() {
                    const indexHandle = testIndexSelect.value;
                    clearCacheButton.disabled = true;
                    clearCacheButton.textContent = T.clearing;

                    postJson(urls.clearTestCache, csrfToken, { indexHandle: indexHandle })
                        .then(response => response.json())
                        .then(data => {
                            clearCacheButton.disabled = false;
                            clearCacheButton.textContent = T.clearSearchCache;
                            if (data.success) {
                                cacheStatus.textContent = data.message;
                                setMessageState(cacheStatus, 'success');
                                setTimeout(() => { cacheStatus.textContent = ''; }, 3000);
                            } else {
                                cacheStatus.textContent = data.error || T.failedClearCache;
                                setMessageState(cacheStatus, 'error');
                            }
                        })
                        .catch(error => {
                            clearCacheButton.disabled = false;
                            clearCacheButton.textContent = T.clearSearchCache;
                            cacheStatus.textContent = error.message;
                            setMessageState(cacheStatus, 'error');
                        });
                });
            }

            if (clearAutocompleteCacheButton) {
                clearAutocompleteCacheButton.addEventListener('click', function() {
                    const indexHandle = testIndexSelect.value;
                    clearAutocompleteCacheButton.disabled = true;
                    clearAutocompleteCacheButton.textContent = T.clearing;

                    postJson(urls.clearAutocompleteCache, csrfToken, { indexHandle: indexHandle })
                        .then(response => response.json())
                        .then(data => {
                            clearAutocompleteCacheButton.disabled = false;
                            clearAutocompleteCacheButton.textContent = T.clearAutocompleteCache;
                            if (data.success) {
                                cacheStatus.textContent = data.message;
                                setMessageState(cacheStatus, 'success');
                                setTimeout(() => { cacheStatus.textContent = ''; }, 3000);
                            } else {
                                cacheStatus.textContent = data.error || T.failedClearAutocompleteCache;
                                setMessageState(cacheStatus, 'error');
                            }
                        })
                        .catch(error => {
                            clearAutocompleteCacheButton.disabled = false;
                            clearAutocompleteCacheButton.textContent = T.clearAutocompleteCache;
                            cacheStatus.textContent = error.message;
                            setMessageState(cacheStatus, 'error');
                        });
                });
            }

            testQueryInput.addEventListener('input', function() {
                this.classList.remove('sm-test-input-invalid');
                const query = this.value.trim();

                if (!showAutocomplete.checked || query.length < autocompleteMinLength) {
                    autocompleteTerms = [];
                    autocompleteDropdown.hidden = true;
                    return;
                }

                clearTimeout(autocompleteTimer);
                autocompleteTimer = setTimeout(() => {
                    fetchAutocomplete(query);
                }, 200);
            });

            testQueryInput.addEventListener('blur', function() {
                setTimeout(() => { autocompleteDropdown.hidden = true; }, 200);
            });

            autocompleteDropdown.addEventListener('click', function(event) {
                const item = event.target.closest('[data-autocomplete-index]');
                if (!item) {
                    return;
                }

                const index = Number(item.dataset.autocompleteIndex);
                const term = autocompleteTerms[index];
                if (typeof term !== 'string') {
                    return;
                }

                testQueryInput.value = term;
                autocompleteDropdown.hidden = true;
                testButton.click();
            });

            async function fetchAutocomplete(query) {
                try {
                    const siteId = getIndexSiteId();
                    const response = await postJson(urls.testAutocomplete, csrfToken, {
                        query: query,
                        indexHandle: testIndexSelect.value,
                        siteId: siteId,
                    });
                    const data = await response.json();
                    const suggestions = data && data.suggestions ? data.suggestions : [];
                    const meta = data && data.meta ? data.meta : null;

                    if (suggestions && suggestions.length > 0) {
                        autocompleteTerms = suggestions;
                        autocompleteDropdown.innerHTML = suggestions.map((term, index) => `
                    <div class="sm-test-autocomplete-option" data-autocomplete-index="${index}">
                        <code class="sm-test-autocomplete-code">${highlightMatch(term, query)}</code>
                    </div>
                `).join('');
                        autocompleteDropdown.hidden = false;
                        updateAutocompleteSection(suggestions, query, meta);
                    } else {
                        autocompleteTerms = [];
                        autocompleteDropdown.hidden = true;
                        updateAutocompleteSection([], query, meta);
                    }
                } catch (error) {
                    autocompleteTerms = [];
                    autocompleteDropdown.hidden = true;
                }
            }

            function highlightMatch(text, query) {
                if (typeof SearchManagerHighlighter !== 'undefined') {
                    return SearchManagerHighlighter.highlight(text, query, { tag: 'strong' });
                }
                const index = text.toLowerCase().indexOf(query.toLowerCase());
                if (index === -1) return Craft.escapeHtml(text);
                return Craft.escapeHtml(text.substring(0, index)) + '<strong>' + Craft.escapeHtml(text.substring(index, index + query.length)) + '</strong>' + Craft.escapeHtml(text.substring(index + query.length));
            }

            function smHighlight(text, query, terms) {
                if (!text) return '';
                if (!showHighlighting.checked) return Craft.escapeHtml(text);
                if (typeof SearchManagerHighlighter !== 'undefined') {
                    return SearchManagerHighlighter.highlight(text, query, {
                        tag: 'mark',
                        className: '',
                        terms: terms && terms.length > 0 ? terms : null,
                    });
                }
                return Craft.escapeHtml(text);
            }

            function getHitTerms(hit, area) {
                const phrases = Array.isArray(hit.matchedPhrases) ? hit.matchedPhrases : [];
                const matchedTerms = hit.matchedTerms;
                let terms = [];
                if (matchedTerms) {
                    if (area === 'title' && Array.isArray(matchedTerms.title) && matchedTerms.title.length > 0) {
                        terms = matchedTerms.title;
                    } else if (area === 'snippet' && Array.isArray(matchedTerms.content) && matchedTerms.content.length > 0) {
                        terms = matchedTerms.content;
                    } else {
                        terms = [
                            ...(Array.isArray(matchedTerms.title) ? matchedTerms.title : []),
                            ...(Array.isArray(matchedTerms.content) ? matchedTerms.content : []),
                        ];
                    }
                }
                const combined = [...phrases, ...terms];
                return combined.length > 0 ? combined : null;
            }

            function renderDebugPill(label, value) {
                if (value === undefined || value === null || value === '') {
                    return '';
                }

                return `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${label}</span>
                    <strong class="sm-test-indexed-value">${escapeDisplay(truncateDisplay(value, 120))}</strong>
                </div>`;
            }

            function renderDebugList(label, values) {
                if (!Array.isArray(values) || values.length === 0) {
                    return '';
                }

                return `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${label}</span>
                    <strong class="sm-test-indexed-value">${values.map(value => escapeDisplay(truncateDisplay(value, 72))).join(', ')}</strong>
                </div>`;
            }

            function normalizedAncestors(value) {
                if (!Array.isArray(value)) {
                    return [];
                }

                return value
                    .filter(ancestor => ancestor && typeof ancestor === 'object' && ancestor.title)
                    .map(ancestor => ({
                        id: ancestor.id,
                        title: String(ancestor.title),
                    }));
            }

            function ancestorBreadcrumb(value) {
                const ancestors = normalizedAncestors(value);

                return ancestors.length > 0 ? ancestors.map(ancestor => ancestor.title).join(' › ') : '';
            }

            function renderMetaPill(label, value) {
                if (value === undefined || value === null || value === '') {
                    return '';
                }

                return `<span class="sm-test-meta-item"><span class="sm-test-meta-label">${formatMetaLabel(label)}</span> ${escapeDisplay(truncateDisplay(value, 96))}</span>`;
            }

            function labelText(label) {
                return String(label || '').replace(/[:：]$/, '');
            }

            function friendlyDebugLabel(key) {
                const labels = {
                    ancestors: T.breadcrumbLabel,
                    assetKind: T.assetKindLabel,
                    backendId: T.backendIdLabel,
                    categoryGroup: T.categoryGroupLabel,
                    categoryGroupHandle: T.categoryGroupHandleLabel,
                    categoryIds: T.categoryIdsLabel,
                    cpEditUrl: T.cpEditUrlLabel,
                    docCategory: T.documentCategoryLabel,
                    documentKey: T.documentKeyLabel,
                    documentType: T.documentTypeLabel,
                    elementId: T.elementIdLabel,
                    elementType: T.elementTypeLabel,
                    entrySection: T.entrySectionLabel,
                    entrySectionHandle: T.entrySectionHandleLabel,
                    entrySectionType: T.entrySectionTypeLabel,
                    extension: T.extensionLabel,
                    fields: T.customFieldsLabel,
                    filename: T.filenameLabel,
                    folderPath: T.folderPathLabel,
                    headings: T.headingsLabel,
                    index: T.indexLabel,
                    indexElementType: T.indexElementTypeLabel,
                    language: T.languageLabel,
                    level: T.levelLabel,
                    matchedIn: T.matchedInLabel,
                    productType: T.productTypeLabel,
                    score: T.scoreLabel,
                    sectionAnchor: T.anchorLabel,
                    sectionLevel: T.levelLabel,
                    sectionType: T.sectionTypeLabel,
                    site: T.siteLabel,
                    siteId: T.siteIdLabel,
                    size: T.sizeLabel,
                    source: T.sourceLabel,
                    title: T.titleLabel,
                    transformerClass: T.transformerClassLabel,
                    type: T.typeLabel,
                    url: T.urlLabel,
                    width: T.widthLabel,
                    height: T.heightLabel,
                };

                if (labels[key]) {
                    return labelText(labels[key]);
                }

                return String(key || '')
                    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
                    .replace(/[_-]+/g, ' ')
                    .replace(/\b\w/g, letter => letter.toUpperCase());
            }

            function fieldRowsFromFields(fields) {
                if (!fields || typeof fields !== 'object' || Array.isArray(fields)) {
                    return [];
                }

                return Object.entries(fields).map(([handle, value]) => {
                    const displayValue = Array.isArray(value) ? value.join(', ') : value;
                    if (displayValue === undefined || displayValue === null || displayValue === '') {
                        return null;
                    }

                    return {
                        label: handle,
                        value: displayValue,
                    };
                }).filter(Boolean);
            }

            function renderCustomField(field) {
                if (!field || typeof field !== 'object') {
                    return '';
                }

                const label = escapeDisplay(truncateDisplay(field.label, 32));
                const children = Array.isArray(field.children) ? field.children : [];
                if (children.length > 0) {
                    const childRows = children.map(child => {
                        if (!child || typeof child !== 'object') {
                            return '';
                        }

                        return `<div class="sm-test-indexed-custom-child">
                            <span class="sm-test-indexed-custom-child-label">${escapeDisplay(truncateDisplay(child.label, 32))}:</span>
                            <code class="sm-test-indexed-custom-child-value">${escapeDisplay(truncateDisplay(child.value, 96))}</code>
                        </div>`;
                    }).filter(Boolean).join('');

                    return childRows ? `<div class="sm-test-indexed-custom-group">
                        <span class="sm-test-indexed-custom-group-label">${label}:</span>
                        <div class="sm-test-indexed-custom-children">${childRows}</div>
                    </div>` : '';
                }

                return `<div class="sm-test-indexed-custom-field">
                    <span class="sm-test-indexed-custom-field-label">${label}:</span>
                    <code class="sm-test-indexed-custom-field-value">${escapeDisplay(truncateDisplay(field.value, 96))}</code>
                </div>`;
            }

            function renderFieldsRow(label, fields) {
                const fieldRows = fieldRowsFromFields(fields).map(renderCustomField).filter(Boolean).join('');
                if (!fieldRows) {
                    return '';
                }

                return `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${escapeDisplay(labelText(label))}</span>
                    <div class="sm-test-indexed-custom-fields">${fieldRows}</div>
                </div>`;
            }

            function isPlainObject(value) {
                return value && typeof value === 'object' && !Array.isArray(value);
            }

            function isEmptyDebugValue(value) {
                if (value === undefined || value === null || value === '') {
                    return true;
                }

                if (Array.isArray(value)) {
                    return value.length === 0;
                }

                return isPlainObject(value) && Object.keys(value).length === 0;
            }

            function debugScalar(value) {
                if (typeof value === 'boolean') {
                    return value ? T.yesLabel : T.noLabel;
                }

                if (typeof value === 'number') {
                    return Number.isInteger(value) ? String(value) : value.toFixed(2);
                }

                return String(value);
            }

            function debugObjectSummary(value) {
                return Object.entries(value)
                    .map(([key, item]) => {
                        if (isEmptyDebugValue(item)) {
                            return null;
                        }

                        if (Array.isArray(item)) {
                            const list = item.map(debugScalar).join(', ');
                            return list ? `${friendlyDebugLabel(key)}: ${list}` : null;
                        }

                        if (isPlainObject(item)) {
                            return `${friendlyDebugLabel(key)}: ${debugObjectSummary(item)}`;
                        }

                        return `${friendlyDebugLabel(key)}: ${debugScalar(item)}`;
                    })
                    .filter(Boolean)
                    .join(' · ');
            }

            function debugDisplayValue(value) {
                if (Array.isArray(value)) {
                    return value.map(item => isPlainObject(item) ? debugObjectSummary(item) : debugScalar(item)).filter(Boolean).join(', ');
                }

                if (isPlainObject(value)) {
                    return debugObjectSummary(value);
                }

                return debugScalar(value);
            }

            function renderDataRow(label, value) {
                if (isEmptyDebugValue(value)) {
                    return '';
                }

                const displayValue = debugDisplayValue(value);
                if (!displayValue) {
                    return '';
                }

                return `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${escapeDisplay(labelText(label))}</span>
                    <strong class="sm-test-indexed-value">${escapeDisplay(truncateDisplay(displayValue, 240))}</strong>
                </div>`;
            }

            function renderHeadingsRow(label, headings) {
                if (!Array.isArray(headings) || headings.length === 0) {
                    return '';
                }

                const headingRows = headings.map(heading => {
                    if (!heading || typeof heading !== 'object') {
                        return '';
                    }

                    const level = heading.level || 2;
                    const title = heading.title || '';
                    const snippet = heading.snippet || '';

                    return `<div class="sm-test-heading-row">
                        <span class="sm-test-heading-tag">${escapeDisplay('h' + level)}</span>${escapeDisplay(title)}${snippet ? `<span class="sm-test-heading-snippet">${escapeDisplay(truncateDisplay(snippet, 160))}</span>` : ''}
                    </div>`;
                }).filter(Boolean).join('');

                return headingRows ? `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${escapeDisplay(labelText(label))}</span>
                    <div class="sm-test-headings sm-test-headings--indexed">${headingRows}</div>
                </div>` : '';
            }

            function mergeDebugData(target, source) {
                if (!isPlainObject(source)) {
                    return;
                }

                Object.entries(source).forEach(([key, value]) => {
                    if (isEmptyDebugValue(value)) {
                        return;
                    }

                    if ((key === 'elementKind' || key === 'commerce') && isPlainObject(value)) {
                        mergeDebugData(target, value);
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(target, key)) {
                        target[key] = value;
                    }
                });
            }

            function indexedDocumentData(hit) {
                const data = {};

                Object.entries(hit || {}).forEach(([key, value]) => {
                    if (key.startsWith('_')) {
                        return;
                    }

                    data[key] = value;
                });

                mergeDebugData(data, hit && hit._indexedDocument);

                return data;
            }

            function renderIndexedDocumentDebug(hit) {
                if (!isPlainObject(hit && hit._indexedDocument)) {
                    return '';
                }

                const data = indexedDocumentData(hit);
                const rows = Object.entries(data).map(([key, value]) => {
                    if (key === 'ancestors') {
                        return renderDataRow(T.breadcrumbLabel, ancestorBreadcrumb(value));
                    }

                    if (key === 'fields') {
                        return renderFieldsRow(T.customFieldsLabel, value);
                    }

                    if (key === 'headings') {
                        return renderHeadingsRow(T.headingsLabel, value);
                    }

                    return renderDataRow(friendlyDebugLabel(key), value);
                }).filter(Boolean);

                if (rows.length === 0) {
                    return '';
                }

                return `<details class="sm-test-indexed-debug">
                    <summary>${T.indexedDocumentLabel}</summary>
                    <div class="sm-test-indexed-grid">${rows.join('')}</div>
                </details>`;
            }

            function renderLiveComparison(hit) {
                const comparison = hit._liveComparison;
                if (!comparison || typeof comparison !== 'object') {
                    return '';
                }

                const linkedUrl = comparison.url ? `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${T.urlLabel}</span>
                    <strong class="sm-test-indexed-value">${renderSafeLinkOrText(comparison.url, comparison.url)}</strong>
                </div>` : '';
                const linkedCpUrl = comparison.cpEditUrl ? `<div class="sm-test-indexed-row">
                    <span class="sm-test-indexed-label">${T.cpEditUrlLabel}</span>
                    <strong class="sm-test-indexed-value">${renderSafeLinkOrText(comparison.cpEditUrl, comparison.cpEditUrl)}</strong>
                </div>` : '';
                const rows = [
                    renderDebugPill(T.elementFoundLabel, comparison.elementFound ? T.yesLabel : T.noLabel),
                    renderDebugPill(T.titleLabel, comparison.title),
                    linkedUrl,
                    linkedCpUrl,
                    renderDebugPill(T.typeLabel, comparison.type),
                    renderDebugPill(T.siteLabel, comparison.site),
                    renderDebugPill(T.languageLabel, comparison.language),
                ].filter(Boolean);

                return `<details class="sm-test-indexed-debug sm-test-live-comparison-debug">
                    <summary>${T.liveComparisonLabel}</summary>
                    <div class="sm-test-indexed-grid">${rows.join('')}</div>
                </details>`;
            }

            function renderStatusLabel(label, colorClass) {
                const color = /^[a-z]+$/.test(colorClass) ? colorClass : 'gray';

                return `<span class="status-label ${color}">
                    <span class="status ${color}"></span>
                    <span class="status-label-text">${Craft.escapeHtml(label)}</span>
                </span>`;
            }

            function formatMetaLabel(label) {
                const value = String(label || '');

                return /[:：]$/.test(value) ? value : `${value}:`;
            }

            function renderSafeLinkOrText(url, label) {
                const display = escapeDisplay(label || url || '');
                const safeUrl = safeUrlAttribute(url);

                return safeUrl ? `<a href="${safeUrl}" target="_blank">${display}</a>` : display;
            }

            function hitElementId(hit) {
                if (!hit || typeof hit !== 'object') {
                    return null;
                }

                const raw = hit.elementId;
                const id = Number(raw);

                return Number.isFinite(id) ? id : null;
            }

            function resultElementIds(searchData, predicate) {
                const ids = new Set();
                const hits = searchData && Array.isArray(searchData.hits) ? searchData.hits : [];

                hits.forEach(hit => {
                    if (!predicate(hit)) {
                        return;
                    }

                    const id = hitElementId(hit);
                    if (id !== null) {
                        ids.add(id);
                    }
                });

                return ids;
            }

            function countAppliedBoostRule(rule, searchData) {
                if (!rule || !rule.id || !searchData || !Array.isArray(searchData.hits)) {
                    return null;
                }

                const ruleId = Number(rule.id);
                if (!Number.isFinite(ruleId)) {
                    return null;
                }

                let count = 0;
                searchData.hits.forEach(hit => {
                    if (!hit || typeof hit !== 'object') {
                        return;
                    }

                    const debug = hit._queryRuleDebug && typeof hit._queryRuleDebug === 'object' ? hit._queryRuleDebug : null;
                    const boosts = debug && Array.isArray(debug.boosts) ? debug.boosts : [];
                    if (boosts.some(boost => Number(boost.ruleId) === ruleId)) {
                        count++;
                    }
                });

                return count;
            }

            function getRedirectRule(queryRulesData) {
                if (!queryRulesData || !Array.isArray(queryRulesData.rules)) {
                    return null;
                }

                return queryRulesData.rules.find(rule => rule.actionType === 'redirect') || null;
            }

            function renderRedirectNotice(searchData, queryRulesData, isCompact) {
                const redirectUrl = queryRulesData && queryRulesData.redirect ? queryRulesData.redirect : searchData && searchData.redirect;
                if (!redirectUrl) {
                    return '';
                }

                const redirectRule = getRedirectRule(queryRulesData);
                const elementInfo = redirectRule && redirectRule.elementInfo && typeof redirectRule.elementInfo === 'object' ? redirectRule.elementInfo : null;
                const targetUrl = elementInfo && elementInfo.url ? elementInfo.url : redirectUrl;
                const details = [];

                if (redirectRule && redirectRule.name) {
                    details.push(`<div><strong>${T.ruleLabel}</strong> ${renderSafeLinkOrText(redirectRule.editUrl, redirectRule.name)}</div>`);
                }

                if (elementInfo) {
                    const elementId = elementInfo.id ? ` <span class="sm-test-muted">ID: ${escapeDisplay(elementInfo.id)}</span>` : '';
                    details.push(`<div><strong>${T.targetLabel}</strong> <strong>${escapeDisplay(elementInfo.type || T.element)}</strong> ${renderSafeLinkOrText(elementInfo.cpEditUrl, elementInfo.title || T.untitled)}${elementId}</div>`);
                } else {
                    details.push(`<div><strong>${T.targetLabel}</strong> ${renderSafeLinkOrText(redirectUrl, redirectUrl)}</div>`);
                }

                details.push(`<div class="sm-test-redirect-url"><strong>${T.urlLabel}</strong> ${renderSafeLinkOrText(targetUrl, targetUrl)}</div>`);

                return `<div class="${isCompact ? 'sm-test-main-redirect-notice' : 'sm-test-redirect-box'}">
                    <div class="sm-test-redirect-heading">&rarr; ${isCompact ? T.redirectRuleMatched : T.redirectLabel}</div>
                    ${isCompact ? `<div class="sm-test-redirect-copy">${T.productionRedirectNotice}</div>` : ''}
                    <div class="sm-test-redirect-details">
                        ${details.join('')}
                    </div>
                </div>`;
            }

            function updateAutocompleteSection(suggestions, query, meta) {
                const container = document.getElementById('autocomplete-results');
                const cacheLabel = meta && meta.cacheEnabled
                    ? (meta.cached ? T.hit : T.miss)
                    : T.disabled;

                if (suggestions.length > 0) {
                    container.innerHTML = `
                <div class="sm-test-diagnostic-summary">
                    <span><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code></span>
                    <span><strong>${T.foundLabel}</strong> ${(suggestions.length === 1 ? T.suggestionsSingular : T.suggestionsPlural).replace('{count}', suggestions.length)}</span>
                    <span><strong>${T.cacheLabel}</strong> ${cacheLabel}${meta && meta.cacheDriver ? ' (' + meta.cacheDriver + ')' : ''}</span>
                </div>
                <div class="sm-test-chip-list">
                    ${suggestions.map(s => `<span class="sm-test-chip">${Craft.escapeHtml(s)}</span>`).join('')}
                </div>
            `;
                } else {
                    container.innerHTML = `
                <p class="light">${T.noSuggestions.replace('{query}', Craft.escapeHtml(query))}</p>
                <p class="light sm-test-terms"><strong>${T.cacheLabel}</strong> ${cacheLabel}${meta && meta.cacheDriver ? ' (' + meta.cacheDriver + ')' : ''}</p>
            `;
                }
            }

            testButton.addEventListener('click', function() {
                const query = testQueryInput.value.trim();
                const indexHandle = testIndexSelect.value;
                const enableWildcard = document.getElementById('enableWildcard').checked;

                if (!query) {
                    testQueryInput.classList.add('sm-test-input-invalid');
                    testQueryInput.focus();
                    testResults.hidden = true;
                    return;
                }

                testQueryInput.classList.remove('sm-test-input-invalid');
                testButton.disabled = true;
                testButton.textContent = T.searching;
                updateSectionVisibility();

                const snippetMaxLengthInput = document.getElementById('snippetMaxLength');
                const snippetMaxLength = Math.min(maxSnippetLength, Math.max(minSnippetLength, parseInt(snippetMaxLengthInput.value, 10) || defaultSnippetLength));
                snippetMaxLengthInput.value = snippetMaxLength;

                Promise.all([
                    postJson(urls.testSearch, csrfToken, {
                        query: query,
                        indexHandle: indexHandle,
                        wildcard: enableWildcard,
                        liveComparison: enableLiveComparison.checked,
                        includeQueryRuleDebug: showQueryRules.checked,
                        snippetMode: document.getElementById('snippetMode').value,
                        snippetMaxLength: snippetMaxLength,
                        snippetIncludeCodeBlocks: document.getElementById('snippetIncludeCodeBlocks').checked,
                        snippetCleanMarkdown: document.getElementById('snippetCleanMarkdown').checked,
                        resultsRequireUrl: document.getElementById('resultsRequireUrl').checked,
                        includeDebugMeta: document.getElementById('includeDebugMeta').checked,
                    }).then(r => r.json()),
                    showPromotions.checked ? postJson(urls.testPromotions, csrfToken, { query: query, indexHandle: indexHandle }).then(r => r.json()) : Promise.resolve(null),
                ])
                    .then(([searchData, promotionsData]) => {
                        const shouldFetchQueryRules = showQueryRules.checked || (searchData && searchData.redirect);

                        return (shouldFetchQueryRules
                            ? postJson(urls.testQueryRules, csrfToken, { query: query, indexHandle: indexHandle }).then(r => r.json())
                            : Promise.resolve(null)
                        ).then(queryRulesData => [searchData, promotionsData, queryRulesData]);
                    })
                    .then(([searchData, promotionsData, queryRulesData]) => {
                        testButton.disabled = false;
                        testButton.textContent = T.searchLabel;
                        lastQueryRulesData = queryRulesData;

                        if (promotionsData && showPromotions.checked) {
                            displayPromotions(promotionsData, query, searchData);
                        }
                        if (queryRulesData && showQueryRules.checked) {
                            displayQueryRules(queryRulesData, query, searchData);
                        }
                        displaySearchResults(searchData, query, queryRulesData);
                    })
                    .catch(error => {
                        testButton.disabled = false;
                        testButton.textContent = T.searchLabel;
                        testResults.hidden = false;
                        resultsTitle.innerHTML = T.error;
                        setMessageState(resultsTitle, 'error');
                        resultsContent.innerHTML = `<p class="sm-test-error">${Craft.escapeHtml(error.message)}</p>`;
                    });
            });

            function displayPromotions(data, query, searchData) {
                const container = document.getElementById('promotions-results');
                const renderedPromotionIds = resultElementIds(searchData, hit => hit.promoted === true);

                if (data.success && data.promotions && data.promotions.length > 0) {
                    container.innerHTML = `
                <div class="sm-test-diagnostic-summary">
                    <span><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code></span>
                    <span><strong>${T.matchedLabel}</strong> ${(data.promotions.length === 1 ? T.promotionsSingular : T.promotionsPlural).replace('{count}', data.promotions.length)}</span>
                </div>
                <div class="sm-test-diagnostic-list">
                    ${data.promotions.map(p => `
                        <article class="sm-test-diagnostic-card${!p.enabled ? ' sm-test-row-disabled' : ''}">
                            <div class="sm-test-diagnostic-card-header">
                                <div class="sm-test-diagnostic-primary">
                                    <div class="sm-test-diagnostic-title">
                                        <a href="${safeUrlAttribute(p.elementEditUrl) || '#'}" target="_blank">${Craft.escapeHtml(p.elementTitle)}</a>
                                        <span class="sm-test-diagnostic-meta">
                                            <span>${T.position}: #${p.position}</span>
                                            <span>ID: ${p.elementId}</span>
                                            ${p.elementTypeLabel ? `<span>${T.typeLabel} ${Craft.escapeHtml(p.elementTypeLabel)}</span>` : ''}
                                        </span>
                                        ${!p.enabled ? `<span class="sm-test-disabled-badge">${T.disabled}</span>` : ''}
                                    </div>
                                </div>
                                <div class="sm-test-diagnostic-hit">
                                    <span class="sm-test-diagnostic-label">${T.hitLabel}</span>
                                    ${renderStatusLabel(renderedPromotionIds.has(Number(p.elementId)) ? T.yesLabel : T.noLabel, renderedPromotionIds.has(Number(p.elementId)) ? 'green' : 'red')}
                                </div>
                            </div>
                            <div class="sm-test-diagnostic-grid">
                                <div class="sm-test-diagnostic-field">
                                    <span class="sm-test-diagnostic-label">${T.matchType}</span>
                                    <code>${Craft.escapeHtml(p.matchType)}</code>
                                </div>
                                <div class="sm-test-diagnostic-field">
                                    <span class="sm-test-diagnostic-label">${T.pattern}</span>
                                    <code>${Craft.escapeHtml(p.query)}</code>
                                </div>
                                ${p.siteIndependent ? '' : `<div class="sm-test-diagnostic-field sm-test-diagnostic-field--wide">
                                    <span class="sm-test-diagnostic-label">${T.liveOnSites}</span>
                                    <span class="sm-test-site-list-items">
                                        ${p.siteStatuses ? p.siteStatuses.filter(s => s.isLive).map(s => `
                                            <span class="sm-test-live-badge">${Craft.escapeHtml(s.siteName)}</span>
                                        `).join('') || '-' : '-'}
                                    </span>
                                </div>`}
                            </div>
                        </article>
                    `).join('')}
                </div>
                <p class="sm-test-promo-note">${T.promotionsNote}</p>
            `;
                } else {
                    container.innerHTML = `<p class="light">${T.noPromotions.replace('{query}', Craft.escapeHtml(query))}</p>`;
                }
            }

            function displayQueryRules(data, query, searchData) {
                const container = document.getElementById('queryrules-results');

                if (data.success && data.rules && data.rules.length > 0) {
                    const actionClasses = {
                        'synonym': 'blue',
                        'boost_section': 'green',
                        'boost_category': 'teal',
                        'boost_element': 'lime',
                        'redirect': 'red',
                    };

                    const redirectHtml = renderRedirectNotice(null, data, false);

                    container.innerHTML = `
                <div class="sm-test-diagnostic-summary">
                    <span><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code></span>
                    <span><strong>${T.matchedLabel}</strong> ${(data.rules.length === 1 ? T.rulesSingular : T.rulesPlural).replace('{count}', data.rules.length)}</span>
                </div>
                ${redirectHtml}
                ${data.synonyms && data.synonyms.length > 1 ? `<div class="sm-test-synonyms-box"><strong>${T.expandedQueriesLabel}</strong> ${data.synonyms.map(s => `<code>${Craft.escapeHtml(s)}</code>`).join(', ')}</div>` : ''}
                <div class="sm-test-diagnostic-list">
                    ${data.rules.map(r => {
                        let effectHtml = Craft.escapeHtml(r.effectDescription);
                        if (r.actionType === 'redirect' && r.elementInfo) {
                            effectHtml = T.redirectToElement.replace('{link}', `<a href="${safeUrlAttribute(r.elementInfo.cpEditUrl) || '#'}" target="_blank">${Craft.escapeHtml(r.elementInfo.title)}</a>`);
                        }
                        const actionLabel = T.actionLabels[r.actionType] || Craft.escapeHtml(r.actionType);
                        const actionClass = actionClasses[r.actionType] || 'gray';
                        const isBoostRule = ['boost_section', 'boost_category', 'boost_element'].includes(r.actionType);
                        const appliedCount = isBoostRule ? countAppliedBoostRule(r, searchData) : null;
                        const resultStatus = appliedCount !== null
                            ? renderStatusLabel(appliedCount > 0 ? T.yesLabel : T.noLabel, appliedCount > 0 ? 'green' : 'red')
                            : '';
                        const targetMeta = [
                            r.targetElementId ? `ID: ${Craft.escapeHtml(r.targetElementId)}` : '',
                            r.targetElementType ? `${T.typeLabel} ${Craft.escapeHtml(r.targetElementType)}` : '',
                        ].filter(Boolean).join(' · ');
                        return `
                            <article class="sm-test-diagnostic-card">
                                <div class="sm-test-diagnostic-card-header">
                                    <div class="sm-test-diagnostic-title">
                                        <a href="${safeUrlAttribute(r.editUrl) || '#'}" target="_blank">${Craft.escapeHtml(r.name)}</a>
                                        ${targetMeta ? `<span class="sm-test-diagnostic-meta"><span>${targetMeta}</span></span>` : ''}
                                    </div>
                                    ${resultStatus ? `<div class="sm-test-diagnostic-hit"><span class="sm-test-diagnostic-label">${T.hitLabel}</span>${resultStatus}</div>` : ''}
                                </div>
                                <div class="sm-test-diagnostic-grid">
                                    <div class="sm-test-diagnostic-field">
                                        <span class="sm-test-diagnostic-label">${T.action}</span>
                                        ${renderStatusLabel(actionLabel, actionClass)}
                                    </div>
                                    <div class="sm-test-diagnostic-field">
                                        <span class="sm-test-diagnostic-label">${T.match}</span>
                                        <span><code>${Craft.escapeHtml(r.matchType)}</code>: <code>${Craft.escapeHtml(r.matchValue)}</code></span>
                                    </div>
                                    <div class="sm-test-diagnostic-field sm-test-diagnostic-field--wide">
                                        <span class="sm-test-diagnostic-label">${T.effect}</span>
                                        <span>${effectHtml}</span>
                                    </div>
                                </div>
                            </article>
                        `;
                    }).join('')}
                </div>
            `;
                } else {
                    container.innerHTML = `<p class="light">${T.noQueryRules.replace('{query}', Craft.escapeHtml(query))}</p>`;
                }
            }

            function displaySearchResults(data, query, queryRulesData) {
                lastSearchData = data;
                lastSearchQuery = query;
                testResults.hidden = false;

                if (data.success) {
                    const hasRedirect = Boolean(data.redirect || (queryRulesData && queryRulesData.redirect));
                    resultsTitle.innerHTML = hasRedirect ? T.redirectRuleMatched : (data.total === 1 ? T.foundResultsSingular : T.foundResultsPlural).replace('{count}', data.total);
                    setMessageState(resultsTitle, null);

                    let html = `
<div class="sm-test-summary">
    <div class="sm-test-summary-grid">
        <div><strong>${T.backendLabel}</strong> ${Craft.escapeHtml(data.backend)}</div>
        <div><strong>${T.executionLabel}</strong> ${data.executionTime}ms</div>
        <div><strong>${T.cacheLabel}</strong> ${data.cacheEnabled ? (data.cacheHit ? T.hit : T.miss) : T.disabled}${data.cacheDriver ? ' (' + data.cacheDriver + ')' : ''}</div>
        <div><strong>${T.queryUsedLabel}</strong> <code>${Craft.escapeHtml(typeof data.queryUsed === 'string' ? data.queryUsed : query)}</code></div>
    </div>
</div>
`;
                    html += renderRedirectNotice(data, queryRulesData, true);

                    if (data.total > 0) {
                        html += '<div class="sm-test-results-grid">';
                        data.hits.forEach(hit => {
                            const hasSectionHit = ['heading', 'intro', 'promoted-page'].includes(String(hit.sectionType || ''));
                            const rawTitle = hasSectionHit ? (hit.sectionTitle || hit.title || T.untitled) : (hit.title || T.untitled);
                            const rawSnippet = hit.snippet || '';
                            const titleTerms = getHitTerms(hit, 'title');
                            const descTerms = getHitTerms(hit, 'snippet');
                            const title = smHighlight(rawTitle, query, titleTerms);
                            const rawDisplayText = rawSnippet;
                            const displayText = rawSnippet ? smHighlight(rawSnippet.substring(0, 400), query, descTerms) : '';
                            const rawUrl = hasSectionHit ? (hit.sectionUrl || hit.url) : hit.url;
                            const url = safeUrlAttribute(rawUrl);
                            const urlText = rawUrl ? escapeDisplay(rawUrl) : '';
                            const isPromoted = hit.promoted === true;
                            const isBoosted = hit.boosted === true;
                            const rawType = hit.type || T.entry;
                            const siteName = hit.siteName || hit.site || T.unknown;
                            const score = hit.score !== undefined && hit.score !== null ? Number(hit.score).toFixed(2) : T.naValue;
                            const cardClass = isPromoted ? ' sm-test-result-card--promoted' : (isBoosted ? ' sm-test-result-card--boosted' : '');

                            html += `
<div class="sm-test-result-card${cardClass}">
    <div class="sm-test-result-layout">
        <div class="sm-test-result-main">
            <div class="sm-test-result-header">
                <div class="sm-test-result-title-wrap">
                    <strong class="sm-test-title">${title}</strong>
                    ${url ? `<div class="sm-test-url"><a href="${url}" target="_blank">${urlText}</a></div>` : ''}
                </div>
                <div class="sm-test-signals">
                    ${isPromoted ? `<span class="sm-test-status sm-test-status--promoted">${T.promoted}</span>` : ''}
                    ${isBoosted ? `<span class="sm-test-status sm-test-status--boosted">${T.boosted}</span>` : ''}
                    <span class="sm-test-score">
                        ${T.scoreLabel} ${score}
                    </span>
                </div>
            </div>
            <div class="sm-test-meta">
                ${renderMetaPill(T.typeLabel, rawType)}
                ${renderMetaPill(T.siteLabel, siteName)}
            </div>
            ${displayText ? `<div class="sm-test-description">${displayText}${rawDisplayText.length > 400 ? '...' : ''}</div>` : ''}
            ${hit._snippet ? `<div class="sm-test-debug-strip">
                <span><span class="sm-test-debug-label">${T.snippetMatchedIn}</span> <strong class="sm-test-debug-value">${Craft.escapeHtml(hit._snippet.snippetSource || '-')}</strong></span>
                <span><span class="sm-test-debug-label">${T.snippetMode}</span> <strong class="sm-test-debug-value">${Craft.escapeHtml(hit._snippet.snippetMode || '-')}</strong></span>
                <span><span class="sm-test-debug-label">${T.snippetSource}</span> <strong class="sm-test-debug-value">${Craft.escapeHtml(hit._snippet.snippetFrom || '-')}</strong></span>
                ${hit._snippet.fullContentLength ? `<span><span class="sm-test-debug-label">${T.snippetContent}</span> <strong class="sm-test-debug-value">${(hit._snippet.fullContentLength === 1 ? T.charsSingular : T.charsPlural).replace('{count}', hit._snippet.fullContentLength.toLocaleString())}</strong></span>` : ''}
            </div>` : ''}
            ${renderLiveComparison(hit)}
            ${renderIndexedDocumentDebug(hit)}
        </div>
    </div>
</div>
`;
                        });
                        html += '</div>';
                    } else if (!hasRedirect) {
                        html += `
<div class="sm-test-empty">
    <p class="sm-test-empty-title">${T.noResults.replace('{query}', Craft.escapeHtml(query))}</p>
    <p class="sm-test-empty-copy">${T.tryDifferent}</p>
</div>
`;
                    }

                    resultsContent.innerHTML = html;
                } else {
                    resultsTitle.innerHTML = T.searchFailed;
                    setMessageState(resultsTitle, 'error');
                    resultsContent.innerHTML = `<p class="sm-test-error">${Craft.escapeHtml(data.error || T.unknownError)}</p>`;
                }
            }

            testQueryInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    autocompleteDropdown.hidden = true;
                    testButton.click();
                }
            });

            updateSectionVisibility();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init, { once: true });
        } else {
            init();
        }
    };
})(window, document);
