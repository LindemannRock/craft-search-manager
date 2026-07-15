# Widget Integration

This guide walks through adding the Search Manager frontend widget to your site and customizing its appearance and behavior.

## Quick Start

The simplest integration — one line in your layout template:

```twig
{% include 'search-manager/_widget/search-modal' %}
```

This renders a trigger button and the search modal. Users press CMD+K (or Ctrl+K) or click the button to open search.

## Using a Widget Config

Create a widget configuration in the CP (Search Manager > Widgets) or config file, then reference it:

```twig
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'main-search',
} %}
```

## API Key (when Require API Key is on)

If [**Require API Key**](../feature-tour/api-keys.md) is enabled, the widget must send a valid **public** API key — on search, autocomplete, **and** the analytics tracking pings. Select a public key on the widget config's **API Key** field (Search Manager → Widgets → your widget). The selector shows the key name, handle, and prefix. Saved/config references should use `settings.apiKeyHandle` to point at the CP-managed key by handle. You can also pass the raw public key value at render time:

```twig
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'main-search',
    apiKey: 'sm_pub_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
} %}
```

A render-time `apiKey` overrides the saved/config `apiKeyHandle` reference. Use `apiKey` only for render-time overrides or config-only widgets that intentionally provide the actual **public** key value — referrer-restricted and scoped to the widget's indices. Never put a server key in a widget; the value is emitted into the page HTML. When **Require API Key** is off, no key is needed.

Public keys selected by widget configs cannot be deleted, disabled, expired, renamed by handle, or narrowed in a way that breaks those widgets. Remove or reassign the key from the widget configs first.

## Overriding Styles Inline

Override specific visual styles at render time. These merge on top of the widget config's [style preset](styles.md):

```twig
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'main-search',
    styles: {
        modalBg: '#0f172a',
        inputBg: '#1e293b',
        spinnerColor: '#818cf8',
    },
} %}
```

> Style presets are assigned to widget configs via `styleHandle` in the [config file](../get-started/configuration.md) or the CP. The `styles` Twig parameter lets you override individual properties at render time without changing the preset.

## Customizing Inline

Override specific settings without creating a config:

```twig
{% include 'search-manager/_widget/search-modal' with {
    indexHandles: ['blog', 'products'],
    placeholder: 'Search articles and products...',
    theme: 'dark',
    resultsLimit: 12,
    recentSearchesEnabled: true,
    resultsGroupingEnabled: true,
} %}
```

## Live Attribute Updates

The rendered `<search-modal>` element watches its configuration attributes. You can update attributes after the widget has mounted, and the modal will re-read its configuration without losing its trigger wiring:

```javascript
const widget = document.querySelector('search-modal');

widget.setAttribute('placeholder', 'Search products...');
widget.setAttribute('theme', 'dark');
widget.setAttribute('trigger-selector', '#mobile-search-trigger');
```

If the modal is open while an attribute changes, it stays open and keeps the current query. Styling-only changes such as `theme` apply without rebuilding the modal DOM; structural changes re-render the widget and reconnect the internal controls, external trigger, hotkey, and document-level listeners.

## Custom Trigger Button

Replace the built-in trigger with your own design:

```twig
<button id="search-trigger" class="my-search-button">
    <svg><!-- search icon --></svg>
    Search
</button>

{% include 'search-manager/_widget/search-modal' with {
    triggerEnabled: false,
    triggerSelector: '#search-trigger',
} %}
```

Any element matching the `triggerSelector` will open the modal when clicked.

## Theming

### Light and Dark Mode

```twig
{% include 'search-manager/_widget/search-modal' with {
    theme: 'light',
} %}

{% include 'search-manager/_widget/search-modal' with {
    theme: 'dark',
} %}
```

### Custom Colors

Override individual style properties:

```twig
{% include 'search-manager/_widget/search-modal' with {
    styles: {
        modalBg: '#1a1a1a',
        modalBorderColor: '#333',
        inputBg: '#2a2a2a',
        inputTextColor: '#fff',
        resultActiveBg: '#333',
        modalBorderRadius: '16',
    },
} %}
```

### Brand Colors via Config

For consistent branding, define styles in your config file:

```php
// config/search-manager.php
'widgets' => [
    'brand-search' => [
        'settings' => [
            'styles' => [
                // Light mode
                'modalBg' => '#ffffff',
                'modalBorderColor' => '#0066cc',
                // Dark mode
                'modalBgDark' => '#1a1a2e',
                'modalBorderColorDark' => '#4da6ff',
            ],
        ],
    ],
],
```

## RTL Support

For right-to-left languages:

```twig
{% include 'search-manager/_widget/search-modal' with {
    dir: 'rtl',
} %}
```

## Analytics Tracking

Track where searches come from by setting an analytics source identifier:

```twig
{% include 'search-manager/_widget/search-modal' with {
    analyticsSource: 'header-search',
} %}

{% include 'search-manager/_widget/search-modal' with {
    analyticsSource: 'mobile-nav',
} %}
```

The analytics source appears in analytics so you can compare search behavior across placements.

### Idle Timeout

By default, a search is tracked after the user stops typing for 1.5 seconds. Adjust or disable:

```twig
{% include 'search-manager/_widget/search-modal' with {
    analyticsIdleTimeoutMs: 2000,
} %}

{% include 'search-manager/_widget/search-modal' with {
    analyticsIdleTimeoutMs: 0,
} %}
```

## Programmatic Control

Access the widget from JavaScript:

```javascript
const widget = document.querySelector('search-modal');

// Open/close/toggle
widget.open();
widget.close();
widget.toggle();
```

For the full JavaScript API including events and advanced control, see [JavaScript API](javascript-api.md).

## Accessibility

The widget is WCAG 2.1 AA compliant:

- Proper ARIA labels and roles
- Full keyboard navigation (arrow keys, Enter, Escape)
- Focus trapping within the modal
- Respects `prefers-reduced-motion`
- All default colors meet 4.5:1 contrast ratio
- Shadow DOM isolation (won't conflict with your styles)

When overriding colors, check that you maintain sufficient contrast ratios.

## Multiple Widgets

You can include multiple widgets with different configs on the same page:

```twig
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'main-search',
    triggerSelector: '#header-search',
} %}

{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'blog-search',
    indexHandles: ['blog'],
    triggerSelector: '#sidebar-search',
} %}
```

Only one search modal can be open at a time. Opening a widget from its trigger, an external trigger, or the JavaScript `open()` method closes any other open widget first, using the normal close behavior so focus, ARIA state, body scroll locking, and backdrop state stay consistent.

If multiple widgets share the same hotkey, the currently open matching widget owns the next keypress and closes. If none of the matching widgets are open, the first matching widget on the page opens. Opening a different widget by click or script replaces the active one.

## Hierarchical Results

For documentation sites, use the hierarchical result layout to group results and show matched headings:

```twig
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'docs-search',
    resultsLayout: 'hierarchical',
    hierarchyMaxHeadings: 5,
} %}
```

With split SourceDoc or AutoTransformer-family indices, the same hierarchical layout groups flat section hits back under their parent element. Intro hits can provide the parent snippet, heading hits render as children, and promoted page hits render at the parent level. `hierarchyMaxHeadings` limits heading children per page block: the widget keeps the highest-scoring heading hits first, then restores document order for display.

### Snippet Modes

Control how snippets are extracted from eligible fields, page bodies, and split section bodies:

```twig
{% include 'search-manager/_widget/search-modal' with {
    snippetMode: 'deep',
    snippetMaxLength: 200,
    snippetIncludeCodeBlocks: true,
    snippetCleanMarkdown: true,
} %}
```

| Mode | Description |
|------|-------------|
| `early` | Minimal leading context — the matched term appears near the start of the snippet |
| `balanced` | Moderate leading context before the matched term (default) |
| `deep` | More leading context — the matched term appears deeper in the snippet |

Each mode returns one snippet per hit; the mode only controls how much text is shown before the matched position.
