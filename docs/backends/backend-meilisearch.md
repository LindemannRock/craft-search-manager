# Meilisearch Backend

Get Algolia-style instant search without the cloud vendor lock-in: the Meilisearch backend connects to a self-hosted Meilisearch instance, a popular open-source alternative with a similar feature set.

## What you'll use it for

- Algolia-like search without cloud vendor lock-in
- Self-hosting your own search infrastructure
- Instant search with native typo tolerance
- A schemaless index — every field in your transformer output is indexed automatically

## Create your first Meilisearch backend

You'll need a running Meilisearch server. For local testing, `docker run -d -p 7700:7700 getmeili/meilisearch:latest` starts one in seconds.

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Development Meilisearch") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **Meilisearch**.
4. Fill in the Meilisearch fields:
   - **Host** — your Meilisearch server URL (e.g. `http://localhost:7700`)
   - **Admin API Key** *(optional)* — used for indexing and other write operations. Don't use your Meilisearch master key here; Meilisearch reserves it for managing API keys. Not required if Meilisearch runs without authentication.
   - **Search API Key** *(optional)* — used for search queries; falls back to the Admin API Key when left empty

   Each field supports environment-variable autosuggest — start typing `$` to pick from your defined environment variables instead of pasting a raw key.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **Yes** for Meilisearch). Use **Refresh Connection** to retest anytime.

For environment-specific setups, define the backend in `config/search-manager.php` instead — see [Configuration](#configuration) below.

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

Then define the referenced environment variables in your `.env` file:

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

## Key behaviors

- **Schemaless storage** — Meilisearch stores all fields in your transformer output automatically. No schema definition needed.
- **Managed searchable attributes** — Search Manager pins the index's searchable attributes to `title`, `content`, `_bodyClean`, `url` (in that order) and resets them automatically if they drift, so queries match the same fields as every other backend. Changing searchable attributes in the Meilisearch dashboard is reverted on the next indexing or search call.
- **Index clearing** uses `deleteAllDocuments()` to clear an index

## Autocomplete

Meilisearch supports autocomplete natively: Search Manager runs a small prefix search against the index and extracts unique result titles as suggestions. This differs from the built-in backends, which suggest indexed terms from their own term index.

## Result scores

Search Manager requests Meilisearch ranking scores and maps `_rankingScore` to the public `score` field when Meilisearch returns it. That value reflects Meilisearch's ranking rules, not Search Manager's BM25 algorithm.

Tune relevance in Meilisearch with ranking rules, typo tolerance, synonyms, and custom ranking rules. Searchable attribute order is the exception — Search Manager manages it (see [Key behaviors](#key-behaviors) above). Do not compare Meilisearch scores directly with built-in backend, Algolia, or Typesense scores.

## Limitations

- Requires hosting a Meilisearch server
- Native search replacement is not available
- Search operators use Meilisearch's native syntax
