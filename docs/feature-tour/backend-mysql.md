# MySQL Backend

The MySQL backend stores search data directly in your Craft database. It's the simplest option — no external services, no additional infrastructure.

## When to Use MySQL

- You want zero-configuration search out of the box
- Your site runs on MySQL (most Craft installations)
- You don't want to manage additional services
- You need all search features including native search replacement

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 5 languages
- Localized boolean operators
- Native search replacement (CP search + `Entry::find()->search()`)
- No external dependencies

## How It Works

When you index content, Search Manager stores document data and a pre-computed term index in MySQL tables alongside your Craft data. Searches run SQL queries against these tables using BM25 scoring to rank results by relevance.

The BM25 algorithm considers:
- **Term frequency** — how often the search term appears in a document
- **Inverse document frequency** — how rare the term is across all documents
- **Document length normalization** — shorter documents with the term rank higher

You can tune BM25 parameters in [Configuration](../get-started/configuration.md) if needed, but the defaults work well for most sites.

## Configuration

```php
'backends' => [
    'craft-mysql' => [
        'name' => 'Craft MySQL',
        'backendType' => 'mysql',
        'enabled' => true,
        'settings' => [],
    ],
],
```

No additional settings are needed — it uses your existing Craft database connection.

## Limitations

- Only available when Craft uses MySQL as its database
- Search performance depends on your database server's capacity
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
- Large indices (100k+ documents) may benefit from Redis or an external backend
