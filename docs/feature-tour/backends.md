# Backends

Search Manager supports seven search backends. You can configure multiple backends and switch between them per environment — your templates don't need to change.

## Choosing a Backend

| Backend | Best For | External Service | BM25 Ranking | Browse API |
|---------|----------|-----------------|--------------|------------|
| [MySQL](backend-mysql.md) | Most Craft sites | No | Yes | No |
| [PostgreSQL](backend-postgresql.md) | PostgreSQL-based Craft sites | No | Yes | No |
| [Redis](backend-redis.md) | High-traffic sites, shared cache infra | No (PHP extension) | Yes | No |
| [File](backend-file.md) | Development, small sites | No | Yes | No |
| [Algolia](backend-algolia.md) | Cloud-hosted, Scout replacement | Yes | Native | Yes |
| [Meilisearch](backend-meilisearch.md) | Self-hosted Algolia alternative | Yes | Native | Yes |
| [Typesense](backend-typesense.md) | Self-hosted, native typo tolerance | Yes | Native | Yes |

### Quick Decision Guide

**Start with MySQL or File** if:
- You want zero additional setup
- Your site is small to medium traffic
- You're evaluating Search Manager for the first time

**Use Redis** if:
- You already have Redis in your stack
- You need fast in-memory search
- You're running a multi-server setup

**Use an external backend** (Algolia, Meilisearch, Typesense) if:
- You need cloud-hosted search at scale
- You want native typo tolerance and faceting
- You're migrating from Scout or another Algolia plugin

## Built-in vs External Backends

The built-in backends (MySQL, PostgreSQL, Redis, File) all share the same feature set:

- Full BM25 ranking algorithm
- All search operators (phrase, NOT, field-specific, wildcards, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 5 languages
- Localized boolean operators
- Native search replacement (CP search, `Entry::find()->search()`)

The external backends (Algolia, Meilisearch, Typesense) use their own ranking and search capabilities, plus:

- `browse()` — iterate through all documents in an index
- `multipleQueries()` — batch search across multiple indices in a single request
- `parseFilters()` — generate backend-specific filter syntax automatically
- Native search replacement is **not available** for external backends

## Configuring Backends

Backends are defined as named instances in `config/search-manager.php`:

```php
'backends' => [
    'my-handle' => [
        'name' => 'Display Name',
        'backendType' => 'mysql',  // mysql, pgsql, redis, file, algolia, meilisearch, typesense
        'enabled' => true,
        'settings' => [
            // Backend-specific settings
        ],
    ],
],
```

You can also create backends through the Control Panel under Search Manager > Backends.

### Config vs Database

- **Config-defined backends** are set in `config/search-manager.php`. They cannot be edited in the CP and show a "Config" badge.
- **Database-defined backends** are created via the CP. They are fully editable and show a "Database" badge.

If a config backend shares a handle with a database backend, the config version takes precedence.

### Default Backend

Set your default backend via `defaultBackendHandle`:

```php
'*' => [
    'defaultBackendHandle' => 'my-mysql',
],
'production' => [
    'defaultBackendHandle' => 'production-algolia',
],
```

If the default backend is deleted or disabled, another enabled backend is automatically assigned.

## Multiple Backends

You can configure multiple backends and use different ones per environment:

```php
return [
    '*' => [
        'defaultBackendHandle' => 'dev-mysql',
        'backends' => [
            'dev-mysql' => [
                'name' => 'Development MySQL',
                'backendType' => 'mysql',
                'enabled' => true,
                'settings' => [],
            ],
            'production-algolia' => [
                'name' => 'Production Algolia',
                'backendType' => 'algolia',
                'enabled' => true,
                'settings' => [
                    'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
                    'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
                ],
            ],
        ],
    ],
    'production' => [
        'defaultBackendHandle' => 'production-algolia',
    ],
];
```

In templates, you can also query a specific backend directly:

```twig
{% set algolia = craft.searchManager.withBackend('production-algolia') %}
{% set results = algolia.search('products', 'laptop') %}
```

See [Multi-Index Search](../template-guides/multi-index-search.md) for more examples.
