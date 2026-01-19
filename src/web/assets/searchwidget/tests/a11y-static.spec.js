/**
 * Static Accessibility Tests for Search Widget
 *
 * Tests against a local static HTML fixture file.
 * Useful for quick offline testing without needing DDEV running.
 * Does not test real search results - only widget structure and ARIA.
 *
 * Run with: npm run test:a11y:static
 *
 * @see https://www.deque.com/axe/
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

// Static test page URL (served by local http-server)
const TEST_PAGE = 'http://localhost:3333/tests/fixtures/test-widget.html';

// axe-core configuration for WCAG 2.1 AA
const AXE_OPTIONS = {
    runOnly: {
        type: 'tag',
        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'],
    },
    rules: {
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
async function getShadowAttribute(page, widgetId, selector, attribute) {
    return page.evaluate(([id, sel, attr]) => {
        const widget = document.getElementById(id);
        const el = widget?.shadowRoot?.querySelector(sel);
        return el?.getAttribute(attr);
    }, [widgetId, selector, attribute]);
}

/**
 * Helper to check if shadow element is focused
 */
async function isShadowElementFocused(page, widgetId, selector) {
    return page.evaluate(([id, sel]) => {
        const widget = document.getElementById(id);
        const el = widget?.shadowRoot?.querySelector(sel);
        return widget?.shadowRoot?.activeElement === el;
    }, [widgetId, selector]);
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
// STATIC FIXTURE TESTS
// ============================================================================

test.describe('Search Widget Accessibility - Static Fixture', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(TEST_PAGE);
        await waitForShadowDOM(page);
    });

    test.describe('Trigger Button', () => {

        test('trigger button passes axe checks', async ({ page }) => {
            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('trigger button has accessible name', async ({ page }) => {
            const ariaLabel = await getShadowAttribute(page, 'widget-light', '.sm-trigger', 'aria-label');
            expect(ariaLabel).toBe('Open search');
        });

        test('trigger button is keyboard focusable', async ({ page }) => {
            await page.keyboard.press('Tab');
            await page.waitForTimeout(100);

            const isFocused = await isShadowElementFocused(page, 'widget-light', '.sm-trigger');
            expect(isFocused).toBe(true);
        });

    });

    test.describe('Modal Dialog', () => {

        test('open modal passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(300);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('modal has correct ARIA attributes', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const role = await getShadowAttribute(page, 'widget-light', '.sm-modal', 'role');
            const ariaModal = await getShadowAttribute(page, 'widget-light', '.sm-modal', 'aria-modal');
            const ariaLabel = await getShadowAttribute(page, 'widget-light', '.sm-modal', 'aria-label');

            expect(role).toBe('dialog');
            expect(ariaModal).toBe('true');
            expect(ariaLabel).toBe('Search');
        });

        test('focus moves to input when modal opens', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);

            const isFocused = await isShadowElementFocused(page, 'widget-light', '.sm-input');
            expect(isFocused).toBe(true);
        });

        test('escape key closes modal', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            await page.keyboard.press('Escape');
            await page.waitForTimeout(100);

            const hidden = await getShadowAttribute(page, 'widget-light', '.sm-backdrop', 'hidden');
            expect(hidden).toBe('');
        });

    });

    test.describe('Combobox Pattern', () => {

        test('input has correct combobox ARIA attributes', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const role = await getShadowAttribute(page, 'widget-light', '.sm-input', 'role');
            const ariaAutocomplete = await getShadowAttribute(page, 'widget-light', '.sm-input', 'aria-autocomplete');
            const ariaHaspopup = await getShadowAttribute(page, 'widget-light', '.sm-input', 'aria-haspopup');
            const ariaExpanded = await getShadowAttribute(page, 'widget-light', '.sm-input', 'aria-expanded');
            const ariaControls = await getShadowAttribute(page, 'widget-light', '.sm-input', 'aria-controls');

            expect(role).toBe('combobox');
            expect(ariaAutocomplete).toBe('list');
            expect(ariaHaspopup).toBe('listbox');
            expect(ariaExpanded).toBe('false');
            expect(ariaControls).toBeTruthy();
        });

        test('listbox container has correct attributes', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const ariaLabel = await getShadowAttribute(page, 'widget-light', '.sm-results', 'aria-label');
            const listboxId = await getShadowAttribute(page, 'widget-light', '.sm-results', 'id');
            const inputControls = await getShadowAttribute(page, 'widget-light', '.sm-input', 'aria-controls');

            expect(ariaLabel).toBe('Search results');
            expect(listboxId).toBe(inputControls);

            // When empty, role should be removed
            const roleWhenEmpty = await getShadowAttribute(page, 'widget-light', '.sm-results', 'role');
            expect(roleWhenEmpty).toBeNull();
        });

    });

    test.describe('Keyboard Navigation', () => {

        test('hotkey opens modal', async ({ page }) => {
            const isMac = process.platform === 'darwin';
            await page.keyboard.press(isMac ? 'Meta+k' : 'Control+k');
            await page.waitForTimeout(200);

            const hidden = await getShadowAttribute(page, 'widget-light', '.sm-backdrop', 'hidden');
            expect(hidden).toBeNull();
        });

        test('arrow keys work while input is focused', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const isFocused = await isShadowElementFocused(page, 'widget-light', '.sm-input');
            expect(isFocused).toBe(true);

            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('ArrowUp');

            const stillFocused = await isShadowElementFocused(page, 'widget-light', '.sm-input');
            expect(stillFocused).toBe(true);
        });

    });

    test.describe('Live Region', () => {

        test('live region exists', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const hasLiveRegion = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return liveRegion !== null;
            }, 'widget-light');

            expect(hasLiveRegion).toBe(true);
        });

        test('live region has correct attributes', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const attrs = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return {
                    role: liveRegion?.getAttribute('role'),
                    ariaLive: liveRegion?.getAttribute('aria-live'),
                    ariaAtomic: liveRegion?.getAttribute('aria-atomic'),
                    className: liveRegion?.className,
                };
            }, 'widget-light');

            expect(attrs.role).toBe('status');
            expect(attrs.ariaLive).toBe('polite');
            expect(attrs.ariaAtomic).toBe('true');
            expect(attrs.className).toContain('sm-sr-only');
        });

    });

    test.describe('Dark Theme', () => {

        test('dark theme passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-dark'));
            await page.waitForTimeout(300);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

    });

    test.describe('Decorative Elements', () => {

        test('search icon is hidden from screen readers', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const ariaHidden = await getShadowAttribute(page, 'widget-light', '.sm-search-icon', 'aria-hidden');
            expect(ariaHidden).toBe('true');
        });

        test('trigger SVG is hidden from screen readers', async ({ page }) => {
            const ariaHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const svg = widget?.shadowRoot?.querySelector('.sm-trigger svg');
                return svg?.getAttribute('aria-hidden');
            }, 'widget-light');

            expect(ariaHidden).toBe('true');
        });

    });

});
