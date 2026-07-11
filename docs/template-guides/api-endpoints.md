# API Endpoints

Search Manager provides REST API endpoints for building instant search interfaces, mobile app integrations, and headless search.

## Authentication

By default the public API endpoints are anonymous — no key required. When **Require API Key** is enabled (Settings → General → API Access), the **search**, **autocomplete**, **track-search**, and **track-click** endpoints require a valid public [API key](../feature-tour/api-keys.md) sent in the `X-Search-Manager-Key` request header:

```text
X-Search-Manager-Key: sm_pub_a1b2c3d4e5f6...
```

Rejections (returned as the endpoint's JSON error, in English):

| Status | When |
|--------|------|
| `401` | No key presented, the key is unknown / fails verification, or a server key is presented to a public endpoint |
| `403` | Key is disabled or expired; the request's `Referer` is outside a public key's allowed referrers; or a requested index is outside the key's allowed indices |
| `400` | A requested `siteId` is not a real site |
| `429` | The key's per-minute rate limit was exceeded |

**Rate limit.** A key may set a `rateLimit` (requests per minute). When exceeded, requests are rejected with `429` until the next one-minute window. The cap is per key (counted across search + autocomplete) and applies only to authenticated requests; a key with no `rateLimit` is unlimited.

**Public vs server keys.** Use a public key for browser-side and public REST callers such as the bundled widget, a custom JavaScript search page, or a headless frontend. Restrict public keys by referrer. Server keys are for trusted server-side integrations only; they are rejected by these public endpoints and should never be emitted into HTML or JavaScript.

**Index scope.** A key authorizes a set of indices (its *allowed indices*). A request that names indices must stay within that set; a request that names none is scoped to the key's allowed indices (a `*` key searches all enabled indices).

**Site scope.** `siteId` is only a filter — site visibility is controlled by each index, not by the key. With no `siteId`, results span all sites the selected indices cover. For a keyed request, a `siteId` outside the scope of a selected index is rejected with `403`; an unknown `siteId` is rejected with `400`. Anonymous requests keep their existing behaviour (the `siteId` is applied as a plain filter).

The `track-search` / `track-click` analytics endpoints are gated the same way when **Require API Key** is on (authenticate + public-key referrer, plus the allowed-indices check when the ping includes `index`/`indices`). They are **not** rate-limited. When the setting is off, they stay anonymous. The bundled widget sends its configured key on these pings automatically.

Tracking pings intentionally remain CSRF-free so they keep working from statically cached pages and the bundled frontend widget. Same-origin browser requests are accepted automatically. If a headless frontend sends tracking pings from another browser origin, add that exact origin in `config/search-manager.php`:

```php
'trackingAllowedOrigins' => App::env('SEARCH_MANAGER_TRACKING_ALLOWED_ORIGINS') ?: [],
```

The value may be an array or a comma-separated environment variable. Origins must match exactly by scheme, host, and effective port, for example `https://frontend.example.com` or `http://localhost:3000`. Paths and wildcards are not supported.

## Search API

```text
GET /actions/search-manager/api/search
```

### Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `indices` | (all indices) | One index handle or a comma-separated list of index handles to search. Omit to search all enabled indices. |
| `hitsPerPage` | `20` | Maximum results per page (min: 1, max: 200). Values below 1 reset to the default. |
| `page` | `0` | Page number (0-based) |
| `type` | (none) | Filter by stable document kind, for example `entry`, `product`, `variant`, `asset`, `category`, or `user` |
| `siteId` | (all sites) | Filter to a specific site. Omit to search all sites. |
| `language` | (auto) | Language code for localized operators (`en`, `de`, `fr`, `nl`, `es`, `ar`, `it`, `pt`, `ja`, `sv`, `da`, `no`) |
| `retrievableFields` | index setting | Optional comma-separated custom field handles to return under each hit's `fields` object. This can narrow the index's `retrievableFields` setting but cannot widen it. Use an empty value to return no custom fields. |
| `source` | (auto-detected) | Analytics source identifier (e.g., `ios-app`) |
| `platform` | (none) | Platform info for analytics (e.g., `iOS 17.2`) |
| `appVersion` | (none) | App version for analytics (e.g., `2.1.0`) |
| `skipAnalytics` | `0` | Skip analytics tracking for this search |

#### Snippet Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `snippetMode` | `balanced` | Snippet positioning: `early`, `balanced`, or `deep` |
| `snippetLength` | `150` | Max snippet length in characters (50–1000) |
| `showCodeSnippets` | `0` | Include block-level code in snippets; inline code text is always preserved |
| `parseMarkdownSnippets` | `0` | Clean Markdown markers from snippet display text without changing indexed content |
| `hideResultsWithoutUrl` | `0` | Exclude results that have no URL |

### Response

```json
{
    "hits": [
        {
            "id": 123,
            "elementId": 123,
            "backendId": "123_1",
            "objectID": 123,
            "promoted": true,
            "position": 1,
            "score": null,
            "elementType": "product",
            "type": "product",
            "productType": "Clothing",
            "productTypeHandle": "clothing",
            "fields": {
                "intro": "Soft cotton with recycled trim",
                "category": "Shirts Summer"
            },
            "title": "Featured Product"
        },
        {
            "id": 456,
            "elementId": 456,
            "backendId": "456_1",
            "objectID": 456,
            "score": 45.23,
            "elementType": "entry",
            "type": "entry",
            "section": "Blog",
            "sectionHandle": "blog",
            "sectionType": "channel",
            "fields": {
                "intro": "A short article introduction",
                "iconSingle": "search"
            }
        }
    ],
    "total": 150,
    "page": 0,
    "hitsPerPage": 20,
    "totalPages": 8
}
```

> [!NOTE]
> The REST response does not return internal metadata such as synonyms expanded, rules matched, or private indexed content.

Retrievable custom field values are returned under each hit's `fields` object. The keys are Craft field handles and the values are the flattened indexed strings. AutoTransformer fills the internal source map automatically from Craft custom fields only when the field's **Use this field's values as search keywords** setting is enabled. The index's `retrievableFields` setting then decides which of those values are returned publicly.

`retrievableFields` is a payload and contract control, not a secrecy boundary. Searchable fields can still affect matching, matched metadata, and snippets even when they are omitted from `fields`. No reindex is required when you change retrievable fields because Phase 1 trims the public response from already indexed data.

Top-level hit fields are reserved for Search Manager identity, ranking, and kind metadata such as `title`, `url`, `section`, `productType`, and `score`. Custom field handles are not returned flat at the top level, so a field handle like `section` or `url` cannot overwrite metadata.

Structure Entries, Categories, and public Assets can also return breadcrumb metadata at the top level. `ancestors` is ordered from root to parent; Entries and Categories can include `level`; public Assets can include `folderPath`, Craft's canonical containing-folder path. Channel/Single Entries, Users, Commerce Products/Variants, and private-volume Assets omit these keys.

### Search Response

Search returns one canonical hit shape:

```json
{
    "hits": [
        {
            "id": 123,
            "title": "Getting Started with Craft CMS",
            "url": "/docs/getting-started",
            "snippet": "A snippet with matched terms...",
            "section": "Documentation",
            "sectionHandle": "documentation",
            "sectionType": "structure",
            "ancestors": [
                { "id": 10, "title": "Guides" }
            ],
            "level": 2,
            "elementType": "entry",
            "type": "entry",
            "fields": {
                "intro": "Install and configure the plugin",
                "category": "Documentation"
            },
            "score": 45.23,
            "promoted": false,
            "headings": [
                {
                    "title": "Installation",
                    "id": "installation",
                    "level": 2,
                    "url": "/docs/getting-started#installation",
                    "snippet": "How to install Craft..."
                }
            ]
        }
    ],
    "total": 42,
    "page": 0,
    "hitsPerPage": 20,
    "totalPages": 3
}
```

`snippet` and `headings.*.snippet` are plain text. Apply any highlighting in the frontend. The top-level `snippet` is derived from eligible searchable custom field values in the indexed `_fields` map, then from the indexed clean body. Snippet source selection is independent of `retrievableFields`, so a field can be omitted from `fields` and still produce a snippet. Heading snippets are dynamic excerpts from the matching heading section in the indexed clean body. Title, slug, URL, SKU, native identity values, and the flattened content bag are not used as snippet sources. If no eligible field or body text contains the query, `snippet` is `null`.

For split SourceDoc indices, each returned hit is a flat section hit, not a grouped page result. Intro and heading section hits share `id` and `elementId` with the parent page, but each has a unique `backendId` and section metadata. `sectionType` is `intro`, `heading`, or `promoted-page`; `promoted-page` is used only for injected promotions on a split index. `snippet` is generated only from that section's own indexed body, and `headings` is empty because the hit is already the section. Client code can group section hits by `elementId`, `url`, or page title when it wants a page-with-sections display.

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `hits` | `array` | Array of hit objects (see below) |
| `total` | `int` | Backend-native number of matching hits. For split SourceDoc indices, this counts matching section hits, not parent pages. |
| `page` | `int` | Current page number (0-based) |
| `hitsPerPage` | `int` | Results per page |
| `totalPages` | `int` | Total number of pages |

### Hit Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | `int` | Craft element ID |
| `elementId` | `int` | Craft element ID. Use this for Craft element queries. |
| `siteId` | `int` | Indexed Craft site ID. |
| `site` | `string` | Indexed Craft site handle. |
| `language` | `string` | Indexed Craft site language. |
| `index` | `string` | Source search index handle when the backend reports it. |
| `backendId` | `string` | Unique Search Manager backend document ID, usually `{elementId}_{siteId}` for page hits and `{elementId}_{siteId}_{sectionId}` for split section hits. Treat hits as unique by `backendId`, not by `id`; split section hits share `id`/`elementId` with their parent page. |
| `objectID` | `int\|string` | Raw backend compatibility field. Prefer `elementId` and `backendId` in new code. |
| `slug` | `string` | Indexed element or document slug when available. |
| `dateCreated` | `int` | Indexed creation timestamp when available. |
| `dateUpdated` | `int` | Indexed update timestamp when available. |
| `score` | `float\|null` | Optional backend-specific relevance signal. Built-in backends return Search Manager's BM25 score; Meilisearch and Typesense map provider ranking values when available; Algolia may omit a comparable score; promoted items can be `null`. |
| `elementType` | `string` | Stable lowercase document kind. Matches `type`. |
| `type` | `string` | Stable lowercase document kind: `entry`, `product`, `variant`, `asset`, `category`, `user`, or `source-doc`. Split SourceDoc section hits keep `type: "source-doc"`. |
| `section` | `string` | Human-readable Entry section name when the hit is an Entry. Assets, Categories, Users, Products, and Variants do not use this field. |
| `sectionHandle` | `string` | Entry section handle when the hit is an Entry. |
| `sectionType` | `string` | Entry section type (`single`, `channel`, or `structure`) for Entry hits. For split SourceDoc hits, one of `heading`, `intro`, or `promoted-page`; `promoted-page` is injection-only for page-level promotions and carries no snippet. |
| `sectionId` | `string` | Section identity within the parent element for split section hits. |
| `sectionTitle` | `string` | Parent page title for split `intro` / `promoted-page` hits, or heading title for split `heading` hits. |
| `sectionLevel` | `int` | Heading level for split `heading` hits; `null` for intro and promoted-page hits. |
| `sectionAnchor` | `string` | URL anchor for split heading hits; `null` for intro and promoted-page hits. |
| `sectionUrl` | `string` | Section URL, including the anchor when available. |
| `sectionIndex` | `int` | Zero-based section order within the parent element. |
| `ancestors` | `array` | Breadcrumb ancestors as `{id, title}` objects, ordered root to parent. Present for nested Structure Entries, nested Categories, and public Asset folders when indexed. |
| `level` | `int` | Structure depth for Entry and Category hits when indexed. |
| `folderPath` | `string` | Craft's canonical containing-folder path for public Asset hits when indexed. This can differ from joining `ancestors[].title` because it uses folder path segments, not display names. |
| `volume` | `string` | Asset volume name when the hit is an Asset. |
| `volumeHandle` | `string` | Asset volume handle when the hit is an Asset. |
| `group` | `string` | Category group name when the hit is a Category. |
| `groupHandle` | `string` | Category group handle when the hit is a Category. |
| `productType` | `string` | Commerce product type name when the hit is a Product or Variant. |
| `productTypeHandle` | `string` | Commerce product type handle when the hit is a Product or Variant. |
| `category` | `string` | Source document category or transformer-provided category metadata when available. |
| `sourceId` | `int` | Source document or transformer-provided source ID when available. |
| `fields` | `object` | Retrievable custom field values keyed by field handle. Values are indexed content, not translated UI labels. |
| `snippet` | `string\|null` | Match-centered plain-text excerpt from the best matching eligible custom field or indexed clean body. `null` when no eligible snippet source contains the query. |
| `headings` | `array` | Public heading results as `{title, id, level, url, snippet}` objects for whole-page records. Split SourceDoc section hits return an empty array. |
| `promoted` | `bool` | Present and `true` for promoted/pinned results |
| `position` | `int` | Position in results (for promoted items) |
| `title` | `string` | Element title (for promoted items) |

`score` is not a universal ranking scale. It is safe to display for debugging or within one backend's results, but do not compare scores across Algolia, Meilisearch, Typesense, and built-in backends.

### Examples

**Full URL format:**

```text
https://your-site.com/actions/search-manager/api/search?q=plugin&indices=docs-manager&language=en&hitsPerPage=5&page=0&siteId=1
```

```javascript
// Basic search
const response = await fetch('/actions/search-manager/api/search?q=craft+cms&indices=entries-en');
const results = await response.json();

// Filter by site and element type
const response = await fetch('/actions/search-manager/api/search?q=laptop&type=product,category&siteId=1');

// Paginated results
const response = await fetch('/actions/search-manager/api/search?q=docs&indices=entries-en&hitsPerPage=10&page=2');

// With localized operators (German)
const response = await fetch('/actions/search-manager/api/search?q=kaffee+ODER+tee&language=de');

// Mobile app tracking
const params = new URLSearchParams({
    q: 'shoes',
    indices: 'products',
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
| `indices` | (all indices) | One index handle or a comma-separated list of index handles. Omit to search all enabled indices. |
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
        {"text": "Test Product", "type": "product", "id": 123, "siteId": 1},
        {"text": "Test Category", "type": "category", "id": 45, "siteId": 1}
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
    {"text": "Test Product", "type": "product", "id": 123, "siteId": 1},
    {"text": "Test Category", "type": "category", "id": 45, "siteId": 1}
]
```

### Examples

**Full URL format:**

```text
https://your-site.com/actions/search-manager/api/autocomplete?q=test&indices=entries-en&hitsPerPage=10&siteId=1
```

```javascript
// Default: both suggestions and results
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&indices=entries-en');
const data = await response.json();
// data.suggestions = ["test", "testing"]
// data.results = [{text: "Test Entry", type: "entry", id: 1, siteId: 1}]

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
    indices: 'products',
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

These endpoints are used by the frontend widget to track search activity. When **Require API Key** is enabled, they require the same `X-Search-Manager-Key` header as search/autocomplete. When the setting is disabled, they accept anonymous requests. They do not require a CSRF token, which keeps them compatible with full-page static caching (Blitz, Servd, etc.).

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
| `cached` @since(5.46.0) | (none) | Boolean-like (`1`/`0`, `true`/`false`, `on`/`off`, `yes`/`no`). Carry forward from the final search response's `meta.cached`. When truthy, the analytics row records `executionTime = 0` (cache hit). |
| `took` @since(5.46.0) | (none) | Backend execution time in ms from `meta.took`. Used only when `cached` is falsy. Clamped to `[0, 60000]`; negative or non-numeric values are ignored. Recorded as the row's `executionTime` for cache-miss accounting. |

```json
{"success": true, "tracked": true}
```

Returns `"tracked": false` when analytics is disabled or no valid indices match.

Omitting `cached` / `took` is supported and writes `executionTime = NULL` (legacy behaviour — the row counts as a search action but is excluded from cache hit rate calculations).

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
        const res = await fetch(`/actions/search-manager/api/search?q=${encodeURIComponent(q)}&indices=all-sites&hitsPerPage=10`);
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
