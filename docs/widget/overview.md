# Frontend widget

Drop a fast, accessible search experience onto any page with one line of Twig — no custom search UI to build. The frontend widget is Search Manager's ready-to-use search interface, built as a web component (`<search-modal>`) with full keyboard navigation and theming in every edition. Pro adds built-in analytics, promotion display controls, and reusable style presets.

## What you'll use it for

- Add sitewide search behind a CMD+K / Ctrl+K shortcut, or wire it to your own trigger button
- Give documentation sites hierarchical results with matched headings, without custom result-rendering code
- Run several search placements (header, mobile nav, docs sidebar) that share one visual style but report separately in analytics
- Ship an accessible, theme-aware, RTL-ready search box without auditing contrast ratios or keyboard traps yourself

## Try it

```twig
{# Include with default widget config #}
{% include 'search-manager/_widget/search-modal' %}

{# Include with a specific config handle #}
{% include 'search-manager/_widget/search-modal' with {
    configHandle: 'homepage',
} %}
```

That's it — the widget renders a trigger button and the search modal. Press CMD+K (or click the button) to open it.

## What's in the box

- **WCAG 2.1 AA compliant** — tested with axe-core, all default colors meet 4.5:1 contrast ratio
- **Keyboard navigation** — arrow keys, Enter, Escape, configurable hotkey (default: CMD+K / Ctrl+K)
- **Modal search widget** — CMD+K overlay with backdrop, focus handling, and scroll locking
- **Light & dark themes** — built-in theme support with customizable colors
- **Reusable style presets (Pro)** — define [Widget Styles](styles.md) once and share across configs
- **Recently viewed** — optional locally stored history of the results a visitor opened, offered back for quick return
- **Grouped results** — group flat results by source, Entry section, or type; hierarchical layouts can group by any public hit field via `hierarchyGroupBy`
- **Heading matching** — show matched headings under results for documentation sites
- **Split section rendering** — split SourceDoc and AutoTransformer-family hits can render as parent rows with matched heading children in hierarchical layouts
- **Snippet modes** — early, balanced, or deep snippet extraction
- **Term highlighting** — highlight matched terms in results; the same highlighter is also available as a [standalone utility](../template-guides/highlighting-snippets.md#client-side-highlighting) for custom search UIs
- **Click analytics (Pro)** — track which results users click with Search Manager's built-in analytics; public JavaScript events remain available in Standard
- **RTL support** — full right-to-left language support
- **Shadow DOM** — styles are encapsulated and don't affect your site

## Widget type

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

## Configuration sources

Widget behavior can be controlled in three ways:

1. **CP settings** — Search Manager > Widgets > create/edit a configuration
2. **Config file** — define widget configs in `config/search-manager.php`
3. **Twig parameters** — override per-include

A widget config referenced without a `configHandle` falls back to the **default widget**, set via `defaultWidgetHandle` in config or CP settings. The active default cannot be deleted; select another default first. When a database-managed default is empty, missing, or disabled, creating or saving a widget assigns the first enabled widget. Config-file defaults remain authoritative.

See [Widget Configuration](configuration.md) for all parameters.

## Manage widgets in the CP

In Pro, each widget config links to a **Widget Style** preset from the sidebar — that's where colors, spacing, and other appearance settings live (see [Widget Styles](styles.md)), not a dedicated tab. Standard omits the style selector and uses the built-in default style; Twig inline `styles:` overrides still apply. The sidebar preview remains available in both editions because it previews the Standard widget configuration rather than a Pro feature.

The config's own tabs cover behavior:

- **General** — name, handle, API key, search indices
- **Search Input** — placeholder, debounce, minimum characters
- **Modal & Trigger** — hotkey, prevent body scroll, loading indicator, trigger button and label
- **Recently Viewed** — the "Recently viewed" section (results the visitor opened) and its stored-entry limit
- **Results** — result limit, URL requirement, and layout (default or hierarchical, with grouping field/style/heading-limit when hierarchical); Pro also exposes promotion display controls
- **Snippets** — block-code snippets, snippet mode, snippet length, Markdown marker cleanup
- **Destination Highlighting** — destination-page highlight toggle, persisted query, query param, content selector
- **Analytics (Pro)** — source identifier and idle timeout controls; the tab is absent in Standard

Manage configs at Search Manager > Widgets.

## Widget analytics (Pro)

The widget tracks searches and clicks to provide meaningful analytics without keystroke spam:

- **Click tracking** — which results users click
- **Search tracking** — records searches when users show intent:
  - Clicking a result
  - Pressing Enter
  - Stopping typing for the idle timeout (default: 1.5s)
- **Source identification** — use `analyticsSource` to distinguish widget placements (e.g., `'header-search'`, `'mobile-nav'`)
- **Verified cache telemetry** — the final search response includes a short-lived opaque envelope that the widget forwards unchanged with its one intent ping. Search Manager verifies the query, site, exact index set, displayed result count, expiry, and one-time use before a hit or miss contributes to Performance. The widget never interprets or reconstructs this value, and debug metadata stays behind the existing authorization boundary.

Standard does not collect built-in widget analytics. Tracking endpoints accept and discard stale requests after a downgrade, so an existing Pro-configured widget keeps searching without browser-console errors. Public [JavaScript events](javascript-api.md) still fire in Standard for integrations with your own analytics platform.

## Next steps

- [Widget Configuration](configuration.md) — all behavior parameters
- [Widget Styles](styles.md) — style presets and CSS properties
- [Widget Integration](integration.md) — template examples and theming
- [JavaScript API](javascript-api.md) — programmatic control and events
