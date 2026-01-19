/**
 * Playwright Configuration for Live A11y Testing
 * Tests against the running DDEV Craft CMS site with real search data
 *
 * Requires: ddev start
 */

const { defineConfig } = require('@playwright/test');

// Site URL - use DDEV site for real integration testing
const SITE_URL = process.env.TEST_SITE_URL || 'https://craftcms.ddev.site';

module.exports = defineConfig({
    testDir: './',
    testMatch: '**/a11y-live.spec.js',

    // Run tests in parallel
    fullyParallel: true,

    // Fail the build on CI if you accidentally left test.only in the source code
    forbidOnly: !!process.env.CI,

    // Retry on CI only
    retries: process.env.CI ? 2 : 0,

    // Longer timeout for real site requests
    timeout: 30000,

    // Reporter
    reporter: [
        ['html', { outputFolder: '../playwright-report', open: 'never' }],
        ['list']
    ],

    // Output folder for test artifacts
    outputDir: '../test-results',

    // Shared settings for all the projects
    use: {
        // Base URL for the Craft CMS site
        baseURL: SITE_URL,

        // Ignore HTTPS errors for local DDEV
        ignoreHTTPSErrors: true,

        // Collect trace when retrying the failed test
        trace: 'on-first-retry',

        // Screenshot on failure
        screenshot: 'only-on-failure',
    },

    // Configure projects for major browsers
    projects: [
        {
            name: 'chromium',
            use: {
                browserName: 'chromium',
            },
        },
        // Optionally test in Firefox and WebKit
        // {
        //     name: 'firefox',
        //     use: { browserName: 'firefox' },
        // },
        // {
        //     name: 'webkit',
        //     use: { browserName: 'webkit' },
        // },
    ],

    // No web server needed - we test against the running DDEV site
});
