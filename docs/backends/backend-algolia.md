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

## Autocomplete

Algolia's autocomplete returns title-based suggestions (full entry titles matching the query), unlike built-in backends which return individual term suggestions. This leverages Algolia's instant search capabilities.

## Migrating from Scout

Search Manager's template API is designed to be compatible with Scout and trendyminds/algolia. Methods like `browse()`, `multipleQueries()`, and `parseFilters()` map directly to their Algolia equivalents.

## Limitations

- Requires an Algolia account (pricing based on usage)
- Native search replacement is not available
- Search operators (phrase, NOT, wildcards, etc.) use Algolia's native syntax, not Search Manager's
