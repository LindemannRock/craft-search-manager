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

            function updateSectionVisibility() {
                autocompleteSection.style.display = showAutocomplete.checked ? 'block' : 'none';
                promotionsSection.style.display = showPromotions.checked ? 'block' : 'none';
                queryrulesSection.style.display = showQueryRules.checked ? 'block' : 'none';
            }

            showAutocomplete.addEventListener('change', updateSectionVisibility);
            showPromotions.addEventListener('change', updateSectionVisibility);
            showQueryRules.addEventListener('change', updateSectionVisibility);

            function updateEnrichPanel() {
                enrichOptionsPanel.style.display = enableEnrich.checked ? 'block' : 'none';
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
                                cacheStatus.style.color = '#059669';
                                setTimeout(() => { cacheStatus.textContent = ''; }, 3000);
                            } else {
                                cacheStatus.textContent = data.error || T.failedClearCache;
                                cacheStatus.style.color = '#dc2626';
                            }
                        })
                        .catch(error => {
                            clearCacheButton.disabled = false;
                            clearCacheButton.textContent = T.clearSearchCache;
                            cacheStatus.textContent = error.message;
                            cacheStatus.style.color = '#dc2626';
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
                                cacheStatus.style.color = '#059669';
                                setTimeout(() => { cacheStatus.textContent = ''; }, 3000);
                            } else {
                                cacheStatus.textContent = data.error || T.failedClearAutocompleteCache;
                                cacheStatus.style.color = '#dc2626';
                            }
                        })
                        .catch(error => {
                            clearAutocompleteCacheButton.disabled = false;
                            clearAutocompleteCacheButton.textContent = T.clearAutocompleteCache;
                            cacheStatus.textContent = error.message;
                            cacheStatus.style.color = '#dc2626';
                        });
                });
            }

            testQueryInput.addEventListener('input', function() {
                this.style.boxShadow = '';
                const query = this.value.trim();

                if (!showAutocomplete.checked || query.length < autocompleteMinLength) {
                    autocompleteTerms = [];
                    autocompleteDropdown.style.display = 'none';
                    return;
                }

                clearTimeout(autocompleteTimer);
                autocompleteTimer = setTimeout(() => {
                    fetchAutocomplete(query);
                }, 200);
            });

            testQueryInput.addEventListener('blur', function() {
                setTimeout(() => { autocompleteDropdown.style.display = 'none'; }, 200);
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
                autocompleteDropdown.style.display = 'none';
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
                    <div style="padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background 0.1s;"
                         onmouseover="this.style.background='#f9fafb'"
                         onmouseout="this.style.background='#fff'"
                         data-autocomplete-index="${index}">
                        <code style="font-size: 13px;">${highlightMatch(term, query)}</code>
                    </div>
                `).join('');
                        autocompleteDropdown.style.display = 'block';
                        updateAutocompleteSection(suggestions, query, meta);
                    } else {
                        autocompleteTerms = [];
                        autocompleteDropdown.style.display = 'none';
                        updateAutocompleteSection([], query, meta);
                    }
                } catch (error) {
                    autocompleteTerms = [];
                    autocompleteDropdown.style.display = 'none';
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

            function updateAutocompleteSection(suggestions, query, meta) {
                const container = document.getElementById('autocomplete-results');
                const cacheLabel = meta && meta.cacheEnabled
                    ? (meta.cached ? T.hit : T.miss)
                    : T.disabled;

                if (suggestions.length > 0) {
                    container.innerHTML = `
                <p style="margin-bottom: 12px;">
                    <strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code> |
                    <strong>${T.foundLabel}</strong> ${(suggestions.length === 1 ? T.suggestionsSingular : T.suggestionsPlural).replace('{count}', suggestions.length)} |
                    <strong>${T.cacheLabel}</strong> ${cacheLabel}${meta && meta.cacheDriver ? ' (' + meta.cacheDriver + ')' : ''}
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    ${suggestions.map(s => `<span style="background: #f3f4f6; padding: 6px 12px; border-radius: 16px; font-size: 13px;">${Craft.escapeHtml(s)}</span>`).join('')}
                </div>
            `;
                } else {
                    container.innerHTML = `
                <p class="light">${T.noSuggestions.replace('{query}', Craft.escapeHtml(query))}</p>
                <p class="light" style="margin-top: 8px;"><strong>${T.cacheLabel}</strong> ${cacheLabel}${meta && meta.cacheDriver ? ' (' + meta.cacheDriver + ')' : ''}</p>
            `;
                }
            }

            testButton.addEventListener('click', function() {
                const query = testQueryInput.value.trim();
                const indexHandle = testIndexSelect.value;
                const enableWildcard = document.getElementById('enableWildcard').checked;

                if (!query) {
                    testQueryInput.style.boxShadow = 'inset 0 0 0 1px #dc2626';
                    testQueryInput.focus();
                    testResults.style.display = 'none';
                    return;
                }

                testQueryInput.style.boxShadow = '';
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
                        testResults.style.display = 'block';
                        resultsTitle.innerHTML = T.error;
                        resultsTitle.style.color = '#dc2626';
                        resultsContent.innerHTML = `<p style="color: #dc2626;">${Craft.escapeHtml(error.message)}</p>`;
                    });
            });

            function displayPromotions(data, query) {
                const container = document.getElementById('promotions-results');

                if (data.success && data.promotions && data.promotions.length > 0) {
                    container.innerHTML = `
                <p style="margin-bottom: 12px;"><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code> | <strong>${T.matchedLabel}</strong> ${(data.promotions.length === 1 ? T.promotionsSingular : T.promotionsPlural).replace('{count}', data.promotions.length)}</p>
                <table class="data fullwidth" style="margin: 0;">
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
                            <tr${!p.enabled ? ' style="opacity: 0.5;"' : ''}>
                                <td><span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-weight: 600;">#${p.position}</span></td>
                                <td>
                                    <a href="${p.elementEditUrl}" target="_blank">${Craft.escapeHtml(p.elementTitle)}</a>
                                    <span style="color: #9ca3af;">(ID: ${p.elementId})</span>
                                    ${!p.enabled ? `<span style="background: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 6px; text-transform: uppercase;">${T.disabled}</span>` : ''}
                                </td>
                                <td><code>${Craft.escapeHtml(p.matchType)}</code></td>
                                <td><code>${Craft.escapeHtml(p.query)}</code></td>
                                <td>
                                    ${p.siteStatuses ? p.siteStatuses.map(s => `
                                        <span style="background: ${s.isLive ? '#dcfce7' : '#fee2e2'}; color: ${s.isLive ? '#166534' : '#dc2626'}; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-right: 4px;">${Craft.escapeHtml(s.siteName)}</span>
                                    `).join('') : '-'}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                <p class="light" style="margin-top: 12px; font-size: 12px;">${T.promotionsNote}</p>
            `;
                } else {
                    container.innerHTML = `<p class="light">${T.noPromotions.replace('{query}', Craft.escapeHtml(query))}</p>`;
                }
            }

            function displayQueryRules(data, query) {
                const container = document.getElementById('queryrules-results');

                if (data.success && data.rules && data.rules.length > 0) {
                    const actionColors = {
                        'synonym': '#dbeafe',
                        'boost_section': '#dcfce7',
                        'boost_category': '#dcfce7',
                        'boost_element': '#dcfce7',
                        'filter': '#fef3c7',
                        'redirect': '#fee2e2',
                    };

                    let redirectHtml = '';
                    if (data.redirect) {
                        const redirectRule = data.rules.find(r => r.actionType === 'redirect');
                        if (redirectRule && redirectRule.elementInfo) {
                            const el = redirectRule.elementInfo;
                            const elementUrl = Craft.escapeHtml(el.url || '');
                            redirectHtml = `
                        <div style="padding: 12px; background: #fee2e2; border-radius: 4px; margin-bottom: 12px;">
                            <strong>&rarr; ${T.redirectLabel}</strong> ${T.redirectsTo}
                            <strong>${Craft.escapeHtml(el.type)}</strong>:
                            <a href="${el.cpEditUrl}" target="_blank" style="font-weight: 600;">${Craft.escapeHtml(el.title)}</a>
                            <span style="color: #9ca3af;">(ID: ${el.id})</span>
                            <br><span style="color: #6b7280; font-size: 13px;">URL: <a href="${elementUrl}" target="_blank">${elementUrl}</a></span>
                        </div>`;
                        } else {
                            const redirectUrl = Craft.escapeHtml(String(data.redirect || ''));
                            redirectHtml = `<div style="padding: 12px; background: #fee2e2; border-radius: 4px; margin-bottom: 12px;"><strong>&rarr; ${T.redirectLabel}</strong> ${T.redirectsTo} <a href="${redirectUrl}" target="_blank">${redirectUrl}</a></div>`;
                        }
                    }

                    container.innerHTML = `
                <p style="margin-bottom: 12px;"><strong>${T.queryLabel}</strong> <code>${Craft.escapeHtml(query)}</code> | <strong>${T.matchedLabel}</strong> ${(data.rules.length === 1 ? T.rulesSingular : T.rulesPlural).replace('{count}', data.rules.length)}</p>
                ${redirectHtml}
                ${data.synonyms && data.synonyms.length > 0 ? `<div style="padding: 12px; background: #dbeafe; border-radius: 4px; margin-bottom: 12px;"><strong>${T.expandedQueriesLabel}</strong> ${data.synonyms.map(s => `<code>${Craft.escapeHtml(s)}</code>`).join(', ')}</div>` : ''}
                <table class="data fullwidth" style="margin: 0;">
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
                            return `
                                <tr>
                                    <td><a href="${r.editUrl}" target="_blank">${Craft.escapeHtml(r.name)}</a></td>
                                    <td><span style="background: ${actionColors[r.actionType] || '#f3f4f6'}; padding: 2px 8px; border-radius: 4px; font-size: 12px;">${actionLabel}</span></td>
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
                testResults.style.display = 'block';

                if (data.success) {
                    resultsTitle.innerHTML = (data.total === 1 ? T.foundResultsSingular : T.foundResultsPlural).replace('{count}', data.total);
                    resultsTitle.style.color = '#059669';

                    let html = `
<div style="padding: 16px; background: #f0fdf4; border-left: 4px solid #059669; margin-bottom: 20px; border-radius: 0 4px 4px 0;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 14px;">
        <div><strong>${T.backendLabel}</strong> ${Craft.escapeHtml(data.backend)}</div>
        <div><strong>${T.executionLabel}</strong> ${data.executionTime}ms</div>
        <div><strong>${T.cacheLabel}</strong> ${data.cacheEnabled ? (data.cacheHit ? T.hit : T.miss) : T.disabled}${data.cacheDriver ? ' (' + data.cacheDriver + ')' : ''}</div>
        <div><strong>${T.queryUsedLabel}</strong> <code>${Craft.escapeHtml(typeof data.queryUsed === 'string' ? data.queryUsed : query)}</code></div>
        <div><strong>${T.modeLabel}</strong> ${data.enriched ? `<span style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 3px;">${T.enriched}</span>` : `<span style="background: #f3f4f6; color: #6b7280; padding: 2px 6px; border-radius: 3px;">${T.raw}</span>`}</div>
    </div>
</div>
`;

                    if (data.total > 0) {
                        html += '<div style="display: grid; gap: 12px;">';
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

                            html += `
<div style="padding: 16px; border: 1px solid ${isPromoted ? '#fbbf24' : (isBoosted ? '#34d399' : '#e5e7eb')}; border-radius: 4px; background: ${isPromoted ? '#fffbeb' : (isBoosted ? '#ecfdf5' : '#fff')};">
    <div style="display: flex; gap: 12px; align-items: start;">
        ${thumbnail ? `<img src="${thumbnail}" style="width: 48px; height: 48px; border-radius: 6px; object-fit: cover; flex-shrink: 0; border: 1px solid #e5e7eb;" alt="">` : ''}
        <div style="flex: 1; min-width: 0;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                <div style="flex: 1;">
                    ${isPromoted ? `<span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-right: 8px; text-transform: uppercase;">${T.promoted}</span>` : ''}
                    ${isBoosted ? `<span style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-right: 8px; text-transform: uppercase;">${T.boosted}</span>` : ''}
                    <strong style="color: #111827; font-size: 15px;">${title}</strong>
                    ${url ? `<div style="font-size: 12px; color: #6b7280; margin-top: 4px;"><a href="${url}" target="_blank" style="color: #0d78f2;">${urlText}</a></div>` : ''}
                </div>
                <div style="display: flex; gap: 8px; margin-left: 12px;">
                    <span style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                        ${T.scoreLabel} ${score}
                    </span>
                </div>
            </div>
            <div style="font-size: 12px; color: #9ca3af; margin-bottom: 8px;">
                ID: #${objectIdDisplay} &bull; ${T.typeLabel} ${type}${section ? ' &bull; ' + T.sectionLabel + ' ' + section : ''}${indexHandle ? ' &bull; ' + T.indexLabel + ' <code>' + indexHandle + '</code>' : ''} &bull; <span style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 3px; font-size: 11px;">${T.siteLabel} ${siteName} (${language})</span>
            </div>
            ${matchedIn ? `<div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;"><strong>${T.matchedInLabel}</strong> <code>${matchedIn}</code></div>` : ''}
            ${displayText ? `<div style="color: #4b5563; line-height: 1.6; font-size: 14px;">${displayText}${rawDisplayText.length > 400 ? '...' : ''}</div>` : ''}
            ${matchedHeadings.length > 0 ? `<div style="margin-top: 8px; padding: 8px 12px; background: #f8fafc; border-radius: 4px; border-left: 3px solid #6366f1;">
                <div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">${T.matchedHeadings}</div>
                ${matchedHeadings.map(h => `<div style="font-size: 13px; color: #374151; padding: 2px 0;"><span style="color: #a5b4fc; font-size: 10px; font-weight: 600; margin-right: 6px;">${Craft.escapeHtml(h.tag || 'h2')}</span>${Craft.escapeHtml(h.text)}</div>`).join('')}
            </div>` : ''}
            ${matchedTerms.length > 0 || matchedPhrases.length > 0 ? `<div style="font-size: 12px; color: #6b7280; margin-top: 6px;">
                ${matchedTerms.length > 0 ? `<strong>${T.termsLabel}</strong> ${matchedTerms.map(t => '<code style="background: #fef3c7; padding: 1px 4px; border-radius: 2px;">' + Craft.escapeHtml(t) + '</code>').join(' ')}` : ''}
                ${matchedPhrases.length > 0 ? `${matchedTerms.length > 0 ? ' &bull; ' : ''}<strong>${T.phrasesLabel}</strong> ${matchedPhrases.map(p => '<code style="background: #dbeafe; padding: 1px 4px; border-radius: 2px;">' + Craft.escapeHtml(p) + '</code>').join(' ')}` : ''}
            </div>` : ''}
            ${hit._snippet ? `<div style="margin-top: 8px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; color: #64748b; display: flex; gap: 16px; flex-wrap: wrap;">
                <span><span style="color: #94a3b8;">${T.snippetMatchedIn}</span> <strong>${Craft.escapeHtml(hit._snippet.snippetSource || '-')}</strong></span>
                <span><span style="color: #94a3b8;">${T.snippetMode}</span> <strong>${Craft.escapeHtml(hit._snippet.snippetMode || '-')}</strong></span>
                <span><span style="color: #94a3b8;">${T.snippetFrom}</span> <strong>${Craft.escapeHtml(hit._snippet.snippetFrom || '-')}</strong></span>
                ${hit._snippet.fullContentLength ? `<span><span style="color: #94a3b8;">${T.snippetContent}</span> <strong>${(hit._snippet.fullContentLength === 1 ? T.charsSingular : T.charsPlural).replace('{count}', hit._snippet.fullContentLength.toLocaleString())}</strong></span>` : ''}
            </div>` : ''}
        </div>
    </div>
</div>
`;
                        });
                        html += '</div>';
                    } else {
                        html += `
<div style="text-align: center; padding: 40px; color: #9ca3af;">
    <p style="font-size: 18px; margin: 0;">${T.noResults.replace('{query}', Craft.escapeHtml(query))}</p>
    <p style="font-size: 14px; margin: 8px 0 0;">${T.tryDifferent}</p>
</div>
`;
                    }

                    resultsContent.innerHTML = html;
                } else {
                    resultsTitle.innerHTML = T.searchFailed;
                    resultsTitle.style.color = '#dc2626';
                    resultsContent.innerHTML = `<p style="color: #dc2626;">${Craft.escapeHtml(data.error || T.unknownError)}</p>`;
                }
            }

            testQueryInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    autocompleteDropdown.style.display = 'none';
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
