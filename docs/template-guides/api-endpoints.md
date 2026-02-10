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
| `index` | `all-sites` | Index handle to search |
| `limit` | `20` | Maximum results (use `0` for unlimited, capped at 100) |
| `type` | (none) | Filter by element type (e.g., `product`, `product,category`) |
| `language` | (auto) | Language code for localized operators (`en`, `de`, `fr`, `es`, `ar`) |
| `source` | (auto-detected) | Analytics source identifier (e.g., `ios-app`) |
| `platform` | (none) | Platform info for analytics (e.g., `iOS 17.2`) |
| `appVersion` | (none) | App version for analytics (e.g., `2.1.0`) |

> **Note:** The `siteId` parameter is not available on the search endpoint. If no `siteId` is set in the search options, all sites are searched. Use per-site index handles (e.g., `entries-en`, `entries-de`) to scope results to a specific site.

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
    "total": 150
}
```

> **Note:** The public search API does not return internal metadata (synonyms expanded, rules matched, promotions matched). Use the `?debug=1` parameter with the `searchManager:viewDebug` permission to inspect query internals during development.

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

```javascript
// Basic search
const response = await fetch('/actions/search-manager/api/search?q=craft+cms&index=entries-en');
const results = await response.json();

// Filter by element type
const response = await fetch('/actions/search-manager/api/search?q=laptop&type=product,category');

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
| `index` | `all-sites` | Index handle |
| `limit` | `10` | Maximum suggestions/results |
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
        const res = await fetch(`/actions/search-manager/api/search?q=${encodeURIComponent(q)}&index=all-sites&limit=10`);
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
