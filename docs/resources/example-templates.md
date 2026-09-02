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

## Exclude the routes from full-page caches

> [!IMPORTANT]
> Exclude the frontend routes mapped to these templates from every active full-page or static cache. Search Manager does not prescribe the public URLs and cannot configure Servd, Blitz, a CDN, a reverse proxy, or another page-cache layer for your project.

These pages are diagnostics, so their HTML depends on the current request, selected site and index, query parameters, Search Manager configuration, and current index and cache state. A cached page can freeze search results, suggestions, promotions and query rules, backend/timing/cache diagnostics, native-comparison output, and the widget playground's selected site, index, saved configuration, and rendered attributes.

Search Manager's result cache and a full-page cache describe different layers:

| Layer | What its status means |
|---|---|
| Search Manager result cache | Whether backend search results were reused after the request reached Craft and Search Manager ran |
| Servd, Blitz, CDN, reverse-proxy, or static-page cache | Whether the entire rendered HTML response was reused, potentially without reaching Craft |

If the page displays `Cache: miss` while the response has an upstream `X-Cache: HIT`, the displayed value is stale HTML from the earlier render. Search Manager did not execute for that browser request. An `Age` header or the cache provider's equivalent can provide the same clue.

Caching query strings as unique pages does not make these routes suitable for diagnostics. It stops different parameter combinations from sharing one HTML snapshot, but it still freezes each individual query and configuration result, can create many cached diagnostic URLs, and prevents reliable testing of Search Manager's current cache hit/miss behavior.

### How the three pages behave

- The generic search and native-comparison pages perform their Search Manager and Craft element-query work while Twig renders the request. Every diagnostic request must reach Craft/Search Manager.
- The widget sends its actual searches to Search Manager's public Craft action endpoint after the page loads. Results can therefore remain dynamic while the surrounding playground is cached, but cached HTML can still freeze the selected site/index, saved widget configuration, and rendered widget attributes.
- Datastar action requests can update results dynamically, but the initial page render and the normal GET fallback can still come from a full-page cache. Installing Datastar does not remove the need for a route exclusion.

### Servd

[Servd static-cache exceptions](https://servd.host/docs/static-caching) are URL-prefix based. Exclude the frontend routes your project maps to the diagnostic templates. A practical approach is to place all three below one project-owned prefix, such as `/diagnostics/search/`, then exclude that prefix.

That path is only an example; Search Manager does not assign routes to these templates. In a multisite project, account for the site or language prefixes that are actually present in each diagnostic URL.

### Blitz

[Blitz](https://putyourlightson.com/plugins/blitz) is a separate cache layer and must be configured independently. Add an excluded URI pattern for the diagnostic routes, or disable caching in each project-owned wrapper that renders one of the pages:

```twig
{% if craft.blitz is defined %}
    {% do craft.blitz.options({ cachingEnabled: false }) %}
{% endif %}
```

Keep that conditional in the project integration, not in Search Manager's portable templates. Servd's `{% dynamicInclude %}` and Blitz's `craft.blitz.includeDynamic()` are separate vendor-specific mechanisms; configuring one does not disable or control the other. Search Manager requires neither plugin.

### Quick diagnostic checklist

1. Inspect `X-Cache`, `Age`, or the cache provider's equivalent response headers.
2. Confirm the request reached Craft rather than stopping at a page-cache layer.
3. Repeat the same query to test Search Manager's own result-cache hit/miss behavior.
4. Verify that every active full-page or static cache excludes the diagnostic route.

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

Each playground includes `noindex,nofollow`, but that is only a crawler directive. It prevents neither caching nor unauthorized access. The pages can expose index handles, result metadata, backend behavior, debug details, and test analytics.

Remove the copied templates after testing, or protect the routes with project-level authentication or access rules. Do not leave diagnostic playgrounds publicly reachable on a production site.

For CP-based diagnostics that require no copied templates, see [Testing tools](testing-tools.md). For the public REST collection, see [Postman](postman.md).
