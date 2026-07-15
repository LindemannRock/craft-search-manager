# Meilisearch Backend

The Meilisearch backend connects to a self-hosted Meilisearch instance. It's a popular open-source alternative to Algolia with a similar feature set.

## When to Use Meilisearch

- You want Algolia-like search without cloud vendor lock-in
- You can host your own search infrastructure
- You need instant search with typo tolerance
- You want a schemaless index (all fields indexed automatically)

## Requirements

- Running Meilisearch server
- Admin API key for indexing and other write operations, unless Meilisearch runs without authentication in development
- Optional search API key for search queries

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
MEILISEARCH_ADMIN_API_KEY=your-admin-api-key
MEILISEARCH_SEARCH_API_KEY=your-search-key
```

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `host` | `string` | (required) | Meilisearch server URL (can be full URL like `https://meilisearch.example.com`) |
| `adminApiKey` | `string` | (optional) | Admin API key for indexing and other write operations. Do not use the Meilisearch master key; Meilisearch reserves it for managing API keys. Not required if Meilisearch runs without authentication. |
| `searchApiKey` | `string` | (optional) | Search API key for search queries. Falls back to `adminApiKey` when empty. |

## Key Behaviors

- **Schemaless storage** — Meilisearch stores all fields in your transformer output automatically. No schema definition needed.
- **Managed searchable attributes** — Search Manager pins the index's searchable attributes to `title`, `content`, `_bodyClean`, `url` (in that order) and resets them automatically if they drift, so queries match the same fields as every other backend. Changing searchable attributes in the Meilisearch dashboard is reverted on the next indexing or search call.
- **Index clearing** uses `deleteAllDocuments()` to clear an index

## Autocomplete

Meilisearch supports autocomplete natively: Search Manager runs a small prefix search against the index and extracts unique result titles as suggestions. This differs from the built-in backends, which suggest indexed terms from their own term index.

## Result Scores

Search Manager requests Meilisearch ranking scores and maps `_rankingScore` to the public `score` field when Meilisearch returns it. That value reflects Meilisearch's ranking rules, not Search Manager's BM25 algorithm.

Tune relevance in Meilisearch with ranking rules, typo tolerance, synonyms, and custom ranking rules. Searchable attribute order is the exception — Search Manager manages it (see Key Behaviors above). Do not compare Meilisearch scores directly with built-in backend, Algolia, or Typesense scores.

## Limitations

- Requires hosting a Meilisearch server
- Native search replacement is not available
- Search operators use Meilisearch's native syntax
