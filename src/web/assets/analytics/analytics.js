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

    function getActiveTabId() {
        const hash = window.location.hash ? window.location.hash.substring(1) : '';
        if (hash && document.getElementById(hash)) {
            return hash;
        }
        const visible = document.querySelector('.lr-tab-content:not(.hidden)');
        return visible ? visible.id : 'overview';
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

        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';
        const chartColors = ['#0d78f2', '#27ae60', '#e74c3c', '#f39c12', '#9b59b6'];

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: currentDateRange, siteId: currentSiteId, type: 'chart', [csrfName]: csrfToken },
            success: function(res) {
                const chartData = res && res.success ? res.data.chartData : null;
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
                            { label: 'With Hits', data: chartData.map(d => d.withResults), borderColor: '#10B981', tension: 0.1 },
                            { label: 'Zero Hits', data: chartData.map(d => d.zeroResults), borderColor: '#EF4444', tension: 0.1 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: currentDateRange, siteId: currentSiteId, type: 'device-stats', [csrfName]: csrfToken },
            success: function(res) {
                const stats = res && res.success ? res.data.deviceStats : null;
                const botStats = stats ? stats.botStats : null;
                const deviceBreakdown = stats ? stats.deviceBreakdown : null;
                const browserBreakdown = stats ? stats.browserBreakdown : null;
                const osBreakdown = stats ? stats.osBreakdown : null;

                const botCtx = document.getElementById('bot-chart');
                const hasBot = botStats && botStats.chart && Array.isArray(botStats.chart.values) &&
                    botStats.chart.values.some(v => Number(v) > 0);
                if (hasBot && botCtx) {
                    resetChartContainer(botCtx);
                    window.smCharts.bot = new Chart(botCtx, {
                        type: 'doughnut',
                        data: {
                            labels: botStats.chart.labels,
                            datasets: [{ data: botStats.chart.values, backgroundColor: ['#27ae60', '#e74c3c'] }]
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
                        }
                    });
                } else if (osCtx) {
                    renderEmptyChart(osCtx, strings.noOs);
                }
            }
        });
    }

    function handleAnalyticsInit(config) {
        const resolved = config || (window.lrAnalyticsConfig || {});
        currentDateRange = resolved.dateRange || currentDateRange;
        currentSiteId = resolved.siteId || '';

        window.performanceLoaded = false;
        window.trafficDevicesLoaded = false;
        window.geographicLoaded = false;
        window.queryRulesLoaded = false;
        window.promotionsLoaded = false;

        loadInitialCharts();
        const activeTab = getActiveTabId();
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

        if (tabName === 'overview' && $('#word-cloud-container').children().length > 0) return;
        if (tabName === 'content-gaps' && $('#content-gaps-body tr').length > 1) return;
        if (tabName === 'performance' && window.performanceLoaded) return;
        if (tabName === 'traffic-devices' && window.trafficDevicesLoaded) return;
        if (tabName === 'geographic' && window.geographicLoaded) return;
        if (tabName === 'query-rules' && window.queryRulesLoaded) return;
        if (tabName === 'promotions' && window.promotionsLoaded) return;

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

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: {
                dateRange: currentDateRange,
                siteId: currentSiteId,
                type: mapping[tabName],
                [csrfName]: csrfToken
            },
            success: function(res) {
                if (res.success) {
                    if (tabName === 'overview') {
                        renderQueryAnalysis(res.data.queryAnalysis);
                        loadBreakdownCharts(currentDateRange, currentSiteId);
                        loadSearchActivityData(currentDateRange, currentSiteId);
                    }
                    if (tabName === 'content-gaps') renderContentGaps(res.data.contentGaps);
                }
            },
            error: function() {
                console.error('Failed to load tab data');
            }
        });
    }

    function hasNonZeroValues(values) {
        if (!Array.isArray(values) || values.length === 0) return false;
        return values.some(value => Number(value) > 0);
    }

    function loadTrafficDevicesData(dateRange, siteId) {
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'hourly', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderHourlyChart(res.data);
                $('#analytics-dashboard').css('opacity', '1');
                window.trafficDevicesLoaded = true;
            }
        });
    }

    function loadGeographicData(dateRange, siteId) {
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'countries', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderCountries(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'cities', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderCities(res.data);
                window.geographicLoaded = true;
            }
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
                        text: 'Peak Hour: ' + data.peakHourFormatted
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
            window.smCharts.length = new Chart(document.getElementById('query-length-chart'), {
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
                    .attr('title', w.weight + ' searches');
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
            let row = `<tr>
                <td><strong>${Craft.escapeHtml(c.representative)}</strong></td>
                <td>${c.count.toLocaleString()}</td>
                <td>${c.queries.slice(0, 3).join(', ')}</td>
                <td>${c.lastSearched}</td>
            </tr>`;
            tbody.append(row);
        });
    }

    function loadBreakdownCharts(dateRange, siteId) {
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'intent', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderIntentChart(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'source', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderSourceChart(res.data);
            }
        });
    }

    function loadSearchActivityData(dateRange, siteId) {
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'hourly', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderPeakHoursChart(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'trending', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderTrendingQueries(res.data);
            }
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
            $('#peak-hour-label').html('<strong>' + strings.peakHourLabel + '</strong> ' + data.peakHourFormatted);
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

            switch(q.trend) {
                case 'up':
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
                    trendColor = '#27ae60';
                    trendText = '+' + q.changePercent + '%';
                    break;
                case 'down':
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
                    trendColor = '#e74c3c';
                    trendText = '-' + q.changePercent + '%';
                    break;
                case 'new':
                    trendIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4" fill="currentColor"></circle></svg>';
                    trendColor = '#3498db';
                    trendText = strings.newLabel;
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
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'cache-stats', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderCacheStats(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'performance', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderPerformanceChart(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'top-queries', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderTopQueries(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'worst-queries', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderWorstQueries(res.data);
            }
        });

        window.performanceLoaded = true;
    }

    function renderCacheStats(data) {
        $('#cache-hit-rate').text(data.hitRate + '%');
        $('#cache-hits').text(data.cacheHits.toLocaleString());
        $('#cache-misses').text(data.cacheMisses.toLocaleString());
        $('#total-searches-perf').text(data.total.toLocaleString());
    }

    function renderTopQueries(data) {
        const tbody = $('#top-queries-body');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="4" class="thin light lr-text-center">' + strings.notEnoughData + '</td></tr>');
            return;
        }

        data.forEach(q => {
            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td>${q.siteName || '—'}</td>
                <td><strong>${q.avgTime}ms</strong></td>
                <td>${q.searches.toLocaleString()}</td>
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
            tbody.append(`<tr>
                <td><code>${Craft.escapeHtml(q.query)}</code></td>
                <td>${q.siteName || '—'}</td>
                <td><strong class="${q.avgTime > 100 ? 'lr-text-red' : ''}">${q.avgTime}ms</strong></td>
                <td>${q.searches.toLocaleString()}</td>
            </tr>`);
        });
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

        const colors = ['#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#e74c3c'];
        window.smCharts.intent = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
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

        window.smCharts.performance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Avg Response Time (ms)',
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
                        title: { display: true, text: 'ms' }
                    }
                }
            }
        });
    }

    function loadQueryRulesData(dateRange, siteId) {
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'query-rules-top', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderTopRules(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'query-rules-by-type', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderRulesByType(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'query-rules-queries', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderRuleQueries(res.data);
                window.queryRulesLoaded = true;
            }
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
            'filter': '#f39c12',
            'redirect': '#e74c3c'
        };

        window.smCharts.rulesByType = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels.map(l => l.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())),
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
        const csrfToken = config.csrfToken || '';
        const csrfName = config.csrfName || '';

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'promotions-top', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderTopPromotions(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'promotions-by-position', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderPromotionsByPosition(res.data);
            }
        });

        $.ajax({
            url: dataEndpoint,
            type: 'POST',
            dataType: 'json',
            data: { dateRange: dateRange, siteId: siteId, type: 'promotions-queries', [csrfName]: csrfToken },
            success: function(res) {
                if (res.success) renderPromotionQueries(res.data);
                window.promotionsLoaded = true;
            }
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
                <td><strong>${Craft.escapeHtml(p.elementTitle || 'Element #' + p.elementId)}</strong></td>
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

        window.smCharts.promosByPosition = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels.map(l => 'Position #' + l),
                datasets: [{
                    label: 'Impressions',
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
