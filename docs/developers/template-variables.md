# Template variables

Building a search results page, a live-search dropdown, or a "did you mean" prompt directly in Twig — without a REST call or a GraphQL client? `craft.searchManager` gives you the same search, autocomplete, and highlighting behavior as the REST API and GraphQL, callable straight from a template.

For PHP-side code (console commands, jobs, controllers), see [API reference](api-reference.md) instead — it exposes the same underlying services with a slightly different calling convention.

## Core search

### `search(indexName, query, options)`

Perform a search against a specific index.

```twig
{% set results = craft.searchManager.search('entries-en', 'craft cms') %}
{% set results = craft.searchManager.search('entries-en', 'craft cms', {
    siteId: 1,
    analyticsSource: 'header-search',
    snippetMode: 'balanced',
    snippetMaxLength: 180,
    retrievableFields: 'intro,summary',
}) %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `indexName` | `string` | — | Index handle to search |
| `query` | `string` | — | Search query (supports all operators) |
| `options` | `array` | `[]` | Additional options (`siteId`, `analyticsSource`, `platform`, snippet options, `retrievableFields`, etc.) |

**Returns:** `array` with `hits` and `total`. Hits are presented through the same public contract as REST, GraphQL, and the Control Panel test tool: public identity is `elementId` + `backendId`, the source index is `index`, custom field values are under `fields`, and `snippet` / `headings` are generated from indexed snippet sources when available. Internal backend keys such as raw `id`, `objectID`, and top-level `_...` values are stripped.

Top-level backend/cache metadata is omitted by default. To inspect it, pass `debugEnabled: true`; Search Manager returns the original `meta` value only when Craft is in `devMode` or the current user has the **View debug information** permission.

Twig search supports the same display options as the REST search endpoint:

| Option | Description |
|--------|-------------|
| `snippetMode` | `early`, `balanced`, or `deep` snippet selection |
| `snippetMaxLength` | Maximum snippet length, clamped to the shared snippet bounds |
| `snippetIncludeCodeBlocks` | Include block-level code while building snippets |
| `snippetCleanMarkdown` | Strip common Markdown markers from plain-text snippets |
| `resultsRequireUrl` | Omit hits that have no indexed URL |
| `retrievableFields` | Request-time field narrowing; it can narrow the index's `retrievableFields` allowlist but never widen it |
| `debugEnabled` | Request top-level backend/cache `meta`; requires `devMode` or the **View debug information** permission |
| `raw` | Set to `true` to return unpresented backend hits for debugging or custom migration code; it does not grant access to `meta` |

Search analytics from both Twig methods defaults to source `twig`. A non-empty `analyticsSource` option overrides that default after shared normalization; missing, empty, or whitespace-only values keep `twig`.

### `searchMultiple(indexNames, query, options)`

Search across multiple indices at once. Results are merged using the backend relevance signal when available. Scores are backend-specific, so do not compare them across different backend types.

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], 'laptop') %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `indexNames` | `array` | Array of index handles |
| `query` | `string` | Search query |
| `options` | `array` | Search options |

**Returns:** `array` with presented `hits` (each tagged with `index`), `total`, and `indices` count breakdown. Snippet options, `retrievableFields`, `analyticsSource`, `debugEnabled`, and `raw: true` behave the same as `search()`.

## Index discovery

Choose the index list that matches what you are building. Site selectors normally want `getAvailableIndices()`. Administration and diagnostics may need every effective configuration from `getIndices()`, while backend inventory tools use `listIndices()`.

| Method | Returns | Provider contact |
|--------|---------|------------------|
| `getIndices()` | Every effective configured `SearchIndex` model | No |
| `getAvailableIndices()` | Enabled, structurally available and referenceable configured `SearchIndex` models | No |
| `listIndices()` | Physical indices or collections reported by the active backend | Possible |

### `getIndices()`

Get every effective configured Search Manager index, including config-file and database-managed definitions. Config-file definitions win when the same handle also exists in the database. Disabled or structurally unavailable definitions can still appear, so use this method when you need the complete configuration rather than a user-selectable list.

```twig
{% for index in craft.searchManager.getIndices() %}
    <li>{{ index.name }} ({{ index.handle }})</li>
{% endfor %}
```

**Returns:** `SearchIndex[]` in Search Manager's canonical configuration order. This method does not discover or contact provider indices.

### `getAvailableIndices()` @since(5.54.0)

Get the configured indices that normal search consumers can select. The result contains only enabled indices that Search Manager's canonical catalogue classifies as available and referenceable. Warning-only definitions remain included; disabled definitions, configuration errors, missing plugin dependencies, and unresolved definitions are excluded.

```twig
{% set availableIndices = craft.searchManager.getAvailableIndices() %}
{% set availableHandles = availableIndices|map(index => index.handle) %}

{% for index in availableIndices %}
    <option value="{{ index.handle }}">{{ index.name }}</option>
{% endfor %}
```

**Returns:** `SearchIndex[]`, preserving the same effective ordering and config-over-database precedence as `getIndices()`.

> [!NOTE]
> Available means Search Manager's catalogue regards the configured definition and its class dependencies as structurally usable. It is not a hosted-provider liveness check and does not contact the provider.

### `listIndices()`

List physical indices or collections reported by the active backend. This is a backend inventory operation, not a configured-index selector, and hosted implementations may contact their provider.

```twig
{% for index in craft.searchManager.listIndices() %}
    <li>{{ index.name }} ({{ index.entries }} entries)</li>
{% endfor %}
```

**Returns:** `array`; each row follows the selected backend's inventory shape.

## Autocomplete

### `suggest(query, indexHandle, options)`

Get autocomplete suggestions for a partial query.

```twig
{% set suggestions = craft.searchManager.suggest('cra', 'entries-en') %}
{% set suggestions = craft.searchManager.suggest('te', 'entries-en', {
    limit: 5,
    fuzzy: true,
    language: 'en',
}) %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `query` | `string` | — | Partial search query |
| `indexHandle` | `string` | `'all-sites'` | Index to search |
| `options` | `array` | `[]` | Options: `limit`, `minLength`, `fuzzy`, `language`, `includeMeta` |

**Returns:** `array` of suggestion strings. With `includeMeta: true`, returns `{ suggestions: [...], meta: { cached, cacheEnabled, cacheDriver } }` instead.

## Highlighting

### `registerHighlighter()` @since(5.39.0)

Register the standalone `SearchManagerHighlighter` JavaScript utility. After calling this, `window.SearchManagerHighlighter` is available in your JavaScript with:

- `highlight(text, query, options)` — highlight matched terms in text (returns HTML string)
- `escapeHtml(text)` — escape HTML special characters
- `escapeRegex(string)` — escape regex special characters
- `create(options)` — create a reusable highlighter function with preset options
- `parseQuery(query)` — parse a query string into highlight-ready terms (returns a string array)

```twig
{% do craft.searchManager.registerHighlighter() %}
```

See [Client-Side Highlighting](../template-guides/highlighting-snippets.md#client-side-highlighting) for full usage examples.

> [!TIP]
> The JS highlighter includes smart features like camelCase splitting (e.g., searching "date" highlights the "Date" part of "DateRangeHelper"), longest-first matching to avoid nested tags, and overlap resolution that keeps the earliest match and drops anything that would nest inside it.

### `highlight(text, terms, options)`

Highlight search terms in text by wrapping them with an HTML tag.

```twig
{{ craft.searchManager.highlight(entry.title, query, { field: 'title' })|raw }}
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',
    class: 'highlight',
    stripTags: true,
    field: 'content',
})|raw }}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `text` | `string` | Text to highlight |
| `terms` | `string\|array` | Search terms or query string |
| `options` | `array` | Options: `tag`, `class`, `stripTags`, `field` (`title`, `content`, or omitted) |

**Returns:** `string` with highlighted terms (use `|raw` in templates).

When `terms` is a query string, pass the display area's `field` so `title:` terms paint titles only and `content:` terms paint content only; unscoped terms paint both. If every query term belongs to the other field, the helper returns the text without highlights. Omitting `field` preserves the legacy scope-blind behavior. Arrays contain no scope metadata and are highlighted as provided.

Exact and typo-corrected matches paint the whole matched word. Strict prefix extensions paint only the typed word-start prefix (`test` → `<mark>Test</mark>ing), and mid-word substrings are never painted.

### `snippets(text, terms, options)`

Generate context snippets with highlighted terms.

```twig
{% set snippets = craft.searchManager.snippets(entry.body, 'craft cms', {
    snippetMaxLength: 200,
    maxSnippets: 3,
}) %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `text` | `string` | Source text |
| `terms` | `string\|array` | Search terms or query string |
| `options` | `array` | Options: `snippetMaxLength`, `maxSnippets` |

**Returns:** `array` of snippet strings with highlighted terms.

## Analytics

### `getRuleAnalytics(ruleId, dateRange, siteId)` @since(5.10.0)

Get analytics for a specific query rule. In Pro, `dateRange` keeps the normal
analytics date semantics and the method returns `totalTriggers`,
`uniqueQueries`, `avgResultsAfter`, `topQueries`, `dailyTriggers`, and
`recentTriggers`.

```twig
{% set analytics = craft.searchManager.getRuleAnalytics(5, 'last30days') %}
{% set scopedAnalytics = craft.searchManager.getRuleAnalytics(5, 'last30days', [1, 2]) %}
```

`siteId` is an optional site ID or array of site IDs. Omitting it, or passing
`null`, preserves the global result for existing trusted templates. Pass the
current user's editable-site IDs for permission-aware output; an empty array
intentionally returns no analytics rather than falling back to global data.

**Returns:** In Pro, the current rule analytics. In Standard, an exact neutral
shape without reading retained Pro detail rows:

```twig
{
    totalTriggers: 0,
    uniqueQueries: 0,
    avgResultsAfter: 0.0,
    topQueries: [],
    dailyTriggers: [],
    recentTriggers: [],
}
```

### `getPromotionAnalytics(promotionId, dateRange, siteId)` @since(5.10.0)

Get analytics for a specific promotion. In Pro, `dateRange` keeps the normal
analytics date semantics and the method returns `totalImpressions`,
`uniqueQueries`, `avgPosition`, `topQueries`, `dailyImpressions`, and
`recentImpressions`.

```twig
{% set analytics = craft.searchManager.getPromotionAnalytics(1, 'last7days') %}
{% set scopedAnalytics = craft.searchManager.getPromotionAnalytics(1, 'last7days', [1, 2]) %}
```

`siteId` follows the same optional scope contract as rule analytics: `null`
or an omitted argument is global, a site ID or ID array limits every summary
and recent row, and an empty array returns no analytics.

**Returns:** In Pro, the current promotion analytics. In Standard, an exact
neutral shape without reading retained Pro detail rows:

```twig
{
    totalImpressions: 0,
    uniqueQueries: 0,
    avgPosition: 0.0,
    topQueries: [],
    dailyImpressions: [],
    recentImpressions: [],
}
```

These neutral reads do not delete retained analytics. Standard's independent
analytics export and permanent-purge tools remain available, and re-upgrading
restores access to the retained detail data.

## Backend-specific methods

Reach for these when you need direct access to a specific backend's own capabilities — browsing an Algolia index, running a native multi-index query, or building a filter string in your backend's syntax — rather than going through the standard `search()` call. These methods are designed for Algolia, Meilisearch, and Typesense backends. Built-in backends provide fallback behavior where applicable.

### `browse(options)`

Iterate through all documents in an index. Works with Algolia, Meilisearch, and Typesense.

```twig
{% if craft.searchManager.supportsBrowse() %}
    {% for doc in craft.searchManager.browse({
        index: 'products',
        query: '',
        params: {},
    }) %}
        <div>{{ doc.title }}</div>
    {% endfor %}
{% endif %}
```

**Returns:** `iterable`

### `multipleQueries(queries)`

Batch search across multiple indices in one request. External backends use native batch APIs; built-in backends fall back to sequential queries.

```twig
{% set results = craft.searchManager.multipleQueries([
    {indexName: 'products', query: 'laptop'},
    {indexName: 'categories', query: 'electronics'},
]) %}
```

**Returns:** `array` with results per query.

### `parseFilters(filters)`

Generate a backend-specific filter string from a key-value array.

```twig
{% set filterString = craft.searchManager.parseFilters({
    category: ['Electronics', 'Computers'],
    inStock: true,
}) %}

{% set results = craft.searchManager.search('products', query, {
    filters: filterString,
}) %}
```

**Returns:** `string` — the filter in your backend's syntax.

### `withBackend(backendHandle)` @since(5.28.0)

Get a proxy for a specific configured backend. The proxy supports `search()`, `suggest()`, `browse()`, `multipleQueries()`, `parseFilters()`, `listIndices()`, `supportsBrowse()`, `supportsMultipleQueries()`, `getName()`, `isAvailable()`, plus:

- `getBackendHandle()` — returns the configured backend handle
- `getBackend()` — returns the underlying `BackendInterface` instance
- `getStatus()` — returns backend status as an array

The proxy's `search()` follows the same public-hit and debug-metadata contract and accepts the same snippet, `resultsRequireUrl`, `retrievableFields`, `debugEnabled`, and `raw` options as the main `search()` method. Hits are presented by default; use `raw: true` only when you intentionally need the selected backend's unpresented hits. `raw` does not expose top-level `meta` unless you also pass `debugEnabled: true` and have debug access.

```twig
{% set algolia = craft.searchManager.withBackend('production-algolia') %}
{% set results = algolia.search('products', 'laptop') %}
{% set indices = algolia.listIndices() %}
<p>{{ algolia.getName() }} - {{ algolia.isAvailable() ? 'Online' : 'Offline' }}</p>
```

**Returns:** `BackendVariableProxy|null`

### `supportsBrowse()`

Check if the active backend supports `browse()`.

**Returns:** `bool`

### `supportsMultipleQueries()`

Check if the active backend supports native batch queries.

**Returns:** `bool`

## Plugin access

### `getSettings()`

Get the plugin's settings model.

```twig
{% set settings = craft.searchManager.getSettings() %}
```

### `getPlugin()`

Get the plugin instance.

```twig
{% set plugin = craft.searchManager.getPlugin() %}
```

One additional public method, `getFileBackendStoragePathDisplay()`, exists for the plugin's own Control Panel forms (it renders the resolved File-backend storage path). It is CP-internal plumbing, not intended for site templates.
