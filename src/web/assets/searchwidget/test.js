/**
 * Simple build verification tests
 * Run with: npm test
 */

const fs = require('fs');
const path = require('path');

const DIST_DIR = path.join(__dirname, 'dist');
const REQUIRED_FILES = ['SearchModalWidget.js', 'SearchModalWidget.min.js'];
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
}

// Test 5: Minified file is smaller than regular
const regularFile = path.join(DIST_DIR, 'SearchModalWidget.js');
const minFile = path.join(DIST_DIR, 'SearchModalWidget.min.js');
if (fs.existsSync(regularFile) && fs.existsSync(minFile)) {
    const regularSize = fs.statSync(regularFile).size;
    const minSize = fs.statSync(minFile).size;
    test(`Minified file is smaller (${(minSize / 1024).toFixed(1)}KB < ${(regularSize / 1024).toFixed(1)}KB)`, minSize < regularSize);
}

// Summary
console.log(`\nResults: ${passed} passed, ${failed} failed\n`);

process.exit(failed > 0 ? 1 : 0);
