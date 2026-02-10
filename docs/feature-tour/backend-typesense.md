# Typesense Backend

The Typesense backend connects to a self-hosted Typesense server. Typesense is an open-source search engine known for its native typo tolerance and easy setup.

## When to Use Typesense

- You want self-hosted search with native typo tolerance
- You need fast, lightweight search infrastructure
- You prefer schema-based indexing for data integrity

## Requirements

- Running Typesense server
- API key for authentication

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
            'apiKey' => App::env('TYPESENSE_API_KEY'),
            'connectionTimeout' => 5,
        ],
    ],
],
```

```bash
# .env
TYPESENSE_API_KEY=your-api-key
```

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `host` | `string` | (required) | Typesense server hostname |
| `port` | `string` | `'8108'` | Server port |
| `protocol` | `string` | `'http'` | Protocol (`http` or `https`) |
| `apiKey` | `string` | (required) | API key for authentication |
| `connectionTimeout` | `int` | `5` | Connection timeout in seconds |

## Schema-Based Indexing

Unlike Algolia and Meilisearch which are schemaless, Typesense requires explicit field definitions. Search Manager handles this automatically — collections are created with a flexible schema on first index.

### Search Fields (`query_by`)

Typesense requires a `query_by` parameter specifying which fields to search. By default, Search Manager searches: `title`, `content`, `url`.

If your custom transformer adds additional fields you want to be searchable, pass them in search options:

```twig
{% set results = craft.searchManager.search('products', 'laptop', {
    query_by: 'title,content,url,description,category'
}) %}
```

## Key Behaviors

- Collections are auto-created with a flexible schema on first index
- Index clearing works by deleting and recreating the collection
- Multi-site uses composite document IDs (`{elementId}_{siteId}`)

## Limitations

- Requires hosting a Typesense server
- Native search replacement is not available
- Must specify `query_by` for custom fields beyond title/content/url
