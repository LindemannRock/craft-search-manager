# Search Manager — Example Templates

These templates are diagnostic playgrounds for development and testing. They expose Search Manager behavior, metadata, and configuration choices deliberately; they are not production-ready starter designs.

## Requirements

- Craft CMS with Search Manager installed and enabled
- At least one enabled Search Manager index whose element type and plugin dependencies are available
- Indexed content that you can search for
- Datastar only if you want reactive updates in the search and comparison playgrounds; both also work as normal GET pages without it

The widget playground renders Search Manager's real widget include. It uses the configured default widget when available and also renders inline custom examples, so it does not mock the widget or require a separate UI kit.

## Copy the templates

From the Craft project root, copy all three Twig files into `templates/`:

```bash
cp vendor/lindemannrock/craft-search-manager/resources/example-templates/*.twig templates/
```

With DDEV:

```bash
ddev exec cp vendor/lindemannrock/craft-search-manager/resources/example-templates/\*.twig templates/
```

The files then resolve through Craft's normal template routes:

| Template | Route |
|---|---|
| `search-manager-search-playground.twig` | `/search-manager-search-playground` |
| `search-manager-native-comparison.twig` | `/search-manager-native-comparison` |
| `search-manager-widget-playground.twig` | `/search-manager-widget-playground` |

If your project changes Craft's template routing or uses a route prefix, use the equivalent project URL.

## Search playground

`search-manager-search-playground.twig` exercises Search Manager's Twig search surface. It can search one available index or let Search Manager search all available indices, and it exposes autocomplete, snippets, highlighting, promotions, query rules, cache metadata, live comparison, and debug output.

The index selector comes from `craft.searchManager.getAvailableIndices()`, so disabled indices and indices with blocking configuration or dependency problems are not offered. In **All indices** mode, the page passes the current ordered available-index handle list to `craft.searchManager.searchMultiple()`. This is an internal Twig call, so public API-key scope does not apply.

## Native comparison

`search-manager-native-comparison.twig` places Search Manager results beside the matching Craft element-query `.search()` results. It supports Entries, Commerce Products and Variants, SmartLink Manager links, ShortLink Manager links, and Docs Manager source documents when those element types and plugins are available.

Select one index for a meaningful side-by-side comparison. If **Replace Native Search** is enabled, the Craft column is routed through Search Manager for covered element queries; it is therefore a replacement-path comparison, not a pure Craft-native baseline. The page labels that state explicitly.

## Widget playground

`search-manager-widget-playground.twig` renders the actual `search-manager/_widget/search-modal` integration in three modes: configured defaults, inline custom styling, and debug-enabled custom styling. Use it to check triggers, modal/inline presentation, theme changes, result metadata, and the browser-facing widget/API contract.

For **All indices**, the template intentionally passes `indexHandles: []`. The empty array preserves Search Manager's server-owned enabled/API-key scope; replacing it with the currently visible handle list would change that contract.

## Optional Datastar support

The search and native-comparison playgrounds detect Datastar at runtime. Without it, their Search buttons submit normal GET requests. With Datastar installed and enabled, input changes patch the result region while the GET form remains available as a fallback.

Install Datastar only when you want that reactive transport:

```bash
composer require putyourlightson/craft-datastar && php craft plugin/install datastar
```

With DDEV:

```bash
ddev composer require putyourlightson/craft-datastar && ddev craft plugin/install datastar
```

## Keep diagnostic routes private

All three templates include `noindex,nofollow`, but that only asks search engines not to index the pages. It is not access control.

Remove the copied templates after testing, or protect their routes with project-level authentication or access rules. Do not expose diagnostic output, index handles, debug metadata, or test analytics on a public production site.
