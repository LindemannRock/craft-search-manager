# Algolia Backend

Hand search off to a fully managed cloud service: the Algolia backend connects Search Manager to Algolia's cloud-hosted search infrastructure, with instant search, native typo tolerance, and a global CDN. If you're migrating from Scout or another Algolia plugin, Search Manager provides a compatible API — see [Migrating from Scout](#migrating-from-scout) below.

## What you'll use it for

- Cloud-hosted search without managing your own infrastructure
- Instant search at scale, served from Algolia's global CDN
- Migrating from Scout or trendyminds/algolia
- Faceted search and advanced filtering on top of Search Manager's indexing

## Create your first Algolia backend

You'll need the PHP cURL extension and an Algolia account with an Application ID and API keys.

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Production Algolia") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **Algolia**.
4. Fill in the Algolia fields, available from your [Algolia dashboard](https://www.algolia.com/):
   - **Application ID** — your Algolia Application ID
   - **Admin API Key** — used for indexing and other write operations
   - **Search-only API Key** *(optional)* — used for search queries, autocomplete, and multi-query requests; falls back to the Admin API Key when left empty

   Each field supports environment-variable autosuggest — start typing `$` to pick from your defined environment variables instead of pasting a raw key.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **Yes** for Algolia). Use **Refresh Connection** to retest anytime.

For environment-specific setups, define the backend in `config/search-manager.php` instead — see [Configuration](#configuration) below.

## Requirements

- PHP cURL extension
- Algolia account with Application ID and API keys

## Features

Everything from the built-in backends, plus:

- `browse()` — iterate through all documents in an index
- `multipleQueries()` — batch search across multiple indices in one API call
- `parseFilters()` — generates Algolia filter syntax automatically
- `listIndices()` — list indices from Algolia's service
- Cloud-hosted with global CDN
- Native typo tolerance and ranking

## Configuration

```php
'backends' => [
    'production-algolia' => [
        'name' => 'Production Algolia',
        'backendType' => 'algolia',
        'enabled' => true,
        'settings' => [
            'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
            'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
            'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
        ],
    ],
],
```

Then define the referenced environment variables in your `.env` file:

```bash
# .env
ALGOLIA_APPLICATION_ID=your-app-id
ALGOLIA_ADMIN_API_KEY=your-admin-key
ALGOLIA_SEARCH_API_KEY=your-search-key
```

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `applicationId` | `string` | (required) | Your Algolia Application ID |
| `adminApiKey` | `string` | (required) | Admin API key for indexing and other write operations |
| `searchApiKey` | `string` | (optional) | Search-only API key for search queries, autocomplete, and multi-query requests. Falls back to `adminApiKey` when empty. |

## Multi-site support

Algolia uses composite document IDs formatted as `{elementId}_{siteId}` (e.g., `5_1`, `5_2`). This ensures the same element across different sites doesn't overwrite each other in the index.

## Algolia index settings

Search Manager handles the connection, indexing, document IDs, search calls, and the built-in Search Manager filters. Algolia still owns Algolia-specific index configuration.

An Algolia index is searchable as soon as Search Manager has indexed records into it. Search Manager configures the searchable attributes (`title`, `content`, `_bodyClean`, `url`) automatically, so a basic query works without extra setup.

Algolia also enforces record-size limits. Build plan indices have a 10 KB hard per-record limit. Elevate/Grow indices allow larger individual records, but still have a 100 KB per-record limit and a 10 KB average record-size limit across the index. These limits matter for documentation pages because page-mode docs records can include long body text, heading metadata, and stored snippet sources.

For Docs Manager, long-form Entry, and rich Commerce Product indices on Algolia, prefer Split Sections. Algolia's recommended pattern for long documents is to split them into smaller records, and Search Manager's split mode does that while keeping each hit tied to the parent element. Page-mode docs or rich AutoTransformer-family indices with large content may exceed Algolia limits even before enabling code snippets.

For production relevance, configure Algolia's index settings in Algolia:

- `customRanking` and ranking settings — tune business relevance such as popularity, rating, recency, availability, or featured flags.
- typo tolerance, rules, synonyms, replicas, and sort replicas — use Algolia's native controls for those behaviours.

> [!WARNING]
> `searchableAttributes` is managed by Search Manager and is not dashboard-tunable. Search Manager keeps it pinned to `title`, `content`, `_bodyClean`, `url` (in that order) and resets it automatically whenever it drifts, so dashboard changes to it are reverted on the next indexing or search call. Facet attributes are different: Search Manager merges its required `attributesForFaceting` (`siteId`, `elementId`, `type`) with yours, so custom facet attributes are safe to add.

Search Manager does not currently expose these relevance settings in the backend configuration form. Configure them in the Algolia dashboard or with Algolia's API.

Search Manager intentionally does not convert Algolia ranking metadata into the `score` field. Treat Algolia result order as the relevance signal. If Search Manager exposes Algolia `_rankingInfo` in a future release, it should be used as debug metadata rather than a portable relevance score.

### Filtering attributes

Algolia requires filter fields to be listed in `attributesForFaceting` before they can be used in `filters`, `facetFilters`, or optional filters.

Search Manager automatically adds the attributes needed for its built-in filters:

- `filterOnly(siteId)`
- `filterOnly(elementId)`
- `filterOnly(type)`

Custom filters are different. If your templates, API callers, or GraphQL queries filter by fields such as `brand`, `category`, `price`, `inStock`, `region`, or `vehicleType`, add those fields to `attributesForFaceting` in Algolia.

## Autocomplete

Algolia's autocomplete returns title-based suggestions (full entry titles matching the query), unlike built-in backends which return individual term suggestions. This leverages Algolia's instant search capabilities.

## Migrating from Scout

Search Manager's template API is designed to be compatible with Scout and trendyminds/algolia. The method names map directly to their Algolia equivalents, so the real migration work is moving each piece of configuration to its Search Manager home:

| What you had | Search Manager equivalent |
|---|---|
| A searchable model / index definition | A named index under **Search Manager → Indices**, or the `indices` block in `config/search-manager.php` (`elementType`, `siteId`, `criteria`) — see [Indices](../feature-tour/indices.md) |
| The logic that builds each searchable record from a model | A transformer's `transform()` method — extend `BaseTransformer` or `AutoTransformer`, or leave the index's Transformer Class blank for automatic field extraction — see [Custom Transformers](../developers/custom-transformers.md) |
| Algolia driver / connection config | The **Application ID**, **Admin API Key**, and **Search-only API Key** settings on this backend — see [Configuration](#configuration) above |
| Running a search query | `craft.searchManager.search('index-handle', 'query')` in Twig, or the REST/GraphQL search endpoints |
| Iterating every record in an index | `browse()` — same method name and job |
| Searching multiple indices in one call | `multipleQueries()` — same method name and job |
| Building Algolia filter syntax | `parseFilters()` — same method name and job |
| Algolia `searchableAttributes` | Managed automatically by Search Manager, not dashboard-tunable — see [Algolia index settings](#algolia-index-settings) above |

This page can't verify the exact shape of your existing Scout model config or `.env` keys, so there's no before/after code sample here — use the table above to map your searchable model to a Search Manager index and transformer, and your Algolia driver credentials to the backend settings above.

## Limitations

- Requires an Algolia account (pricing based on usage)
- Native search replacement is not available
- Search operators (phrase, NOT, wildcards, etc.) use Algolia's native syntax, not Search Manager's
- Algolia relevance settings such as `searchableAttributes`, `customRanking`, rules, and replicas are configured in Algolia, not in Search Manager
- Large page-mode documentation records can exceed Algolia's per-record or average record-size limits. Use Split Sections for Docs Manager indices to keep records smaller.
