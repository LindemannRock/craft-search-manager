# Template Variables

Search Manager provides a Twig variable at `craft.searchManager` with methods for searching, autocomplete, highlighting, and backend-specific operations.

## Core Search

### `search(indexName, query, options)`

Perform a search against a specific index.

```twig
{% set results = craft.searchManager.search('entries-en', 'craft cms') %}
{% set results = craft.searchManager.search('entries-en', 'craft cms', {
    siteId: 1,
    source: 'header-search',
}) %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `indexName` | `string` | — | Index handle to search |
| `query` | `string` | — | Search query (supports all operators) |
| `options` | `array` | `[]` | Additional options (siteId, source, platform, etc.) |

**Returns:** `array` with `hits`, `total`, and `meta` keys.

### `searchMultiple(indexNames, query, options)`

Search across multiple indices at once. Results are merged and sorted by score.

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], 'laptop') %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `indexNames` | `array` | Array of index handles |
| `query` | `string` | Search query |
| `options` | `array` | Search options |

**Returns:** `array` with `hits` (each tagged with `_index`), `total`, and `indices` count breakdown.

### `getIndices()`

Get all configured indices.

```twig
{% set indices = craft.searchManager.getIndices() %}
```

**Returns:** `array` of index configuration data.

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
| `options` | `array` | `[]` | Options: `limit`, `minLength`, `fuzzy`, `language` |

**Returns:** `array` of suggestion strings.

## Highlighting

### `highlight(text, terms, options)`

Highlight search terms in text by wrapping them with an HTML tag.

```twig
{{ craft.searchManager.highlight(entry.title, 'craft cms')|raw }}
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',
    class: 'highlight',
    stripTags: true,
})|raw }}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `text` | `string` | Text to highlight |
| `terms` | `string\|array` | Search terms or query string |
| `options` | `array` | Options: `tag`, `class`, `stripTags` |

**Returns:** `string` with highlighted terms (use `|raw` in templates).

### `snippets(text, terms, options)`

Generate context snippets with highlighted terms.

```twig
{% set snippets = craft.searchManager.snippets(entry.body, 'craft cms', {
    snippetLength: 200,
    maxSnippets: 3,
}) %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `text` | `string` | Source text |
| `terms` | `string\|array` | Search terms or query string |
| `options` | `array` | Options: `snippetLength`, `maxSnippets` |

**Returns:** `array` of snippet strings with highlighted terms.

## Analytics

### `getRuleAnalytics(ruleId, dateRange)`

Get analytics for a specific query rule.

```twig
{% set analytics = craft.searchManager.getRuleAnalytics(5, 'last30days') %}
```

**Returns:** `array` with rule analytics data.

### `getPromotionAnalytics(promotionId, dateRange)`

Get analytics for a specific promotion.

```twig
{% set analytics = craft.searchManager.getPromotionAnalytics(1, 'last7days') %}
```

**Returns:** `array` with promotion analytics data.

## Backend-Specific Methods

These methods are designed for Algolia, Meilisearch, and Typesense backends. Built-in backends provide fallback behavior where applicable.

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

### `listIndices()`

List all indices available in the backend.

```twig
{% for index in craft.searchManager.listIndices() %}
    <li>{{ index.name }} ({{ index.entries }} entries)</li>
{% endfor %}
```

**Returns:** `array`

### `withBackend(backendHandle)`

Get a proxy for a specific configured backend. All methods above are available on the proxy.

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

## Plugin Access

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
