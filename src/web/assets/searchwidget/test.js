/**
 * Simple build verification tests
 * Run with: npm test
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const esbuild = require('esbuild');

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
    test('Source uses normalized highlight tag for markup', source.includes('return applyHighlightRanges(text, termList, safeTag, classAttr);'));
    test('Source does not render raw className in class attribute', !source.includes('classes.push(className);'));
    test('Source escapes constructed class attribute', source.includes("const classAttr = ` class=\"${escapeHtml(classes.join(' '))}\"`;"));
    test('Source preserves dotted filename-like queries as one highlight term', source.includes('terms.push(word);'));
}

const widgetBaseFile = path.join(SRC_DIR, 'core', 'SearchWidgetBase.js');
if (fs.existsSync(widgetBaseFile)) {
    const source = fs.readFileSync(widgetBaseFile, 'utf8');
    test('Stale search responses are discarded before state updates', source.includes('requestId !== this.searchSequence'));
    test('Stale search failures are discarded before error state', (source.match(/requestId !== this\.searchSequence/g) || []).length >= 2);
    test('Source does not abort in-flight searches', !source.includes('new AbortController'));
    test('Destination page highlighter can mark code/pre text nodes', !source.includes("parent.closest('script, style, noscript, textarea, code, pre, mark"));
}

const urlUtilsFile = path.join(SRC_DIR, 'modules', 'UrlUtils.js');
if (fs.existsSync(urlUtilsFile)) {
    const source = fs.readFileSync(urlUtilsFile, 'utf8');
    test('Source URL guard strips tab/newline/carriage return', source.includes('replace(/[\\t\\n\\r]/g, \'\')'));
    test('Source URL guard strips leading C0 controls and space', source.includes('replace(/^[\\u0000-\\u0020]+/, \'\')'));
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
    const { performSearch } = loadSearchServiceModule();
    const originalFetch = global.fetch;
    const requestedUrls = [];
    global.fetch = async function(url) {
        requestedUrls.push(String(url));

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
            parseMarkdownSnippets: true,
        });
        performSearch({
            query: 'daterangehelper',
            endpoint: '/actions/search-manager/api/search',
            parseMarkdownSnippets: false,
        });
    } finally {
        global.fetch = originalFetch;
    }

    test('Widget forwards parseMarkdownSnippets when enabled', requestedUrls[0] && requestedUrls[0].includes('parseMarkdownSnippets=1'));
    test('Widget omits parseMarkdownSnippets when disabled', requestedUrls[1] && !requestedUrls[1].includes('parseMarkdownSnippets=1'));
} catch (error) {
    console.error(error);
    test('Widget parseMarkdownSnippets forwarding tests execute', false);
}

try {
    const { renderResults } = loadRendererModule();
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
        resultLayout: 'hierarchical',
        listboxId: 'split-list',
        maxHeadingsPerResult: 2,
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
        resultLayout: 'hierarchical',
        listboxId: 'no-intro-list',
        maxHeadingsPerResult: 3,
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
        resultLayout: 'hierarchical',
        listboxId: 'promoted-list',
        maxHeadingsPerResult: 3,
        promotions: {
            showBadge: true,
            badgeText: 'Promoted',
        },
    });
    test('Split promoted-page hits render as page-level hierarchy rows', promotedPageHtml.includes('Promoted Guide') && promotedPageHtml.includes('sm-hierarchy-parent') && !promotedPageHtml.includes('sm-hierarchy-children'));
    test('Split promoted-page hits keep backendId DOM identity and elementId analytics identity', promotedPageHtml.includes('data-id="707_1_promoted-page" data-element-id="707"'));

    const flatSectionHtml = renderResults([splitHits[0]], 'install', {
        resultLayout: 'default',
        listboxId: 'flat-list',
    });
    test('Flat section hits render section title and section URL', flatSectionHtml.includes('Install') && flatSectionHtml.includes('href="/guide-a#install'));
    test('Flat section hits use backendId for DOM identity and elementId for analytics identity', flatSectionHtml.includes('data-id="101_1_install" data-element-id="101"'));

    const pageModeHtml = renderResults([{
        elementId: 404,
        backendId: '404_1',
        title: 'Plain Page',
        url: '/plain',
        entrySection: 'Pages',
        snippet: 'Plain snippet',
    }], 'plain', {
        resultLayout: 'default',
        listboxId: 'plain-list',
    });
    test('Page-mode hits use backendId DOM identity and elementId analytics identity', pageModeHtml.includes('data-id="404_1" data-element-id="404"'));

    const mixedHtml = renderResults([splitHits[0], {
        elementId: 505,
        backendId: '505_1',
        title: 'Mixed Plain Page',
        url: '/mixed',
        entrySection: 'Pages',
        snippet: 'Mixed plain snippet',
        score: 5,
    }], 'mixed', {
        resultLayout: 'hierarchical',
        listboxId: 'mixed-list',
        maxHeadingsPerResult: 3,
    });
    test('Mixed hierarchical results render split and page-mode shapes together', mixedHtml.includes('Guide A') && mixedHtml.includes('Mixed Plain Page'));
} catch (error) {
    console.error(error);
    test('Renderer split-hit tests execute', false);
}

// Summary
console.log(`\nResults: ${passed} passed, ${failed} failed\n`);

process.exit(failed > 0 ? 1 : 0);
