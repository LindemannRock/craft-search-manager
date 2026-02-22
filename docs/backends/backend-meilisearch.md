# Meilisearch Backend

The Meilisearch backend connects to a self-hosted Meilisearch instance. It's a popular open-source alternative to Algolia with a similar feature set.

## When to Use Meilisearch

- You want Algolia-like search without cloud vendor lock-in
- You can host your own search infrastructure
- You need instant search with typo tolerance
- You want a schemaless index (all fields indexed automatically)

## Requirements

- Running Meilisearch server
- Admin API key (master key) for indexing
- Optional search API key for frontend queries

## Features

Everything from the built-in backends, plus:

- `browse()` — iterate through all documents in an index
- `multipleQueries()` — batch search across multiple indices in one request
- `parseFilters()` — generates Meilisearch filter syntax automatically
- `listIndices()` — list indices from Meilisearch
- Schemaless — indexes all fields automatically
- Native typo tolerance

## Configuration

```php
'backends' => [
    'dev-meilisearch' => [
        'name' => 'Development Meilisearch',
        'backendType' => 'meilisearch',
        'enabled' => true,
        'settings' => [
            'host' => App::env('MEILISEARCH_HOST') ?: 'http://localhost:7700',
            'adminApiKey' => App::env('MEILISEARCH_ADMIN_API_KEY'),
            'searchApiKey' => App::env('MEILISEARCH_SEARCH_API_KEY'),
        ],
    ],
],
```

```bash
# .env
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_ADMIN_API_KEY=your-master-key
MEILISEARCH_SEARCH_API_KEY=your-search-key
```

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `host` | `string` | (required) | Meilisearch server URL (can be full URL like `https://meilisearch.example.com`) |
| `adminApiKey` | `string` | (optional) | Master/admin key for indexing operations. Not required if Meilisearch runs without authentication. |
| `searchApiKey` | `string` | (optional) | Search-only key for frontend queries |

## Key Behaviors

- **Schemaless** — Meilisearch indexes all fields in your transformer output automatically. No schema definition needed.
- **Searches all fields** by default
- **Index clearing** uses `deleteAllDocuments()` to clear an index

## Limitations

- Requires hosting a Meilisearch server
- Native search replacement is not available
- Search operators use Meilisearch's native syntax
