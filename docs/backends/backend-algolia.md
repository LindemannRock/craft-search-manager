# Algolia Backend

The Algolia backend connects Search Manager to Algolia's cloud-hosted search service. If you're migrating from Scout or another Algolia plugin, Search Manager provides a compatible API.

## When to Use Algolia

- You want cloud-hosted search without managing infrastructure
- You need instant search at scale with global CDN
- You're migrating from Scout or trendyminds/algolia
- You need faceted search and advanced filtering

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
| `adminApiKey` | `string` | (required) | Admin API key (for indexing) |
| `searchApiKey` | `string` | (optional) | Search-only API key (for frontend) |

## Multi-Site Support

Algolia uses composite document IDs formatted as `{elementId}_{siteId}` (e.g., `5_1`, `5_2`). This ensures the same element across different sites doesn't overwrite each other in the index.

## Algolia Index Settings

Search Manager handles the connection, indexing, document IDs, search calls, and the built-in Search Manager filters. Algolia still owns Algolia-specific index configuration.

An Algolia index is searchable as soon as Search Manager has indexed records into it. By default, Algolia searches all searchable attributes in the records, so a basic query works without extra setup.

Algolia also enforces record-size limits. Build plan indices have a 10 KB hard per-record limit. Elevate/Grow indices allow larger individual records, but still have a 100 KB per-record limit and a 10 KB average record-size limit across the index. These limits matter for documentation pages because page-mode docs records can include long body text, heading metadata, and stored snippet sources.

For Docs Manager, long-form Entry, and rich Commerce Product indices on Algolia, prefer Split Sections. Algolia's recommended pattern for long documents is to split them into smaller records, and Search Manager's split mode does that while keeping each hit tied to the parent element. Page-mode docs or rich AutoTransformer-family indices with large content may exceed Algolia limits even before enabling code snippets.

For production relevance, configure Algolia's index settings in Algolia:

- `searchableAttributes` — choose which record fields Algolia searches, and in what priority order.
- `customRanking` and ranking settings — tune business relevance such as popularity, rating, recency, availability, or featured flags.
- typo tolerance, rules, synonyms, replicas, and sort replicas — use Algolia's native controls for those behaviours.

Search Manager does not currently expose these relevance settings in the backend configuration form. Configure them in the Algolia dashboard or with Algolia's API.

Search Manager intentionally does not convert Algolia ranking metadata into the `score` field. Treat Algolia result order as the relevance signal. If Search Manager exposes Algolia `_rankingInfo` in a future release, it should be used as debug metadata rather than a portable relevance score.

### Filtering Attributes

Algolia requires filter fields to be listed in `attributesForFaceting` before they can be used in `filters`, `facetFilters`, or optional filters.

Search Manager automatically adds the attributes needed for its built-in filters:

- `filterOnly(siteId)`
- `filterOnly(elementId)`
- `filterOnly(type)`

Custom filters are different. If your templates, API callers, or GraphQL queries filter by fields such as `brand`, `category`, `price`, `inStock`, `region`, or `vehicleType`, add those fields to `attributesForFaceting` in Algolia.

## Autocomplete

Algolia's autocomplete returns title-based suggestions (full entry titles matching the query), unlike built-in backends which return individual term suggestions. This leverages Algolia's instant search capabilities.

## Migrating from Scout

Search Manager's template API is designed to be compatible with Scout and trendyminds/algolia. Methods like `browse()`, `multipleQueries()`, and `parseFilters()` map directly to their Algolia equivalents.

## Limitations

- Requires an Algolia account (pricing based on usage)
- Native search replacement is not available
- Search operators (phrase, NOT, wildcards, etc.) use Algolia's native syntax, not Search Manager's
- Algolia relevance settings such as `searchableAttributes`, `customRanking`, rules, and replicas are configured in Algolia, not in Search Manager
- Large page-mode documentation records can exceed Algolia's per-record or average record-size limits. Use Split Sections for Docs Manager indices to keep records smaller.
