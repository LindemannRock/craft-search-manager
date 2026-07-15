# Typesense Backend

The Typesense backend connects to a self-hosted Typesense server. Typesense is an open-source search engine known for its native typo tolerance and easy setup.

## When to Use Typesense

- You want self-hosted search with native typo tolerance
- You need fast, lightweight search infrastructure
- You prefer schema-based indexing for data integrity

## Requirements

- Running Typesense server
- Admin API key with write access for indexing
- Optional search-only API key for search queries

## Features

Everything from the built-in backends, plus:

- `browse()` — iterate through all documents in an index
- `multipleQueries()` — batch search across multiple indices in one request
- `parseFilters()` — generates Typesense filter syntax automatically
- `listIndices()` — list collections from Typesense
- Native typo tolerance
- Schema-based collections

## Configuration

```php
'backends' => [
    'typesense-server' => [
        'name' => 'Typesense Server',
        'backendType' => 'typesense',
        'enabled' => true,
        'settings' => [
            'host' => 'localhost',
            'port' => '8108',
            'protocol' => 'http',
            'adminApiKey' => App::env('TYPESENSE_ADMIN_API_KEY'),
            'searchApiKey' => App::env('TYPESENSE_SEARCH_API_KEY'),
            'connectionTimeout' => 5,
        ],
    ],
],
```

```bash
# .env
TYPESENSE_ADMIN_API_KEY=your-admin-api-key
TYPESENSE_SEARCH_API_KEY=your-search-key
```

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `host` | `string` | (required) | Typesense server hostname |
| `port` | `string` | `'8108'` | Server port |
| `protocol` | `string` | `'http'` | Protocol (`http` or `https`) |
| `adminApiKey` | `string` | (required) | Admin API key with write access for indexing. In Typesense Cloud, use Generate API Keys. Do not use the bootstrap key (`--api-key`) in production. |
| `searchApiKey` | `string` | (optional) | Search-only API key for search queries, autocomplete, and multi-search. Falls back to `adminApiKey` when empty. |
| `connectionTimeout` | `int` | `5` | Connection timeout in seconds |

## Schema-Based Indexing

Unlike Algolia and Meilisearch which are schemaless, Typesense requires explicit field definitions. Search Manager handles this automatically — collections are created with a flexible schema on first index.

### Search Fields (`query_by`)

Typesense requires a `query_by` parameter specifying which fields to search. By default, Search Manager searches `title`, `content`, `_bodyClean`, `url` with weights `5, 3, 1, 1` — titles rank highest, body text lowest.

If your custom transformer adds additional fields you want to be searchable, pass them in search options. An explicit `query_by` replaces the default entirely, so include the default fields (and supply `query_by_weights` if you still want weighting):

```twig
{% set results = craft.searchManager.search('products', 'laptop', {
    query_by: 'title,content,_bodyClean,url,description,category',
    query_by_weights: '5,3,1,1,2,2'
}) %}
```

## Key Behaviors

- Collections are auto-created with a flexible schema on first index
- Index clearing works by deleting and recreating the collection
- Multi-site uses composite document IDs (`{elementId}_{siteId}`)

## Autocomplete

Typesense supports autocomplete natively: Search Manager runs a small prefix search and extracts unique result titles as suggestions. Autocomplete queries search `title` and `content` only (not the full main-search field set).

## Result Scores

Search Manager maps Typesense's text match value to the public `score` field when Typesense returns it. That value reflects Typesense's text matching and ranking settings, not Search Manager's BM25 algorithm.

Tune relevance in Typesense with `query_by`, `query_by_weights`, `sort_by`, `_text_match` options, pinned or hidden hits, and other Typesense ranking controls. Do not compare Typesense scores directly with built-in backend, Algolia, or Meilisearch scores.

## Limitations

- Requires hosting a Typesense server
- Native search replacement is not available
- Must specify `query_by` for custom fields beyond the default `title,content,_bodyClean,url` set
