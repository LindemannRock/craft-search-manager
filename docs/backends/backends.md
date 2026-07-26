# Backends

Point Search Manager at the search engine that fits your stack — your Craft database, an in-memory store, or a managed cloud service — and switch backends per environment without touching a template. Search Manager supports seven backends: MySQL, PostgreSQL, Redis, File, Algolia, Meilisearch, and Typesense.

## What you'll use it for

- Get search running with zero new infrastructure, using the Craft database you already have (MySQL or PostgreSQL)
- Reuse Redis you already run for caching or sessions, or move up to it once an index passes ~50,000 elements
- Prototype locally or on a small site with the File backend — no database tables, no services
- Hand search off to a managed cloud service (Algolia, Meilisearch, or Typesense) for typo tolerance, faceting, and CDN-backed speed
- Run different backends per environment — MySQL in development, Algolia in production — while your templates stay the same

## Choosing a backend

| Backend | Best for | External service | Ranking model | Browse API |
|---------|----------|-----------------|---------------|------------|
| [MySQL](backend-mysql.md) | Most Craft sites (up to ~50k elements per index) | No | Search Manager BM25 | No |
| [PostgreSQL](backend-postgresql.md) | PostgreSQL-based Craft sites (up to ~50k elements per index) | No | Search Manager BM25 | No |
| [Redis](backend-redis.md) | Multi-server setups, 50k+ element indices | No (PHP extension) | Search Manager BM25 | No |
| [File](backend-file.md) | Development, prototyping (under ~500 elements) | No | Search Manager BM25 | No |
| [Algolia](backend-algolia.md) | Cloud-hosted, Scout replacement | Yes | Algolia ranking | Yes |
| [Meilisearch](backend-meilisearch.md) | Self-hosted Algolia alternative | Yes | Meilisearch ranking | Yes |
| [Typesense](backend-typesense.md) | Self-hosted, native typo tolerance | Yes | Typesense ranking | Yes |

### Quick decision guide

**Start with MySQL or File** if:
- You want zero additional setup
- Your indices have up to ~50,000 elements (MySQL) or ~500 elements (File)
- You're evaluating Search Manager for the first time

**Use Redis** if:
- You already have Redis in your stack
- You're running a multi-server setup and need shared search data
- Your indices exceed ~50,000 elements and you want in-memory speed

**Use an external backend** (Algolia, Meilisearch, Typesense) if:
- You need cloud-hosted, fully managed search infrastructure
- You want native typo tolerance and faceting
- You're migrating from Scout or another Algolia plugin

## Built-in vs external backends

The built-in backends (MySQL, PostgreSQL, Redis, File) all share the same feature set:

- Full BM25 ranking algorithm
- All search operators (phrase, NOT, field-specific, wildcards, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (front-end `Entry::find()->search()` template queries only — Control Panel search always uses Craft's native search)

The external backends (Algolia, Meilisearch, Typesense) use their own ranking and search capabilities, plus:

- `browse()` — iterate through all documents in an index (external backends only; built-in backends return an empty result)
- `multipleQueries()` — batch search across multiple indices in a single native request (built-in backends fall back to sequential per-index searches)
- `parseFilters()` — generate backend-specific filter syntax (built-in backends use a generic SQL-like syntax)
- Native search replacement is **not available** for external backends

### Result scores

The `score` field is a backend-specific relevance signal, not a universal scale:

| Backend | `score` meaning |
|---------|-----------------|
| MySQL / PostgreSQL / Redis / File | Search Manager's BM25 relevance score. |
| Algolia | No Search Manager score mapping. Algolia result order comes from Algolia's ranking criteria; ranking metadata is not converted into `score`. |
| Meilisearch | Mapped from Meilisearch's `_rankingScore` when Meilisearch returns it. |
| Typesense | Mapped from Typesense's text match value when Typesense returns it. |
| Promoted results | Can be `null` because promoted placement bypasses normal relevance scoring. |

Use `score` for debugging or display within a single backend response. Do not compare scores across different backend types or treat every score as BM25.

## Configuring backends

The fastest way to add a backend is in the Control Panel: go to **Search Manager → Backends**, click **New Backend**, give it a **Name** and pick a **Backend Type**, then fill in that type's settings. Each backend page below walks through the exact fields for that type. Backends created this way show a **Database** badge and are fully editable in the CP.

For environment-specific setups — different backends per environment, secrets pulled from environment variables — define backends in `config/search-manager.php` instead:

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

### Config vs database

- **Config-defined backends** are set in `config/search-manager.php`. They cannot be edited in the CP and show a "Config" badge.
- **Database-defined backends** are created via the CP. They are fully editable and show a "Database" badge.

If a config backend shares a handle with a database backend, the config version takes precedence.

### Default backend

Set your default backend via `defaultBackendHandle`:

```php
'*' => [
    'defaultBackendHandle' => 'my-mysql',
],
'production' => [
    'defaultBackendHandle' => 'production-algolia',
],
```

The active default backend cannot be deleted or disabled — select another default first. This guard also applies to direct model deletion, so code cannot bypass the Control Panel check. When you create or save a backend while the database-managed default is empty, missing, or disabled, Search Manager assigns the first enabled backend deterministically. A `defaultBackendHandle` set in `config/search-manager.php` remains authoritative and cannot be changed in the Control Panel.

## Multiple backends

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
