(function(window) {
    'use strict';

    window.lrSearchAnalyticsInit = function(initConfig) {
        const config = initConfig || {};

        if (window.lrSearchAnalyticsBound) {
            if (window.lrAnalyticsInit) {
                window.lrAnalyticsInit(config);
            }
            return;
        }
        window.lrSearchAnalyticsBound = true;

        if (window.lrAnalyticsInit) {
            window.lrAnalyticsInit(config);
        }

    function init() {
        var $ = (window.Craft && Craft.$) || window.jQuery || window.$;
        if (!$) {
            console.error('Search analytics requires jQuery.');
            return;
        }

        const strings = config.strings || {};
        const endpoints = config.endpoints || {};
        const dataEndpoint = (window.Craft && Craft.getActionUrl && endpoints.data) ? Craft.getActionUrl(endpoints.data) : endpoints.data;
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        // Global Chart Instances
        window.smCharts = window.smCharts || {};
        let currentDateRange = config.dateRange || 'last7days';
        let currentSiteId = config.siteId || '';
        let currentContent = null;
        let requestGeneration = 0;
        let requestSequence = 0;
        const requestStates = new Map();
        const successfulConsumers = new Set();
        const requestFamilies = {
            overview: {
                flag: 'overviewLoaded',
                members: ['overview-trend', 'overview-query-analysis', 'overview-intent', 'overview-source', 'overview-peak-hours', 'overview-trending'],
            },
            searches: { flag: 'recentSearchesLoaded', members: ['searches-recent'] },
            'content-gaps': { flag: 'contentGapsRecentLoaded', members: ['content-gaps-clusters', 'content-gaps-recent'] },
            performance: { flag: 'performanceLoaded', members: ['performance-cache', 'performance-trend', 'performance-top', 'performance-worst'] },
            'traffic-devices': { flag: 'trafficDevicesLoaded', members: ['traffic-hourly', 'traffic-devices', 'traffic-agents'] },
            geographic: { flag: 'geographicLoaded', members: ['geographic-countries', 'geographic-cities'] },
            'query-rules': { flag: 'queryRulesLoaded', members: ['query-rules-top', 'query-rules-types', 'query-rules-queries'] },
            promotions: { flag: 'promotionsLoaded', members: ['promotions-top', 'promotions-positions', 'promotions-queries'] },
        };

        function resetRequestGeneration() {
            requestGeneration += 1;
            requestStates.clear();
            successfulConsumers.clear();
            Object.values(requestFamilies).forEach(family => {
                window[family.flag] = false;
            });
            document.querySelectorAll('.lr-analytics-request-error').forEach(el => el.remove());
            document.querySelectorAll('.lr-analytics-request-errors').forEach(el => el.remove());
        }

        function updateFamilyLoaded(familyName) {
            const family = requestFamilies[familyName];
            if (!family) return;
            window[family.flag] = family.members.every(consumer => successfulConsumers.has(consumer));
        }

        function getRequestTarget(selector) {
            if (typeof selector !== 'string') return null;
            if (selector.charAt(0) === '#' && !selector.includes(' ') && !selector.includes(',')) {
                return document.getElementById(selector.substring(1));
            }
            return document.querySelector(selector);
        }

        function setTargetsBusy(selectors, busy) {
            (selectors || []).forEach(selector => {
                const target = getRequestTarget(selector);
                if (!target) return;
                if (busy) {
                    target.setAttribute('aria-busy', 'true');
                } else {
                    target.removeAttribute('aria-busy');
                }
            });
        }

        function getErrorHost(tabId) {
            const tab = document.getElementById(tabId);
            if (!tab) return null;
            let host = Array.from(tab.children).find(child => child.classList && child.classList.contains('lr-analytics-request-errors'));
            if (!host) {
                host = document.createElement('div');
                host.className = 'lr-analytics-request-errors';
                tab.prepend(host);
            }
            return host;
        }

        function getConsumerError(host, consumer) {
            return Array.from(host.children).find(child => child.dataset && child.dataset.analyticsRequestError === consumer) || null;
        }

        function removeRequestError(consumer, tabId) {
            const tab = document.getElementById(tabId);
            if (!tab) return;
            const host = Array.from(tab.children).find(child => child.classList && child.classList.contains('lr-analytics-request-errors'));
            if (!host) return;
            const error = getConsumerError(host, consumer);
            if (error) error.remove();
            if (host.children.length === 0) host.remove();
        }

        function presentRequestError(options, retry) {
            const host = getErrorHost(options.tab);
            if (!host) return;
            let error = getConsumerError(host, options.consumer);
            if (!error) {
                error = document.createElement('div');
                error.className = 'lr-info-box lr-info-box--error lr-info-box--subtle lr-info-box--margin-bottom lr-analytics-request-error';
                error.dataset.analyticsRequestError = options.consumer;
                error.setAttribute('role', 'alert');
                error.setAttribute('aria-live', 'assertive');

                const inner = document.createElement('div');
                inner.className = 'lr-info-box__inner';
                const message = document.createElement('span');
                message.className = 'lr-info-box__message';
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn small';
                button.addEventListener('click', function() {
                    if (typeof error.retryRequest === 'function') {
                        error.retryRequest();
                    }
                });
                inner.appendChild(message);
                inner.appendChild(button);
                error.appendChild(inner);
                host.appendChild(error);
            }

            const message = error.querySelector('.lr-info-box__message');
            const button = error.querySelector('button');
            message.textContent = strings.analyticsLoadError || '';
            button.textContent = strings.retry || '';
            button.setAttribute('aria-label', strings.retry || '');
            button.disabled = false;
            button.classList.remove('loading');
            error.retryRequest = retry;
        }

        function setRequestRetrying(consumer, tabId) {
            const tab = document.getElementById(tabId);
            if (!tab) return;
            const host = Array.from(tab.children).find(child => child.classList && child.classList.contains('lr-analytics-request-errors'));
            const error = host ? getConsumerError(host, consumer) : null;
            if (!error) return;
            const message = error.querySelector('.lr-info-box__message');
            const button = error.querySelector('button');
            message.textContent = strings.loading || '';
            button.disabled = true;
            button.classList.add('loading');
        }

        function clearChart(canvasId) {
            destroyChartByCanvasId(canvasId);
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const parent = canvas.parentElement || canvas.parentNode;
            if (parent) {
                parent.querySelectorAll('.zilch').forEach(el => el.remove());
            }
            canvas.style.display = 'none';
        }

        function analyticsRequest(options, force) {
            const existing = requestStates.get(options.consumer);
            if (!force && existing && existing.generation === requestGeneration && (existing.inFlight || successfulConsumers.has(options.consumer))) {
                return existing.request || null;
            }

            const generation = requestGeneration;
            const sequence = ++requestSequence;
            const state = { generation: generation, sequence: sequence, inFlight: true, request: null };
            requestStates.set(options.consumer, state);
            successfulConsumers.delete(options.consumer);
            updateFamilyLoaded(options.family);
            setTargetsBusy(options.targets, true);
            setRequestRetrying(options.consumer, options.tab);

            function isCurrent() {
                const active = requestStates.get(options.consumer);
                return generation === requestGeneration && active && active.sequence === sequence;
            }

            function fail() {
                if (!isCurrent()) return;
                state.inFlight = false;
                setTargetsBusy(options.targets, false);
                successfulConsumers.delete(options.consumer);
                updateFamilyLoaded(options.family);
                if (typeof options.onFailure === 'function') {
                    options.onFailure();
                }
                presentRequestError(options, function() {
                    analyticsRequest(Object.assign({}, options, {
                        dateRange: currentDateRange,
                        siteId: currentSiteId,
                    }), true);
                });
            }

            state.request = $.ajax({
                url: dataEndpoint,
                type: 'POST',
                dataType: 'json',
                data: {
                    dateRange: options.dateRange,
                    siteId: options.siteId,
                    type: options.data.type,
                    [csrfName]: csrfToken,
                },
                success: function(res) {
                    if (!isCurrent()) return;
                    if (!res || res.success !== true) {
                        fail();
                        return;
                    }
                    try {
                        options.onSuccess(res.data);
                    } catch (error) {
                        fail();
                        return;
                    }
                    if (!isCurrent()) return;
                    state.inFlight = false;
                    setTargetsBusy(options.targets, false);
                    removeRequestError(options.consumer, options.tab);
                    successfulConsumers.add(options.consumer);
                    updateFamilyLoaded(options.family);
                },
                error: fail,
            });
            return state.request;
        }

        // Map known intent enums to translated labels; capitalize unknown/custom values.
        // Shared by the searches table column and the intent chart legend.
        function intentLabel(value) {
            if (!value) {
                return '—';
            }
            const intentLabels = {
                informational: strings.intentInformational,
                product: strings.intentProduct,
                navigational: strings.intentNavigational,
                question: strings.intentQuestion,
            };
            return intentLabels[value] || (value.charAt(0).toUpperCase() + value.slice(1));
        }

    function getActiveTabId() {
        const hash = window.location.hash ? window.location.hash.substring(1) : '';
        if (hash && document.getElementById(hash)) {
            return hash;
        }
        const activeTabLink = document.querySelector('#tabs a.sel[href^="#"], #tabs a.active[href^="#"], .tabs a.sel[href^="#"], .tabs a.active[href^="#"]');
        if (activeTabLink) {
            const tabId = activeTabLink.getAttribute('href').substring(1);
            if (tabId && document.getElementById(tabId)) {
                return tabId;
            }
        }
        if (window.currentTab && document.getElementById(window.currentTab)) {
            return window.currentTab;
        }
        const visible = document.querySelector('.lr-tab-content:not(.hidden)');
        return visible ? visible.id : 'overview';
    }

    function syncVisibleTab(tabId) {
        if (!tabId || !document.getElementById(tabId)) return;
        document.querySelectorAll('.lr-tab-content').forEach(el => el.classList.add('hidden'));
        document.getElementById(tabId).classList.remove('hidden');
        window.currentTab = tabId;
    }

    function resetChartContainer(ctx) {
        if (!ctx) return;
        ctx.style.display = '';
        const parent = ctx.parentElement || ctx.parentNode;
        if (!parent) return;
        parent.querySelectorAll('.zilch').forEach(el => el.remove());
    }

    function renderEmptyChart(ctx, message) {
        if (!ctx) return;
        resetChartContainer(ctx);
        ctx.style.display = 'none';
        const parent = ctx.parentElement || ctx.parentNode;
        if (!parent) return;
        const empty = document.createElement('div');
        empty.className = 'zilch';
        empty.style.padding = '40px';
        empty.style.textAlign = 'center';
        empty.innerHTML = '<p class="light">' + message + '</p>';
        parent.appendChild(empty);
    }

    function destroyChartByCanvasId(canvasId) {
        if (typeof Chart === 'undefined' || !Chart.getChart) return;
        const existing = Chart.getChart(canvasId);
        if (existing) {
            existing.destroy();
        }
    }

    function loadInitialCharts() {
        Object.values(window.smCharts).forEach(c => c.destroy());
        window.smCharts = {};

        analyticsRequest({
            consumer: 'overview-trend',
            family: 'overview',
            tab: 'overview',
            targets: ['#404-trend-chart'],
            data: { type: 'chart' },
            dateRange: currentDateRange,
            siteId: currentSiteId,
            onFailure: function() { clearChart('404-trend-chart'); },
            onSuccess: function(data) {
                const chartData = data.chartData;
                const ctx = document.getElementById('404-trend-chart');
                if (!ctx) return;
                const hasTrend = Array.isArray(chartData) && chartData.some(d => Number(d.withResults) > 0 || Number(d.zeroResults) > 0);
                if (!chartData || chartData.length === 0 || !hasTrend) {
                    renderEmptyChart(ctx, strings.noTrend);
                    return;
                }
                resetChartContainer(ctx);
                window.smCharts.trend = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.map(d => d.date),
                        datasets: [
                            { label: strings.withHits || '', data: chartData.map(d => d.withResults), borderColor: '#10B981', tension: 0.1 },
                            { label: strings.zeroHits || '', data: chartData.map(d => d.zeroResults), borderColor: '#EF4444', tension: 0.1 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            },
        });

    }

    function handleAnalyticsInit(config) {
        const resolved = config || (window.lrAnalyticsConfig || {});
        const nextDateRange = resolved.dateRange || currentDateRange;
        const nextSiteId = resolved.siteId || '';
        const nextContent = document.getElementById('lr-analytics-content');
        if (requestGeneration > 0 && nextContent === currentContent && nextDateRange === currentDateRange && nextSiteId === currentSiteId) {
            loadTabData(getActiveTabId());
            return;
        }

        currentDateRange = nextDateRange;
        currentSiteId = nextSiteId;
        currentContent = nextContent;
        resetRequestGeneration();

        loadInitialCharts();
        const activeTab = getActiveTabId();
        syncVisibleTab(activeTab);
        loadTabData(activeTab);
    }

    document.addEventListener('lr:analyticsInit', function(e) {
        const config = e.detail && e.detail.config ? e.detail.config : null;
        handleAnalyticsInit(config);
    });

    document.addEventListener('lr:tabChanged', function(e) {
        const tabId = e.detail && e.detail.tabId ? e.detail.tabId : getActiveTabId();
        loadTabData(tabId);
    });

    // AJAX Data Loading for Tabs
    function loadTabData(tabName) {
        const mapping = {
            'overview': 'query-analysis',
            'content-gaps': 'content-gaps'
        };

        if (tabName === 'overview' && window.overviewLoaded) return;
        if (tabName === 'searches' && window.recentSearchesLoaded) return;
        if (tabName === 'content-gaps' && window.contentGapsRecentLoaded) return;
        if (tabName === 'performance' && window.performanceLoaded) return;
        if (tabName === 'traffic-devices' && window.trafficDevicesLoaded) return;
        if (tabName === 'geographic' && window.geographicLoaded) return;
        if (tabName === 'query-rules' && window.queryRulesLoaded) return;
        if (tabName === 'promotions' && window.promotionsLoaded) return;

        if (tabName === 'searches') {
            loadRecentSearchesData(currentDateRange, currentSiteId);
            return;
        }

        if (tabName === 'performance') {
            loadPerformanceData(currentDateRange, currentSiteId);
            return;
        }

        if (tabName === 'traffic-devices') {
            loadTrafficDevicesData(currentDateRange, currentSiteId);
            return;
        }

        if (tabName === 'geographic') {
            loadGeographicData(currentDateRange, currentSiteId);
            return;
        }

        if (tabName === 'query-rules') {
            loadQueryRulesData(currentDateRange, currentSiteId);
            return;
        }

        if (tabName === 'promotions') {
            loadPromotionsData(currentDateRange, currentSiteId);
            return;
        }

        analyticsRequest({
            consumer: tabName === 'overview' ? 'overview-query-analysis' : 'content-gaps-clusters',
            family: tabName,
            tab: tabName,
            targets: tabName === 'overview' ? ['#query-length-chart', '#word-cloud-container'] : ['#content-gaps-body'],
            data: { type: mapping[tabName] },
            dateRange: currentDateRange,
            siteId: currentSiteId,
            onFailure: function() {
                if (tabName === 'overview') {
                    clearChart('query-length-chart');
                    $('#query-length-legend').empty();
                    $('#word-cloud-container').empty();
                } else {
                    $('#content-gaps-body').empty();
                }
            },
            onSuccess: function(data) {
                if (tabName === 'overview') {
                    renderQueryAnalysis(data.queryAnalysis);
                    loadBreakdownCharts(currentDateRange, currentSiteId);
                    loadSearchActivityData(currentDateRange, currentSiteId);
                }
                if (tabName === 'content-gaps') {
                    renderContentGaps(data.contentGaps);
                    loadRecentUnhandledData(currentDateRange, currentSiteId);
                }
            },
        });
    }

    function hasNonZeroValues(values) {
        if (!Array.isArray(values) || values.length === 0) return false;
        return values.some(value => Number(value) > 0);
    }

    function loadTrafficDevicesData(dateRange, siteId) {
        const chartColors = ['#0d78f2', '#27ae60', '#e74c3c', '#f39c12', '#9b59b6'];

        analyticsRequest({
            consumer: 'traffic-hourly', family: 'traffic-devices', tab: 'traffic-devices', targets: ['#hourly-chart'],
            data: { type: 'hourly' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('hourly-chart'); },
            onSuccess: renderHourlyChart,
        });

        analyticsRequest({
            consumer: 'traffic-devices', family: 'traffic-devices', tab: 'traffic-devices',
            targets: ['#bot-chart', '#device-chart', '#browser-chart', '#os-chart'],
            data: { type: 'device-stats' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { ['bot-chart', 'device-chart', 'browser-chart', 'os-chart'].forEach(clearChart); },
            onSuccess: function(data) {
                const stats = data.deviceStats;
                const deviceBreakdown = stats ? stats.deviceBreakdown : null;
                const browserBreakdown = stats ? stats.browserBreakdown : null;
                const osBreakdown = stats ? stats.osBreakdown : null;

                const botStats = stats ? stats.botStats : null;
                const botCtx = document.getElementById('bot-chart');
                const hasBot = botStats && botStats.chart && Array.isArray(botStats.chart.values) &&
                    botStats.chart.values.some(v => Number(v) > 0);
                if (hasBot && botCtx) {
                    resetChartContainer(botCtx);
                    window.smCharts.bot = new Chart(botCtx, {
                        type: 'doughnut',
                        data: {
                            labels: botStats.chart.types ? botStats.chart.types.map(trafficTypeLabel) : botStats.chart.labels,
                            datasets: [{ data: botStats.chart.values, backgroundColor: ['#27ae60', '#e74c3c', '#f39c12'] }]
                        },
                        options: {
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                } else if (botCtx) {
                    renderEmptyChart(botCtx, strings.noBot);
                }

                const deviceCtx = document.getElementById('device-chart');
                const hasDevice = deviceBreakdown && deviceBreakdown.labels && deviceBreakdown.labels.length &&
                    Array.isArray(deviceBreakdown.values) && deviceBreakdown.values.some(v => Number(v) > 0);
                if (hasDevice) {
                    resetChartContainer(deviceCtx);
                    window.smCharts.device = new Chart(deviceCtx, {
                        type: 'doughnut',
                        data: {
                            labels: deviceBreakdown.labels,
                            datasets: [{ data: deviceBreakdown.values, backgroundColor: chartColors }]
                        },
                        options: {
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                } else if (deviceCtx) {
                    renderEmptyChart(deviceCtx, strings.noDevice);
                }

                const browserCtx = document.getElementById('browser-chart');
                const hasBrowser = browserBreakdown && browserBreakdown.labels && browserBreakdown.labels.length &&
                    Array.isArray(browserBreakdown.values) && browserBreakdown.values.some(v => Number(v) > 0);
                if (hasBrowser) {
                    resetChartContainer(browserCtx);
                    window.smCharts.browser = new Chart(browserCtx, {
                        type: 'bar',
                        data: {
                            labels: browserBreakdown.labels,
                            datasets: [{ label: strings.searchesLabel, data: browserBreakdown.values, backgroundColor: '#0d78f2' }]
                        },
                        options: {
                            plugins: { legend: { display: false } }
                        }
                    });
                } else if (browserCtx) {
                    renderEmptyChart(browserCtx, strings.noBrowser);
                }

                const osCtx = document.getElementById('os-chart');
                const hasOs = osBreakdown && osBreakdown.labels && osBreakdown.labels.length &&
                    Array.isArray(osBreakdown.values) && osBreakdown.values.some(v => Number(v) > 0);
                if (hasOs) {
                    resetChartContainer(osCtx);
                    window.smCharts.os = new Chart(osCtx, {
                        type: 'doughnut',
                        data: {
                            labels: osBreakdown.labels,
                            datasets: [{ data: osBreakdown.values, backgroundColor: chartColors }]
                        },
                        options: {
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                } else if (osCtx) {
                    renderEmptyChart(osCtx, strings.noOs);
                }
            },
        });

        analyticsRequest({
            consumer: 'traffic-agents', family: 'traffic-devices', tab: 'traffic-devices',
            targets: ['#bot-percentage-label', '#top-bots-body'],
            data: { type: 'bot-stats' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#bot-percentage-label').empty(); $('#top-bots-body').empty(); },
            onSuccess: renderBotStats,
        });
    }

    function loadGeographicData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'geographic-countries', family: 'geographic', tab: 'geographic', targets: ['#countries-body'],
            data: { type: 'countries' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#countries-body').empty(); },
            onSuccess: renderCountries,
        });

        analyticsRequest({
            consumer: 'geographic-cities', family: 'geographic', tab: 'geographic', targets: ['#cities-body'],
            data: { type: 'cities' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#cities-body').empty(); },
            onSuccess: renderCities,
        });
    }

    function renderCountries(data) {
        const tbody = $('#countries-body');
        tbody.empty();
        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="3" class="light lr-text-center">' + strings.noDataAvailable + '</td></tr>');
            return;
        }
        data.forEach(c => {
            tbody.append(`<tr><td>${Craft.escapeHtml(c.name)}</td><td>${c.count.toLocaleString()}</td><td>${c.percentage}%</td></tr>`);
        });
    }

    function renderCities(data) {
        const tbody = $('#cities-body');
        tbody.empty();
        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="3" class="light lr-text-center">' + strings.noDataAvailable + '</td></tr>');
            return;
        }
        data.forEach(c => {
            tbody.append(`<tr><td>${Craft.escapeHtml(c.city)}</td><td>${Craft.escapeHtml(c.countryName)}</td><td>${c.count.toLocaleString()}</td></tr>`);
        });
    }

    function renderHourlyChart(data) {
        const ctx = document.getElementById('hourly-chart');
        if (!ctx) return;

        if (!data || !data.data || !hasNonZeroValues(data.data)) {
            renderEmptyChart(ctx, strings.noUsage);
            return;
        }

        if (window.smCharts.hourly) window.smCharts.hourly.destroy();
        resetChartContainer(ctx);

        window.smCharts.hourly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: strings.searchesLabel,
                    data: data.data,
                    backgroundColor: '#0d78f2'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: (strings.peakHourTitle || '') + ' ' + data.peakHourFormatted
                    }
                }
            }
        });
    }

    function renderQueryAnalysis(data) {
        if (data.lengthDistribution && data.lengthDistribution.labels && data.lengthDistribution.labels.length && hasNonZeroValues(data.lengthDistribution.values)) {
            destroyChartByCanvasId('query-length-chart');
            if (window.smCharts.length) {
                window.smCharts.length.destroy();
                delete window.smCharts.length;
            }
            const lengthCtx = document.getElementById('query-length-chart');
            resetChartContainer(lengthCtx);
            window.smCharts.length = new Chart(lengthCtx, {
                type: 'pie',
                data: {
                    labels: data.lengthDistribution.labels,
                    datasets: [{ data: data.lengthDistribution.values, backgroundColor: ['#3498db', '#9b59b6', '#34495e'] }]
                }
            });
        } else {
            const lengthCtx = document.getElementById('query-length-chart');
            if (lengthCtx) {
                renderEmptyChart(lengthCtx, strings.noQueryLength);
            }
            $('#query-length-legend').empty();
        }

        const container = $('#word-cloud-container');
        container.empty();
        if (data.wordCloud && data.wordCloud.length) {
            const maxWeight = Math.max(...data.wordCloud.map(w => w.weight));
            const minSize = 12;
            const maxSize = 48;

            data.wordCloud.forEach(w => {
                const size = minSize + ((w.weight / maxWeight) * (maxSize - minSize));
                const span = $('<span>')
                    .text(w.text)
                    .addClass('lr-word-cloud-item')
                    .css('fontSize', size + 'px')
                    .attr('title', w.weight + ' ' + strings.searchesLabel.toLowerCase());
                container.append(span).append(' ');
            });
        } else {
            container.html('<div class="zilch" style="padding: 40px; text-align: center;"><p class="light">' + strings.noWordCloud + '</p></div>');
        }
    }

    function renderContentGaps(data) {
        const tbody = $('#content-gaps-body');
        tbody.empty();

        if (!data.clusters || data.clusters.length === 0) {
            tbody.html('<tr><td colspan="4" class="thin light lr-text-center">' + strings.noContentGaps + '</td></tr>');
            return;
        }

        data.clusters.forEach(c => {
            const queries = c.queries.slice(0, 3).map(q => Craft.escapeHtml(q)).join(', ');
            let row = `<tr>
                <td><strong>${Craft.escapeHtml(c.representative)}</strong></td>
                <td>${c.count.toLocaleString()}</td>
                <td>${queries}</td>
                <td>${Craft.escapeHtml(c.lastSearched)}</td>
            </tr>`;
            tbody.append(row);
        });
    }

    function loadRecentSearchesData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'searches-recent', family: 'searches', tab: 'searches', targets: ['#recent-searches-body'],
            data: { type: 'recent-searches' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#recent-searches-body').empty(); },
            onSuccess: renderRecentSearches,
        });
    }

    function loadRecentUnhandledData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'content-gaps-recent', family: 'content-gaps', tab: 'content-gaps', targets: ['#recent-unhandled-body'],
            data: { type: 'recent-unhandled' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#recent-unhandled-body').empty(); },
            onSuccess: renderRecentUnhandled,
        });
    }

    function renderRecentSearches(data) {
        var tbody = $('#recent-searches-body');
        var enableGeo = config.enableGeoDetection || false;
        var labels = config.backendLabels || {};
        var cols = enableGeo ? 19 : 18;
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="' + cols + '" class="thin light lr-text-center">' + strings.noSearches + '</td></tr>');
            return;
        }

        data.forEach(function(s) {
            var hits;
            if (s.wasRedirected) {
                hits = '<span class="lr-text-purple" title="' + strings.redirected + '">\u2014</span>';
            } else if (s.resultsCount > 0) {
                hits = '<span class="lr-text-green">' + Number(s.resultsCount).toLocaleString() + '</span>';
            } else {
                hits = '<span class="lr-text-red">0</span>';
            }

            var synonyms = s.synonymsExpanded ? '<span class="status green" title="' + strings.synonymsUsed + '"></span>' : '<span class="light">\u2014</span>';
            var rules = s.rulesMatched > 0 ? '<span class="lr-text-blue">' + s.rulesMatched + '</span>' : '<span class="light">\u2014</span>';
            var promos = s.promotionsShown > 0 ? '<span class="lr-text-amber">' + s.promotionsShown + '</span>' : '<span class="light">\u2014</span>';
            var redirected = s.wasRedirected ? '<span class="status red" title="' + strings.redirectedToPage + '"></span>' : '<span class="light">\u2014</span>';
            var intent = s.intent ? Craft.escapeHtml(intentLabel(s.intent)) : '\u2014';
            var source = {cp: 'CP', frontend: strings.frontend || '', api: 'API'}[s.source] || Craft.escapeHtml((s.source || '').charAt(0).toUpperCase() + (s.source || '').slice(1));

            var row = '<tr>' +
                '<td class="nowrap">' + Craft.escapeHtml(s.date || '\u2014') + '</td>' +
                '<td class="nowrap">' + Craft.escapeHtml(s.time || '\u2014') + '</td>' +
                '<td><code>' + Craft.escapeHtml(s.query) + '</code></td>' +
                '<td>' + Craft.escapeHtml(s.siteName || '\u2014') + '</td>' +
                '<td>' + hits + '</td>' +
                '<td>' + synonyms + '</td>' +
                '<td>' + rules + '</td>' +
                '<td>' + promos + '</td>' +
                '<td>' + redirected + '</td>' +
                '<td>' + Craft.escapeHtml(s.indexHandle || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(labels[s.backend] || (s.backend ? s.backend.charAt(0).toUpperCase() + s.backend.slice(1) : '\u2014')) + '</td>' +
                '<td>' + intent + '</td>' +
                '<td>' + source + '</td>' +
                '<td>' + Craft.escapeHtml(s.platform || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(s.appVersion || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(s.deviceType ? s.deviceType.charAt(0).toUpperCase() + s.deviceType.slice(1) : '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(s.browser || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(s.osName || '\u2014') + '</td>';

            if (enableGeo) {
                var loc = '\u2014';
                if (s.city && s.country) loc = Craft.escapeHtml(s.city + ', ' + s.country);
                else if (s.country) loc = Craft.escapeHtml(s.country);
                row += '<td class="nowrap">' + loc + '</td>';
            }

            row += '</tr>';
            tbody.append(row);
        });
    }

    function renderRecentUnhandled(data) {
        var tbody = $('#recent-unhandled-body');
        var labels = config.backendLabels || {};
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="6" class="thin light lr-text-center">' + strings.noUnhandled + '</td></tr>');
            return;
        }

        data.forEach(function(s) {
            tbody.append('<tr>' +
                '<td><code>' + Craft.escapeHtml(s.query) + '</code></td>' +
                '<td>' + Craft.escapeHtml(s.siteName || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(s.indexHandle || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(labels[s.backend] || (s.backend ? s.backend.charAt(0).toUpperCase() + s.backend.slice(1) : '\u2014')) + '</td>' +
                '<td>' + Craft.escapeHtml(s.date || '\u2014') + '</td>' +
                '<td>' + Craft.escapeHtml(s.time || '\u2014') + '</td>' +
            '</tr>');
        });
    }

    function renderBotStats(data) {
        var label = $('#bot-percentage-label');
        var tbody = $('#top-bots-body');

        if (!data) {
            label.text(strings.noAgentData || strings.noBotData);
            tbody.html('<tr><td colspan="5" class="light lr-text-center">' + (strings.noAgentData || strings.noBotData) + '</td></tr>');
            return;
        }

        label.html(Craft.escapeHtml(strings.nonHumanTraffic || 'Non-human traffic') + ': <strong>' + (data.nonHumanPercentage || data.botPercentage || 0) + '%</strong>');

        tbody.empty();
        const topAgents = data.topAgents || data.topBots || [];
        if (topAgents.length > 0) {
            topAgents.forEach(function(bot) {
                tbody.append('<tr>' +
                    '<td>' + Craft.escapeHtml(bot.botName || '—') + '</td>' +
                    '<td>' + Craft.escapeHtml(trafficTypeLabel(bot.trafficType || 'bot')) + '</td>' +
                    '<td>' + Craft.escapeHtml(bot.botCategory || '—') + '</td>' +
                    '<td>' + Craft.escapeHtml(bot.botProducerName || '—') + '</td>' +
                    '<td>' + Number(bot.count).toLocaleString() + '</td>' +
                '</tr>');
            });
        } else {
            tbody.html('<tr><td colspan="5" class="light lr-text-center">' + (strings.noAgentData || strings.noBotData) + '</td></tr>');
        }
    }

    function trafficTypeLabel(type) {
        if (type === 'system') return strings.system || 'System';
        if (type === 'bot') return strings.bot || 'Bot';
        return strings.human || 'Human';
    }

    function loadBreakdownCharts(dateRange, siteId) {
        analyticsRequest({
            consumer: 'overview-intent', family: 'overview', tab: 'overview', targets: ['#intent-chart'],
            data: { type: 'intent' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('intent-chart'); },
            onSuccess: renderIntentChart,
        });

        analyticsRequest({
            consumer: 'overview-source', family: 'overview', tab: 'overview', targets: ['#source-chart'],
            data: { type: 'source' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('source-chart'); },
            onSuccess: renderSourceChart,
        });
    }

    function loadSearchActivityData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'overview-peak-hours', family: 'overview', tab: 'overview', targets: ['#peak-hours-chart', '#peak-hour-label'],
            data: { type: 'hourly' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('peak-hours-chart'); $('#peak-hour-label').empty(); },
            onSuccess: renderPeakHoursChart,
        });

        analyticsRequest({
            consumer: 'overview-trending', family: 'overview', tab: 'overview', targets: ['#trending-queries-body'],
            data: { type: 'trending' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#trending-queries-body').empty(); },
            onSuccess: renderTrendingQueries,
        });
    }

    function renderPeakHoursChart(data) {
        const ctx = document.getElementById('peak-hours-chart');
        if (!ctx) return;

        if (window.smCharts.peakHours) {
            window.smCharts.peakHours.destroy();
        }

        if (!data || !data.data || !hasNonZeroValues(data.data)) {
            renderEmptyChart(ctx, strings.noPeakHour);
            $('#peak-hour-label').empty();
            return;
        }

        resetChartContainer(ctx);
        window.smCharts.peakHours = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: strings.searchesLabel,
                    data: data.data,
                    backgroundColor: data.data.map((val, idx) => idx === data.peakHour ? '#e74c3c' : 'rgba(13, 120, 242, 0.7)'),
                    borderColor: data.data.map((val, idx) => idx === data.peakHour ? '#c0392b' : '#0d78f2'),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
        ctx.style.width = '100%';
        ctx.style.height = '100%';

        if (data.peakHourFormatted) {
            $('#peak-hour-label').html('<strong>' + strings.peakHourLabel + '</strong> ' + Craft.escapeHtml(data.peakHourFormatted));
        }
    }

    function renderTrendingQueries(data) {
        const tbody = $('#trending-queries-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="4" class="thin light lr-text-center">' + strings.noTrending + '</td></tr>');
            return;
        }

        data.forEach(q => {
            let trendIcon = '';
            let trendColor = '';
            let trendText = '';
            const changePercent = Number(q.changePercent);
            const safeChangePercent = Number.isFinite(changePercent) ? changePercent : 0;

            switch(q.trend) {
                case 'up':
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
                    trendColor = '#27ae60';
                    trendText = '+' + safeChangePercent + '%';
                    break;
                case 'down':
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
                    trendColor = '#e74c3c';
                    trendText = '-' + safeChangePercent + '%';
                    break;
                case 'new':
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4" fill="currentColor"></circle></svg>';
                    trendColor = '#3498db';
                    trendText = Craft.escapeHtml(strings.newLabel);
                    break;
                default:
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
                    trendColor = '#95a5a6';
                    trendText = '—';
            }

            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td class="lr-text-end">${q.count.toLocaleString()}</td>
                <td class="lr-text-end lr-text-muted">${q.previousCount > 0 ? q.previousCount.toLocaleString() : '—'}</td>
                <td class="lr-text-end" style="color: ${trendColor};">
                    <span class="lr-inline-flex lr-gap-4">
                        ${trendIcon} ${trendText}
                    </span>
                </td>
            </tr>`);
        });
    }

    function loadPerformanceData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'performance-cache', family: 'performance', tab: 'performance',
            targets: ['#cache-hit-rate', '#cache-hits', '#cache-misses', '#total-searches-perf'],
            data: { type: 'cache-stats' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() {
                $('#cache-hit-rate .lr-unified-card-value, #cache-hits .lr-unified-card-value, #cache-misses .lr-unified-card-value, #total-searches-perf .lr-unified-card-value').text('—');
            },
            onSuccess: renderCacheStats,
        });

        analyticsRequest({
            consumer: 'performance-trend', family: 'performance', tab: 'performance', targets: ['#performance-chart'],
            data: { type: 'performance' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('performance-chart'); },
            onSuccess: renderPerformanceChart,
        });

        analyticsRequest({
            consumer: 'performance-top', family: 'performance', tab: 'performance', targets: ['#top-queries-body'],
            data: { type: 'top-queries' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#top-queries-body').empty(); },
            onSuccess: renderTopQueries,
        });

        analyticsRequest({
            consumer: 'performance-worst', family: 'performance', tab: 'performance', targets: ['#worst-queries-body'],
            data: { type: 'worst-queries' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#worst-queries-body').empty(); },
            onSuccess: renderWorstQueries,
        });
    }

    function renderCacheStats(data) {
        $('#cache-hit-rate .lr-unified-card-value').text(data.hitRate + '%');
        $('#cache-hits .lr-unified-card-value').text(data.cacheHits.toLocaleString());
        $('#cache-misses .lr-unified-card-value').text(data.cacheMisses.toLocaleString());
        $('#total-searches-perf .lr-unified-card-value').text(data.total.toLocaleString());
    }

    function renderTopQueries(data) {
        const tbody = $('#top-queries-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="4" class="thin light lr-text-center">' + strings.notEnoughData + '</td></tr>');
            return;
        }

        data.forEach(q => {
            const timeClass = getPerformanceTimeClass(q.avgTime);
            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td>${q.siteName ? Craft.escapeHtml(q.siteName) : '—'}</td>
                <td><strong class="${timeClass}">${Number(q.avgTime).toLocaleString()}ms</strong></td>
                <td>${q.indexSearches.toLocaleString()}</td>
            </tr>`);
        });
    }

    function renderWorstQueries(data) {
        const tbody = $('#worst-queries-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="4" class="thin light lr-text-center">' + strings.notEnoughData + '</td></tr>');
            return;
        }

        data.forEach(q => {
            const timeClass = getPerformanceTimeClass(q.avgTime);
            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td>${q.siteName ? Craft.escapeHtml(q.siteName) : '—'}</td>
                <td><strong class="${timeClass}">${Number(q.avgTime).toLocaleString()}ms</strong></td>
                <td>${q.indexSearches.toLocaleString()}</td>
            </tr>`);
        });
    }

    function getPerformanceTimeClass(avgTime) {
        const value = Number(avgTime);
        if (value > 1000) {
            return 'lr-text-red';
        }
        if (value >= 250) {
            return 'lr-text-orange';
        }
        return '';
    }

    function renderIntentChart(data) {
        if (window.smCharts.intent) {
            window.smCharts.intent.destroy();
        }
        const ctx = document.getElementById('intent-chart');
        if (!ctx) return;

        if (!data.labels || data.labels.length === 0) {
            renderEmptyChart(ctx, strings.noIntent);
            return;
        }

        resetChartContainer(ctx);
        const colors = ['#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#e74c3c'];
        window.smCharts.intent = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels.map(l => intentLabel(l)),
                datasets: [{
                    data: data.values,
                    backgroundColor: colors.slice(0, data.labels.length)
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    function renderSourceChart(data) {
        if (window.smCharts.source) {
            window.smCharts.source.destroy();
        }
        const ctx = document.getElementById('source-chart');
        if (!ctx) return;

        if (!data.labels || data.labels.length === 0) {
            renderEmptyChart(ctx, strings.noSource);
            return;
        }

        resetChartContainer(ctx);
        const colors = ['#27ae60', '#3498db', '#e67e22', '#9b59b6', '#1abc9c', '#e74c3c'];
        window.smCharts.source = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: colors.slice(0, data.labels.length)
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    function renderPerformanceChart(data) {
        if (window.smCharts.performance) {
            window.smCharts.performance.destroy();
        }
        const ctx = document.getElementById('performance-chart');
        if (!ctx) return;

        if (!data.labels || data.labels.length === 0) {
            renderEmptyChart(ctx, strings.noPerformance);
            return;
        }

        resetChartContainer(ctx);
        window.smCharts.performance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: strings.avgResponseTimeMs || '',
                    data: data.avgTime,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: strings.ms || '' }
                    }
                }
            }
        });
    }

    function loadQueryRulesData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'query-rules-top', family: 'query-rules', tab: 'query-rules', targets: ['#top-rules-body'],
            data: { type: 'query-rules-top' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#top-rules-body').empty(); },
            onSuccess: renderTopRules,
        });

        analyticsRequest({
            consumer: 'query-rules-types', family: 'query-rules', tab: 'query-rules', targets: ['#rules-by-type-chart'],
            data: { type: 'query-rules-by-type' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('rules-by-type-chart'); },
            onSuccess: renderRulesByType,
        });

        analyticsRequest({
            consumer: 'query-rules-queries', family: 'query-rules', tab: 'query-rules', targets: ['#rule-queries-body'],
            data: { type: 'query-rules-queries' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#rule-queries-body').empty(); },
            onSuccess: renderRuleQueries,
        });
    }

    const actionTypeBadges = config.actionTypeBadges || {};

    function renderTopRules(data) {
        const tbody = $('#top-rules-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="4" class="light lr-text-center">' + strings.noQueryRules + '</td></tr>');
            return;
        }

        data.forEach(r => {
            const actionBadge = actionTypeBadges[r.actionType] || Craft.escapeHtml(r.actionType || '');
            tbody.append(`<tr>
                <td><strong>${Craft.escapeHtml(r.ruleName)}</strong></td>
                <td>${actionBadge}</td>
                <td>${r.hits.toLocaleString()}</td>
                <td>${r.avgResults.toLocaleString()}</td>
            </tr>`);
        });
    }

    function renderRulesByType(data) {
        if (window.smCharts.rulesByType) {
            window.smCharts.rulesByType.destroy();
        }
        const ctx = document.getElementById('rules-by-type-chart');
        if (!ctx) return;

        if (!data.labels || data.labels.length === 0) {
            renderEmptyChart(ctx, strings.noDataFilters);
            return;
        }

        const colors = {
            'synonym': '#3498db',
            'boost_section': '#2ecc71',
            'boost_category': '#27ae60',
            'boost_element': '#1abc9c',
            'redirect': '#e74c3c'
        };

        // Map action-type enums to translated labels (reusing the table badge labels);
        // fall back to title-casing the raw enum for unknown/custom types
        const actionTypeLabels = config.actionTypeLabels || {};

        resetChartContainer(ctx);
        window.smCharts.rulesByType = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels.map(l => actionTypeLabels[l] || l.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())),
                datasets: [{
                    data: data.values,
                    backgroundColor: data.labels.map(l => colors[l] || '#95a5a6')
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        ctx.style.width = '100%';
        ctx.style.height = '100%';
    }

    function renderRuleQueries(data) {
        const tbody = $('#rule-queries-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="3" class="light lr-text-center">' + strings.noRuleQueries + '</td></tr>');
            return;
        }

        data.forEach(q => {
            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td>${q.rulesTriggered.toLocaleString()}</td>
                <td>${q.count.toLocaleString()}</td>
            </tr>`);
        });
    }

    function loadPromotionsData(dateRange, siteId) {
        analyticsRequest({
            consumer: 'promotions-top', family: 'promotions', tab: 'promotions', targets: ['#top-promotions-body'],
            data: { type: 'promotions-top' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#top-promotions-body').empty(); },
            onSuccess: renderTopPromotions,
        });

        analyticsRequest({
            consumer: 'promotions-positions', family: 'promotions', tab: 'promotions', targets: ['#promotions-by-position-chart'],
            data: { type: 'promotions-by-position' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { clearChart('promotions-by-position-chart'); },
            onSuccess: renderPromotionsByPosition,
        });

        analyticsRequest({
            consumer: 'promotions-queries', family: 'promotions', tab: 'promotions', targets: ['#promotion-queries-body'],
            data: { type: 'promotions-queries' }, dateRange: dateRange, siteId: siteId,
            onFailure: function() { $('#promotion-queries-body').empty(); },
            onSuccess: renderPromotionQueries,
        });
    }

    function renderTopPromotions(data) {
        const tbody = $('#top-promotions-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="4" class="light lr-text-center">' + strings.noPromotionsShown + '</td></tr>');
            return;
        }

        data.forEach(p => {
            tbody.append(`<tr>
                <td><strong>${Craft.escapeHtml(p.elementTitle || (strings.elementNumber || '') + p.elementId)}</strong></td>
                <td>#${p.position}</td>
                <td>${p.impressions.toLocaleString()}</td>
                <td>${p.uniqueQueries.toLocaleString()}</td>
            </tr>`);
        });
    }

    function renderPromotionsByPosition(data) {
        if (window.smCharts.promosByPosition) {
            window.smCharts.promosByPosition.destroy();
        }
        const ctx = document.getElementById('promotions-by-position-chart');
        if (!ctx) return;

        if (!data.labels || data.labels.length === 0) {
            renderEmptyChart(ctx, strings.noDataFilters);
            return;
        }

        resetChartContainer(ctx);
        window.smCharts.promosByPosition = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels.map(l => (strings.positionNumber || '') + l),
                datasets: [{
                    label: strings.impressions || '',
                    data: data.values,
                    backgroundColor: '#0d78f2'
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    function renderPromotionQueries(data) {
        const tbody = $('#promotion-queries-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="3" class="light lr-text-center">' + strings.noPromotionQueries + '</td></tr>');
            return;
        }

        data.forEach(q => {
            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td>${q.promotionsShown.toLocaleString()}</td>
                <td>${q.count.toLocaleString()}</td>
            </tr>`);
        });
    }

    if (config.testMode === true) {
        window.lrSearchAnalyticsTestHooks = {
            analyticsRequest: analyticsRequest,
            handleAnalyticsInit: handleAnalyticsInit,
            loadInitialCharts: loadInitialCharts,
            loadTabData: loadTabData,
            resetRequestGeneration: resetRequestGeneration,
            state: function() {
                return {
                    dateRange: currentDateRange,
                    siteId: currentSiteId,
                    generation: requestGeneration,
                    successfulConsumers: Array.from(successfulConsumers),
                };
            },
        };
    }

    // If analytics was initialized before we bound, run immediately.
    if (window.lrAnalyticsConfig) {
        handleAnalyticsInit(window.lrAnalyticsConfig);
    }
    }

    if (window.Garnish && Garnish.onReady) {
        Garnish.onReady(init);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    };
})(window);
