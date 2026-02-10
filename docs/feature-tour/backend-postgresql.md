# PostgreSQL Backend

The PostgreSQL backend uses your Craft database for search storage, just like the MySQL backend. If your Craft installation runs on PostgreSQL, this is the natural choice.

## When to Use PostgreSQL

- Your Craft site uses PostgreSQL as its database
- You want zero-configuration search
- You need all built-in search features including native search replacement

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 5 languages
- Localized boolean operators
- Native search replacement (CP search + `Entry::find()->search()`)
- No external dependencies

## Configuration

```php
'backends' => [
    'craft-pgsql' => [
        'name' => 'Craft PostgreSQL',
        'backendType' => 'pgsql',
        'enabled' => true,
        'settings' => [],
    ],
],
```

No additional settings are needed — it uses your existing Craft database connection.

## Limitations

- Only available when Craft uses PostgreSQL as its database
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
- Search performance depends on your database server's capacity
