/**
 * Playwright Configuration for Static A11y Testing
 * Uses local http-server to serve test fixtures
 */

const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './',
    testMatch: '**/a11y-static.spec.js',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    timeout: 30000,

    reporter: [
        ['html', { outputFolder: '../playwright-report', open: 'never' }],
        ['list']
    ],

    outputDir: '../test-results',

    use: {
        baseURL: 'http://localhost:3333',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: { browserName: 'chromium' },
        },
    ],

    // Local web server for static fixtures
    webServer: {
        command: 'npx http-server . -p 3333 -c-1 --silent',
        port: 3333,
        reuseExistingServer: !process.env.CI,
        cwd: __dirname + '/..',
    },
});
