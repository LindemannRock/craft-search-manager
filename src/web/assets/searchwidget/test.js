/**
 * Simple build verification tests
 * Run with: npm test
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const esbuild = require('esbuild');
const { pathToFileURL } = require('url');
const { chromium } = require('@playwright/test');
const { build, getOutputPaths } = require('./build.js');

const DIST_DIR = path.join(__dirname, 'dist');
const SRC_DIR = path.join(__dirname, 'src');
const REQUIRED_FILES = ['SearchModalWidget.js'];
const MIN_FILE_SIZE = 10000; // At least 10KB

let passed = 0;
let failed = 0;

function test(name, condition) {
    if (condition) {
        console.log(`✓ ${name}`);
        passed++;
    } else {
        console.log(`✗ ${name}`);
        failed++;
    }
}

console.log('\nRunning build verification tests...\n');

// Test 1: dist directory exists
test('dist directory exists', fs.existsSync(DIST_DIR));

// Test 2: Required files exist
for (const file of REQUIRED_FILES) {
    const filePath = path.join(DIST_DIR, file);
    test(`${file} exists`, fs.existsSync(filePath));
}

// Test 3: Files are not empty and meet minimum size
for (const file of REQUIRED_FILES) {
    const filePath = path.join(DIST_DIR, file);
    if (fs.existsSync(filePath)) {
        const stats = fs.statSync(filePath);
        test(`${file} has content (${(stats.size / 1024).toFixed(1)}KB)`, stats.size > MIN_FILE_SIZE);
    }
}

// Test 4: Files contain expected content
const mainFile = path.join(DIST_DIR, 'SearchModalWidget.js');
const standaloneFile = path.join(__dirname, '..', 'highlighter', 'dist', 'SearchManagerHighlighter.js');
if (fs.existsSync(mainFile)) {
    const content = fs.readFileSync(mainFile, 'utf8');
    test('Contains customElements.define', content.includes('customElements.define'));
    test('Contains search-modal registration', content.includes('search-modal'));
    test('Contains SearchModalWidget class', content.includes('SearchModalWidget'));
    test('Blocks scriptable result URL schemes', content.includes('javascript|data|vbscript'));
    test('Normalizes control characters before URL scheme checks', content.includes('[\\t\\n\\r]'));
    test('Escapes double quotes in rendered HTML', content.includes('&quot;'));
    test('Escapes single quotes in rendered HTML', content.includes('&#39;'));
    test('Dist normalizes highlight tags before rendering markup', content.includes('ALLOWED_HIGHLIGHT_TAGS') || content.includes('new Set(["mark","em","strong","u","b","i","span"])') || content.includes('["mark","em","strong","u","b","i","span"]'));
    test('Dist filters highlight class tokens before rendering attributes', content.includes('CSS_CLASS_TOKEN_PATTERN') || content.includes('[A-Za-z0-9_-]'));
    test('Does not render non-JSON error bodies', !content.includes('.text()'));
    test('Search uses stale-response sequence guard', content.includes('searchSequence'));
    test('Search requests are not aborted per keystroke', !content.includes('new AbortController'));
}

// Test 5: Source hardening remains explicit and reviewable
const highlighterFile = path.join(SRC_DIR, 'modules', 'Highlighter.js');
if (fs.existsSync(highlighterFile)) {
    const source = fs.readFileSync(highlighterFile, 'utf8');
    test('Source escapeHtml encodes double quotes', source.includes('.replace(/"/g, \'&quot;\')'));
    test('Source escapeHtml encodes single quotes', source.includes(".replace(/'/g, '&#39;')"));
    test('Source escapeHtml avoids DOM serialization', !source.includes('document.createElement'));
    test('Source allowlists highlight tags', source.includes("const ALLOWED_HIGHLIGHT_TAGS = new Set(['mark', 'em', 'strong', 'u', 'b', 'i', 'span']);"));
    test('Source filters highlight class tokens', source.includes('const CSS_CLASS_TOKEN_PATTERN = /^[A-Za-z0-9_-]+$/;'));
    test('Source uses normalized highlight tag for markup', source.includes('return applyHighlightRanges(text, termList, safeTag, classAttr, queryTerms);'));
    test('Source does not render raw className in class attribute', !source.includes('classes.push(className);'));
    test('Source escapes constructed class attribute', source.includes("const classAttr = ` class=\"${escapeHtml(classes.join(' '))}\"`;"));
    test('Source preserves dotted filename-like queries as one highlight term', source.includes('records.push({ term: word, field: termField });'));
}

const widgetBaseFile = path.join(SRC_DIR, 'core', 'SearchWidgetBase.js');
if (fs.existsSync(widgetBaseFile)) {
    const source = fs.readFileSync(widgetBaseFile, 'utf8');
    test('Stale search responses are discarded before state updates', source.includes('requestId !== this.searchSequence'));
    test('Stale search failures are discarded before error state', (source.match(/requestId !== this\.searchSequence/g) || []).length >= 2);
    test('Source does not abort in-flight searches', !source.includes('new AbortController'));
    test('Widget delegates destination-page highlighting to the shared module', source.includes('return highlightFromUrl({'));
    test('Widget retains no destination-page DOM walker', !source.includes('createTreeWalker'));
    test('Widget forwards its runtime type to analytics tracking', source.includes('widgetType: this.widgetType'));
}

const pageHighlighterFile = path.join(SRC_DIR, 'modules', 'PageHighlighter.js');
if (fs.existsSync(pageHighlighterFile)) {
    const source = fs.readFileSync(pageHighlighterFile, 'utf8');
    test('Shared page highlighter can mark code/pre text nodes', !source.includes("textarea, code, pre, mark"));
    test('Shared page highlighter bounds URL queries at 256 characters', source.includes('const MAX_QUERY_LENGTH = 256;'));
    test('Shared page highlighter creates marks with textContent', source.includes('mark.textContent = matchText;'));
    test('Shared page highlighter escapes terms before regex construction', source.includes('.map(term => escapeRegex(term))'));
    test('Shared page highlighter has no top-level browser access', !source.match(/^const .*\b(?:window|document)\b/m));
}

const urlUtilsFile = path.join(SRC_DIR, 'modules', 'UrlUtils.js');
if (fs.existsSync(urlUtilsFile)) {
    const source = fs.readFileSync(urlUtilsFile, 'utf8');
    test('Source URL guard strips tab/newline/carriage return', source.includes('replace(/[\\t\\n\\r]/g, \'\')'));
    test('Source URL guard strips leading C0 controls and space', source.includes('replace(/^[\\u0000-\\u0020]+/, \'\')'));
    const { appendQueryParam, isUnsafeNavigationUrl } = loadUrlUtilsModule();
    test('Shared URL guard owns all executable scheme checks', ['javascript:', 'data:', 'vbscript:', 'file:'].every(isUnsafeNavigationUrl));
    test('URL query persistence rejects file navigation', appendQueryParam('file:///etc/passwd', 'needle') === '#');
}

// Test 6: Renderer supports split section hits without changing page-mode identity
function loadRendererModule() {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-renderer-'));
    const outfile = path.join(tmpDir, 'ResultRenderer.cjs');
    esbuild.buildSync({
        entryPoints: [path.join(SRC_DIR, 'modules', 'ResultRenderer.js')],
        bundle: true,
        platform: 'node',
        format: 'cjs',
        outfile,
        logLevel: 'silent',
    });
    return require(outfile);
}

function loadHighlighterModule() {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-highlighter-'));
    const outfile = path.join(tmpDir, 'Highlighter.cjs');
    esbuild.buildSync({
        entryPoints: [path.join(SRC_DIR, 'modules', 'Highlighter.js')],
        bundle: true,
        platform: 'node',
        format: 'cjs',
        outfile,
        logLevel: 'silent',
    });
    return require(outfile);
}

function loadPageHighlighterModule() {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-page-highlighter-'));
    const outfile = path.join(tmpDir, 'PageHighlighter.cjs');
    esbuild.buildSync({
        entryPoints: [path.join(SRC_DIR, 'modules', 'PageHighlighter.js')],
        bundle: true,
        platform: 'node',
        format: 'cjs',
        outfile,
        logLevel: 'silent',
    });
    return require(outfile);
}

function loadUrlUtilsModule() {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-url-utils-'));
    const outfile = path.join(tmpDir, 'UrlUtils.cjs');
    esbuild.buildSync({
        entryPoints: [path.join(SRC_DIR, 'modules', 'UrlUtils.js')],
        bundle: true,
        platform: 'node',
        format: 'cjs',
        outfile,
        logLevel: 'silent',
    });
    return require(outfile);
}

function loadSearchServiceModule() {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-service-'));
    const outfile = path.join(tmpDir, 'SearchService.cjs');
    esbuild.buildSync({
        entryPoints: [path.join(SRC_DIR, 'modules', 'SearchService.js')],
        bundle: true,
        platform: 'node',
        format: 'cjs',
        outfile,
        logLevel: 'silent',
    });
    return require(outfile);
}

try {
    const { groupResultsByType, groupResultsByField } = loadSearchServiceModule();
    const reservedKeys = ['__proto__', 'constructor', 'toString', 'hasOwnProperty'];
    const reservedResults = reservedKeys.map((value, index) => ({
        id: index + 1,
        title: `Reserved ${value}`,
        source: value,
        category: value,
    }));
    const ordinaryResults = [
        { id: 10, title: 'First docs', source: 'Docs', category: 'Guides' },
        { id: 11, title: 'Fallback', type: 'Entry' },
        { id: 12, title: 'Second docs', source: 'Docs', category: 'Guides' },
    ];
    const flatGroups = groupResultsByType([...reservedResults, ...ordinaryResults]);
    const fieldGroups = groupResultsByField([...reservedResults, ...ordinaryResults], 'category');
    const renderableReserved = reservedResults.map(result => ({
        ...result,
        backendId: String(result.id),
        elementId: result.id,
        url: `/reserved-${result.id}`,
    }));
    const { renderResults } = loadRendererModule();
    const flatReservedHtml = renderResults(renderableReserved, 'reserved', {
        resultsGroupingEnabled: true,
        listboxId: 'reserved-flat',
    });
    const hierarchicalReservedHtml = renderResults(renderableReserved, 'reserved', {
        resultsLayout: 'hierarchical',
        hierarchyGroupBy: 'category',
        listboxId: 'reserved-hierarchical',
    });

    test('Flat grouping preserves every inherited-key label and member',
        reservedKeys.every((key, index) => flatGroups[key]?.[0] === reservedResults[index]));
    test('Configured grouping preserves every inherited-key label and member',
        reservedKeys.every((key, index) => fieldGroups[key]?.[0] === reservedResults[index]));
    test('Flat grouping preserves first-seen order, repeated members, and fallback labels',
        Object.keys(flatGroups).slice(-2).join(',') === 'Docs,Entry'
        && flatGroups.Docs.length === 2
        && flatGroups.Entry[0] === ordinaryResults[1]);
    test('Configured grouping preserves first-seen order, repeated members, and fallback labels',
        Object.keys(fieldGroups).slice(-2).join(',') === 'Guides,Entry'
        && fieldGroups.Guides.length === 2
        && fieldGroups.Entry[0] === ordinaryResults[1]);
    test('Flat renderer emits every reserved group without losing result membership',
        reservedKeys.every((key, index) => flatReservedHtml.includes(`aria-label="${key}"`)
            && flatReservedHtml.includes(`Reserved ${reservedKeys[index]}`)));
    test('Hierarchical renderer emits every configured reserved group without losing result membership',
        reservedKeys.every((key, index) => hierarchicalReservedHtml.includes(`aria-label="${key}"`)
            && hierarchicalReservedHtml.includes(`Reserved ${reservedKeys[index]}`)));
} catch (error) {
    console.error(error);
    test('Reserved-key grouping tests execute', false);
}

async function runBuildParityTests() {
    const firstRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-build-one-'));
    const secondRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-build-two-'));
    const layoutRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-widget-build-layouts-'));

    try {
        const tracked = getOutputPaths();
        const first = await build({ outputRoot: firstRoot, quiet: true });
        const second = await build({ outputRoot: secondRoot, quiet: true });
        const standaloneProject = path.join(layoutRoot, 'standalone');
        const standaloneSource = path.join(standaloneProject, 'src/web/assets/searchwidget/src');
        const strictProject = path.join(layoutRoot, 'strict-project');
        const strictSource = path.join(strictProject, 'src/web/assets/searchwidget/src');
        fs.cpSync(SRC_DIR, standaloneSource, { recursive: true });
        fs.cpSync(SRC_DIR, strictSource, { recursive: true });
        fs.mkdirSync(path.join(standaloneProject, 'src/config'), { recursive: true });
        fs.mkdirSync(path.join(strictProject, 'src/config'), { recursive: true });
        fs.copyFileSync(
            path.join(__dirname, '..', '..', '..', 'config/style-defaults.json'),
            path.join(standaloneProject, 'src/config/style-defaults.json'),
        );
        fs.copyFileSync(
            path.join(__dirname, '..', '..', '..', 'config/style-defaults.json'),
            path.join(strictProject, 'src/config/style-defaults.json'),
        );
        fs.writeFileSync(path.join(strictProject, 'tsconfig.json'), JSON.stringify({
            compilerOptions: { strict: true },
        }));
        const standalone = await build({
            sourceRoot: standaloneSource,
            outputRoot: path.join(layoutRoot, 'standalone-output'),
            quiet: true,
        });
        const ambientStrict = await build({
            sourceRoot: strictSource,
            outputRoot: path.join(layoutRoot, 'strict-output'),
            quiet: true,
        });

        for (const artifact of ['modal', 'highlighter']) {
            const trackedBytes = fs.readFileSync(tracked[artifact]);
            const firstBytes = fs.readFileSync(first[artifact]);
            const secondBytes = fs.readFileSync(second[artifact]);
            const standaloneBytes = fs.readFileSync(standalone[artifact]);
            const ambientStrictBytes = fs.readFileSync(ambientStrict[artifact]);
            const label = path.basename(tracked[artifact]);

            test(`${label} matches a fresh canonical production build`, trackedBytes.equals(firstBytes));
            test(`${label} is byte-identical across isolated production builds`, firstBytes.equals(secondBytes));
            test(`${label} ignores an ambient strict TypeScript project`, standaloneBytes.equals(ambientStrictBytes));
        }
    } catch (error) {
        console.error(error);
        test('Canonical build parity tests execute', false);
    } finally {
        fs.rmSync(firstRoot, { recursive: true, force: true });
        fs.rmSync(secondRoot, { recursive: true, force: true });
        fs.rmSync(layoutRoot, { recursive: true, force: true });
    }
}

try {
    const { performSearch, trackSearch } = loadSearchServiceModule();
    const originalFetch = global.fetch;
    const requestedUrls = [];
    const trackingBodies = [];
    global.fetch = async function(url, options = {}) {
        requestedUrls.push(String(url));
        if (options.body instanceof FormData) {
            trackingBodies.push(options.body);
        }

        return {
            ok: true,
            async json() {
                return { hits: [], total: 0 };
            },
        };
    };

    try {
        performSearch({
            query: 'daterangehelper',
            endpoint: '/actions/search-manager/api/search',
            snippetCleanMarkdown: true,
        });
        performSearch({
            query: 'daterangehelper',
            endpoint: '/actions/search-manager/api/search',
            snippetCleanMarkdown: false,
        });
        trackSearch({
            endpoint: '/actions/search-manager/search/track-search',
            query: 'widget default',
            widgetType: 'modal',
        });
        trackSearch({
            endpoint: '/actions/search-manager/search/track-search',
            query: 'widget custom',
            widgetType: 'inline',
            analyticsSource: 'header-search',
        });
    } finally {
        global.fetch = originalFetch;
    }

    test('Widget forwards snippetCleanMarkdown when enabled', requestedUrls[0] && requestedUrls[0].includes('snippetCleanMarkdown=1'));
    test('Widget omits snippetCleanMarkdown when disabled', requestedUrls[1] && !requestedUrls[1].includes('snippetCleanMarkdown=1'));
    test('Widget sends its deterministic type boundary', trackingBodies[0]?.get('widgetType') === 'modal');
    test('Widget leaves an omitted custom source for the server default', !trackingBodies[0]?.has('analyticsSource'));
    test('Widget sends an explicit custom source unchanged', trackingBodies[1]?.get('widgetType') === 'inline' && trackingBodies[1]?.get('analyticsSource') === 'header-search');
} catch (error) {
    console.error(error);
    test('Widget request metadata tests execute', false);
}

try {
    const { renderPromotionMarker, renderResults } = loadRendererModule();
    const maliciousBadge = renderPromotionMarker({ promoted: true }, {
        promotionDisplay: 'badge',
        promotionBadgeText: '<Featured>',
        promotionBadgePosition: 'top-right" onmouseover="alert(1)',
    });
    const inlineBadge = renderPromotionMarker({ promoted: true }, {
        promotionDisplay: 'badge',
        promotionBadgeText: 'Featured',
        promotionBadgePosition: 'inline',
    });
    const belowBadge = renderPromotionMarker({ promoted: true }, {
        promotionDisplay: 'badge',
        promotionBadgeText: 'Featured',
        promotionBadgePosition: 'below',
    });
    const aboveBadge = renderPromotionMarker({ promoted: true }, {
        promotionDisplay: 'badge',
        promotionBadgeText: 'Featured',
        promotionBadgePosition: 'above',
    });
    const tintMarker = renderPromotionMarker({ promoted: true }, { promotionDisplay: 'tint', promotionBadgeText: 'Featured' });
    const hiddenMarker = renderPromotionMarker({ promoted: true }, { promotionDisplay: 'none' });
    const defaultMarker = renderPromotionMarker({ promoted: true }, {});
    const unpromotedMarker = renderPromotionMarker({ promoted: false }, { promotionDisplay: 'badge' });

    test('Unrecognised badge positions fall back to inline without leaking markup', maliciousBadge.titlePrefix.includes('sm-promoted-badge') && !maliciousBadge.titlePrefix.includes('onmouseover') && maliciousBadge.blockMarkup === '');
    test('Promoted badge text remains escaped', maliciousBadge.titlePrefix.includes('&lt;Featured&gt;'));
    test('Inline badge renders in the title slot', inlineBadge.titlePrefix.includes('sm-promoted-badge') && inlineBadge.blockMarkup === '');
    test('Below badge renders on its own line', belowBadge.blockMarkup.includes('sm-promoted-badge-row') && belowBadge.titlePrefix === '');
    test('Above badge renders on its own line above the title', aboveBadge.aboveMarkup.includes('sm-promoted-badge-row--above') && aboveBadge.titlePrefix === '' && aboveBadge.blockMarkup === '');
    test('Tint mode marks the row and keeps a screen-reader label', tintMarker.rowClass.includes('sm-promoted--tint') && tintMarker.titleSuffix.includes('sm-sr-only'));
    test('None mode and the default render no marker', hiddenMarker.rowClass === '' && hiddenMarker.titlePrefix === '' && defaultMarker.titlePrefix === '');
    test('Unpromoted results never get a marker', unpromotedMarker.rowClass === '' && unpromotedMarker.titlePrefix === '' && unpromotedMarker.blockMarkup === '');

    const { sanitizeUrl, getHitHighlightTerms, highlightMatches, parseQueryTerms } = loadHighlighterModule();
    const emptyMatchedTermsHit = { matchedTerms: { title: [], content: [] } };
    const crossMatchedTermsHit = { matchedTerms: { title: ['search'], content: ['search'] } };
    const titleScopedTitleTerms = getHitHighlightTerms(emptyMatchedTermsHit, 'title', 'title:search');
    const titleScopedSnippetTerms = getHitHighlightTerms(crossMatchedTermsHit, 'snippet', 'title:search');
    test('Title-scoped query terms highlight title only', titleScopedTitleTerms.join(',') === 'search' && titleScopedSnippetTerms.length === 0);
    test('Title-scoped empty snippet terms do not fall back to the raw query', !highlightMatches('search body', 'title:search', { terms: titleScopedSnippetTerms }).includes('<mark'));

    const contentScopedTitleTerms = getHitHighlightTerms(crossMatchedTermsHit, 'title', 'content:search');
    const contentScopedSnippetTerms = getHitHighlightTerms(emptyMatchedTermsHit, 'snippet', 'content:search');
    test('Content-scoped query terms highlight content only', contentScopedTitleTerms.length === 0 && contentScopedSnippetTerms.join(',') === 'search');
    test('Content-scoped empty title terms do not fall back to the raw query', !highlightMatches('Search title', 'content:search', { terms: contentScopedTitleTerms }).includes('<mark'));

    const bareTitleTerms = getHitHighlightTerms(emptyMatchedTermsHit, 'title', 'search');
    const bareSnippetTerms = getHitHighlightTerms(emptyMatchedTermsHit, 'snippet', 'search');
    test('Bare query terms remain eligible for title and content', bareTitleTerms.join(',') === 'search' && bareSnippetTerms.join(',') === 'search');

    const exactFirstHit = { matchedTerms: { title: [], content: ['test'] }, matchedPhrases: [] };
    test(
        'Hit-aware result painting consumes exact-first backend metadata',
        highlightMatches('TEST TEXT', 'test', {
            terms: getHitHighlightTerms(exactFirstHit, 'snippet', 'test'),
        }) === '<mark class="sm-highlight">TEST</mark> TEXT',
    );
    const typoHit = { matchedTerms: { title: ['jacket'], content: [] }, matchedPhrases: [] };
    test(
        'Hit-aware result painting preserves backend-confirmed typo correction',
        highlightMatches('JACKET', 'jaket', {
            terms: getHitHighlightTerms(typoHit, 'title', 'jaket'),
        }) === '<mark class="sm-highlight">JACKET</mark>',
    );

    test(
        'Prefix extensions paint only the raw query prefix at word starts',
        highlightMatches('Testing Tools', 'test tool', { terms: ['testing', 'tools'] })
            === '<mark class="sm-highlight">Test</mark>ing <mark class="sm-highlight">Tool</mark>s',
    );
    test(
        'Exact and typo matches paint the whole word',
        highlightMatches('Testing jacket', 'test testing jaket', { terms: ['testing', 'jacket'] })
            === '<mark class="sm-highlight">Testing</mark> <mark class="sm-highlight">jacket</mark>',
    );
    test(
        'Mid-word occurrences never paint',
        highlightMatches('stop', 'to') === 'stop',
    );
    test(
        'Prefix painting is case and accent insensitive',
        highlightMatches('Caféteria', 'cafe') === '<mark class="sm-highlight">Café</mark>teria'
        && highlightMatches('Cafe\u0301teria', 'cafe') === '<mark class="sm-highlight">Cafe\u0301</mark>teria',
    );
    const prefixScopedHit = { matchedTerms: { title: ['testing'], content: ['tools'] } };
    test(
        'Prefix painting runs after field-scope filtering',
        highlightMatches('Testing Tools', 'title:test content:tool', {
            terms: getHitHighlightTerms(prefixScopedHit, 'title', 'title:test content:tool'),
        }) === '<mark class="sm-highlight">Test</mark>ing Tools'
        && highlightMatches('Testing Tools', 'title:test content:tool', {
            terms: getHitHighlightTerms(prefixScopedHit, 'snippet', 'title:test content:tool'),
        }) === 'Testing <mark class="sm-highlight">Tool</mark>s',
    );

    const splitHeadingHit = {
        matchedTerms: { title: ['search'], content: ['phrase'] },
        matchedPhrases: [],
    };
    test(
        'Displayed split headings project matched title and content terms that occur in the heading',
        getHitHighlightTerms(splitHeadingHit, 'heading', 'phrase search', 'Phrase search').join(',') === 'search,phrase'
        && highlightMatches('Phrase search', 'phrase search', {
            terms: getHitHighlightTerms(splitHeadingHit, 'heading', 'phrase search', 'Phrase search'),
        }) === '<mark class="sm-highlight">Phrase</mark> <mark class="sm-highlight">search</mark>',
    );

    const builtInHit = {
        matchedTerms: { title: ['backends'], content: ['built'] },
        matchedPhrases: [],
    };
    test(
        'Default AND heading projection uses effective matched terms after hyphen tokenization and stop-word removal',
        highlightMatches('Built-in backends', 'built-in backends', {
            terms: getHitHighlightTerms(builtInHit, 'heading', 'built-in backends', 'Built-in backends'),
        }) === '<mark class="sm-highlight">Built</mark>-in <mark class="sm-highlight">backends</mark>',
    );
    test(
        'Explicit AND and localized AND preserve the same per-hit heading projection',
        ['built AND backends', 'built UND backends'].every(query => (
            highlightMatches('Built-in backends', query, {
                terms: getHitHighlightTerms(builtInHit, 'heading', query, 'Built-in backends'),
            }) === '<mark class="sm-highlight">Built</mark>-in <mark class="sm-highlight">backends</mark>'
        )),
    );

    const quotedHeadingHit = {
        matchedTerms: { title: [], content: [] },
        matchedPhrases: ['built-in backends'],
    };
    test(
        'Quoted split-heading phrases retain one contiguous phrase highlight',
        highlightMatches('Built-in backends', '"built-in backends"', {
            terms: getHitHighlightTerms(quotedHeadingHit, 'heading', '"built-in backends"', 'Built-in backends'),
        }) === '<mark class="sm-highlight">Built-in backends</mark>',
    );

    const orFirstHit = { matchedTerms: { title: ['alpha'], content: [] }, matchedPhrases: [] };
    const orSecondHit = { matchedTerms: { title: [], content: ['beta'] }, matchedPhrases: [] };
    test(
        'OR heading projection highlights only the terms matched by each hit',
        highlightMatches('Alpha beta', 'alpha OR beta', {
            terms: getHitHighlightTerms(orFirstHit, 'heading', 'alpha OR beta', 'Alpha beta'),
        }) === '<mark class="sm-highlight">Alpha</mark> beta'
        && highlightMatches('Alpha beta', 'alpha OR beta', {
            terms: getHitHighlightTerms(orSecondHit, 'heading', 'alpha OR beta', 'Alpha beta'),
        }) === 'Alpha <mark class="sm-highlight">beta</mark>',
    );

    const excludedHeadingHit = { matchedTerms: { title: ['public'], content: [] }, matchedPhrases: [] };
    test(
        'Excluded NOT terms are never projected into split headings',
        highlightMatches('Public draft', 'public NOT draft', {
            terms: getHitHighlightTerms(excludedHeadingHit, 'heading', 'public NOT draft', 'Public draft'),
        }) === '<mark class="sm-highlight">Public</mark> draft',
    );

    const scopedHeadingHit = { matchedTerms: { title: ['parent'], content: ['child'] }, matchedPhrases: [] };
    test(
        'Explicit title and content scopes remain restrictive for displayed split headings',
        getHitHighlightTerms(scopedHeadingHit, 'heading', 'title:parent', 'Parent child').length === 0
        && highlightMatches('Parent child', 'content:child', {
            terms: getHitHighlightTerms(scopedHeadingHit, 'heading', 'content:child', 'Parent child'),
        }) === 'Parent <mark class="sm-highlight">child</mark>',
    );
    const mixedScopeCases = [
        {
            query: 'title:parent child',
            expectedTerms: 'child',
            expectedHtml: 'Parent <mark class="sm-highlight">child</mark>',
        },
        {
            query: 'parent title:child',
            expectedTerms: 'parent',
            expectedHtml: '<mark class="sm-highlight">Parent</mark> child',
        },
        {
            query: 'content:parent child',
            expectedTerms: 'child',
            expectedHtml: 'Parent <mark class="sm-highlight">child</mark>',
        },
        {
            query: 'title:parent content:child',
            expectedTerms: 'child',
            expectedHtml: 'Parent <mark class="sm-highlight">child</mark>',
        },
        {
            query: 'title:parent parent',
            expectedTerms: 'parent',
            expectedHtml: '<mark class="sm-highlight">Parent</mark> child',
        },
    ];
    test(
        'Mixed scoped and unscoped heading terms preserve term-level provenance',
        mixedScopeCases.every(({ query, expectedTerms, expectedHtml }) => {
            const terms = getHitHighlightTerms(scopedHeadingHit, 'heading', query, 'Parent child');
            return terms.join(',') === expectedTerms
                && highlightMatches('Parent child', query, { terms }) === expectedHtml;
        }),
    );

    const expansionCases = [
        {
            label: 'boost',
            text: 'Ranking guide',
            query: 'ranking^2',
            hit: { matchedTerms: { title: [], content: ['ranking'] }, matchedPhrases: [] },
            expected: '<mark class="sm-highlight">Ranking</mark> guide',
        },
        {
            label: 'wildcard prefix',
            text: 'Testing guide',
            query: 'test*',
            hit: { matchedTerms: { title: [], content: ['testing'] }, matchedPhrases: [] },
            expected: '<mark class="sm-highlight">Test</mark>ing guide',
        },
        {
            label: 'typo fuzzy expansion',
            text: 'Jacket guide',
            query: 'jaket',
            hit: { matchedTerms: { title: [], content: ['jacket'] }, matchedPhrases: [] },
            expected: '<mark class="sm-highlight">Jacket</mark> guide',
        },
        {
            label: 'case and accent folding',
            text: 'CAFÉ guide',
            query: 'cafe',
            hit: { matchedTerms: { title: [], content: ['café'] }, matchedPhrases: [] },
            expected: '<mark class="sm-highlight">CAFÉ</mark> guide',
        },
    ];
    test(
        'Boost, wildcard, fuzzy, case, and accent families retain matched heading provenance',
        expansionCases.every(({ text, query, hit, expected }) => (
            highlightMatches(text, query, {
                terms: getHitHighlightTerms(hit, 'heading', query, text),
            }) === expected
        )),
    );

    test(
        'Missing matched-term metadata retains raw-query heading fallback',
        highlightMatches('Fallback heading', 'fallback', {
            terms: getHitHighlightTerms({}, 'heading', 'fallback', 'Fallback heading'),
        }) === '<mark class="sm-highlight">Fallback</mark> heading',
    );
    test(
        'Raw highlighting fallback excludes English and localized NOT operands',
        parseQueryTerms('public NOT draft').join(',') === 'public'
        && parseQueryTerms('offen NICHT entwurf', null, 'de').join(',') === 'offen'
        && highlightMatches('Public draft', 'public NOT draft') === '<mark class="sm-highlight">Public</mark> draft',
    );
    const localizedBooleanCases = [
        { language: 'en', and: 'AND', or: 'OR', not: 'NOT' },
        { language: 'de', and: 'UND', or: 'ODER', not: 'NICHT' },
        { language: 'fr', and: 'ET', or: 'OU', not: 'SAUF' },
        { language: 'es', and: 'Y', or: 'O', not: 'NO' },
        { language: 'nl', and: 'EN', or: 'OF', not: 'NIET' },
        { language: 'it', and: 'E', or: 'O', not: 'NON' },
        { language: 'pt', and: 'E', or: 'OU', not: 'NÃO' },
        { language: 'sv', and: 'OCH', or: 'ELLER', not: 'INTE' },
        { language: 'da', and: 'OG', or: 'ELLER', not: 'IKKE' },
        { language: 'no', and: 'OG', or: 'ELLER', not: 'IKKJE' },
        { language: 'ja', and: 'かつ', or: 'もしくは', not: 'ではない' },
        { language: 'ar', and: 'و', or: 'او', not: 'لا' },
    ];
    test(
        'All supported result languages recognize representative AND, OR, and NOT operators',
        localizedBooleanCases.every(({ language, and, or, not }) => (
            parseQueryTerms(`alpha ${and} beta`, null, language).join(',') === 'alpha,beta'
            && parseQueryTerms(`alpha ${or} beta`, null, language).join(',') === 'alpha,beta'
            && parseQueryTerms(`alpha ${not} beta`, null, language).join(',') === 'alpha'
        )),
    );
    test(
        'Localized boolean variants follow normalized regional result languages',
        parseQueryTerms('aberto NAO rascunho', null, 'pt_BR').join(',') === 'aberto'
        && parseQueryTerms('openbaar NIET concept', null, 'nl-NL').join(',') === 'openbaar'
        && parseQueryTerms('公開 または 下書き', null, 'ja-JP').join(',') === '公開,下書き'
        && parseQueryTerms('عام أو مسودة', null, 'ar-EG').join(',') === 'عام,مسودة',
    );
    test(
        'Ambiguous common and one-letter localized operators remain terms outside their language',
        parseQueryTerms('alpha Y beta', null, 'en').join(',') === 'alpha,Y,beta'
        && parseQueryTerms('alpha O beta', null, 'en').join(',') === 'alpha,O,beta'
        && parseQueryTerms('alpha NO beta', null, 'en').join(',') === 'alpha,NO,beta'
        && parseQueryTerms('alpha E beta', null, 'en').join(',') === 'alpha,E,beta'
        && parseQueryTerms('alpha EN beta', null, 'de').join(',') === 'alpha,EN,beta'
        && parseQueryTerms('alpha OF beta', null, 'de').join(',') === 'alpha,OF,beta'
        && parseQueryTerms('alpha NIET beta', null, 'de').join(',') === 'alpha,NIET,beta',
    );
    test(
        'Quoted phrases retain explicit display-field scope in raw fallback',
        parseQueryTerms('title:"parent guide" content:"child guide"', 'title').join(',') === 'parent guide'
        && parseQueryTerms('title:"parent guide" content:"child guide"', 'content').join(',') === 'child guide',
    );

    const testToolSource = fs.readFileSync(path.join(__dirname, '..', 'testtool', 'src', 'test-tool.js'), 'utf8');
    test('CP test tool delegates field scope to the shared highlighter rule', testToolSource.includes('return SearchManagerHighlighter.getHitTerms(hit, area, query);'));
    const { renderRecentlyViewed } = loadRendererModule();
    test('Dangerous URL schemes are neutralized', sanitizeUrl('javascript:alert(1)') === '#' && sanitizeUrl('JaVa\tScRiPt:alert(1)') === '#' && sanitizeUrl('data:text/html,x') === '#' && sanitizeUrl('vbscript:x') === '#' && sanitizeUrl('file:///etc/passwd') === '#');
    test('Safe URLs pass the scheme guard unchanged', sanitizeUrl('/docs/page#anchor') === '/docs/page#anchor' && sanitizeUrl('https://example.com/a?b=1') === 'https://example.com/a?b=1' && sanitizeUrl('mailto:a@b.com') === 'mailto:a@b.com');
    const hostileRecent = renderRecentlyViewed([{ query: 'x', title: 'X', url: 'javascript:alert(1)' }], 'recent-list', {});
    test('Recently viewed entries neutralize dangerous stored URLs', hostileRecent.includes('data-url="#"') && !hostileRecent.includes('javascript:'));

    const navigatorSource = fs.readFileSync(path.join(SRC_DIR, 'modules', 'KeyboardNavigator.js'), 'utf8');
    test('Hover selection reacts to pointer movement, not scroll-induced mouseenter', navigatorSource.includes("addEventListener('mousemove'") && !navigatorSource.includes("addEventListener('mouseenter'"));
    const splitHits = [
        {
            elementId: 101,
            siteId: 1,
            backendId: '101_1_install',
            title: 'Guide A',
            url: '/guide-a',
            source: 'Docs',
            type: 'source-doc',
            sectionType: 'heading',
            sectionId: 'install',
            sectionTitle: 'Install',
            sectionLevel: 2,
            sectionUrl: '/guide-a#install',
            sectionIndex: 1,
            snippet: 'Install snippet',
            score: 20,
            index: 'docs',
        },
        {
            elementId: 101,
            siteId: 1,
            backendId: '101_1_low',
            title: 'Guide A',
            url: '/guide-a',
            source: 'Docs',
            type: 'source-doc',
            sectionType: 'heading',
            sectionId: 'low',
            sectionTitle: 'Low Score H3',
            sectionLevel: 3,
            sectionUrl: '/guide-a#low',
            sectionIndex: 2,
            snippet: 'Low score snippet',
            score: 1,
            index: 'docs',
        },
        {
            elementId: 101,
            siteId: 1,
            backendId: '101_1_advanced',
            title: 'Guide A',
            url: '/guide-a',
            source: 'Docs',
            type: 'source-doc',
            sectionType: 'heading',
            sectionId: 'advanced',
            sectionTitle: 'Advanced',
            sectionLevel: 3,
            sectionUrl: '/guide-a#advanced',
            sectionIndex: 3,
            snippet: 'Advanced snippet',
            score: 30,
            index: 'docs',
        },
        {
            elementId: 101,
            siteId: 1,
            backendId: '101_1_intro',
            title: 'Guide A',
            url: '/guide-a',
            source: 'Docs',
            type: 'source-doc',
            sectionType: 'intro',
            sectionId: 'intro',
            sectionTitle: 'Guide A',
            sectionUrl: '/guide-a',
            sectionIndex: 0,
            snippet: 'Intro snippet only',
            score: 4,
            index: 'docs',
        },
        {
            elementId: 202,
            siteId: 1,
            backendId: '202_1_intro',
            title: 'Guide B',
            url: '/guide-b',
            source: 'Docs',
            type: 'source-doc',
            sectionType: 'intro',
            sectionId: 'intro',
            sectionTitle: 'Guide B',
            sectionUrl: '/guide-b',
            sectionIndex: 0,
            snippet: 'Guide B intro',
            score: 50,
            index: 'docs',
        },
    ];

    const hierarchicalHtml = renderResults(splitHits, 'install', {
        resultsLayout: 'hierarchical',
        listboxId: 'split-list',
        hierarchyMaxHeadings: 2,
    });

    test('Split hierarchy orders page groups by best section score', hierarchicalHtml.indexOf('Guide B') < hierarchicalHtml.indexOf('Guide A'));
    test('Split hierarchy uses intro snippet for the page node', hierarchicalHtml.includes('Intro snippet only'));
    test('Split hierarchy keeps highest-scoring heading children before restoring section order', hierarchicalHtml.indexOf('Install') < hierarchicalHtml.indexOf('Advanced') && !hierarchicalHtml.includes('Low Score H3'));
    test('Split hierarchy nests h3 children under h2 in tree mode', hierarchicalHtml.includes('sm-hierarchy-depth-1') && hierarchicalHtml.includes('data-id="101_1_advanced" data-element-id="101"'));

    const noIntroHtml = renderResults([{
        elementId: 303,
        siteId: 1,
        backendId: '303_1_child',
        title: 'No Intro Page',
        url: '/no-intro',
        source: 'Docs',
        type: 'source-doc',
        sectionType: 'heading',
        sectionId: 'child',
        sectionTitle: 'Child',
        sectionLevel: 2,
        sectionUrl: '/no-intro#child',
        sectionIndex: 1,
        snippet: 'Child snippet must stay child-only',
        score: 10,
        index: 'docs',
    }], 'child', {
        resultsLayout: 'hierarchical',
        listboxId: 'no-intro-list',
        hierarchyMaxHeadings: 3,
    });
    const noIntroParentHtml = noIntroHtml.split('<div class="sm-hierarchy-children"')[0] || '';
    test('Split hierarchy does not borrow child snippet for page node without intro hit', !noIntroParentHtml.includes('sm-result-desc') && noIntroHtml.includes('snippet must stay'));

    const promotedPageHtml = renderResults([{
        elementId: 707,
        siteId: 1,
        backendId: '707_1_promoted-page',
        title: 'Promoted Guide',
        url: '/promoted-guide',
        source: 'Docs',
        type: 'source-doc',
        sectionType: 'promoted-page',
        sectionId: 'promoted-page',
        sectionTitle: 'Promoted Guide',
        sectionUrl: '/promoted-guide',
        sectionIndex: 0,
        snippet: null,
        score: null,
        promoted: true,
        index: 'docs',
    }], 'guide', {
        resultsLayout: 'hierarchical',
        listboxId: 'promoted-list',
        hierarchyMaxHeadings: 3,
        promotionDisplay: 'badge',
        promotionBadgeText: 'Promoted',
    });
    test('Split promoted-page hits render as page-level hierarchy rows', promotedPageHtml.includes('Promoted Guide') && promotedPageHtml.includes('sm-hierarchy-parent') && !promotedPageHtml.includes('sm-hierarchy-children'));
    test('Split promoted-page hits keep backendId DOM identity and elementId analytics identity', promotedPageHtml.includes('data-id="707_1_promoted-page" data-element-id="707"'));
    test('Hierarchy parents inherit the promoted flag and render the marker', promotedPageHtml.includes('sm-hierarchy-parent sm-promoted') && promotedPageHtml.includes('sm-promoted-badge'));

    const flatSectionHtml = renderResults([splitHits[0]], 'install', {
        resultsLayout: 'default',
        listboxId: 'flat-list',
    });
    test('Flat section hits render section title and section URL', flatSectionHtml.includes('Install') && flatSectionHtml.includes('href="/guide-a#install'));
    test('Flat section hits use backendId for DOM identity and elementId for analytics identity', flatSectionHtml.includes('data-id="101_1_install" data-element-id="101"'));

    const headingProjectionHit = {
        elementId: 808,
        siteId: 1,
        backendId: '808_1_phrase-search',
        title: 'Search features',
        url: '/search-features',
        source: 'Docs',
        type: 'source-doc',
        sectionType: 'heading',
        sectionId: 'phrase-search',
        sectionTitle: 'Phrase search',
        sectionLevel: 2,
        sectionUrl: '/search-features#phrase-search',
        sectionIndex: 1,
        snippet: 'The phrase matcher searches content only.',
        score: 42,
        index: 'docs',
        matchedTerms: { title: ['search'], content: ['phrase'] },
        matchedPhrases: [],
    };
    const flatHeadingProjectionHtml = renderResults([headingProjectionHit], 'phrase search', {
        resultsLayout: 'default',
        listboxId: 'flat-heading-projection',
        highlightDestinationPersistQuery: true,
    });
    test(
        'Flat split rows highlight every matched displayed heading term without changing snippets, URLs, or identities',
        flatHeadingProjectionHtml.includes('<mark class="sm-highlight">Phrase</mark> <mark class="sm-highlight">search</mark>')
        && flatHeadingProjectionHtml.includes('The <mark class="sm-highlight">phrase</mark> matcher searches content only.')
        && flatHeadingProjectionHtml.includes('href="/search-features?smq=phrase+search#phrase-search"')
        && flatHeadingProjectionHtml.includes('data-id="808_1_phrase-search" data-element-id="808"'),
    );

    const hierarchyHeadingProjectionHtml = renderResults([
        {
            ...headingProjectionHit,
            sectionTitle: 'Phrase search',
            sectionId: 'phrase-search',
            sectionLevel: 2,
            sectionIndex: 1,
        },
        {
            ...headingProjectionHit,
            backendId: '808_1_backend-search',
            sectionTitle: 'Backend search',
            sectionId: 'backend-search',
            sectionLevel: 3,
            sectionUrl: '/search-features#backend-search',
            sectionIndex: 2,
            score: 40,
            matchedTerms: { title: ['search'], content: ['backend'] },
        },
    ], 'phrase search OR backend', {
        resultsLayout: 'hierarchical',
        listboxId: 'hierarchy-heading-projection',
        hierarchyMaxHeadings: 3,
    });
    test(
        'Hierarchical H2 and H3 children use each section hit\'s own matched heading projection',
        hierarchyHeadingProjectionHtml.includes('<mark class="sm-highlight">Phrase</mark> <mark class="sm-highlight">search</mark>')
        && hierarchyHeadingProjectionHtml.includes('<mark class="sm-highlight">Backend</mark> <mark class="sm-highlight">search</mark>')
        && hierarchyHeadingProjectionHtml.includes('sm-hierarchy-level-2')
        && hierarchyHeadingProjectionHtml.includes('sm-hierarchy-level-3'),
    );

    const localizedHierarchyHtml = renderResults([{
        ...headingProjectionHit,
        language: 'nl-NL',
        backendId: '808_1_openbaar-concept',
        sectionTitle: 'Openbaar NIET concept',
        sectionId: 'openbaar-concept',
        sectionUrl: '/search-features#openbaar-concept',
        matchedTerms: { title: [], content: [] },
    }], 'openbaar NIET concept', {
        resultsLayout: 'hierarchical',
        listboxId: 'localized-hierarchy-projection',
        hierarchyMaxHeadings: 3,
    });
    test(
        'Hierarchical projection preserves child result language for localized boolean fallback',
        localizedHierarchyHtml.includes('<mark class="sm-highlight">Openbaar</mark> NIET concept')
        && !localizedHierarchyHtml.includes('<mark class="sm-highlight">NIET</mark>')
        && !localizedHierarchyHtml.includes('<mark class="sm-highlight">concept</mark>'),
    );

    const pageModeHtml = renderResults([{
        elementId: 404,
        backendId: '404_1',
        title: 'Plain Page',
        url: '/plain',
        entrySection: 'Pages',
        snippet: 'Plain snippet',
    }], 'plain', {
        resultsLayout: 'default',
        listboxId: 'plain-list',
    });
    test('Page-mode hits use backendId DOM identity and elementId analytics identity', pageModeHtml.includes('data-id="404_1" data-element-id="404"'));

    const nonSplitControlHtml = renderResults([{
        elementId: 909,
        backendId: '909_1',
        title: 'Search reference',
        url: '/reference',
        entrySection: 'Pages',
        snippet: 'Phrase details stay in the snippet.',
        matchedTerms: { title: ['search'], content: ['phrase'] },
        matchedPhrases: [],
    }], 'phrase search', {
        resultsLayout: 'default',
        listboxId: 'non-split-control',
    });
    test(
        'Non-split page titles and snippets retain their field-specific highlighting',
        nonSplitControlHtml.includes('<mark class="sm-highlight">Search</mark> reference')
        && nonSplitControlHtml.includes('<mark class="sm-highlight">Phrase</mark> details stay in the snippet.')
        && !nonSplitControlHtml.includes('<mark class="sm-highlight">reference</mark>'),
    );

    const mixedHtml = renderResults([splitHits[0], {
        elementId: 505,
        backendId: '505_1',
        title: 'Mixed Plain Page',
        url: '/mixed',
        entrySection: 'Pages',
        snippet: 'Mixed plain snippet',
        score: 5,
    }], 'mixed', {
        resultsLayout: 'hierarchical',
        listboxId: 'mixed-list',
        hierarchyMaxHeadings: 3,
    });
    test('Mixed hierarchical results render split and page-mode shapes together', mixedHtml.includes('Guide A') && mixedHtml.includes('Mixed Plain Page'));
} catch (error) {
    console.error(error);
    test('Renderer split-hit tests execute', false);
}

async function waitForWidgets(page, ids) {
    await page.waitForFunction((widgetIds) => widgetIds.every((id) => {
        const widget = document.getElementById(id);
        return widget?.shadowRoot?.querySelector('.sm-trigger');
    }), ids);
}

async function getWidgetStates(page, ids) {
    return page.evaluate((widgetIds) => {
        const states = {
            bodyOverflow: document.body.style.overflow,
        };

        for (const id of widgetIds) {
            const widget = document.getElementById(id);
            const backdrop = widget.shadowRoot.querySelector('.sm-backdrop');
            const trigger = widget.shadowRoot.querySelector('.sm-trigger');

            states[id] = {
                open: widget.state.get('isOpen') === true && backdrop.hidden === false,
                expanded: trigger.getAttribute('aria-expanded'),
            };
        }

        return states;
    }, ids);
}

async function dispatchSharedHotkey(page) {
    await page.evaluate(() => {
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'k',
            ctrlKey: true,
            metaKey: true,
            bubbles: true,
            cancelable: true,
        }));
    });
}

async function runWidgetInstanceBehaviorTests() {
    if (!fs.existsSync(mainFile)) {
        test('Widget instance behavior tests can load dist file', false);
        return;
    }

    let browser = null;

    try {
        browser = await chromium.launch();
        const page = await browser.newPage();

        const { renderResults } = loadRendererModule();
        const browserPageHtml = renderResults([{
            elementId: 1001,
            backendId: '1001_1',
            title: 'Search features',
            url: '/search-features',
            snippet: 'Phrase overview',
            matchedTerms: { title: ['search'], content: ['phrase'] },
            matchedPhrases: [],
        }], 'phrase search', {
            listboxId: 'browser-page',
        });
        const browserSectionHit = {
            elementId: 1001,
            siteId: 1,
            backendId: '1001_1_phrase-search',
            title: 'Search features',
            url: '/search-features',
            source: 'Docs',
            sectionType: 'heading',
            sectionId: 'phrase-search',
            sectionTitle: 'Phrase search',
            sectionLevel: 2,
            sectionUrl: '/search-features#phrase-search',
            sectionIndex: 1,
            snippet: 'Phrase overview',
            score: 20,
            matchedTerms: { title: ['search'], content: ['phrase'] },
            matchedPhrases: [],
        };
        const browserFlatHtml = renderResults([browserSectionHit], 'phrase search', {
            listboxId: 'browser-flat',
        });
        const browserHierarchyHtml = renderResults([
            browserSectionHit,
            {
                ...browserSectionHit,
                backendId: '1001_1_backend-search',
                sectionId: 'backend-search',
                sectionTitle: 'Backend search',
                sectionLevel: 3,
                sectionUrl: '/search-features#backend-search',
                sectionIndex: 2,
                score: 18,
                matchedTerms: { title: ['search'], content: ['backend'] },
            },
        ], 'phrase search OR backend', {
            resultsLayout: 'hierarchical',
            hierarchyMaxHeadings: 3,
            listboxId: 'browser-hierarchy',
        });

        await page.setContent(`
            <!doctype html>
            <html>
                <body>
                    <div id="browser-page">${browserPageHtml}</div>
                    <div id="browser-flat">${browserFlatHtml}</div>
                    <div id="browser-hierarchy">${browserHierarchyHtml}</div>
                </body>
            </html>
        `);
        const browserHeadingProjection = await page.evaluate(() => ({
            pageMarks: Array.from(document.querySelectorAll('#browser-page .sm-result-title mark')).map(mark => mark.textContent),
            flatMarks: Array.from(document.querySelectorAll('#browser-flat .sm-result-title mark')).map(mark => mark.textContent),
            hierarchyMarks: Array.from(document.querySelectorAll('#browser-hierarchy .sm-hierarchy-child .sm-result-title mark')).map(mark => mark.textContent),
            hierarchyLevels: Array.from(document.querySelectorAll('#browser-hierarchy .sm-hierarchy-child')).map(row => (
                row.classList.contains('sm-hierarchy-level-2') ? 2 : 3
            )),
        }));
        test('Browser rendering keeps page/H1 title highlighting field-specific',
            browserHeadingProjection.pageMarks.join(',') === 'Search');
        test('Browser rendering highlights both matched terms in a flat split heading',
            browserHeadingProjection.flatMarks.join(',') === 'Phrase,search');
        test('Browser rendering applies per-hit projection to hierarchical H2 and H3 headings',
            browserHeadingProjection.hierarchyMarks.join(',') === 'Phrase,search,Backend,search'
            && browserHeadingProjection.hierarchyLevels.join(',') === '2,3');

        await page.setContent(`
            <!doctype html>
            <html>
                <body>
                    <search-modal id="widget-a" trigger-hotkey="k"></search-modal>
                    <search-modal id="widget-b" trigger-hotkey="k"></search-modal>
                </body>
            </html>
        `);
        await page.addScriptTag({ path: mainFile });
        await waitForWidgets(page, ['widget-a', 'widget-b']);

        await page.evaluate(() => {
            document.getElementById('widget-a').shadowRoot.querySelector('.sm-trigger').click();
        });
        let states = await getWidgetStates(page, ['widget-a', 'widget-b']);
        test('Opening first widget locks body scroll', states['widget-a'].open && !states['widget-b'].open && states.bodyOverflow === 'hidden');

        await page.evaluate(() => {
            document.getElementById('widget-b').shadowRoot.querySelector('.sm-trigger').click();
        });
        states = await getWidgetStates(page, ['widget-a', 'widget-b']);
        test('Opening second widget closes first and keeps body scroll locked', !states['widget-a'].open && states['widget-b'].open && states.bodyOverflow === 'hidden');
        test('Replacing widgets updates trigger aria-expanded state', states['widget-a'].expanded === 'false' && states['widget-b'].expanded === 'true');

        await dispatchSharedHotkey(page);
        states = await getWidgetStates(page, ['widget-a', 'widget-b']);
        test('Shared hotkey closes the active widget without opening another instance', !states['widget-a'].open && !states['widget-b'].open && states.bodyOverflow === '');

        await dispatchSharedHotkey(page);
        states = await getWidgetStates(page, ['widget-a', 'widget-b']);
        test('Shared hotkey opens one matching widget when none are open', states['widget-a'].open && !states['widget-b'].open && states.bodyOverflow === 'hidden');

        await page.evaluate(() => {
            document.getElementById('widget-b').open({ source: 'test' });
        });
        states = await getWidgetStates(page, ['widget-a', 'widget-b']);
        test('Programmatic open replaces the currently open widget', !states['widget-a'].open && states['widget-b'].open && states.bodyOverflow === 'hidden');

        await page.setContent(`
            <!doctype html>
            <html>
                <body>
                    <search-modal id="solo-widget" trigger-hotkey="k"></search-modal>
                </body>
            </html>
        `);
        await waitForWidgets(page, ['solo-widget']);

        await page.evaluate(() => {
            document.getElementById('solo-widget').shadowRoot.querySelector('.sm-trigger').click();
        });
        states = await getWidgetStates(page, ['solo-widget']);
        const singleOpen = states['solo-widget'].open && states.bodyOverflow === 'hidden';

        await page.evaluate(() => {
            document.getElementById('solo-widget').shadowRoot.querySelector('.sm-trigger').click();
        });
        states = await getWidgetStates(page, ['solo-widget']);
        test('Single-instance trigger behavior still toggles open and closed', singleOpen && !states['solo-widget'].open && states.bodyOverflow === '');

        await page.setContent(`
            <!doctype html>
            <html>
                <body>
                    <button id="external-trigger" type="button">External search</button>
                    <search-modal
                        id="live-widget"
                        trigger-hotkey="k"
                        trigger-enabled="false"
                        trigger-selector="#external-trigger"
                    ></search-modal>
                    <search-modal
                        id="style-widget"
                        trigger-hotkey="x"
                        trigger-enabled="false"
                        styles='{"modalBg":"#ffffff","modalBgDark":"#09090b","inputTextColor":"#18181b","inputTextColorDark":"#f4f4f5","modalMaxWidth":640}'
                    ></search-modal>
                    <search-modal id="registry-widget" trigger-hotkey="j"></search-modal>
                </body>
            </html>
        `);
        await waitForWidgets(page, ['live-widget', 'style-widget', 'registry-widget']);

        await page.evaluate(() => {
            document.getElementById('live-widget').setAttribute('theme', 'dark');
            document.getElementById('external-trigger').click();
        });
        states = await getWidgetStates(page, ['live-widget', 'registry-widget']);
        test('Theme attribute updates keep the external trigger attached', states['live-widget'].open && states.bodyOverflow === 'hidden');

        const themeStyleStates = await page.evaluate(async () => {
            const widget = document.getElementById('style-widget');
            const readVars = () => ({
                modalBg: widget.style.getPropertyValue('--sm-modal-bg').trim(),
                modalBgDark: widget.style.getPropertyValue('--sm-modal-bg-dark').trim(),
                inputColor: widget.style.getPropertyValue('--sm-input-color').trim(),
                inputColorDark: widget.style.getPropertyValue('--sm-input-color-dark').trim(),
                width: widget.style.getPropertyValue('--sm-modal-width').trim(),
            });
            const nextFrame = () => new Promise(resolve => requestAnimationFrame(resolve));

            const initial = readVars();
            widget.setAttribute('theme', 'dark');
            await nextFrame();
            const dark = readVars();

            widget.setAttribute('theme', 'light');
            await nextFrame();
            const light = readVars();

            widget.setAttribute('theme', 'dark');
            await nextFrame();
            const darkAgain = readVars();

            return { initial, dark, light, darkAgain };
        });

        test('Theme style switch removes stale light inline vars when dark is active',
            themeStyleStates.initial.modalBg === '#ffffff'
            && themeStyleStates.dark.modalBg === ''
            && themeStyleStates.dark.inputColor === ''
            && themeStyleStates.dark.modalBgDark === '#09090b'
            && themeStyleStates.dark.inputColorDark === '#f4f4f5');
        test('Theme style switch removes stale dark inline vars when light is active',
            themeStyleStates.light.modalBg === '#ffffff'
            && themeStyleStates.light.inputColor === '#18181b'
            && themeStyleStates.light.modalBgDark === ''
            && themeStyleStates.light.inputColorDark === '');
        test('Theme-neutral style vars survive repeated theme switches',
            themeStyleStates.initial.width === '640px'
            && themeStyleStates.dark.width === '640px'
            && themeStyleStates.light.width === '640px'
            && themeStyleStates.darkAgain.width === '640px');
        test('Repeated light-dark-light-dark style toggles stay clean',
            themeStyleStates.darkAgain.modalBg === ''
            && themeStyleStates.darkAgain.inputColor === ''
            && themeStyleStates.darkAgain.modalBgDark === '#09090b'
            && themeStyleStates.darkAgain.inputColorDark === '#f4f4f5');

        await page.evaluate(() => {
            document.getElementById('live-widget').close({ source: 'test' });
            document.getElementById('live-widget').setAttribute('placeholder', 'Updated search');
            document.getElementById('external-trigger').click();
        });
        states = await getWidgetStates(page, ['live-widget', 'registry-widget']);
        test('Full attribute re-render keeps the external trigger attached', states['live-widget'].open && !states['registry-widget'].open && states.bodyOverflow === 'hidden');

        await page.evaluate(() => {
            document.getElementById('live-widget').close({ source: 'test' });
            document.getElementById('live-widget').setAttribute('placeholder', 'Hotkey search');
        });
        await dispatchSharedHotkey(page);
        states = await getWidgetStates(page, ['live-widget', 'registry-widget']);
        test('Full attribute re-render keeps the hotkey listener attached', states['live-widget'].open && !states['registry-widget'].open && states.bodyOverflow === 'hidden');

        const preserved = await page.evaluate(async () => {
            const widget = document.getElementById('live-widget');
            widget.shadowRoot.querySelector('.sm-input').value = 'alpha';
            widget.shadowRoot.querySelector('.sm-input').dispatchEvent(new Event('input', { bubbles: true }));
            widget.setAttribute('placeholder', 'Still open');
            await new Promise(resolve => requestAnimationFrame(resolve));

            return {
                open: widget.state.get('isOpen') === true && widget.shadowRoot.querySelector('.sm-backdrop').hidden === false,
                query: widget.state.get('query'),
                inputValue: widget.shadowRoot.querySelector('.sm-input').value,
                focused: widget.shadowRoot.activeElement === widget.shadowRoot.querySelector('.sm-input'),
                overflow: document.body.style.overflow,
            };
        });
        test('Attribute changes while open preserve modal state, query, focus, and scroll lock', preserved.open && preserved.query === 'alpha' && preserved.inputValue === 'alpha' && preserved.focused && preserved.overflow === 'hidden');

        await page.evaluate(() => {
            document.getElementById('registry-widget').open({ source: 'test' });
        });
        states = await getWidgetStates(page, ['live-widget', 'registry-widget']);
        test('Registry remains consistent after attribute-driven re-render', !states['live-widget'].open && states['registry-widget'].open && states.bodyOverflow === 'hidden');

        await page.evaluate(() => {
            document.getElementById('live-widget').setAttribute('placeholder', 'Closed after rebuild');
            document.getElementById('external-trigger').click();
        });
        states = await getWidgetStates(page, ['live-widget', 'registry-widget']);
        test('Single-instance replace behavior still works after attribute-driven re-render', states['live-widget'].open && !states['registry-widget'].open && states.bodyOverflow === 'hidden');

        // Focus containment across conditional controls and modal lifecycle.
        await page.setContent(`
            <!doctype html>
            <html>
                <body>
                    <button id="background" type="button">Background</button>
                    <search-modal id="focus-widget" search-min-chars="1" search-debounce-ms="0" recently-viewed-enabled="false"></search-modal>
                    <search-modal id="replacement-widget" trigger-hotkey="j"></search-modal>
                </body>
            </html>
        `);
        await waitForWidgets(page, ['focus-widget', 'replacement-widget']);
        await page.evaluate(() => {
            window.fetch = async () => ({
                ok: true,
                async json() {
                    return {
                        results: [{ id: 1, elementId: 1, title: 'Alpha result', url: '/alpha', source: 'Docs', index: 'docs' }],
                        meta: { cached: false, took: 2 },
                    };
                },
            });
            document.getElementById('background').focus();
            document.getElementById('focus-widget').open({ source: 'test' });
        });
        let focusState = false;
        try {
            await page.waitForFunction(() => {
                const widget = document.getElementById('focus-widget');
                return widget.shadowRoot.activeElement === widget.shadowRoot.querySelector('.sm-input');
            }, null, { timeout: 2000 });
            focusState = true;
        } catch (error) {
            if (error.name !== 'TimeoutError') {
                throw error;
            }
        }
        test('Modal open moves initial focus to the search input', focusState);

        await page.evaluate(() => {
            const widget = document.getElementById('focus-widget');
            const input = widget.shadowRoot.querySelector('.sm-input');
            input.value = 'alpha';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await page.waitForFunction(() => document.getElementById('focus-widget').shadowRoot.querySelector('.sm-result-item'));
        await page.keyboard.press('Shift+Tab');
        focusState = await page.evaluate(() => {
            const root = document.getElementById('focus-widget').shadowRoot;
            return root.activeElement === root.querySelector('.sm-footer-brand a')
                && root.querySelector('.sm-clear').hidden === false
                && Boolean(root.querySelector('.sm-result-item'));
        });
        test('Shift+Tab wraps first to last with clear, result, and footer controls present', focusState);

        await page.keyboard.press('Tab');
        focusState = await page.evaluate(() => {
            const root = document.getElementById('focus-widget').shadowRoot;
            return root.activeElement === root.querySelector('.sm-input');
        });
        test('Tab wraps last to first inside the open modal', focusState);

        focusState = await page.evaluate(() => {
            const background = document.getElementById('background');
            const widget = document.getElementById('focus-widget');
            background.focus();
            const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
            document.dispatchEvent(event);
            return event.defaultPrevented
                && widget.shadowRoot.activeElement === widget.shadowRoot.querySelector('.sm-input');
        });
        test('Open modal prevents background focus escape', focusState);

        await page.keyboard.press('Escape');
        focusState = await page.evaluate(() => {
            const widget = document.getElementById('focus-widget');
            const background = document.getElementById('background');
            const closedEvent = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
            document.dispatchEvent(closedEvent);
            return widget.state.get('isOpen') === false
                && document.activeElement === background
                && closedEvent.defaultPrevented === false;
        });
        test('Escape closes, restores focus, and leaves closed focus handling inert', focusState);

        focusState = await page.evaluate(() => {
            const first = document.getElementById('focus-widget');
            const replacement = document.getElementById('replacement-widget');
            first.open({ source: 'test' });
            replacement.open({ source: 'test' });
            document.getElementById('background').focus();
            const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
            document.dispatchEvent(event);
            const replacementOwnsFocus = replacement.shadowRoot.activeElement === replacement.shadowRoot.querySelector('.sm-input');
            replacement.close({ source: 'test', restoreFocus: false });
            first.remove();
            document.getElementById('background').focus();
            const disconnectedEvent = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
            document.dispatchEvent(disconnectedEvent);
            return !first.state.get('isOpen')
                && replacementOwnsFocus
                && disconnectedEvent.defaultPrevented === false;
        });
        test('Replacement and disconnection leave focus ownership with only the active widget', focusState);

        // Query ownership, live configuration, request headers, and analytics.
        await page.setContent(`
            <!doctype html>
            <html>
                <body>
                    <button id="query-trigger" type="button">Search</button>
                    <search-modal
                        id="query-widget"
                        trigger-enabled="false"
                        trigger-selector="#query-trigger"
                        search-min-chars="2"
                        search-debounce-ms="0"
                        analytics-idle-timeout-ms="60000"
                        api-key="old-key"
                        snippet-defaults='{"snippetMode":"early","snippetMaxLength":120}'
                    ></search-modal>
                </body>
            </html>
        `);
        await waitForWidgets(page, ['query-widget']);
        await page.evaluate(() => {
            window.__smRequests = [];
            window.fetch = (url, options = {}) => {
                const request = {
                    url: String(url),
                    headers: { ...(options.headers || {}) },
                    body: options.body || null,
                    signal: options.signal,
                };
                window.__smRequests.push(request);

                if (!request.url.includes('/api/search')) {
                    return Promise.resolve({ ok: true, async json() { return { success: true }; } });
                }

                return new Promise((resolve, reject) => {
                    request.succeed = (title = 'Result') => resolve({
                        ok: true,
                        async json() {
                            return {
                                results: [{ id: title, elementId: title, title, url: `/${title}`, source: 'Docs', index: 'docs' }],
                                meta: { cached: false, took: 3 },
                            };
                        },
                    });
                    request.fail = (message = 'Deferred failure') => reject(new Error(message));
                });
            };
            document.getElementById('query-trigger').click();
        });
        await page.waitForTimeout(20);

        const inputQuery = async (value) => {
            await page.evaluate((nextValue) => {
                const input = document.getElementById('query-widget').shadowRoot.querySelector('.sm-input');
                input.value = nextValue;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }, value);
            await page.waitForTimeout(10);
        };
        const resolveSearch = async (index, title) => {
            await page.evaluate(({ requestIndex, resultTitle }) => {
                window.__smRequests.filter(request => request.url.includes('/api/search'))[requestIndex].succeed(resultTitle);
            }, { requestIndex: index, resultTitle: title });
            await page.waitForTimeout(10);
        };
        const rejectSearch = async (index) => {
            await page.evaluate((requestIndex) => {
                window.__smRequests.filter(request => request.url.includes('/api/search'))[requestIndex].fail();
            }, index);
            await page.waitForTimeout(10);
        };

        await inputQuery('seed');
        await resolveSearch(0, 'Seed');
        await inputQuery('older');
        let queryState = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            return widget.state.get('query') === 'older'
                && widget.state.get('results').length === 0
                && widget.state.get('meta') === null
                && widget.state.get('error') === null
                && widget.shadowRoot.querySelectorAll('.sm-result-item').length === 0
                && window.__smRequests.filter(request => !request.url.includes('/api/search')).length === 0;
        });
        test('New debounced query removes stale links and attribution before its request settles', queryState);
        await inputQuery('');
        queryState = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            return {
                query: widget.state.get('query'),
                results: widget.state.get('results').length,
                meta: widget.state.get('meta'),
                error: widget.state.get('error'),
                loading: widget.state.get('loading'),
                selectedIndex: widget.state.get('selectedIndex'),
                linkCount: widget.shadowRoot.querySelectorAll('.sm-result-item').length,
                analyticsTimer: widget.analyticsIdleTimer,
                cacheState: widget.lastSearchCacheState,
                trackingCount: window.__smRequests.filter(request => !request.url.includes('/api/search')).length,
            };
        });
        test('Empty input clears prior-query UI, metadata, selection, loading, and analytics intent',
            queryState.query === '' && queryState.results === 0 && queryState.meta === null
            && queryState.error === null && queryState.loading === false && queryState.selectedIndex === -1
            && queryState.linkCount === 0 && queryState.analyticsTimer === null
            && queryState.cacheState === null && queryState.trackingCount === 0);
        await resolveSearch(1, 'Late empty success');
        queryState = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            return widget.state.get('query') === ''
                && widget.state.get('results').length === 0
                && widget.shadowRoot.querySelectorAll('.sm-result-item').length === 0;
        });
        test('Late success cannot resurrect results after empty input', queryState);

        await inputQuery('failure');
        await inputQuery('x');
        await rejectSearch(2);
        queryState = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            return widget.state.get('query') === 'x'
                && widget.state.get('results').length === 0
                && widget.state.get('error') === null
                && widget.state.get('loading') === false
                && widget.shadowRoot.querySelectorAll('.sm-result-item').length === 0;
        });
        test('Below-minimum input clears ownership and ignores a late failure', queryState);

        await inputQuery('older-valid');
        await inputQuery('newer-valid');
        await resolveSearch(4, 'Newer');
        await resolveSearch(3, 'Older');
        queryState = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            const requests = window.__smRequests.filter(request => request.url.includes('/api/search'));
            return widget.state.get('query') === 'newer-valid'
                && widget.state.get('results')[0]?.title === 'Newer'
                && widget.shadowRoot.querySelector('.sm-result-item')?.textContent.includes('Newer')
                && requests.every(request => request.signal === undefined);
        });
        test('Newer debounced request retains ownership without AbortController cancellation', queryState);

        const liveConfig = await page.evaluate(async () => {
            const widget = document.getElementById('query-widget');
            const input = widget.shadowRoot.querySelector('.sm-input');
            input.focus();
            const beforeCount = window.__smRequests.filter(request => request.url.includes('/api/search')).length;
            widget.setAttribute('api-key', 'new-key');
            await new Promise(resolve => setTimeout(resolve, 5));
            const afterKey = window.__smRequests.filter(request => request.url.includes('/api/search'));
            const keyRequest = afterKey[afterKey.length - 1];
            keyRequest.succeed('New key result');
            await new Promise(resolve => setTimeout(resolve, 5));
            widget.setAttribute('snippet-defaults', JSON.stringify({
                snippetIncludeCodeBlocks: true,
                snippetMode: 'deep',
                snippetMaxLength: 240,
                snippetCleanMarkdown: true,
            }));
            await new Promise(resolve => setTimeout(resolve, 5));
            const afterSnippet = window.__smRequests.filter(request => request.url.includes('/api/search'));
            const snippetRequest = afterSnippet[afterSnippet.length - 1];
            snippetRequest.succeed('Configured result');
            await new Promise(resolve => setTimeout(resolve, 5));
            const preserved = {
                sameInput: input === widget.shadowRoot.querySelector('.sm-input'),
                open: widget.state.get('isOpen') && !widget.shadowRoot.querySelector('.sm-backdrop').hidden,
                query: widget.state.get('query'),
                focused: widget.shadowRoot.activeElement === input,
                overflow: document.body.style.overflow,
            };
            const resultItem = widget.shadowRoot.querySelector('.sm-result-item');
            resultItem.addEventListener('click', event => event.preventDefault(), { once: true });
            resultItem.click();
            await new Promise(resolve => setTimeout(resolve, 5));
            const trackingRequests = window.__smRequests.filter(request => !request.url.includes('/api/search'));
            document.getElementById('query-trigger').click();

            return {
                keyRequestDelta: afterKey.length - beforeCount,
                snippetRequestDelta: afterSnippet.length - afterKey.length,
                keyHeader: keyRequest.headers['X-Search-Manager-Key'],
                snippetHeader: snippetRequest.headers['X-Search-Manager-Key'],
                snippetUrl: snippetRequest.url,
                ...preserved,
                externalTriggerOpen: widget.state.get('isOpen'),
                trackingHeaders: trackingRequests.map(request => request.headers['X-Search-Manager-Key']),
                noAbort: afterSnippet.every(request => request.signal === undefined),
            };
        });
        test('Live api-key mutation refreshes exactly once with the new search header',
            liveConfig.keyRequestDelta === 1 && liveConfig.keyHeader === 'new-key');
        test('Live snippet-defaults mutation refreshes exactly once with derived snippet options',
            liveConfig.snippetRequestDelta === 1
            && liveConfig.snippetUrl.includes('snippetIncludeCodeBlocks=1')
            && liveConfig.snippetUrl.includes('snippetMode=deep')
            && liveConfig.snippetUrl.includes('snippetMaxLength=240')
            && liveConfig.snippetUrl.includes('snippetCleanMarkdown=1'));
        test('Live configuration preserves modal DOM, query, focus, scroll lock, and no-abort contract',
            liveConfig.sameInput && liveConfig.open && liveConfig.query === 'newer-valid'
            && liveConfig.focused && liveConfig.overflow === 'hidden'
            && liveConfig.externalTriggerOpen && liveConfig.noAbort);
        test('Live api-key mutation reaches best-effort click and search analytics headers',
            liveConfig.trackingHeaders.length === 2
            && liveConfig.trackingHeaders.every(header => header === 'new-key')
            && liveConfig.snippetHeader === 'new-key');

        // Reduced motion keeps the loading indicator functional and changes
        // scrolling only when the media preference asks for reduction.
        await inputQuery('motion');
        await resolveSearch(7, 'Motion result');
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const reducedMotion = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            const root = widget.shadowRoot;
            const loading = root.querySelector('.sm-loading');
            loading.hidden = false;
            const item = root.querySelector('.sm-result-item');
            const results = root.querySelector('.sm-results');
            results.getBoundingClientRect = () => ({ top: 0, bottom: 10 });
            item.getBoundingClientRect = () => ({ top: 20, bottom: 30 });
            let scrollBehavior = null;
            item.scrollIntoView = (options) => { scrollBehavior = options.behavior; };
            widget.state.set({ selectedIndex: -1 });
            widget.state.set({ selectedIndex: 0 });

            return {
                backdropAnimation: getComputedStyle(root.querySelector('.sm-backdrop')).animationName,
                modalAnimation: getComputedStyle(root.querySelector('.sm-modal')).animationName,
                spinnerAnimation: getComputedStyle(root.querySelector('.sm-spinner')).animationName,
                resultTransition: getComputedStyle(item).transitionDuration,
                loadingVisible: getComputedStyle(loading).display !== 'none',
                scrollBehavior,
            };
        });
        test('Reduced motion neutralizes modal, spinner, and transition motion while keeping loading visible',
            reducedMotion.backdropAnimation === 'none'
            && reducedMotion.modalAnimation === 'none'
            && reducedMotion.spinnerAnimation === 'none'
            && reducedMotion.resultTransition === '0s'
            && reducedMotion.loadingVisible);
        test('Reduced motion uses non-smooth result scrolling', reducedMotion.scrollBehavior === 'auto');

        await page.emulateMedia({ reducedMotion: 'no-preference' });
        const defaultMotion = await page.evaluate(() => {
            const widget = document.getElementById('query-widget');
            const root = widget.shadowRoot;
            const item = root.querySelector('.sm-result-item');
            const results = root.querySelector('.sm-results');
            results.getBoundingClientRect = () => ({ top: 0, bottom: 10 });
            item.getBoundingClientRect = () => ({ top: 20, bottom: 30 });
            let scrollBehavior = null;
            item.scrollIntoView = (options) => { scrollBehavior = options.behavior; };
            widget.state.set({ selectedIndex: -1 });
            widget.state.set({ selectedIndex: 0 });
            return {
                backdropAnimation: getComputedStyle(root.querySelector('.sm-backdrop')).animationName,
                spinnerAnimation: getComputedStyle(root.querySelector('.sm-spinner')).animationName,
                resultTransition: getComputedStyle(item).transitionDuration,
                scrollBehavior,
            };
        });
        test('Default motion preserves modal, spinner, transition, and smooth-scroll behavior',
            defaultMotion.backdropAnimation === 'sm-fade-in'
            && defaultMotion.spinnerAnimation === 'sm-spin'
            && defaultMotion.resultTransition !== '0s'
            && defaultMotion.scrollBehavior === 'smooth');
    } catch (error) {
        console.error(error);
        test('Widget instance behavior tests execute', false);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

async function runDestinationPageHighlightBehaviorTests() {
    if (!fs.existsSync(mainFile) || !fs.existsSync(standaloneFile)) {
        test('Destination-page behavior tests can load both shipped bundles', false);
        return;
    }

    let browser = null;
    const fixtureUrl = pathToFileURL(path.join(__dirname, 'test-modal.html')).href;
    const openPage = async (page, query = '', param = 'smq') => {
        const suffix = query ? `?${encodeURIComponent(param)}=${encodeURIComponent(query)}` : '';
        await page.goto(`${fixtureUrl}${suffix}`);
    };
    const loadStandalone = page => page.addScriptTag({ path: standaloneFile });

    try {
        browser = await chromium.launch();
        const page = await browser.newPage();

        await openPage(page, 'craft');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>Craft CMS</main></body></html>');
        await loadStandalone(page);
        const standaloneOnly = await page.evaluate(async () => {
            const result = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                result,
                marks: Array.from(document.querySelectorAll('main mark')).map(mark => mark.textContent),
                modalCount: document.querySelectorAll('search-modal').length,
                methodType: typeof window.SearchManagerHighlighter.highlightFromUrl,
                retainedMethods: ['highlight', 'escapeHtml', 'escapeRegex', 'create', 'parseQuery', 'getHitTerms']
                    .every(method => typeof window.SearchManagerHighlighter[method] === 'function'),
            };
        });
        test('Standalone bundle exposes highlightFromUrl on its global surface', standaloneOnly.methodType === 'function');
        test('Standalone barrel retains every existing documented global method', standaloneOnly.retainedMethods);
        test('Standalone bundle highlights a real URL query without a search-modal',
            standaloneOnly.result.status === 'applied'
            && standaloneOnly.result.markCount === 1
            && standaloneOnly.marks.join(',') === 'Craft'
            && standaloneOnly.modalCount === 0);

        await openPage(page, 'widget');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>Widget destination</main><search-modal id="adapter-widget"></search-modal></body></html>');
        await page.addScriptTag({ path: mainFile });
        await waitForWidgets(page, ['adapter-widget']);
        await page.waitForFunction(() => document.querySelectorAll('main mark').length === 1);
        const widgetOnlyMarks = await page.locator('main mark').allTextContents();
        test('Widget bundle thin adapter highlights a real URL query', widgetOnlyMarks.join(',') === 'Widget');

        await openPage(page, 'shared');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>Shared destination</main></body></html>');
        await loadStandalone(page);
        await page.addScriptTag({ path: mainFile });
        const sharedBundles = await page.evaluate(async () => {
            const first = window.SearchManagerHighlighter.highlightFromUrl();
            const pendingDuplicate = window.SearchManagerHighlighter.highlightFromUrl();
            const widget = document.createElement('search-modal');
            widget.id = 'shared-widget';
            document.body.appendChild(widget);
            const firstResult = await first;
            const pendingResult = await pendingDuplicate;
            const duplicate = await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', '?smq=destination');
            const replacementWidget = document.createElement('search-modal');
            replacementWidget.id = 'shared-replacement-widget';
            document.body.appendChild(replacementWidget);
            for (let attempt = 0; attempt < 20; attempt++) {
                if (document.querySelector('main mark')?.textContent === 'destination') {
                    break;
                }
                await new Promise(resolve => setTimeout(resolve, 10));
            }
            const crossBundleDuplicate = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                firstResult,
                pendingResult,
                duplicate,
                crossBundleDuplicate,
                marks: document.querySelectorAll('main mark').length,
                markText: document.querySelector('main mark')?.textContent,
                nestedMarks: document.querySelectorAll('mark mark').length,
                registrySize: window.__smPageHighlightRegistry.size,
            };
        });
        test('Widget and standalone bundles share pending/applied registry identity',
            sharedBundles.firstResult.status === 'applied'
            && sharedBundles.pendingResult.status === 'duplicate'
            && sharedBundles.pendingResult.reason === 'pending'
            && sharedBundles.duplicate.status === 'duplicate'
            && sharedBundles.crossBundleDuplicate.status === 'duplicate'
            && sharedBundles.marks === 1
            && sharedBundles.markText === 'destination'
            && sharedBundles.nestedMarks === 0
            && sharedBundles.registrySize === 1);

        await openPage(page, 'Needle', 'find');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><div class="custom-scope">Needle haystack</div><main>Needle default</main></body></html>');
        await loadStandalone(page);
        const customOptions = await page.evaluate(async () => {
            const result = await window.SearchManagerHighlighter.highlightFromUrl({
                param: 'find',
                selector: '.custom-scope',
            });
            return {
                result,
                customMarks: document.querySelectorAll('.custom-scope mark').length,
                defaultMarks: document.querySelectorAll('main mark').length,
            };
        });
        test('Custom param and selector options restrict destination highlighting',
            customOptions.result.status === 'applied'
            && customOptions.customMarks === 1
            && customOptions.defaultMarks === 0);

        await openPage(page);
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>No query</main></body></html>');
        await loadStandalone(page);
        const noQuery = await page.evaluate(() => window.SearchManagerHighlighter.highlightFromUrl());
        test('Missing URL query returns no-query without marks',
            noQuery.status === 'no-query' && noQuery.markCount === 0);

        await openPage(page, 'later');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><div id="mount"></div></body></html>');
        await loadStandalone(page);
        const retryContract = await page.evaluate(async () => {
            const noScopes = await window.SearchManagerHighlighter.highlightFromUrl();
            const main = document.createElement('main');
            main.textContent = 'Later content';
            document.getElementById('mount').appendChild(main);
            const retry = await window.SearchManagerHighlighter.highlightFromUrl();
            main.appendChild(document.createTextNode(' later update'));
            const duplicate = await window.SearchManagerHighlighter.highlightFromUrl();
            const beforeForce = main.querySelectorAll('mark').length;
            const forced = await window.SearchManagerHighlighter.highlightFromUrl({ force: true });
            return {
                noScopes,
                retry,
                duplicate,
                forced,
                beforeForce,
                afterForce: main.querySelectorAll('mark').length,
            };
        });
        test('No-scope attempts remain retryable without force',
            retryContract.noScopes.status === 'no-scopes'
            && retryContract.retry.status === 'applied'
            && retryContract.retry.markCount === 1);
        test('Applied calls are one-shot until force explicitly rescans dynamic content',
            retryContract.duplicate.status === 'duplicate'
            && retryContract.beforeForce === 1
            && retryContract.forced.status === 'applied'
            && retryContract.forced.markCount === 1
            && retryContract.forced.removedMarkCount === 0
            && retryContract.afterForce === 2);

        await openPage(page, 'alpha');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>alpha beta</main></body></html>');
        await loadStandalone(page);
        const changedQuery = await page.evaluate(async () => {
            const first = await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', '?smq=beta');
            const second = await window.SearchManagerHighlighter.highlightFromUrl();
            const main = document.querySelector('main');
            return {
                first,
                second,
                marks: Array.from(main.querySelectorAll('mark')).map(mark => mark.textContent),
                childNodeTypes: Array.from(main.childNodes).map(node => node.nodeType),
                emptyMarks: main.querySelectorAll('mark:empty').length,
                nestedMarks: main.querySelectorAll('mark mark').length,
                legacyClaims: main.hasAttribute('data-sm-highlighted'),
            };
        });
        test('Changed queries reconcile one channel and leave only current-run marks',
            changedQuery.first.status === 'applied'
            && changedQuery.second.status === 'applied'
            && changedQuery.second.removedMarkCount === 1
            && changedQuery.marks.join(',') === 'beta');
        test('Channel cleanup restores normalized text without legacy, empty, or nested marks',
            changedQuery.childNodeTypes.join(',') === '3,1'
            && changedQuery.emptyMarks === 0
            && changedQuery.nestedMarks === 0
            && changedQuery.legacyClaims === false);

        await openPage(page, 'alpha');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>alpha beta</main></body></html>');
        await loadStandalone(page);
        const removedQuery = await page.evaluate(async () => {
            await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', location.pathname);
            const result = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                result,
                marks: document.querySelectorAll('main mark').length,
                text: document.querySelector('main').textContent,
                childNodes: document.querySelector('main').childNodes.length,
            };
        });
        test('Removing the URL query clears prior channel-owned marks',
            removedQuery.result.status === 'no-query'
            && removedQuery.result.removedMarkCount === 1
            && removedQuery.marks === 0
            && removedQuery.text === 'alpha beta'
            && removedQuery.childNodes === 1);

        await openPage(page, 'alpha');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>alpha beta</main></body></html>');
        await loadStandalone(page);
        const ineligibleReplacements = await page.evaluate(async () => {
            await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', `?smq=${'x'.repeat(257)}`);
            const overlong = await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', '?smq=beta');
            await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', '?smq=a%20OR%20b');
            const noTerms = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                overlong,
                noTerms,
                marks: document.querySelectorAll('main mark').length,
                text: document.querySelector('main').textContent,
                childNodes: document.querySelector('main').childNodes.length,
            };
        });
        test('Overlong and no-term replacement runs clear prior channel-owned marks',
            ineligibleReplacements.overlong.status === 'query-too-long'
            && ineligibleReplacements.overlong.removedMarkCount === 1
            && ineligibleReplacements.noTerms.status === 'no-terms'
            && ineligibleReplacements.noTerms.removedMarkCount === 1
            && ineligibleReplacements.marks === 0
            && ineligibleReplacements.text === 'alpha beta'
            && ineligibleReplacements.childNodes === 1);

        await openPage(page, 'alpha');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>alpha beta gamma</main></body></html>');
        await loadStandalone(page);
        const supersededPending = await page.evaluate(async () => {
            await window.SearchManagerHighlighter.highlightFromUrl();
            let readyState = 'loading';
            Object.defineProperty(document, 'readyState', {
                configurable: true,
                get: () => readyState,
            });
            history.replaceState(null, '', '?smq=beta');
            const oldRun = window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', '?smq=gamma');
            const newRun = window.SearchManagerHighlighter.highlightFromUrl();
            readyState = 'interactive';
            document.dispatchEvent(new Event('DOMContentLoaded'));
            const [oldResult, newResult] = await Promise.all([oldRun, newRun]);
            return {
                oldResult,
                newResult,
                marks: Array.from(document.querySelectorAll('main mark')).map(mark => mark.textContent),
            };
        });
        test('A newer pending run supersedes the old run before it can paint',
            supersededPending.oldResult.status === 'superseded'
            && supersededPending.oldResult.reason === 'newer-run'
            && supersededPending.oldResult.removedMarkCount === 1
            && supersededPending.newResult.status === 'applied'
            && supersededPending.marks.join(',') === 'gamma');

        await openPage(page, 'alpha');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main><div class="channel-a">alpha beta</div><div class="channel-b">alpha beta</div></main></body></html>');
        await loadStandalone(page);
        const independentChannels = await page.evaluate(async () => {
            await window.SearchManagerHighlighter.highlightFromUrl({ selector: '.channel-a' });
            await window.SearchManagerHighlighter.highlightFromUrl({ selector: '.channel-b' });
            const channelBMark = document.querySelector('.channel-b mark');
            history.replaceState(null, '', '?smq=beta');
            const updated = await window.SearchManagerHighlighter.highlightFromUrl({ selector: '.channel-a' });
            return {
                updated,
                channelAMarks: Array.from(document.querySelectorAll('.channel-a mark')).map(mark => mark.textContent),
                channelBMarks: Array.from(document.querySelectorAll('.channel-b mark')).map(mark => mark.textContent),
                channelBIdentityPreserved: document.querySelector('.channel-b mark') === channelBMark,
                registrySize: window.__smPageHighlightRegistry.size,
            };
        });
        test('Different selector channels retain independent marks and ownership',
            independentChannels.updated.status === 'applied'
            && independentChannels.updated.removedMarkCount === 1
            && independentChannels.channelAMarks.join(',') === 'beta'
            && independentChannels.channelBMarks.join(',') === 'alpha'
            && independentChannels.channelBIdentityPreserved
            && independentChannels.registrySize === 2);

        await openPage(page, 'alpha');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main><mark id="author-mark">alpha</mark><span id="author-highlight" class="sm-highlight">alpha</span><span id="ordinary">alpha beta</span></main></body></html>');
        await loadStandalone(page);
        const authorMarkup = await page.evaluate(async () => {
            const authorMark = document.getElementById('author-mark');
            const authorHighlight = document.getElementById('author-highlight');
            await window.SearchManagerHighlighter.highlightFromUrl();
            history.replaceState(null, '', '?smq=beta');
            const updated = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                updated,
                authorMarkPreserved: document.getElementById('author-mark') === authorMark,
                authorHighlightPreserved: document.getElementById('author-highlight') === authorHighlight,
                authorMarkText: authorMark.textContent,
                authorHighlightText: authorHighlight.textContent,
                ordinaryMarks: Array.from(document.querySelectorAll('#ordinary mark')).map(mark => mark.textContent),
            };
        });
        test('Reconciliation never unwraps pre-existing author mark/highlight elements',
            authorMarkup.updated.removedMarkCount === 1
            && authorMarkup.authorMarkPreserved
            && authorMarkup.authorHighlightPreserved
            && authorMarkup.authorMarkText === 'alpha'
            && authorMarkup.authorHighlightText === 'alpha'
            && authorMarkup.ordinaryMarks.join(',') === 'beta');

        await openPage(page, 'alpha NIET beta');
        await page.setContent('<!doctype html><html lang="de-DE"><head></head><body><main>alpha NIET beta</main></body></html>');
        await loadStandalone(page);
        const changedLanguage = await page.evaluate(async () => {
            const first = await window.SearchManagerHighlighter.highlightFromUrl();
            document.documentElement.lang = 'nl-NL';
            const second = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                first,
                second,
                marks: Array.from(document.querySelectorAll('main mark')).map(mark => mark.textContent),
            };
        });
        test('Changed page language reconciles the channel as a new run',
            changedLanguage.first.markCount === 3
            && changedLanguage.second.status === 'applied'
            && changedLanguage.second.removedMarkCount === 3
            && changedLanguage.marks.join(',') === 'alpha');

        await openPage(page, 'invalid');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>Invalid selector</main></body></html>');
        await loadStandalone(page);
        const invalidSelector = await page.evaluate(() => (
            window.SearchManagerHighlighter.highlightFromUrl({ selector: '[' })
        ));
        test('Invalid selectors resolve as invalid-selector instead of rejecting',
            invalidSelector.status === 'invalid-selector' && invalidSelector.reason.length > 0);

        await openPage(page, 'scheduled');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>Scheduled content</main></body></html>');
        await loadStandalone(page);
        const domLoading = await page.evaluate(async () => {
            let readyState = 'loading';
            Object.defineProperty(document, 'readyState', {
                configurable: true,
                get: () => readyState,
            });
            const pending = window.SearchManagerHighlighter.highlightFromUrl();
            await Promise.resolve();
            const before = document.querySelectorAll('main mark').length;
            readyState = 'interactive';
            document.dispatchEvent(new Event('DOMContentLoaded'));
            const result = await pending;
            return { before, result, after: document.querySelectorAll('main mark').length };
        });
        test('DOM-loading calls wait for DOMContentLoaded before applying marks',
            domLoading.before === 0 && domLoading.result.status === 'applied' && domLoading.after === 1);

        const queryCases = [
            { language: 'en', query: 'public NOT draft', text: 'Public draft', expected: 'Public' },
            { language: 'nl-NL', query: 'openbaar NIET concept', text: 'Openbaar NIET concept', expected: 'Openbaar' },
            { language: 'de-DE', query: 'alpha NIET beta', text: 'Alpha NIET beta', expected: 'Alpha,NIET,beta' },
            { language: 'zz-ZZ', query: 'public NOT draft', text: 'Public draft', expected: 'Public' },
        ];
        const queryOutcomes = [];
        for (const scenario of queryCases) {
            await openPage(page, scenario.query);
            await page.setContent(`<!doctype html><html lang="${scenario.language}"><head></head><body><main>${scenario.text}</main></body></html>`);
            await loadStandalone(page);
            queryOutcomes.push(await page.evaluate(async () => {
                const result = await window.SearchManagerHighlighter.highlightFromUrl();
                return {
                    status: result.status,
                    language: result.language,
                    marks: Array.from(document.querySelectorAll('main mark')).map(mark => mark.textContent).join(','),
                };
            }));
        }
        test('Destination highlighting excludes English and localized NOT operands',
            queryOutcomes[0].marks === queryCases[0].expected
            && queryOutcomes[1].marks === queryCases[1].expected);
        test('Destination highlighting keeps locale isolation and English fallback',
            queryOutcomes[2].marks === queryCases[2].expected
            && queryOutcomes[3].marks === queryCases[3].expected
            && queryOutcomes.map(outcome => outcome.status).every(status => status === 'applied'));

        await openPage(page, 'needle');
        await page.setContent(`<!doctype html><html lang="en"><head></head><body><main>
            Needle visible
            <script>const needle = true;</script>
            <style>.needle { color: red; }</style>
            <noscript>Needle noscript</noscript>
            <textarea>Needle textarea</textarea>
            <mark>Needle existing mark</mark>
            <span class="sm-highlight">Needle existing highlight</span>
            <code>Needle code</code>
            <pre>Needle pre</pre>
            <search-modal highlight-destination-enabled="false">Needle nested widget</search-modal>
        </main></body></html>`);
        await loadStandalone(page);
        const exclusions = await page.evaluate(async () => {
            const result = await window.SearchManagerHighlighter.highlightFromUrl();
            return {
                result,
                allMarks: document.querySelectorAll('main mark').length,
                pageMarks: document.querySelectorAll('main mark.sm-page-highlight').length,
                codeMarks: document.querySelectorAll('code mark.sm-page-highlight').length,
                preMarks: document.querySelectorAll('pre mark.sm-page-highlight').length,
                nestedMarks: document.querySelectorAll('mark mark').length,
                highlightedMarks: document.querySelectorAll('.sm-highlight mark').length,
                widgetMarks: document.querySelectorAll('search-modal mark').length,
            };
        });
        test('Destination walker preserves exclusions and existing highlights while marking code/pre',
            exclusions.result.status === 'applied'
            && exclusions.result.markCount === 3
            && exclusions.allMarks === 4
            && exclusions.pageMarks === 3
            && exclusions.codeMarks === 1
            && exclusions.preMarks === 1
            && exclusions.nestedMarks === 0
            && exclusions.highlightedMarks === 0
            && exclusions.widgetMarks === 0);

        await openPage(page, 'a OR b');
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>A or B</main></body></html>');
        await loadStandalone(page);
        const noTerms = await page.evaluate(() => window.SearchManagerHighlighter.highlightFromUrl());
        test('Queries without two-character terms return no-terms', noTerms.status === 'no-terms');

        await openPage(page, 'x'.repeat(257));
        await page.setContent('<!doctype html><html lang="en"><head></head><body><main>Bounded query</main></body></html>');
        await loadStandalone(page);
        const overlong = await page.evaluate(() => window.SearchManagerHighlighter.highlightFromUrl());
        test('URL queries over 256 characters return query-too-long without work',
            overlong.status === 'query-too-long' && overlong.scopeCount === 0 && overlong.markCount === 0);
    } catch (error) {
        console.error(error);
        test('Destination-page behavior tests execute', false);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

(async () => {
    const { highlightFromUrl } = loadPageHighlighterModule();
    const unsupported = await highlightFromUrl();
    test('PageHighlighter imports safely and reports unsupported non-browser environments',
        unsupported.status === 'unsupported-environment');
    await runBuildParityTests();
    await runDestinationPageHighlightBehaviorTests();
    await runWidgetInstanceBehaviorTests();

    // Summary
    console.log(`\nResults: ${passed} passed, ${failed} failed\n`);

    process.exit(failed > 0 ? 1 : 0);
})();
