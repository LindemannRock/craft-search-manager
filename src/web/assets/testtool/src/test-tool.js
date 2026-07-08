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
            const enableEnrich = document.getElementById('enableEnrich');
            const enrichOptionsPanel = document.getElementById('enrichOptions');
            const autocompleteMinLength = config.autocompleteMinLength || 2;
            const indexSiteIds = config.indexSiteIds || {};
            let autocompleteTimer;
            let autocompleteTerms = [];
            let lastSearchData = null;
            let lastSearchQuery = null;

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

            function updateEnrichPanel() {
                enrichOptionsPanel.hidden = !enableEnrich.checked;
            }
            enableEnrich.addEventListener('change', updateEnrichPanel);
            updateEnrichPanel();

            showHighlighting.addEventListener('change', function() {
                if (lastSearchData && lastSearchQuery) {
                    displaySearchResults(lastSearchData, lastSearchQuery);
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
                    } else if (area === 'description' && Array.isArray(matchedTerms.content) && matchedTerms.content.length > 0) {
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

            function renderIndexedDocumentDebug(hit) {
                const debug = hit._indexedDocument;
                if (!debug || typeof debug !== 'object') {
                    return '';
                }

                const commerce = debug.commerce && typeof debug.commerce === 'object' ? debug.commerce : {};
                const productType = commerce.productType && typeof commerce.productType === 'object' ? commerce.productType : null;
                const parentProduct = commerce.parentProduct && typeof commerce.parentProduct === 'object' ? commerce.parentProduct : null;
                const customFields = Array.isArray(debug.customFields) ? debug.customFields : [];
                const parentUrl = parentProduct ? safeUrlAttribute(parentProduct.url) : null;
                const parentUrlText = parentProduct && parentProduct.url ? escapeDisplay(truncateDisplay(parentProduct.url, 64)) : '';

                const rows = [
                    renderDebugPill(T.transformerClassLabel, debug.transformerClass),
                    renderDebugPill(T.indexElementTypeLabel, debug.indexElementType),
                    renderDebugPill(T.documentTypeLabel, debug.documentType),
                    productType ? renderDebugPill(T.productTypeLabel, [productType.name, productType.handle ? `(${productType.handle})` : ''].filter(Boolean).join(' ')) : '',
                    renderDebugList(T.variantSkusLabel, commerce.variantSkus),
                    renderDebugList(T.variantOptionsLabel, commerce.variantOptions),
                    parentProduct ? `<div class="sm-test-indexed-row">
                        <span class="sm-test-indexed-label">${T.parentProductLabel}</span>
                        <span class="sm-test-indexed-value"><strong>${escapeDisplay(truncateDisplay(parentProduct.title || '-', 72))}</strong>${parentProduct.slug ? ` <code>${escapeDisplay(truncateDisplay(parentProduct.slug, 56))}</code>` : ''}${parentUrl ? ` <a class="sm-test-parent-link" href="${parentUrl}" target="_blank">${parentUrlText}</a>` : ''}</span>
                    </div>` : '',
                ].filter(Boolean);

                if (customFields.length > 0) {
                    rows.push(`<div class="sm-test-indexed-row">
                        <span class="sm-test-indexed-label">${T.customFieldsLabel}</span>
                        <div class="sm-test-indexed-custom-fields">${customFields.map(field => `<code class="sm-test-indexed-custom-field">${escapeDisplay(truncateDisplay(field.label, 32))}: ${escapeDisplay(truncateDisplay(field.value, 96))}</code>`).join('')}</div>
                    </div>`);
                }

                if (rows.length === 0) {
                    return '';
                }

                return `<details class="sm-test-indexed-debug">
                    <summary>${T.indexedDocumentLabel}</summary>
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

                Promise.all([
                    postJson(urls.testSearch, csrfToken, Object.assign({
                        query: query,
                        indexHandle: indexHandle,
                        wildcard: enableWildcard,
                        enrich: enableEnrich.checked,
                    }, enableEnrich.checked ? {
                        snippetMode: document.getElementById('snippetMode').value,
                        snippetLength: parseInt(document.getElementById('snippetLength').value, 10) || 200,
                        showCodeSnippets: document.getElementById('showCodeSnippets').checked,
                        parseMarkdownSnippets: document.getElementById('parseMarkdownSnippets').checked,
                        hideResultsWithoutUrl: document.getElementById('hideResultsWithoutUrl').checked,
                        includeDebugMeta: document.getElementById('includeDebugMeta').checked,
                    } : {})).then(r => r.json()),
                    showPromotions.checked ? postJson(urls.testPromotions, csrfToken, { query: query, indexHandle: indexHandle }).then(r => r.json()) : Promise.resolve(null),
                    showQueryRules.checked ? postJson(urls.testQueryRules, csrfToken, { query: query, indexHandle: indexHandle }).then(r => r.json()) : Promise.resolve(null),
                ])
                    .then(([searchData, promotionsData, queryRulesData]) => {
                        testButton.disabled = false;
                        testButton.textContent = T.searchLabel;

                        if (promotionsData && showPromotions.checked) {
                            displayPromotions(promotionsData, query);
                        }
                        if (queryRulesData && showQueryRules.checked) {
                            displayQueryRules(queryRulesData, query);
                        }
                        displaySearchResults(searchData, query);
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

            function displayPromotions(data, query) {
                const container = document.getElementById('promotions-results');

                if (data.success && data.promotions && data.promotions.length > 0) {
                    container.innerHTML = `
                <div class="sm-test-diagnostic-summary">
                    <span><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code></span>
                    <span><strong>${T.matchedLabel}</strong> ${(data.promotions.length === 1 ? T.promotionsSingular : T.promotionsPlural).replace('{count}', data.promotions.length)}</span>
                </div>
                <table class="data fullwidth sm-test-table">
                    <thead>
                        <tr>
                            <th>${T.position}</th>
                            <th>${T.element}</th>
                            <th>${T.matchType}</th>
                            <th>${T.pattern}</th>
                            <th>${T.liveOnSites}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.promotions.map(p => `
                            <tr${!p.enabled ? ' class="sm-test-row-disabled"' : ''}>
                                <td><span class="sm-test-position">#${p.position}</span></td>
                                <td>
                                    <a href="${p.elementEditUrl}" target="_blank">${Craft.escapeHtml(p.elementTitle)}</a>
                                    <span class="sm-test-table-subtext">ID: ${p.elementId}</span>
                                    ${!p.enabled ? `<span class="sm-test-disabled-badge">${T.disabled}</span>` : ''}
                                </td>
                                <td><code>${Craft.escapeHtml(p.matchType)}</code></td>
                                <td><code>${Craft.escapeHtml(p.query)}</code></td>
                                <td class="sm-test-site-list">
                                    ${p.siteStatuses ? p.siteStatuses.map(s => `
                                        <span class="${s.isLive ? 'sm-test-live-badge' : 'sm-test-not-live-badge'}">${Craft.escapeHtml(s.siteName)}</span>
                                    `).join('') : '-'}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                <p class="sm-test-promo-note">${T.promotionsNote}</p>
            `;
                } else {
                    container.innerHTML = `<p class="light">${T.noPromotions.replace('{query}', Craft.escapeHtml(query))}</p>`;
                }
            }

            function displayQueryRules(data, query) {
                const container = document.getElementById('queryrules-results');

                if (data.success && data.rules && data.rules.length > 0) {
                    const actionClasses = {
                        'synonym': 'blue',
                        'boost_section': 'green',
                        'boost_category': 'teal',
                        'boost_element': 'lime',
                        'filter': 'orange',
                        'redirect': 'red',
                    };

                    let redirectHtml = '';
                    if (data.redirect) {
                        const redirectRule = data.rules.find(r => r.actionType === 'redirect');
                        if (redirectRule && redirectRule.elementInfo) {
                            const el = redirectRule.elementInfo;
                            const elementUrl = Craft.escapeHtml(el.url || '');
                            redirectHtml = `
                        <div class="sm-test-redirect-box">
                            <div class="sm-test-redirect-heading">&rarr; ${T.redirectLabel}</div>
                            <div class="sm-test-redirect-details">
                                <div><strong>Target:</strong> <strong>${Craft.escapeHtml(el.type)}</strong> <a href="${el.cpEditUrl}" target="_blank">${Craft.escapeHtml(el.title)}</a> <span class="sm-test-muted">ID: ${el.id}</span></div>
                                <div class="sm-test-redirect-url"><strong>URL:</strong> <a href="${elementUrl}" target="_blank">${elementUrl}</a></div>
                            </div>
                        </div>`;
                        } else {
                            const redirectUrl = Craft.escapeHtml(String(data.redirect || ''));
                            redirectHtml = `<div class="sm-test-redirect-url-box">
                                <div class="sm-test-redirect-heading">&rarr; ${T.redirectLabel}</div>
                                <div class="sm-test-redirect-details">
                                    <div class="sm-test-redirect-url"><strong>URL:</strong> <a href="${redirectUrl}" target="_blank">${redirectUrl}</a></div>
                                </div>
                            </div>`;
                        }
                    }

                    container.innerHTML = `
                <div class="sm-test-diagnostic-summary">
                    <span><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code></span>
                    <span><strong>${T.matchedLabel}</strong> ${(data.rules.length === 1 ? T.rulesSingular : T.rulesPlural).replace('{count}', data.rules.length)}</span>
                </div>
                ${redirectHtml}
                ${data.synonyms && data.synonyms.length > 1 ? `<div class="sm-test-synonyms-box"><strong>${T.expandedQueriesLabel}</strong> ${data.synonyms.map(s => `<code>${Craft.escapeHtml(s)}</code>`).join(', ')}</div>` : ''}
                <table class="data fullwidth sm-test-table">
                    <thead>
                        <tr>
                            <th>${T.ruleName}</th>
                            <th>${T.action}</th>
                            <th>${T.match}</th>
                            <th>${T.effect}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.rules.map(r => {
                            let effectHtml = Craft.escapeHtml(r.effectDescription);
                            if (r.actionType === 'redirect' && r.elementInfo) {
                                effectHtml = T.redirectToElement.replace('{link}', `<a href="${r.elementInfo.cpEditUrl}" target="_blank">${Craft.escapeHtml(r.elementInfo.title)}</a>`);
                            }
                            const actionLabel = T.actionLabels[r.actionType] || Craft.escapeHtml(r.actionType);
                            const actionClass = actionClasses[r.actionType] || 'gray';
                            return `
                                <tr>
                                    <td><a href="${r.editUrl}" target="_blank">${Craft.escapeHtml(r.name)}</a></td>
                                    <td>${renderStatusLabel(actionLabel, actionClass)}</td>
                                    <td><code>${Craft.escapeHtml(r.matchType)}</code>: <code>${Craft.escapeHtml(r.matchValue)}</code></td>
                                    <td>${effectHtml}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
                } else {
                    container.innerHTML = `<p class="light">${T.noQueryRules.replace('{query}', Craft.escapeHtml(query))}</p>`;
                }
            }

            function displaySearchResults(data, query) {
                lastSearchData = data;
                lastSearchQuery = query;
                testResults.hidden = false;

                if (data.success) {
                    resultsTitle.innerHTML = (data.total === 1 ? T.foundResultsSingular : T.foundResultsPlural).replace('{count}', data.total);
                    setMessageState(resultsTitle, 'success');

                    let html = `
<div class="sm-test-summary">
    <div class="sm-test-summary-grid">
        <div><strong>${T.backendLabel}</strong> ${Craft.escapeHtml(data.backend)}</div>
        <div><strong>${T.executionLabel}</strong> ${data.executionTime}ms</div>
        <div><strong>${T.cacheLabel}</strong> ${data.cacheEnabled ? (data.cacheHit ? T.hit : T.miss) : T.disabled}${data.cacheDriver ? ' (' + data.cacheDriver + ')' : ''}</div>
        <div><strong>${T.queryUsedLabel}</strong> <code>${Craft.escapeHtml(typeof data.queryUsed === 'string' ? data.queryUsed : query)}</code></div>
        <div><strong>${T.modeLabel}</strong> ${data.enriched ? T.enriched : T.raw}</div>
    </div>
</div>
`;

                    if (data.total > 0) {
                        html += '<div class="sm-test-results-grid">';
                        data.hits.forEach(hit => {
                            const rawTitle = hit.title || T.untitled;
                            const rawDescription = hit.description || '';
                            const rawExcerpt = hit.excerpt || hit.content || '';
                            const titleTerms = getHitTerms(hit, 'title');
                            const descTerms = getHitTerms(hit, 'description');
                            const title = smHighlight(rawTitle, query, titleTerms);
                            const rawDisplayText = rawDescription || rawExcerpt;
                            const displayText = rawDisplayText ? smHighlight(rawDisplayText.substring(0, 400), query, descTerms) : '';
                            const url = safeUrlAttribute(hit.url);
                            const urlText = hit.url ? escapeDisplay(hit.url) : '';
                            const isPromoted = hit.promoted === true;
                            const isBoosted = hit.boosted === true;
                            const matchedIn = hit.matchedIn && hit.matchedIn.length > 0 ? hit.matchedIn.map(escapeDisplay).join(', ') : null;
                            const indexHandle = hit._index ? escapeDisplay(hit._index) : null;
                            const objectId = hit.objectID || hit.id;
                            const objectIdDisplay = objectId ? escapeDisplay(objectId) : '';
                            const type = escapeDisplay(hit.type || T.entry);
                            const section = hit.section ? escapeDisplay(hit.section) : '';
                            const siteName = escapeDisplay(hit.siteName || T.unknown);
                            const language = escapeDisplay(hit.language || '??');
                            const thumbnail = safeUrlAttribute(hit.thumbnail);
                            const matchedHeadings = hit._matchedHeadings || [];
                            const matchedTerms = hit.matchedTerms || [];
                            const matchedPhrases = hit.matchedPhrases || [];
                            const score = hit.score !== undefined && hit.score !== null ? Number(hit.score).toFixed(2) : T.naValue;
                            const cardClass = isPromoted ? ' sm-test-result-card--promoted' : (isBoosted ? ' sm-test-result-card--boosted' : '');

                            html += `
<div class="sm-test-result-card${cardClass}">
    <div class="sm-test-result-layout">
        ${thumbnail ? `<img src="${thumbnail}" class="sm-test-thumb" alt="">` : ''}
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
                <span class="sm-test-meta-item"><span class="sm-test-meta-label">ID</span> #${objectIdDisplay}</span>
                <span class="sm-test-meta-item"><span class="sm-test-meta-label">${T.typeLabel}</span> ${type}</span>
                ${section ? `<span class="sm-test-meta-item"><span class="sm-test-meta-label">${T.sectionLabel}</span> ${section}</span>` : ''}
                ${indexHandle ? `<span class="sm-test-meta-item"><span class="sm-test-meta-label">${T.indexLabel}</span> <code>${indexHandle}</code></span>` : ''}
                <span class="sm-test-site-badge"><span class="sm-test-meta-label">${T.siteLabel}</span> ${siteName} (${language})</span>
            </div>
            ${matchedIn ? `<div class="sm-test-match-line"><strong>${T.matchedInLabel}</strong> <code>${matchedIn}</code></div>` : ''}
            ${displayText ? `<div class="sm-test-description">${displayText}${rawDisplayText.length > 400 ? '...' : ''}</div>` : ''}
            ${matchedHeadings.length > 0 ? `<div class="sm-test-headings">
                <div class="sm-test-headings-title">${T.matchedHeadings}</div>
                ${matchedHeadings.map(h => `<div class="sm-test-heading-row"><span class="sm-test-heading-tag">${Craft.escapeHtml(h.tag || 'h2')}</span>${Craft.escapeHtml(h.text)}</div>`).join('')}
            </div>` : ''}
            ${matchedTerms.length > 0 || matchedPhrases.length > 0 ? `<div class="sm-test-terms">
                ${matchedTerms.length > 0 ? `<strong>${T.termsLabel}</strong> ${matchedTerms.map(t => '<code class="sm-test-term">' + Craft.escapeHtml(t) + '</code>').join(' ')}` : ''}
                ${matchedPhrases.length > 0 ? `${matchedTerms.length > 0 ? ' &bull; ' : ''}<strong>${T.phrasesLabel}</strong> ${matchedPhrases.map(p => '<code class="sm-test-phrase">' + Craft.escapeHtml(p) + '</code>').join(' ')}` : ''}
            </div>` : ''}
            ${hit._snippet ? `<div class="sm-test-debug-strip">
                <span><span class="sm-test-debug-label">${T.snippetMatchedIn}</span> <strong class="sm-test-debug-value">${Craft.escapeHtml(hit._snippet.snippetSource || '-')}</strong></span>
                <span><span class="sm-test-debug-label">${T.snippetMode}</span> <strong class="sm-test-debug-value">${Craft.escapeHtml(hit._snippet.snippetMode || '-')}</strong></span>
                <span><span class="sm-test-debug-label">${T.snippetFrom}</span> <strong class="sm-test-debug-value">${Craft.escapeHtml(hit._snippet.snippetFrom || '-')}</strong></span>
                ${hit._snippet.fullContentLength ? `<span><span class="sm-test-debug-label">${T.snippetContent}</span> <strong class="sm-test-debug-value">${(hit._snippet.fullContentLength === 1 ? T.charsSingular : T.charsPlural).replace('{count}', hit._snippet.fullContentLength.toLocaleString())}</strong></span>` : ''}
            </div>` : ''}
            ${renderIndexedDocumentDebug(hit)}
        </div>
    </div>
</div>
`;
                        });
                        html += '</div>';
                    } else {
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
