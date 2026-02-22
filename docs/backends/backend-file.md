# File Backend

The File backend stores search data as files in Craft's storage directory. It's the simplest option for development and small sites — no database tables, no external services.

## When to Use File

- Development and testing environments
- Small sites with limited content
- When you want zero dependencies beyond PHP
- Quick prototyping before choosing a production backend

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 5 languages
- Localized boolean operators
- Native search replacement (CP search + `Entry::find()->search()`)
- No external dependencies whatsoever

## Storage Location

Index data is stored in:

```text
storage/runtime/search-manager/indices/
```

Search and autocomplete caches are stored in:

```text
storage/runtime/search-manager/cache/search/
storage/runtime/search-manager/cache/autocomplete/
```

These directories are created automatically and can be safely deleted — they'll be recreated on the next index rebuild.

## Configuration

```php
'backends' => [
    'local-file' => [
        'name' => 'Local File Storage',
        'backendType' => 'file',
        'enabled' => true,
        'settings' => [],
    ],
],
```

No additional settings are needed. By default, index files are stored in `storage/runtime/search-manager/indices/`.

### Custom Storage Path

You can specify a custom directory for index storage:

```php
'backends' => [
    'local-file' => [
        'name' => 'Local File Storage',
        'backendType' => 'file',
        'enabled' => true,
        'settings' => [
            'storagePath' => '@storage/custom-search-indices',
        ],
    ],
],
```

The path supports Craft aliases (`@storage`, `@root`) and environment variables (`$ENV_VAR`) via `App::parseEnv()`. Path traversal (`..`) is not allowed — the path must start with an allowed prefix (`@root`, `@storage`, or `$`).

## Limitations

- Slower than MySQL or Redis for large datasets
- Not suitable for multi-server deployments (files are local to each server)
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
- File I/O can be a bottleneck on high-traffic sites

For production sites with more than a few hundred indexed documents, consider MySQL, Redis, or an external backend.
