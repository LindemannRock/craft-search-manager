# Widget Configuration

Widget behavior can be controlled in three ways: CP settings, config file, and Twig parameters.

## Configuration Sources

1. **CP settings** — Search Manager > Widgets > create/edit a configuration
2. **Config file** — define widget configs in `config/search-manager.php`
3. **Twig parameters** — override per-include

Twig parameters take highest priority, followed by config file values, then CP settings.

## Config File

Define widget configs in `config/search-manager.php`:

```php
'widgets' => [
    'main-search' => [
        'name' => 'Main Search',
        'type' => 'modal',
        'enabled' => true,
        'styleHandle' => 'brand-dark',
        'settings' => [
            // Preferred: reference a CP-managed public API key by handle.
            // A render-time apiKey value still overrides this saved/config reference.
            'apiKeyHandle' => 'main-widget-key',
            'search' => [
                'indexHandles' => ['blog', 'products'],
                'placeholder' => 'Search...',
            ],
            'behavior' => [
                'resultsLimit' => 15,
                'debounce' => 300,
                'minChars' => 2,
                'recentlyViewedEnabled' => true,
                'recentlyViewedLimit' => 5,
                'resultsGroupingEnabled' => true,
                'hotkey' => 'k',
            ],
        ],
    ],
],
```

Config-defined widgets show a "Config" badge in the CP and cannot be edited there. Database-defined widgets show a "Database" badge and are fully editable. The supported widget `type` is `modal`; other type values are rejected during validation or config loading.

## Twig Parameters

Override settings per-include:

```twig
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'homepage',
    indexHandles: ['blog', 'products'],
    placeholder: 'Search articles...',
    theme: 'dark',
    resultsLimit: 15,
    searchDebounceMs: 300,
    searchMinChars: 2,
    recentlyViewedEnabled: true,
    recentlyViewedLimit: 5,
    triggerHotkey: 'k',
    resultsLayout: 'hierarchical',
    hierarchyGroupBy: '',
    hierarchyStyle: 'tree',
    hierarchyDisplay: 'unified',
    resultsRequireUrl: false,
    triggerEnabled: true,
    triggerLabel: 'Search',
    triggerSelector: '#my-search-btn',

    {# Style overrides (from style preset, can be overridden inline) #}
    highlightResultsEnabled: true,
    highlightTag: 'mark',
    highlightClass: 'search-highlight',
    modalBackdropOpacity: 50,
    modalBackdropBlurEnabled: true,

    {# Modal behavior #}
    modalPreventBodyScroll: true,
    highlightDestinationEnabled: true,
    highlightDestinationPersistQuery: true,
    highlightDestinationQueryParam: 'smq',
    highlightDestinationContentSelector: 'main, article, [data-search-content]',

    {# Analytics #}
    analyticsSource: 'header-search',
    analyticsIdleTimeoutMs: 1500,

    {# Developer #}
    debugEnabled: false,
} %}
```

## All Parameters

### General

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `configHandle` | `string` | — | Widget config handle (loads settings from CP/config) |
| `indexHandles` | `array|string` | `[]` | Up to 5 explicit index handles to search. Use an array in Twig config or a comma-separated string on the web component; empty = all enabled indices. |
| `placeholder` | `string` | `'Search...'` | Input placeholder text |
| `theme` | `string` | `'light'` | `'light'` or `'dark'` |
| `siteId` | `int` | — | Specific site to search |
| `dir` | `string` | — | Text direction: `'ltr'` or `'rtl'` |
| `apiKey` | `string` | — | Optional raw **public** [API key](../feature-tour/api-keys.md) value emitted into page HTML as `X-Search-Manager-Key` request material. Prefer `settings.apiKeyHandle` for saved/config references to CP-managed public keys by handle. Use `apiKey` only for render-time overrides or config-only widgets that intentionally provide the actual public key value. Never use a server key. |
| `styles` | `object` | `{}` | Override individual [style properties](styles.md) at render time |

### Search Input

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `searchDebounceMs` | `int` | `200` | Debounce delay in ms (0-2000) |
| `searchMinChars` | `int` | `2` | Minimum characters before searching (1-10) |
| `loadingIndicatorEnabled` | `bool` | `true` | Show loading spinner during search |

### Modal & Trigger

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `modalPreventBodyScroll` | `bool` | `true` | Prevent body scroll when open |
| `modalBackdropOpacity` | `int` | `50` | Backdrop opacity (0-100) |
| `modalBackdropBlurEnabled` | `bool` | `true` | Enable backdrop blur effect |
| `triggerEnabled` | `bool` | `true` | Show the trigger button |
| `triggerHotkey` | `string` | `'k'` | Keyboard shortcut key |
| `triggerLabel` | `string` | `'Search'` | Trigger button text |
| `triggerSelector` | `string` | — | CSS selector for an external trigger element |

### Recently Viewed

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `recentlyViewedEnabled` | `bool` | `true` | Show the "Recently viewed" section — results the visitor previously opened, stored in their browser |
| `recentlyViewedLimit` | `int` | `5` | Maximum recently viewed entries to store (1-50) |
| `promotionDisplay` | `string` | `none` | How promoted results are marked: `badge`, `tint` (row background), or `none`. Colors come from the widget style's Promoted section |
| `promotionBadgeText` | `string` | `Featured` | Badge label; also the screen-reader label in tint mode. The default is localized per site language; custom text runs through Craft's `site` translation category |
| `promotionBadgePosition` | `string` | `inline` | Badge placement: `inline` (before the title), `above` (own line above the title), or `below` (own line below it) — badge mode only |

### Results

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `resultsLimit` | `int` | `10` | Maximum results to show (1-100) |
| `resultsGroupingEnabled` | `bool` | `true` | Group flat results by `source`, `entrySection`, or `type`. **Default layout only** — the hierarchical layout ignores this and always groups by `hierarchyGroupBy` |
| `resultsRequireUrl` | `bool` | `false` | Hide results without a URL |

### Hierarchy @since(5.39.0)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `resultsLayout` | `string` | `'default'` | Result layout: `'default'` or `'hierarchical'` |
| `hierarchyGroupBy` | `string` | `''` | Field whose values become the section headers in hierarchical layout (grouping is always on there). Leave empty for the default `source` → `entrySection` → `type` chain. |
| `hierarchyStyle` | `string` | `'tree'` | Hierarchy display style: `'tree'` (indented + connectors), `'flat'` (no indentation + connectors), or `'none'` (no indentation, no connectors) |
| `hierarchyDisplay` | `string` | `'individual'` | Card mode: `'individual'` (each parent result is its own card) or `'unified'` (page block and heading children share one card) |
| `hierarchyMaxHeadings` | `int` | `3` | Maximum heading children shown per page block (1-50). Split hits are selected by score, then displayed in document order. |

When a searched split-capable index uses split sections, `resultsLayout: 'default'` renders each section hit as its own result. `resultsLayout: 'hierarchical'` groups split section hits by parent element, orders parents by their best matching section score, uses an intro hit as the parent snippet only when that intro hit is present, and renders kept heading hits as children in document order. `hierarchyMaxHeadings` keeps the highest-scoring heading children before restoring document order for display.

### Snippets

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `snippetMode` | `string` | `'balanced'` | Snippet passage choice for eligible fields, page bodies, and split section bodies: `'early'`, `'balanced'`, or `'deep'` |
| `snippetMaxLength` | `int` | `150` | Maximum snippet length in characters for page and section snippets (50-1000) |
| `snippetIncludeCodeBlocks` | `bool` | `false` | Allow snippets to use block-level code from page or section bodies; inline code text is always preserved |
| `snippetCleanMarkdown` | `bool` | `false` | Clean Markdown markers from page and section snippet display text without changing indexed content |
### Result Highlighting

> [!NOTE]
> `highlightResultsEnabled`, `highlightTag`, and `highlightClass` are style-layer overrides — they come from the style preset and can be overridden inline. The destination page highlighting params below are config-layer behavior settings.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `highlightResultsEnabled` | `bool` | `true` | Highlight matched terms in results |
| `highlightTag` | `string` | `'mark'` | Highlight HTML tag |
| `highlightClass` | `string` | — | Highlight CSS class |

The widget uses `highlightTag` and `highlightClass` client-side for titles and snippets. Search responses return plain snippet text; the widget applies highlighting while rendering.

### Destination Highlighting

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `highlightDestinationEnabled` | `bool` | `true` | Highlight search terms on the destination page after navigating from a result |
| `highlightDestinationPersistQuery` | `bool` | `true` | Append the search query to the destination URL so the page knows what to highlight |
| `highlightDestinationQueryParam` | `string` | `'smq'` | URL parameter name for the persisted search query |
| `highlightDestinationContentSelector` | `string` | `'main, article, [data-search-content]'` | CSS selector for page content areas to scan for highlighting |

### Analytics

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `analyticsSource` | `string` | — | Custom analytics source identifier |
| `analyticsIdleTimeoutMs` | `int` | `1500` | Track search after idle (ms), 0 to disable |

### Developer

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `debugEnabled` | `bool` | `false` | Enable debug toolbar overlay. Requires `devMode` or `searchManager:viewDebug` permission. |

## External Trigger

Connect any element on your page to open the search modal:

```twig
<button id="my-search-btn" class="search-button">
    <svg>...</svg> Search
</button>

{% include 'search-manager/_widget/search-modal' with {
    triggerEnabled: false,
    triggerSelector: '#my-search-btn',
} %}
```

Any element matching the `triggerSelector` will open the modal when clicked.

## Default Widget

Set a default widget via `defaultWidgetHandle` in config or CP settings:

```php
// config/search-manager.php
'defaultWidgetHandle' => 'main-search',
```

If the default widget is deleted, another enabled widget is automatically assigned.

## CP Widget Management

Widget configurations can be managed at Search Manager > Widgets. Each config uses these sections:

- **General** — name, handle, search indices
- **Search Input** — placeholder, debounce, minimum characters, loading indicator
- **Modal & Trigger** — hotkey, trigger button, scroll lock, backdrop behavior
- **Recent Searches** — the "Recently viewed" section (results the visitor opened) and its stored-entry limit
- **Results** — result limit, grouping, URL requirement, line clamping
- **Hierarchy** — result layout, grouping field, hierarchy style, heading limit
- **Snippets** — block-code snippets, snippet mode, snippet length, Markdown marker cleanup
- **Destination Highlighting** — destination-page highlight toggle, persisted query, query param, content selector
- **Analytics** — source identifier, idle timeout

Visual appearance and result highlighting are controlled via the **Widget Style** selector in the sidebar, not a dedicated tab.
