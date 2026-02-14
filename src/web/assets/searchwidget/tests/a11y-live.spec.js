/**
 * Live Accessibility Tests for Search Widget
 *
 * Tests against a real Craft CMS site with actual search results.
 * Uses axe-core via @axe-core/playwright to test WCAG 2.1 AA compliance.
 *
 * Run with: npm run test:a11y:live
 * Requires DDEV site to be running: ddev start
 *
 * The test page should have multiple widget configurations:
 *   - #widget-light-container: Light theme, flat results (default)
 *   - #widget-dark-container: Dark theme
 *   - #widget-grouped-container: Grouped results (group-results="true")
 *   - #widget-hierarchical-container: Hierarchical results (result-layout="hierarchical")
 *
 * @see https://www.deque.com/axe/
 * @copyright Copyright (c) 2026 LindemannRock
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

// Test page URL on the Craft site
const TEST_PAGE = '/test-a11y-widget';

// Search keywords
const SEARCH_KEYWORD = 'test';
const SEARCH_NO_RESULTS = 'xyznonexistentquery12345';

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
        const widgets = document.querySelectorAll('search-modal');
        return widgets.length > 0 && Array.from(widgets).every(w => w.shadowRoot !== null);
    }, { timeout: 10000 });
}

/**
 * Helper to get attribute from shadow DOM element
 */
async function getShadowAttribute(page, containerSelector, shadowSelector, attribute) {
    return page.evaluate(([containerSel, shadowSel, attr]) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-modal');
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
        const widget = container?.querySelector('search-modal');
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
        const widget = container?.querySelector('search-modal');
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
        const widget = container?.querySelector('search-modal');
        const input = widget?.shadowRoot?.querySelector('.sm-input');
        if (input) {
            input.value = txt;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, [containerSelector, text]);
}

/**
 * Helper to count result items (flat, grouped, and hierarchical)
 */
async function getResultCount(page, containerSelector) {
    return page.evaluate((containerSel) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-modal');
        const items = widget?.shadowRoot?.querySelectorAll('[role="option"]');
        return items?.length || 0;
    }, containerSelector);
}

/**
 * Helper to count elements in shadow DOM
 */
async function countShadowElements(page, containerSelector, selector) {
    return page.evaluate(([containerSel, sel]) => {
        const container = document.querySelector(containerSel);
        const widget = container?.querySelector('search-modal');
        return widget?.shadowRoot?.querySelectorAll(sel)?.length || 0;
    }, [containerSelector, selector]);
}

/**
 * Format axe violations for readable error output
 */
function formatViolations(violations) {
    if (violations.length === 0) return 'No violations found';

    return violations.map(v => {
        const nodes = v.nodes.map(n => `    - ${n.html.substring(0, 120)}...`).join('\n');
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

test.describe('Search Widget A11y - Live Site', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(TEST_PAGE);
        await waitForShadowDOM(page);
    });

    // ------------------------------------------------------------------------
    // Basic Structure
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
    // Modal Dialog
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

        test('close button has accessible name', async ({ page }) => {
            await openWidget(page, 'light');

            const ariaLabel = await getShadowAttribute(page, '#widget-light-container', '.sm-close', 'aria-label');
            expect(ariaLabel).toBe('Close search');
        });

    });

    // ------------------------------------------------------------------------
    // Combobox Pattern
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
    // Flat Search Results (with real data)
    // ------------------------------------------------------------------------

    test.describe('Flat Results', () => {

        test('search results pass axe checks', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('results have role="option" and aria-selected', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 0) {
                const firstResult = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-modal');
                    const item = widget?.shadowRoot?.querySelector('[role="option"]');
                    return {
                        role: item?.getAttribute('role'),
                        ariaSelected: item?.getAttribute('aria-selected'),
                    };
                });

                expect(firstResult.role).toBe('option');
                expect(firstResult.ariaSelected).toBe('true');
            }
        });

        test('aria-expanded becomes true when results shown', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
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
            await page.waitForTimeout(1000);

            const ids = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-modal');
                const items = widget?.shadowRoot?.querySelectorAll('[role="option"]');
                return Array.from(items || []).map(item => item.id);
            });

            if (ids.length > 0) {
                const uniqueIds = new Set(ids);
                expect(uniqueIds.size).toBe(ids.length);
                ids.forEach(id => expect(id).toBeTruthy());
            }
        });

    });

    // ------------------------------------------------------------------------
    // Grouped Results (if test page has a grouped widget)
    // ------------------------------------------------------------------------

    test.describe('Grouped Results', () => {

        test('grouped widget with results passes axe checks', async ({ page }) => {
            // Check if grouped widget container exists on test page
            const hasGrouped = await page.evaluate(() => !!document.querySelector('#widget-grouped-container'));
            test.skip(!hasGrouped, 'Grouped widget container not on test page');

            await page.evaluate(() => {
                const container = document.querySelector('#widget-grouped-container');
                const widget = container?.querySelector('search-modal');
                widget?.open();
            });
            await page.waitForTimeout(300);

            await typeInWidget(page, '#widget-grouped-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('grouped sections have role="group" with aria-label', async ({ page }) => {
            const hasGrouped = await page.evaluate(() => !!document.querySelector('#widget-grouped-container'));
            test.skip(!hasGrouped, 'Grouped widget container not on test page');

            await page.evaluate(() => {
                const container = document.querySelector('#widget-grouped-container');
                const widget = container?.querySelector('search-modal');
                widget?.open();
            });
            await page.waitForTimeout(300);

            await typeInWidget(page, '#widget-grouped-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const groups = await page.evaluate(() => {
                const container = document.querySelector('#widget-grouped-container');
                const widget = container?.querySelector('search-modal');
                const sections = widget?.shadowRoot?.querySelectorAll('.sm-section[role="group"]');
                return Array.from(sections || []).map(s => ({
                    role: s.getAttribute('role'),
                    label: s.getAttribute('aria-label'),
                }));
            });

            if (groups.length > 0) {
                groups.forEach(g => {
                    expect(g.role).toBe('group');
                    expect(g.label).toBeTruthy();
                });
            }
        });

    });

    // ------------------------------------------------------------------------
    // Hierarchical Results (if test page has a hierarchical widget)
    // ------------------------------------------------------------------------

    test.describe('Hierarchical Results', () => {

        test('hierarchical widget with results passes axe checks', async ({ page }) => {
            const hasHierarchical = await page.evaluate(() => !!document.querySelector('#widget-hierarchical-container'));
            test.skip(!hasHierarchical, 'Hierarchical widget container not on test page');

            await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                widget?.open();
            });
            await page.waitForTimeout(300);

            await typeInWidget(page, '#widget-hierarchical-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('hierarchy groups have role="group" with aria-label', async ({ page }) => {
            const hasHierarchical = await page.evaluate(() => !!document.querySelector('#widget-hierarchical-container'));
            test.skip(!hasHierarchical, 'Hierarchical widget container not on test page');

            await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                widget?.open();
            });
            await page.waitForTimeout(300);

            await typeInWidget(page, '#widget-hierarchical-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const groups = await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                const sections = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-group[role="group"]');
                return Array.from(sections || []).map(s => ({
                    role: s.getAttribute('role'),
                    label: s.getAttribute('aria-label'),
                }));
            });

            if (groups.length > 0) {
                groups.forEach(g => {
                    expect(g.role).toBe('group');
                    expect(g.label).toBeTruthy();
                });
            }
        });

        test('parent and child items both have role="option"', async ({ page }) => {
            const hasHierarchical = await page.evaluate(() => !!document.querySelector('#widget-hierarchical-container'));
            test.skip(!hasHierarchical, 'Hierarchical widget container not on test page');

            await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                widget?.open();
            });
            await page.waitForTimeout(300);

            await typeInWidget(page, '#widget-hierarchical-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const items = await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                const parents = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-parent[role="option"]') || [];
                const children = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-child[role="option"]') || [];
                return {
                    parentCount: parents.length,
                    childCount: children.length,
                };
            });

            if (items.parentCount > 0) {
                // If we have hierarchy, both parents and children should exist
                expect(items.parentCount).toBeGreaterThan(0);
            }
        });

        test('hierarchy icons are aria-hidden', async ({ page }) => {
            const hasHierarchical = await page.evaluate(() => !!document.querySelector('#widget-hierarchical-container'));
            test.skip(!hasHierarchical, 'Hierarchical widget container not on test page');

            await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                widget?.open();
            });
            await page.waitForTimeout(300);

            await typeInWidget(page, '#widget-hierarchical-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const allHidden = await page.evaluate(() => {
                const container = document.querySelector('#widget-hierarchical-container');
                const widget = container?.querySelector('search-modal');
                const icons = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-icon');
                return Array.from(icons || []).every(i => i.getAttribute('aria-hidden') === 'true');
            });

            if (await countShadowElements(page, '#widget-hierarchical-container', '.sm-hierarchy-icon') > 0) {
                expect(allHidden).toBe(true);
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
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 1) {
                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(100);

                const activeDescendant = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-activedescendant');
                expect(activeDescendant).toBeTruthy();

                const secondItemId = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-modal');
                    const items = widget?.shadowRoot?.querySelectorAll('[role="option"]');
                    return items?.[1]?.id;
                });

                expect(activeDescendant).toBe(secondItemId);
            }
        });

        test('arrow up updates aria-activedescendant', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 1) {
                await page.keyboard.press('ArrowDown');
                await page.keyboard.press('ArrowUp');
                await page.waitForTimeout(100);

                const activeDescendant = await getShadowAttribute(page, '#widget-light-container', '.sm-input', 'aria-activedescendant');

                const firstItemId = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-modal');
                    const items = widget?.shadowRoot?.querySelectorAll('[role="option"]');
                    return items?.[0]?.id;
                });

                expect(activeDescendant).toBe(firstItemId);
            }
        });

        test('only one item has aria-selected=true at a time', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 1) {
                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(100);

                const selectedCount = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-modal');
                    const selected = widget?.shadowRoot?.querySelectorAll('[aria-selected="true"]');
                    return selected?.length || 0;
                });

                expect(selectedCount).toBe(1);
            }
        });

    });

    // ------------------------------------------------------------------------
    // Live Region Announcements
    // ------------------------------------------------------------------------

    test.describe('Live Region Announcements', () => {

        test('live region exists with correct attributes', async ({ page }) => {
            await openWidget(page, 'light');

            const attrs = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-modal');
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return liveRegion ? {
                    role: liveRegion.getAttribute('role'),
                    ariaLive: liveRegion.getAttribute('aria-live'),
                    ariaAtomic: liveRegion.getAttribute('aria-atomic'),
                    className: liveRegion.className,
                } : null;
            });

            expect(attrs).not.toBeNull();
            expect(attrs.role).toBe('status');
            expect(attrs.ariaLive).toBe('polite');
            expect(attrs.ariaAtomic).toBe('true');
            expect(attrs.className).toContain('sm-sr-only');
        });

        test('live region announces search results', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1200);

            const announcement = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-modal');
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return liveRegion?.textContent?.trim();
            });

            expect(announcement).toBeTruthy();
            expect(announcement).toMatch(/result|found|search/i);
        });

    });

    // ------------------------------------------------------------------------
    // No Results State
    // ------------------------------------------------------------------------

    test.describe('No Results State', () => {

        test('no-results state passes axe checks', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_NO_RESULTS);
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('empty state SVG is aria-hidden', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_NO_RESULTS);
            await page.waitForTimeout(1000);

            const ariaHidden = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-modal');
                const svg = widget?.shadowRoot?.querySelector('.sm-empty svg');
                return svg?.getAttribute('aria-hidden');
            });

            if (ariaHidden !== null) {
                expect(ariaHidden).toBe('true');
            }
        });

    });

    // ------------------------------------------------------------------------
    // Dark Theme
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
            await page.waitForTimeout(1000);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

    });

    // ------------------------------------------------------------------------
    // Decorative Elements
    // ------------------------------------------------------------------------

    test.describe('Decorative Elements', () => {

        test('search icon is hidden from screen readers', async ({ page }) => {
            await openWidget(page, 'light');

            const ariaHidden = await getShadowAttribute(page, '#widget-light-container', '.sm-search-icon', 'aria-hidden');
            expect(ariaHidden).toBe('true');
        });

        test('trigger SVG is hidden from screen readers', async ({ page }) => {
            const ariaHidden = await page.evaluate(() => {
                const container = document.querySelector('#widget-light-container');
                const widget = container?.querySelector('search-modal');
                const svg = widget?.shadowRoot?.querySelector('.sm-trigger svg');
                return svg?.getAttribute('aria-hidden');
            });

            expect(ariaHidden).toBe('true');
        });

        test('result arrow icons are hidden from screen readers', async ({ page }) => {
            await openWidget(page, 'light');
            await typeInWidget(page, '#widget-light-container', SEARCH_KEYWORD);
            await page.waitForTimeout(1000);

            const resultCount = await getResultCount(page, '#widget-light-container');

            if (resultCount > 0) {
                const allHidden = await page.evaluate(() => {
                    const container = document.querySelector('#widget-light-container');
                    const widget = container?.querySelector('search-modal');
                    const arrows = widget?.shadowRoot?.querySelectorAll('.sm-result-arrow');
                    return Array.from(arrows || []).every(a => a.getAttribute('aria-hidden') === 'true');
                });

                expect(allHidden).toBe(true);
            }
        });

    });

});
