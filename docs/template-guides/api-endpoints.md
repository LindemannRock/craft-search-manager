# API Endpoints

Search Manager provides REST API endpoints for building instant search interfaces, mobile app integrations, and headless search.

## Search API

```text
GET /actions/search-manager/api/search
```

### Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `indices` | (all indices) | Comma-separated index handles to search. Omit to search all enabled indices. |
| `index` | (all indices) | Single index handle (legacy — prefer `indices`). |
| `hitsPerPage` | `20` | Maximum results per page (min: 1, max: 200). Values below 1 reset to the default. |
| `page` | `0` | Page number (0-based) |
| `type` | (none) | Filter by element type (e.g., `product`, `product,category`) |
| `siteId` | (all sites) | Filter to a specific site. Omit to search all sites. |
| `language` | (auto) | Language code for localized operators (`en`, `de`, `fr`, `es`, `ar`) |
| `source` | (auto-detected) | Analytics source identifier (e.g., `ios-app`) |
| `platform` | (none) | Platform info for analytics (e.g., `iOS 17.2`) |
| `appVersion` | (none) | App version for analytics (e.g., `2.1.0`) |
| `enrich` | `0` | Enable result enrichment. When `1`, results include snippets, heading expansion, thumbnails, and promoted/boosted flags. See [Enriched Response](#enriched-response). |
| `skipAnalytics` | `0` | Skip analytics tracking for this search |

#### Enrichment Parameters

These parameters only apply when `enrich=1`:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `snippetMode` | `balanced` | Snippet positioning: `early`, `balanced`, or `deep` |
| `snippetLength` | `150` | Max snippet length in characters (50–1000) |
| `showCodeSnippets` | `0` | Show code block content in snippets |
| `parseMarkdownSnippets` | `0` | Parse markdown before generating snippets |
| `hideResultsWithoutUrl` | `0` | Exclude results that have no URL |
| `debug` | (devMode) | Include debug metadata. Requires `devMode` or `searchManager:viewDebug` permission. |

### Response

```json
{
    "hits": [
        {
            "objectID": 123,
            "id": 123,
            "promoted": true,
            "position": 1,
            "score": null,
            "type": "product",
            "title": "Featured Product"
        },
        {
            "objectID": 456,
            "id": 456,
            "score": 45.23,
            "type": "product"
        }
    ],
    "total": 150,
    "page": 0,
    "hitsPerPage": 20,
    "totalPages": 8
}
```

> [!NOTE]
> The raw response does not return internal metadata (synonyms expanded, rules matched, promotions matched). Use the `?debug=1` parameter with the `searchManager:viewDebug` permission to inspect query internals during development.

### Enriched Response

When `enrich=1`, results are resolved to full element data with snippets and heading expansion:

```json
{
    "hits": [
        {
            "id": 123,
            "title": "Getting Started with Craft CMS",
            "url": "/docs/getting-started",
            "description": "A snippet with <mark>matched</mark> terms...",
            "section": "Documentation",
            "type": "page",
            "score": 45.23,
            "promoted": false,
            "headings": [
                {
                    "title": "Installation",
                    "description": "How to install <mark>Craft</mark>...",
                    "url": "/docs/getting-started#installation"
                }
            ]
        }
    ],
    "total": 42,
    "query": "craft",
    "page": 0,
    "hitsPerPage": 20,
    "totalPages": 3
}
```

Enriched mode is what the frontend widget uses internally. It's useful for headless integrations that need ready-to-render results without additional element lookups.

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `hits` | `array` | Array of hit objects (see below) |
| `total` | `int` | Total number of matching results |
| `page` | `int` | Current page number (0-based) |
| `hitsPerPage` | `int` | Results per page |
| `totalPages` | `int` | Total number of pages |

### Hit Fields

| Field | Type | Description |
|-------|------|-------------|
| `objectID` | `int` | Element ID |
| `id` | `int` | Element ID (alias) |
| `score` | `float\|null` | BM25 relevance score (`null` for promoted items) |
| `type` | `string` | Element type (product, category, entry, etc.) |
| `promoted` | `bool` | Present and `true` for promoted/pinned results |
| `position` | `int` | Position in results (for promoted items) |
| `title` | `string` | Element title (for promoted items) |

### Examples

**Full URL format:**

```text
https://your-site.com/actions/search-manager/api/search?q=plugin&index=docs-manager&language=en&hitsPerPage=5&page=0&siteId=1
```

```javascript
// Basic search
const response = await fetch('/actions/search-manager/api/search?q=craft+cms&index=entries-en');
const results = await response.json();

// Filter by site and element type
const response = await fetch('/actions/search-manager/api/search?q=laptop&type=product,category&siteId=1');

// Paginated results
const response = await fetch('/actions/search-manager/api/search?q=docs&index=entries-en&hitsPerPage=10&page=2');

// With localized operators (German)
const response = await fetch('/actions/search-manager/api/search?q=kaffee+ODER+tee&language=de');

// Mobile app tracking
const params = new URLSearchParams({
    q: 'shoes',
    index: 'products',
    source: 'ios-app',
    platform: 'iOS 17.2',
    appVersion: '2.1.0',
});
const response = await fetch(`/actions/search-manager/api/search?${params}`);
```

## Autocomplete API

```text
GET /actions/search-manager/api/autocomplete
```

### Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `indices` | (all indices) | Comma-separated index handles. Omit to search all enabled indices. |
| `index` | (all indices) | Single index handle (legacy — prefer `indices`). |
| `hitsPerPage` | `10` | Maximum suggestions/results (capped at 100) |
| `siteId` | (all sites) | Filter to a specific site |
| `language` | (auto) | Language code (alias: `lang`) |
| `only` | (none) | Return only `suggestions` or `results` |
| `type` | (none) | Filter results by element type |

### Response Formats

**Default** (no `only` param) — returns both suggestions and element results:

```json
{
    "suggestions": ["test", "testing", "tested"],
    "results": [
        {"text": "Test Product", "type": "product", "id": 123},
        {"text": "Test Category", "type": "category", "id": 45}
    ]
}
```

**Only suggestions** (`only=suggestions`) — returns term strings:

```json
["test", "testing", "tested"]
```

**Only results** (`only=results`) — returns element objects:

```json
[
    {"text": "Test Product", "type": "product", "id": 123},
    {"text": "Test Category", "type": "category", "id": 45}
]
```

### Examples

**Full URL format:**

```text
https://your-site.com/actions/search-manager/api/autocomplete?q=test&index=entries-en&hitsPerPage=10&siteId=1
```

```javascript
// Default: both suggestions and results
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&index=entries-en');
const data = await response.json();
// data.suggestions = ["test", "testing"]
// data.results = [{text: "Test Page", type: "page", id: 1}]

// Only suggestions
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&only=suggestions');
const suggestions = await response.json();
// ["test", "testing", "tested"]

// Only results, filtered by type
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&only=results&type=product');
```

## Search Operators in API

All search operators work in API queries:

```text
Phrase:         ?q="exact phrase"
Boolean:        ?q=coffee OR tea
NOT:            ?q=coffee NOT decaf
Wildcards:      ?q=coff*
Field-specific: ?q=title:muesli
Boosting:       ?q=coffee^2 beans
Localized:      ?q=kaffee ODER tee&language=de
```

## Mobile App Integration

The API is designed for mobile app use. Pass analytics context for proper tracking:

```javascript
const params = new URLSearchParams({
    q: 'kaffee ODER tee NICHT entkoffeiniert',
    index: 'products',
    language: 'de',
    source: 'ios-app',
    platform: 'iOS 17.2',
    appVersion: '2.1.0',
});

const response = await fetch(`/actions/search-manager/api/search?${params}`);
```

This ensures:
- German boolean operators are parsed correctly
- Analytics records the request as coming from your iOS app
- Platform and version info are tracked for analysis

## Analytics Tracking Endpoints

These endpoints are used by the frontend widget to track search activity. They accept anonymous requests (no CSRF token required) for compatibility with full-page static caching (Blitz, Servd, etc.).

### Track Search

```text
POST /actions/search-manager/search/track-search
```

Records a search query when the user shows intent (clicking a result, pressing Enter, or idle timeout).

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query (truncated at 256 characters) |
| `indices` | (all) | Comma-separated index handles. Only enabled indices are accepted. |
| `resultsCount` | `0` | Number of results shown (capped at 1000) |
| `trigger` | `unknown` | What triggered tracking: `click`, `enter`, `idle`, or `unknown` |
| `source` | `frontend-widget` | Source identifier (alphanumeric, dash, underscore; max 64 chars) |
| `siteId` | (none) | Site ID |

```json
{"success": true, "tracked": true}
```

Returns `"tracked": false` when analytics is disabled or no valid indices match.

### Track Click

```text
POST /actions/search-manager/search/track-click
```

Records when a user clicks a search result.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `elementId` | (required) | The clicked element's ID |
| `query` | `''` | The search query that produced this result |
| `index` | `''` | The index handle |
| `position` | (none) | Position in the results list |

```json
{"success": true}
```

> [!NOTE]
> Both tracking endpoints require a `POST` request with `Accept: application/json` header. They silently succeed when analytics is disabled.

## Instant Search Example

```html
<input type="search" id="search" placeholder="Search...">
<div id="results"></div>

<script>
const input = document.getElementById('search');
const resultsDiv = document.getElementById('results');
let timer;

const typeIcons = {
    product: '&#128230;',
    category: '&#127991;',
    article: '&#128196;',
};

input.addEventListener('input', (e) => {
    clearTimeout(timer);
    const q = e.target.value.trim();
    if (q.length < 2) { resultsDiv.innerHTML = ''; return; }

    timer = setTimeout(async () => {
        const res = await fetch(`/actions/search-manager/api/search?q=${encodeURIComponent(q)}&index=all-sites&hitsPerPage=10`);
        const data = await res.json();

        resultsDiv.innerHTML = data.hits.map(hit => `
            <div class="result">
                <span>${typeIcons[hit.type] || ''}</span>
                <strong>${hit.title || 'Result #' + hit.id}</strong>
                ${hit.promoted ? '<span class="badge">Promoted</span>' : ''}
            </div>
        `).join('');
    }, 300);
});
</script>
```
