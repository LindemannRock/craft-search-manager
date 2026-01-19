/**
 * Accessibility Tests for Search Widget
 *
 * Tests against a real Craft CMS site with actual search results.
 * Uses axe-core via @axe-core/playwright to test WCAG 2.1 AA compliance.
 *
 * Run with: npm run test:a11y
 * Requires DDEV site to be running: ddev start
 *
 * @see https://www.deque.com/axe/
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

// Test page URL on the Craft site
const TEST_PAGE = '/test-a11y-widget';

// Search keyword that should return results
const SEARCH_KEYWORD = 'test';

// axe-core configuration for WCAG 2.1 AA
const AXE_OPTIONS = {
    runOnly: {
        type: 'tag',
        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'],
    },
    rules: {
        // Disable page-level landmark rules (test page structure, not widget)
        'landmark-one-main': { enabled: false },
        'region': { enabled: false },
    },
};

/**
 * Helper to wait for shadow DOM to be ready
 */
async function waitForShadowDOM(page) {
    await page.waitForFunction(() => {
        const widgets = document.querySelectorAll('search-widget');
        return Array.from(widgets).every(w => w.shadowRoot !== null);
    });
}

/**
 * Helper to get attribute from shadow DOM element
 */
async function getShadowAttribute(page, containerSelector, shadowSelector, attribute) {
    return page.evaluate(([containerSel, shadowSel, attr]) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-widget');
        const el = widget?.shadowRoot?.querySelector(shadowSel);
        return el?.getAttribute(attr);
    }, [containerSelector, shadowSelector, attribute]);
}

/**
 * Helper to get text content from shadow DOM element
 */
async function getShadowTextContent(page, containerSelector, shadowSelector) {
    return page.evaluate(([containerSel, shadowSel]) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-widget');
        const el = widget?.shadowRoot?.querySelector(shadowSel);
        return el?.textContent?.trim();
    }, [containerSelector, shadowSelector]);
}

/**
 * Helper to check if shadow element is focused
 */
async function isShadowElementFocused(page, containerSelector, shadowSelector) {
    return page.evaluate(([containerSel, shadowSel]) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-widget');
        const el = widget?.shadowRoot?.querySelector(shadowSel);
        return widget?.shadowRoot?.activeElement === el;
    }, [containerSelector, shadowSelector]);
}

/**
 * Helper to open widget by theme
 */
async function openWidget(page, theme) {
    await page.evaluate((t) => window.openWidgetByTheme(t), theme);
    await page.waitForTimeout(300);
}

/**
 * Helper to close widget by theme
 */
async function closeWidget(page, theme) {
    await page.evaluate((t) => window.closeWidgetByTheme(t), theme);
    await page.waitForTimeout(100);
}

/**
 * Helper to type in widget input
 */
async function typeInWidget(page, containerSelector, text) {
    await page.evaluate(([containerSel, txt]) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-widget');
        const input = widget?.shadowRoot?.querySelector('.sm-input');
        if (input) {
            input.value = txt;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, [containerSelector, text]);
}

/**
 * Helper to count result items
 */
async function getResultCount(page, containerSelector) {
    return page.evaluate((containerSel) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-widget');
        const items = widget?.shadowRoot?.querySelectorAll('.sm-result-item[role="option"]');
        return items?.length || 0;
    }, containerSelector);
}

/**
 * Helper to format axe violations
 */
function formatViolations(violations) {
    if (violations.length === 0) return 'No violations found';

    return violations.map(v => {
        const nodes = v.nodes.map(n => `    - ${n.html.substring(0, 100)}...`).join('\n');
        return `
[${v.impact?.toUpperCase()}] ${v.id}: ${v.description}
  Help: ${v.helpUrl}
  Affected nodes:
${nodes}`;
    }).join('\n');
}

// ============================================================================
// TESTS
// ============================================================================

test.describe('Search Widget Accessibility - Real Site', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(TEST_PAGE);
        await waitForShadowDOM(page);
    });

    // ------------------------------------------------------------------------
    // Basic Structure Tests
    // ------------------------------------------------------------------------

    test.describe('Basic Structure', () => {

        test('page loads without axe violations', async ({ page }) => {
            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('trigger button has accessible name', async ({ page }) => {
            const ariaLabel = await getShadowAttribute(page, '#widget-light-container', '.sm-trigger', 'aria-label');
            expect(ariaLabel).toBe('Open search');
        });

        test('trigger button is keyboard focusable', async ({ page }) => {
            await page.keyboard.press('Tab');
            await page.waitForTimeout(100);

            const isFocused = await isShadowElementFocused(page, '#widget-light-container', '.sm-trigger');
            expect(isFocused).toBe(true);
        });

    });

    // ------------------------------------------------------------------------
    // Modal Dialog Tests
    // ------------------------------------------------------------------------

    test.describe('Modal Dialog', () => {

        test('open modal passes axe checks', async ({ page }) => {
            await openWidget(page, 'light');

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('modal has correct ARIA attributes', async ({ page }) => {
            await openWidget(page, 'light');

            const role = await getShadowAttribute(page, '#widget-light-container', '.sm-modal', 'role');
            const ariaModal = await getShadowAttribute(page, '#widget-light-container', '.sm-modal', 'aria-modal');
            const ariaLabel = await getShadowAttribute(page, '#widget-light-container', '.sm-modal', 'aria-label');

            expect(role).toBe('dialog');
            expect(ariaModal).toBe('true');
            expect(ariaLabel).toBe('Search');
        });

        test('focus moves to input when modal opens', async ({ page }) => {
            await openWidget(page, 'light');

            const isFocused = await isShadowElementFocused(page, '#widget-light-container', '.sm-input');
            expect(isFocused).toBe(true);
        });

        test('escape key closes modal', async ({ page }) => {
            await openWidget(page, 'light');
            await page.keyboard.press('Escape');
            await page.waitForTimeout(100);

            const hidden = await getShadowAttribute(page, '#widget-light-container', '.sm-backdrop', 'hidden');
            expect(hidden).toBe('');
        });

    });

    // ------------------------------------------------------------------------
    // Combobox Pattern Tests
    // ------------------------------------------------------------------------

    test.describe('Combobox Pattern', () => {

        test('input has correct combobox ARIA attributes', async ({ page }) => {
            await openWidget(page, 'light');

            const role = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'role');
            const ariaAutocomplete = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-autocomplete');
            const ariaHaspopup = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-haspopup');
            const ariaControls = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-controls');

            expect(role).toBe('combobox');
            expect(ariaAutocomplete).toBe('list');
            expect(ariaHaspopup).toBe('listbox');
            expect(ariaControls).toBeTruthy();
        });

        test('aria-expanded is false when no results', async ({ page }) => {
            await openWidget(page, 'light');

            const ariaExpanded = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-expanded');
            expect(ariaExpanded).toBe('false');
        });

    });

    // ------------------------------------------------------------------------
    // Search Results Tests (with real data)
    // ------------------------------------------------------------------------

    test.describe('Search Results', () => {

        test('search returns results and passes axe checks', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('results have role="option" and aria-selected', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 0) {
                // Check first result has correct attributes
                const firstResultRole = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-widget');
                    const item = widget?.shadowRoot?.querySelector('.sm-result-item');
                    return item?.getAttribute('role');
                });

                const firstResultSelected = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-widget');
                    const item = widget?.shadowRoot?.querySelector('.sm-result-item');
                    return item?.getAttribute('aria-selected');
                });

                expect(firstResultRole).toBe('option');
                expect(firstResultSelected).toBe('true'); // First item auto-selected
            }
        });

        test('aria-expanded becomes true when results shown', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 0) {
                const ariaExpanded = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-expanded');
                expect(ariaExpanded).toBe('true');
            }
        });

        test('listbox role is present when results shown', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 0) {
                const role = await getShadowAttribute(page, '#widget-light-container', '.sm-results', 'role');
                expect(role).toBe('listbox');
            }
        });

        test('result items have unique IDs', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const ids = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-widget');
                const items = widget?.shadowRoot?.querySelectorAll('.sm-result-item[role="option"]');
                return Array.from(items || []).map(item => item.id);
            });

            if (ids.length > 0) {
                // All IDs should be unique
                const uniqueIds = new Set(ids);
                expect(uniqueIds.size).toBe(ids.length);

                // All IDs should be non-empty
                ids.forEach(id => {
                    expect(id).toBeTruthy();
                });
            }
        });

    });

    // ------------------------------------------------------------------------
    // Keyboard Navigation with Results
    // ------------------------------------------------------------------------

    test.describe('Keyboard Navigation', () => {

        test('arrow down updates aria-activedescendant', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 1) {
                // First item is auto-selected, press down to go to second
                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(100);

                const activeDescendant = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-activedescendant');
                expect(activeDescendant).toBeTruthy();

                // Check it points to the second item
                const secondItemId = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-widget');
                    const items = widget?.shadowRoot?.querySelectorAll('.sm-result-item[role="option"]');
                    return items?.[1]?.id;
                });

                expect(activeDescendant).toBe(secondItemId);
            }
        });

        test('arrow up updates aria-activedescendant', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 1) {
                // Go down then up
                await page.keyboard.press('ArrowDown');
                await page.keyboard.press('ArrowUp');
                await page.waitForTimeout(100);

                const activeDescendant = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-activedescendant');

                // Should be back to first item
                const firstItemId = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-widget');
                    const items = widget?.shadowRoot?.querySelectorAll('.sm-result-item[role="option"]');
                    return items?.[0]?.id;
                });

                expect(activeDescendant).toBe(firstItemId);
            }
        });

        test('only one item has aria-selected=true at a time', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 1) {
                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(100);

                const selectedCount = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-widget');
                    const selected = widget?.shadowRoot?.querySelectorAll('[aria-selected="true"]');
                    return selected?.length || 0;
                });

                expect(selectedCount).toBe(1);
            }
        });

    });

    // ------------------------------------------------------------------------
    // Live Region Tests
    // ------------------------------------------------------------------------

    test.describe('Live Region Announcements', () => {

        test('live region exists', async ({ page }) => {
            await openWidget(page, 'light');

            const hasLiveRegion = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-widget');
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return liveRegion !== null;
            });

            expect(hasLiveRegion).toBe(true);
        });

        test('live region has correct attributes', async ({ page }) => {
            await openWidget(page, 'light');

            const attrs = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-widget');
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return {
                    role: liveRegion?.getAttribute('role'),
                    ariaLive: liveRegion?.getAttribute('aria-live'),
                    ariaAtomic: liveRegion?.getAttribute('aria-atomic'),
                    className: liveRegion?.className,
                };
            });

            expect(attrs.role).toBe('status');
            expect(attrs.ariaLive).toBe('polite');
            expect(attrs.ariaAtomic).toBe('true');
            expect(attrs.className).toContain('sm-sr-only');
        });

        test('live region announces search results', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results and announcement
            await page.waitForTimeout(1200);

            const announcement = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-widget');
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return liveRegion?.textContent?.trim();
            });

            // Should have some announcement about results
            expect(announcement).toBeTruthy();
            expect(announcement).toMatch(/result|found|search/i);
        });

    });

    // ------------------------------------------------------------------------
    // Dark Theme Tests
    // ------------------------------------------------------------------------

    test.describe('Dark Theme', () => {

        test('dark theme modal passes axe checks', async ({ page }) => {
            await openWidget(page, 'dark');

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('dark theme with results passes axe checks', async ({ page }) => {
            await openWidget(page, 'dark');
            await typeInWidget(page, '#widget-dark-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

    });

    // ------------------------------------------------------------------------
    // Decorative Elements Tests
    // ------------------------------------------------------------------------

    test.describe('Decorative Elements', () => {

        test('search icon is hidden from screen readers', async ({ page }) => {
            await openWidget(page, 'light');

            const ariaHidden = await getShadowAttribute(page, '#widget-light-container', '.sm-search-icon', 'aria-hidden');
            expect(ariaHidden).toBe('true');
        });

        test('result arrow icons are hidden from screen readers', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);

            // Wait for search results
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 0) {
                const ariaHidden = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-widget');
                    const arrow = widget?.shadowRoot?.querySelector('.sm-result-arrow');
                    return arrow?.getAttribute('aria-hidden');
                });

                expect(ariaHidden).toBe('true');
            }
        });

    });

});
