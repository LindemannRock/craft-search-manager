# Frontend Widget

Search Manager includes a ready-to-use CMD+K style search modal for your frontend. It's built as a web component (`<search-widget>`) with full keyboard navigation, accessibility, theming, and click analytics.

## Key Features

- **WCAG 2.1 AA compliant** — tested with axe-core, all default colors meet 4.5:1 contrast ratio
- **Keyboard navigation** — arrow keys, Enter, Escape, configurable hotkey (default: CMD+K / Ctrl+K)
- **Light & dark themes** — built-in theme support with customizable colors
- **Recent searches** — optional search history stored locally
- **Grouped results** — group results by type/section
- **Term highlighting** — highlight matched terms in results
- **Click analytics** — track which results users click
- **RTL support** — full right-to-left language support
- **Shadow DOM** — styles are encapsulated and don't affect your site

## Basic Usage

```twig
{# Include with default widget config #}
{% include 'search-manager/_widget/search-modal' %}

{# Include with a specific config handle #}
{% include 'search-manager/_widget/search-modal' with {
    config: 'homepage',
} %}
```

That's it — the widget renders a trigger button and the search modal. Press CMD+K (or click the button) to open it.

## Configuration

Widget behavior can be controlled in three ways:

1. **CP settings** — Search Manager > Widgets > create/edit a configuration
2. **Config file** — define widget configs in `config/search-manager.php`
3. **Twig parameters** — override per-include

### Twig Parameters

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
    hideResultsWithoutUrl: false,
    showTrigger: true,
    triggerText: 'Search',
    triggerSelector: '#my-search-btn',
    enableHighlighting: true,
    highlightTag: 'mark',
    highlightClass: 'search-highlight',
    backdropOpacity: 50,
    enableBackdropBlur: true,
    preventBodyScroll: true,
    source: 'header-search',
    idleTimeout: 1500,
} %}
```

### All Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `config` | `string` | — | Widget config handle (loads settings from CP/config) |
| `indices` | `array` | `[]` | Index handles to search (empty = all) |
| `placeholder` | `string` | — | Input placeholder text |
| `theme` | `string` | `'light'` | `'light'` or `'dark'` |
| `maxResults` | `int` | `8` | Maximum results to show |
| `debounce` | `int` | `300` | Debounce delay in ms |
| `minChars` | `int` | `2` | Minimum characters before searching |
| `showRecent` | `bool` | `false` | Show recent search history |
| `maxRecentSearches` | `int` | `5` | Maximum recent searches to store |
| `groupResults` | `bool` | `false` | Group results by type/section |
| `hotkey` | `string` | `'k'` | Keyboard shortcut key |
| `hideResultsWithoutUrl` | `bool` | `false` | Hide results without a URL |
| `showTrigger` | `bool` | `true` | Show the trigger button |
| `triggerText` | `string` | `'Search'` | Trigger button text |
| `triggerSelector` | `string` | — | CSS selector for an external trigger element |
| `siteId` | `int` | — | Specific site to search |
| `dir` | `string` | — | Text direction: `'ltr'` or `'rtl'` |
| `enableHighlighting` | `bool` | `true` | Highlight matched terms |
| `highlightTag` | `string` | `'mark'` | Highlight HTML tag |
| `highlightClass` | `string` | — | Highlight CSS class |
| `backdropOpacity` | `int` | `50` | Backdrop opacity (0–100) |
| `enableBackdropBlur` | `bool` | `true` | Enable backdrop blur effect |
| `preventBodyScroll` | `bool` | `true` | Prevent body scroll when open |
| `styles` | `object` | `{}` | Override individual style values |
| `source` | `string` | — | Custom analytics source identifier |
| `idleTimeout` | `int` | `1500` | Track search after idle (ms), 0 to disable |

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

## Style Overrides

Override individual style properties:

```twig
{% include 'search-manager/_widget/search-modal' with {
    styles: {
        modalBg: '#1a1a1a',
        modalBorderRadius: '16',
        modalMaxHeight: '70',
        inputBg: '#2a2a2a',
        inputTextColor: '#ffffff',
        resultHoverBg: '#333333',
    },
} %}
```

Style defaults are WCAG 2.1 AA compliant. When overriding colors, ensure you maintain sufficient contrast ratios (4.5:1 for normal text, 3:1 for large text).

### Config File Styles

Define styles in your widget config:

```php
'widgets' => [
    'brand-search' => [
        'settings' => [
            'styles' => [
                'modalBg' => '#ffffff',
                'modalBorderColor' => '#0066cc',
                'modalBgDark' => '#1a1a2e',
                'modalBorderColorDark' => '#4da6ff',
            ],
        ],
    ],
],
```

## CP Widget Management

Widget configurations can be managed at Search Manager > Widgets:

- **Settings Tab** — name, handle, search indices
- **Behavior Tab** — backdrop, debounce, results, recent searches, hotkey, trigger, analytics
- **Appearance Tab** — colors, fonts, spacing, border radius, highlighting
- **Preview** — live preview of light and dark mode

## Default Widget

Set a default widget via `defaultWidgetHandle` in config or CP settings. If the default widget is deleted, another enabled widget is automatically assigned.

## Programmatic Control

```javascript
const widget = document.querySelector('search-widget');

widget.open();    // Open the modal
widget.close();   // Close it
widget.toggle();  // Toggle open/close
```

## Widget Analytics

The widget tracks searches and clicks to provide meaningful analytics without keystroke spam:

- **Click tracking** — which results users click
- **Search tracking** — records searches when users show intent:
  - Clicking a result
  - Pressing Enter
  - Stopping typing for the idle timeout (default: 1.5s)
- **Source identification** — use `source` to distinguish widget placements (e.g., `'header-search'`, `'mobile-nav'`)

See [Widget Integration](../template-guides/widget-integration.md) for complete implementation examples.
