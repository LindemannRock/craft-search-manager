# Example templates

Copy Search Manager's three diagnostic Twig playgrounds into a Craft project when you want a visible, repeatable way to test the Twig API, compare element-query search, or exercise the real frontend widget. They preserve the project's current development behavior and expose useful metadata deliberately; they are not production-ready starter designs.

## Requirements

- Craft CMS with Search Manager installed and enabled
- At least one enabled Search Manager index whose element type and plugin dependencies are available
- Indexed content that you can search for
- Datastar only if you want reactive updates in the search and comparison pages

The widget page renders Search Manager's real widget include. A configured default widget is useful but not required because the page also demonstrates fallback/default and inline custom settings.

## Copy the playgrounds

From the Craft project root, copy the packaged Twig files into `templates/`:

```bash title="Shell"
cp vendor/lindemannrock/craft-search-manager/resources/example-templates/*.twig templates/
```

```bash title="DDEV"
ddev exec cp vendor/lindemannrock/craft-search-manager/resources/example-templates/\*.twig templates/
```

Craft's normal template routing then exposes these paths:

| Template | Route | Primary use |
|---|---|---|
| `search-manager-search-playground.twig` | `/search-manager-search-playground` | Twig search, autocomplete, snippets, highlighting, rules, promotions, cache, and debug output |
| `search-manager-native-comparison.twig` | `/search-manager-native-comparison` | Search Manager beside the matching Craft element-query `.search()` path |
| `search-manager-widget-playground.twig` | `/search-manager-widget-playground` | Real modal widget with default, inline-custom, and debug-enabled examples |

Projects with custom route rules or a template-route prefix should use the equivalent project URLs.

## Search playground

The search playground uses `craft.searchManager.getAvailableIndices()` to build its selector. That keeps disabled indices and indices with blocking configuration or dependency problems out of the page while preserving warning-only available indices.

Choose one index for focused testing or **All indices**. In **All indices** mode, the page passes the current ordered available-index handle list to `craft.searchManager.searchMultiple()`. This is an internal Twig call, so public API-key scope does not apply. The page can show autocomplete, wildcard rewriting, snippets, highlighting, promotions, query rules, live element comparison, cache state, and debug metadata. It does not test Craft's native element-query search; use the comparison page for that.

## Search Manager/native comparison

The comparison playground puts Search Manager results beside an appropriate Craft element query for Entries, Commerce Products and Variants, SmartLink Manager links, ShortLink Manager links, or Docs Manager source documents when the matching plugin and element type are available.

Select a single index so the page can choose a matching element query. If **Replace Native Search** is enabled, the Craft `.search()` column is covered by Search Manager for supported queries and is not a pure native baseline. The page reports that state rather than implying otherwise.

## Widget playground

The widget playground includes `search-manager/_widget/search-modal` directly. It renders:

- the configured default widget and normal style resolution;
- a custom inline trigger and inline style settings;
- a debug-enabled custom widget that exposes widget/API metadata.

Use the scope selector to test a single available index or **All indices**. The all-index option deliberately passes `indexHandles: []`, preserving the server-owned enabled/API-key scope. It must not be replaced with the selector's visible handle list.

## Optional Datastar behavior

The search and comparison pages work as ordinary GET forms when Datastar is missing or disabled. When Datastar is installed and enabled, the same templates use reactive input updates and DOM patches while keeping the Search button as a normal GET fallback.

Install Datastar only if you want that transport:

```bash title="Composer"
composer require putyourlightson/craft-datastar && php craft plugin/install datastar
```

```bash title="DDEV"
ddev composer require putyourlightson/craft-datastar && ddev craft plugin/install datastar
```

## Remove or protect the routes

Each playground includes `noindex,nofollow`, but that is not authentication. The pages can expose index handles, result metadata, backend behavior, debug details, and test analytics.

Remove the copied templates after testing, or protect the routes with project-level authentication or access rules. Do not leave diagnostic playgrounds publicly reachable on a production site.

For CP-based diagnostics that require no copied templates, see [Testing tools](testing-tools.md). For the public REST collection, see [Postman](postman.md).
