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
        'style' => 'brand-dark',
        'settings' => [
            'search' => [
                'indexHandles' => ['blog', 'products'],
                'placeholder' => 'Search...',
            ],
            'behavior' => [
                'maxResults' => 15,
                'debounce' => 300,
                'minChars' => 2,
                'showRecent' => true,
                'maxRecentSearches' => 5,
                'groupResults' => true,
                'hotkey' => 'k',
            ],
        ],
    ],
    'docs-search' => [
        'name' => 'Documentation Search',
        'type' => 'page',
        'settings' => [
            'behavior' => [
                'resultLayout' => 'hierarchical',
                'hierarchyGroupBy' => 'section',
                'hierarchyStyle' => 'tree',
                'hierarchyDisplay' => 'unified',
                'maxHeadingsPerResult' => 5,
                'snippetMode' => 'deep',
            ],
        ],
    ],
],
```

Config-defined widgets show a "Config" badge in the CP and cannot be edited there. Database-defined widgets show a "Database" badge and are fully editable.

## Twig Parameters

Override settings per-include:

```twig
{% include 'search-manager/_widget/search-modal' with {
    config: 'homepage',
    indices: ['blog', 'products'],
    placeholder: 'Search articles...',
    theme: 'dark',
    maxResults: 15,
    debounce: 300,
    minChars: 2,
    showRecent: true,
    maxRecentSearches: 5,
    groupResults: true,
    hotkey: 'k',
    resultLayout: 'hierarchical',
    hierarchyGroupBy: 'section',
    hierarchyStyle: 'tree',
    hierarchyDisplay: 'unified',
    hideResultsWithoutUrl: false,
    showTrigger: true,
    triggerText: 'Search',
    triggerSelector: '#my-search-btn',

    {# Style overrides (from style preset, can be overridden inline) #}
    enableHighlighting: true,
    highlightTag: 'mark',
    highlightClass: 'search-highlight',
    backdropOpacity: 50,
    enableBackdropBlur: true,

    {# Behavior settings (from widget config) #}
    preventBodyScroll: true,
    highlightDestinationPage: true,
    persistQueryInUrl: true,
    queryParamName: 'smq',
    destinationHighlightSelector: 'main, article, [data-search-content]',

    {# Analytics #}
    source: 'header-search',
    idleTimeout: 1500,

    {# Developer #}
    debug: false,
} %}
```

## All Parameters

### General

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `config` | `string` | — | Widget config handle (loads settings from CP/config) |
| `indices` | `array` | `[]` | Index handles to search (empty = all) |
| `placeholder` | `string` | `'Search...'` | Input placeholder text |
| `theme` | `string` | `'light'` | `'light'` or `'dark'` |
| `siteId` | `int` | — | Specific site to search |
| `dir` | `string` | — | Text direction: `'ltr'` or `'rtl'` |
| `styles` | `object` | `{}` | Override individual style values |
| `styleHandle` | `string` | — | Handle of a [Widget Style](styles.md) preset for appearance |

### Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `maxResults` | `int` | `10` | Maximum results to show (1-100) |
| `debounce` | `int` | `200` | Debounce delay in ms (0-2000) |
| `minChars` | `int` | `2` | Minimum characters before searching (1-10) |
| `showRecent` | `bool` | `true` | Show recent search history |
| `maxRecentSearches` | `int` | `5` | Maximum recent searches to store (1-50) |
| `groupResults` | `bool` | `true` | Group results by type/section |
| `hotkey` | `string` | `'k'` | Keyboard shortcut key |
| `hideResultsWithoutUrl` | `bool` | `false` | Hide results without a URL |
| `preventBodyScroll` | `bool` | `true` | Prevent body scroll when open |
| `showLoadingIndicator` | `bool` | `true` | Show loading spinner during search |

### Result Layout @since(5.39.0)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `resultLayout` | `string` | `'default'` | Result layout: `'default'` or `'hierarchical'` |
| `hierarchyGroupBy` | `string` | `''` | Field to group hierarchical results by (e.g., `'section'`) |
| `hierarchyStyle` | `string` | `'tree'` | Hierarchy display style: `'tree'` (indented + connectors), `'flat'` (no indentation + connectors), or `'none'` (no indentation, no connectors) |
| `hierarchyDisplay` | `string` | `'individual'` | Card mode: `'individual'` (each result is its own card) or `'unified'` (page and headings share one card, Starlight-style) |
| `maxHeadingsPerResult` | `int` | `3` | Maximum heading children shown per result (1-50) |

### Snippets

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `snippetMode` | `string` | `'balanced'` | Snippet extraction: `'early'`, `'balanced'`, or `'deep'` |
| `snippetLength` | `int` | `150` | Maximum snippet length in characters (50-500) |
| `showCodeSnippets` | `bool` | `false` | Show code snippets in descriptions |
| `parseMarkdownSnippets` | `bool` | `false` | Parse markdown before building snippets |
| `resultTitleLines` | `int` | `1` | Title line clamp count (1-5) |
| `resultDescLines` | `int` | `1` | Description line clamp count (1-5) |

### Trigger

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `showTrigger` | `bool` | `true` | Show the trigger button |
| `triggerText` | `string` | `'Search'` | Trigger button text |
| `triggerSelector` | `string` | — | CSS selector for an external trigger element |

### Highlighting

> [!NOTE]
> `enableHighlighting`, `highlightTag`, `highlightClass`, `backdropOpacity`, and `enableBackdropBlur` are style-layer overrides — they come from the style preset and can be overridden inline. The destination page highlighting params below are config-layer behavior settings.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enableHighlighting` | `bool` | `true` | Highlight matched terms in results |
| `highlightTag` | `string` | `'mark'` | Highlight HTML tag |
| `highlightClass` | `string` | — | Highlight CSS class |
| `backdropOpacity` | `int` | `50` | Backdrop opacity (0-100) |
| `enableBackdropBlur` | `bool` | `true` | Enable backdrop blur effect |

### Destination Highlighting

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `highlightDestinationPage` | `bool` | `true` | Highlight search terms on the destination page after navigating from a result |
| `persistQueryInUrl` | `bool` | `true` | Append the search query to the destination URL so the page knows what to highlight |
| `queryParamName` | `string` | `'smq'` | URL parameter name for the persisted search query |
| `destinationHighlightSelector` | `string` | `'main, article, [data-search-content]'` | CSS selector for page content areas to scan for highlighting |

### Analytics

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `source` | `string` | — | Custom analytics source identifier |
| `idleTimeout` | `int` | `1500` | Track search after idle (ms), 0 to disable |

### Developer

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `debug` | `bool` | `false` | Enable debug toolbar overlay. Requires `devMode` or `searchManager:viewDebug` permission. |

## External Trigger

Connect any element on your page to open the search modal:

```twig
<button id="my-search-btn" class="search-button">
    <svg>...</svg> Search
</button>

{% include 'search-manager/_widget/search-modal' with {
    showTrigger: false,
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

Widget configurations can be managed at Search Manager > Widgets. Each config has six tabs:

- **General** — name, handle, search indices
- **Behavior** — placeholder, debounce, min chars, hotkey, scroll lock, loading indicator, recent searches, trigger button
- **Results** — max results, hide no-URL results, result layout (default/hierarchical), hierarchy options, line clamping
- **Snippets** — code snippets, snippet mode, snippet length, markdown parsing
- **Highlights** — destination page highlighting, persist query in URL, query param name, content selector
- **Analytics** — source identifier, idle timeout

Visual appearance is controlled via the **Widget Style** selector in the sidebar, not a dedicated tab.
