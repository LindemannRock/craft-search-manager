/**
 * Static Accessibility Tests for Search Widget
 *
 * Tests against a local static HTML fixture file.
 * Useful for quick offline testing without needing DDEV running.
 * Tests widget structure, ARIA attributes, and all result rendering modes.
 *
 * Run with: npm run test:a11y
 *
 * @see https://www.deque.com/axe/
 * @copyright Copyright (c) 2026 LindemannRock
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

// Static test page URL (served by local http-server)
const TEST_PAGE = 'http://localhost:3333/tests/fixtures/test-a11y.html';

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
 * Helper to wait for shadow DOM to be ready on all search-modal elements
 */
async function waitForShadowDOM(page) {
    await page.waitForFunction(() => {
        const widgets = document.querySelectorAll('search-modal');
        return widgets.length > 0 && Array.from(widgets).every(w => w.shadowRoot !== null);
    }, { timeout: 5000 });
}

/**
 * Helper to get attribute from shadow DOM element by widget ID
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
 * Helper to count elements in shadow DOM
 */
async function countShadowElements(page, widgetId, selector) {
    return page.evaluate(([id, sel]) => {
        const widget = document.getElementById(id);
        return widget?.shadowRoot?.querySelectorAll(sel)?.length || 0;
    }, [widgetId, selector]);
}

/**
 * Helper to get text content from shadow DOM
 */
async function getShadowText(page, widgetId, selector) {
    return page.evaluate(([id, sel]) => {
        const widget = document.getElementById(id);
        const el = widget?.shadowRoot?.querySelector(sel);
        return el?.textContent?.trim();
    }, [widgetId, selector]);
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
// STATIC FIXTURE TESTS
// ============================================================================

test.describe('Search Widget A11y - Static Fixture', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(TEST_PAGE);
        await waitForShadowDOM(page);
    });

    // ------------------------------------------------------------------------
    // Trigger Button
    // ------------------------------------------------------------------------

    test.describe('Trigger Button', () => {

        test('page loads without axe violations', async ({ page }) => {
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

        test('trigger SVG is hidden from screen readers', async ({ page }) => {
            const ariaHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const svg = widget?.shadowRoot?.querySelector('.sm-trigger svg');
                return svg?.getAttribute('aria-hidden');
            }, 'widget-light');

            expect(ariaHidden).toBe('true');
        });

        test('keyboard badge is hidden from screen readers', async ({ page }) => {
            const ariaHidden = await getShadowAttribute(page, 'widget-light', '.sm-trigger-kbd', 'aria-hidden');
            expect(ariaHidden).toBe('true');
        });

    });

    // ------------------------------------------------------------------------
    // Modal Dialog
    // ------------------------------------------------------------------------

    test.describe('Modal Dialog', () => {

        test('open modal passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(300);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('modal has role="dialog" with aria-modal', async ({ page }) => {
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

        test('close button has accessible name', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const ariaLabel = await getShadowAttribute(page, 'widget-light', '.sm-close', 'aria-label');
            expect(ariaLabel).toBe('Close search');
        });

    });

    // ------------------------------------------------------------------------
    // Combobox Pattern
    // ------------------------------------------------------------------------

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

        test('results container has aria-label', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const ariaLabel = await getShadowAttribute(page, 'widget-light', '.sm-results', 'aria-label');
            expect(ariaLabel).toBe('Search results');
        });

        test('aria-controls points to results container ID', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const inputControls = await getShadowAttribute(page, 'widget-light', '.sm-input', 'aria-controls');
            const listboxId = await getShadowAttribute(page, 'widget-light', '.sm-results', 'id');

            expect(inputControls).toBe(listboxId);
        });

    });

    // ------------------------------------------------------------------------
    // Flat Results (default)
    // ------------------------------------------------------------------------

    test.describe('Flat Results', () => {

        test('flat results pass axe checks', async ({ page }) => {
            await page.evaluate(() => {
                window.openWidget('widget-light');
            });
            await page.waitForTimeout(200);
            await page.evaluate(() => {
                window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'guide');
            });
            await page.waitForTimeout(200);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('result items have role="option"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const count = await countShadowElements(page, 'widget-light', '[role="option"]');
            expect(count).toBeGreaterThan(0);
        });

        test('result items have unique IDs', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const ids = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const items = widget?.shadowRoot?.querySelectorAll('[role="option"]');
                return Array.from(items || []).map(item => item.id);
            }, 'widget-light');

            expect(ids.length).toBeGreaterThan(0);
            const uniqueIds = new Set(ids);
            expect(uniqueIds.size).toBe(ids.length);
            ids.forEach(id => expect(id).toBeTruthy());
        });

        test('first result has aria-selected="true"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const firstSelected = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const first = widget?.shadowRoot?.querySelector('[role="option"]');
                return first?.getAttribute('aria-selected');
            }, 'widget-light');

            expect(firstSelected).toBe('true');
        });

        test('arrow icons are hidden from screen readers', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const allHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const arrows = widget?.shadowRoot?.querySelectorAll('.sm-result-arrow');
                return Array.from(arrows || []).every(a => a.getAttribute('aria-hidden') === 'true');
            }, 'widget-light');

            expect(allHidden).toBe(true);
        });

    });

    // ------------------------------------------------------------------------
    // Grouped Results
    // ------------------------------------------------------------------------

    test.describe('Grouped Results', () => {

        test('grouped results pass axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-grouped'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-grouped', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('groups have role="group" with aria-label', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-grouped'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-grouped', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const groups = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const sections = widget?.shadowRoot?.querySelectorAll('.sm-section[role="group"]');
                return Array.from(sections || []).map(s => ({
                    role: s.getAttribute('role'),
                    label: s.getAttribute('aria-label'),
                }));
            }, 'widget-grouped');

            expect(groups.length).toBeGreaterThan(0);
            groups.forEach(g => {
                expect(g.role).toBe('group');
                expect(g.label).toBeTruthy();
            });
        });

        test('group section headers are present', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-grouped'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-grouped', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const headerCount = await countShadowElements(page, 'widget-grouped', '.sm-section-header');
            expect(headerCount).toBeGreaterThan(0);
        });

        test('result items inside groups still have role="option"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-grouped'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-grouped', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const count = await countShadowElements(page, 'widget-grouped', '.sm-section [role="option"]');
            expect(count).toBeGreaterThan(0);
        });

    });

    // ------------------------------------------------------------------------
    // Hierarchical Results
    // ------------------------------------------------------------------------

    test.describe('Hierarchical Results', () => {

        test('hierarchical results pass axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('hierarchy groups have role="group" with aria-label', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const groups = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const sections = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-group[role="group"]');
                return Array.from(sections || []).map(s => ({
                    role: s.getAttribute('role'),
                    label: s.getAttribute('aria-label'),
                }));
            }, 'widget-hierarchical');

            expect(groups.length).toBeGreaterThan(0);
            groups.forEach(g => {
                expect(g.role).toBe('group');
                expect(g.label).toBeTruthy();
            });
        });

        test('parent items have role="option"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const parents = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const items = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-parent[role="option"]');
                return Array.from(items || []).map(i => ({
                    role: i.getAttribute('role'),
                    id: i.id,
                }));
            }, 'widget-hierarchical');

            expect(parents.length).toBeGreaterThan(0);
            parents.forEach(p => {
                expect(p.role).toBe('option');
                expect(p.id).toBeTruthy();
            });
        });

        test('child heading items have role="option"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const children = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const items = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-child[role="option"]');
                return Array.from(items || []).map(i => ({
                    role: i.getAttribute('role'),
                    id: i.id,
                }));
            }, 'widget-hierarchical');

            expect(children.length).toBeGreaterThan(0);
            children.forEach(c => {
                expect(c.role).toBe('option');
                expect(c.id).toBeTruthy();
            });
        });

        test('all hierarchy icons are aria-hidden', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const allHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const icons = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-icon');
                return Array.from(icons || []).every(i => i.getAttribute('aria-hidden') === 'true');
            }, 'widget-hierarchical');

            expect(allHidden).toBe(true);
        });

        test('child heading links include anchor fragment', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const hrefs = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const children = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-child');
                return Array.from(children || []).map(c => c.getAttribute('href'));
            }, 'widget-hierarchical');

            expect(hrefs.length).toBeGreaterThan(0);
            hrefs.forEach(href => {
                expect(href).toContain('#');
            });
        });

        test('all option IDs are unique across parents and children', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const ids = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const options = widget?.shadowRoot?.querySelectorAll('[role="option"]');
                return Array.from(options || []).map(o => o.id);
            }, 'widget-hierarchical');

            expect(ids.length).toBeGreaterThan(0);
            const uniqueIds = new Set(ids);
            expect(uniqueIds.size).toBe(ids.length);
        });

        test('heading levels 1 through 6 are all rendered as children', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const levels = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const children = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-child');
                const found = new Set();
                children?.forEach(c => {
                    for (let i = 1; i <= 6; i++) {
                        if (c.classList.contains('sm-hierarchy-level-' + i)) {
                            found.add(i);
                        }
                    }
                });
                return Array.from(found).sort();
            }, 'widget-hierarchical');

            expect(levels).toEqual([1, 2, 3, 4, 5, 6]);
        });

        test('parents without children render without children container', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const stats = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const blocks = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-block');
                let withChildren = 0;
                let withoutChildren = 0;
                blocks?.forEach(b => {
                    if (b.classList.contains('sm-hierarchy-block--has-children')) {
                        withChildren++;
                    } else {
                        withoutChildren++;
                    }
                });
                return { withChildren, withoutChildren };
            }, 'widget-hierarchical');

            expect(stats.withChildren).toBeGreaterThan(0);
            expect(stats.withoutChildren).toBeGreaterThan(0);
        });

        test('multiple groups are rendered with distinct labels', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const labels = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const groups = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-group[role="group"]');
                return Array.from(groups || []).map(g => g.getAttribute('aria-label'));
            }, 'widget-hierarchical');

            expect(labels.length).toBeGreaterThanOrEqual(3);
            const uniqueLabels = new Set(labels);
            expect(uniqueLabels.size).toBe(labels.length);
        });

        test('total option count matches parents + children', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-hierarchical'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-hierarchical', window.MOCK_HIERARCHICAL_RESULTS, 'install'));
            await page.waitForTimeout(200);

            const counts = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const parents = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-parent[role="option"]')?.length || 0;
                const children = widget?.shadowRoot?.querySelectorAll('.sm-hierarchy-child[role="option"]')?.length || 0;
                const totalOptions = widget?.shadowRoot?.querySelectorAll('[role="option"]')?.length || 0;
                return { parents, children, totalOptions };
            }, 'widget-hierarchical');

            expect(counts.totalOptions).toBe(counts.parents + counts.children);
            // Verify we have significant data: 10 parents, 30+ children
            expect(counts.parents).toBeGreaterThanOrEqual(10);
            expect(counts.children).toBeGreaterThanOrEqual(25);
        });

    });

    // ------------------------------------------------------------------------
    // Promoted Results
    // ------------------------------------------------------------------------

    test.describe('Promoted Results', () => {

        test('promoted result has sm-promoted class', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'result'));
            await page.waitForTimeout(200);

            const promotedCount = await countShadowElements(page, 'widget-light', '.sm-promoted');
            expect(promotedCount).toBeGreaterThan(0);
        });

        test('promoted badge is present on promoted items', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'result'));
            await page.waitForTimeout(200);

            const badgeCount = await countShadowElements(page, 'widget-light', '.sm-promoted-badge');
            expect(badgeCount).toBeGreaterThan(0);
        });

        test('promoted results still have role="option"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'result'));
            await page.waitForTimeout(200);

            const promotedRole = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const promoted = widget?.shadowRoot?.querySelector('.sm-promoted');
                return promoted?.getAttribute('role');
            }, 'widget-light');

            expect(promotedRole).toBe('option');
        });

    });

    // ------------------------------------------------------------------------
    // Empty State
    // ------------------------------------------------------------------------

    test.describe('Empty State', () => {

        test('empty state (no query) passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(300);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('empty state SVG is aria-hidden', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);

            const ariaHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const svg = widget?.shadowRoot?.querySelector('.sm-empty svg');
                return svg?.getAttribute('aria-hidden');
            }, 'widget-light');

            expect(ariaHidden).toBe('true');
        });

        test('no-results state passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectEmptyState('widget-light', 'xyznonexistent'));
            await page.waitForTimeout(200);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

    });

    // ------------------------------------------------------------------------
    // Loading State
    // ------------------------------------------------------------------------

    test.describe('Loading State', () => {

        test('loading spinner SVG is aria-hidden', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectLoadingState('widget-light'));
            await page.waitForTimeout(200);

            const ariaHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const spinner = widget?.shadowRoot?.querySelector('.sm-spinner');
                return spinner?.getAttribute('aria-hidden');
            }, 'widget-light');

            expect(ariaHidden).toBe('true');
        });

    });

    // ------------------------------------------------------------------------
    // Recent Searches
    // ------------------------------------------------------------------------

    test.describe('Recent Searches', () => {

        test('recent search items have role="option"', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectRecentSearches('widget-light'));
            await page.waitForTimeout(200);

            const recentItems = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const items = widget?.shadowRoot?.querySelectorAll('.sm-recent-item[role="option"]');
                return Array.from(items || []).map(i => ({
                    role: i.getAttribute('role'),
                    id: i.id,
                }));
            }, 'widget-light');

            expect(recentItems.length).toBeGreaterThan(0);
            recentItems.forEach(item => {
                expect(item.role).toBe('option');
                expect(item.id).toBeTruthy();
            });
        });

        test('recent search clock icons are aria-hidden', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectRecentSearches('widget-light'));
            await page.waitForTimeout(200);

            const allHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const icons = widget?.shadowRoot?.querySelectorAll('.sm-recent-item .sm-result-icon');
                return Array.from(icons || []).every(i => i.getAttribute('aria-hidden') === 'true');
            }, 'widget-light');

            expect(allHidden).toBe(true);
        });

        test('recent searches section has header', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectRecentSearches('widget-light'));
            await page.waitForTimeout(200);

            const headerText = await getShadowText(page, 'widget-light', '.sm-section-header');
            expect(headerText).toContain('Recent');
        });

    });

    // ------------------------------------------------------------------------
    // Keyboard Navigation
    // ------------------------------------------------------------------------

    test.describe('Keyboard Navigation', () => {

        test('hotkey opens modal', async ({ page }) => {
            const isMac = process.platform === 'darwin';
            await page.keyboard.press(isMac ? 'Meta+k' : 'Control+k');
            await page.waitForTimeout(200);

            const hidden = await getShadowAttribute(page, 'widget-light', '.sm-backdrop', 'hidden');
            expect(hidden).toBeNull();
        });

        test('arrow keys stay in input (focus does not leave)', async ({ page }) => {
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

    // ------------------------------------------------------------------------
    // Live Region
    // ------------------------------------------------------------------------

    test.describe('Live Region', () => {

        test('live region exists with correct attributes', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const attrs = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const liveRegion = widget?.shadowRoot?.querySelector('[aria-live="polite"]');
                return liveRegion ? {
                    role: liveRegion.getAttribute('role'),
                    ariaLive: liveRegion.getAttribute('aria-live'),
                    ariaAtomic: liveRegion.getAttribute('aria-atomic'),
                    className: liveRegion.className,
                } : null;
            }, 'widget-light');

            expect(attrs).not.toBeNull();
            expect(attrs.role).toBe('status');
            expect(attrs.ariaLive).toBe('polite');
            expect(attrs.ariaAtomic).toBe('true');
            expect(attrs.className).toContain('sm-sr-only');
        });

    });

    // ------------------------------------------------------------------------
    // Dark Theme
    // ------------------------------------------------------------------------

    test.describe('Dark Theme', () => {

        test('dark theme passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-dark'));
            await page.waitForTimeout(300);

            const results = await new AxeBuilder({ page })
                .options(AXE_OPTIONS)
                .analyze();

            expect(results.violations, formatViolations(results.violations)).toHaveLength(0);
        });

        test('dark theme with results passes axe checks', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-dark'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-dark', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

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

        test('search icon in header is aria-hidden', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(100);

            const ariaHidden = await getShadowAttribute(page, 'widget-light', '.sm-search-icon', 'aria-hidden');
            expect(ariaHidden).toBe('true');
        });

        test('all decorative SVGs in results are aria-hidden', async ({ page }) => {
            await page.evaluate(() => window.openWidget('widget-light'));
            await page.waitForTimeout(200);
            await page.evaluate(() => window.injectResults('widget-light', window.MOCK_FLAT_RESULTS, 'guide'));
            await page.waitForTimeout(200);

            const allHidden = await page.evaluate((id) => {
                const widget = document.getElementById(id);
                const svgs = widget?.shadowRoot?.querySelectorAll('.sm-results svg');
                return Array.from(svgs || []).every(svg => svg.getAttribute('aria-hidden') === 'true');
            }, 'widget-light');

            expect(allHidden).toBe(true);
        });

    });

});
