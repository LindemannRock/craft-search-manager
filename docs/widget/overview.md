# Frontend Widget

Search Manager includes a ready-to-use search widget for your frontend. It's built as a web component (`<search-modal>`) with full keyboard navigation, accessibility, theming, and click analytics.

## Key Features

- **WCAG 2.1 AA compliant** — tested with axe-core, all default colors meet 4.5:1 contrast ratio
- **Keyboard navigation** — arrow keys, Enter, Escape, configurable hotkey (default: CMD+K / Ctrl+K)
- **Modal search widget** — CMD+K overlay with backdrop, focus handling, and scroll locking
- **Light & dark themes** — built-in theme support with customizable colors
- **Reusable style presets** — define [Widget Styles](styles.md) once and share across configs
- **Recent searches** — optional search history stored locally
- **Grouped results** — group results by type/section with hierarchical layout option
- **Heading matching** — show matched headings under results for documentation sites
- **Split section rendering** — split SourceDoc and AutoTransformer-family hits can render as parent rows with matched heading children in hierarchical layouts
- **Snippet modes** — early, balanced, or deep snippet extraction
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

## Widget Type

Each widget config has a `type`. For this release, use the modal widget type:

| Type | Description |
|------|-------------|
| `modal` | CMD+K overlay — the default. Opens on top of the page with a backdrop. |

Set the type in the CP when creating a widget config, or in the config file:

```php
'widgets' => [
    'main-search' => [
        'type' => 'modal',
        // ...
    ],
],
```

## Configuration Sources

Widget behavior can be controlled in three ways:

1. **CP settings** — Search Manager > Widgets > create/edit a configuration
2. **Config file** — define widget configs in `config/search-manager.php`
3. **Twig parameters** — override per-include

See [Widget Configuration](configuration.md) for all parameters.

## CP Widget Management

Widget configurations can be managed at Search Manager > Widgets. Each config has six tabs:

- **General** — name, handle, search indices
- **Behavior** — placeholder, debounce, min chars, hotkey, scroll lock, loading indicator, recent searches, trigger button
- **Results** — max results, hide no-URL results, result layout (default/hierarchical), hierarchy options, line clamping
- **Snippets** — block-code snippets, snippet mode, snippet length, Markdown marker cleanup
- **Highlights** — destination page highlighting, persist query in URL, query param name, content selector
- **Analytics** — source identifier, idle timeout

Visual appearance is controlled via the **Widget Style** selector in the sidebar, not a dedicated tab. The sidebar also shows a live preview of the widget in light and dark mode.

## Default Widget

Set a default widget via `defaultWidgetHandle` in config or CP settings. If the default widget is deleted, another enabled widget is automatically assigned.

## Widget Analytics

The widget tracks searches and clicks to provide meaningful analytics without keystroke spam:

- **Click tracking** — which results users click
- **Search tracking** — records searches when users show intent:
  - Clicking a result
  - Pressing Enter
  - Stopping typing for the idle timeout (default: 1.5s)
- **Source identification** — use `source` to distinguish widget placements (e.g., `'header-search'`, `'mobile-nav'`)
- **Cache telemetry** @since(5.46.0) — the intent ping carries the final search response's `meta.cached` and `meta.took` forward so the recorded row has an accurate `executionTime` (`0` for cache hits, `took` ms for misses). This makes widget activity contribute to the dashboard's Cache Hit Rate, Cache Hits / Misses, and other performance metrics — without resurrecting per-keystroke spam.

## Next Steps

- [Widget Configuration](configuration.md) — all behavior parameters
- [Widget Styles](styles.md) — style presets and CSS properties
- [Widget Integration](integration.md) — template examples and theming
- [JavaScript API](javascript-api.md) — programmatic control and events
