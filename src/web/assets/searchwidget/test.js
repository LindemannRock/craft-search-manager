/**
 * Simple build verification tests
 * Run with: npm test
 */

const fs = require('fs');
const path = require('path');

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

// Summary
console.log(`\nResults: ${passed} passed, ${failed} failed\n`);

process.exit(failed > 0 ? 1 : 0);
