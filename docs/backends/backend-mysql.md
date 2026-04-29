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
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (CP search + `Entry::find()->search()`)
- No external dependencies

## How It Works

When you index content, Search Manager stores document data and a pre-computed term index in MySQL tables alongside your Craft data. Searches run SQL queries against these tables using BM25 scoring to rank results by relevance.

The BM25 algorithm considers:
- **Term frequency** — how often the search term appears in a document
- **Inverse document frequency** — how rare the term is across all documents
- **Document length normalization** — shorter documents with the term rank higher

You can tune BM25 parameters under **Settings → Search** in the CP if needed, but the defaults work well for most sites.

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

## Sizing Guidance

Each indexed element produces multiple rows in the database — typically 50–100+ term rows per element depending on content length and the number of searchable fields. A site with 2,700 elements across 3 indices can have ~200,000 document rows and ~190,000 term rows — this is completely normal and performs well on standard MySQL servers.

| Index Size (elements) | Approximate DB Rows | MySQL Performance |
|----------------------|--------------------|--------------------|
| Up to 5,000 | ~500k rows | Excellent — no tuning needed |
| 5,000–50,000 | 500k–5M rows | Good — standard shared hosting handles this fine |
| 50,000–100,000 | 5M–10M rows | Adequate — dedicated DB recommended, consider Redis if queries slow down |
| 100,000+ | 10M+ rows | Consider Redis or an external backend |

These numbers assume default BM25 settings and typical content (entries with title, body, and a few custom fields). Indices with many searchable fields or very long content will have more rows per element.

## Limitations

- Only available when Craft uses MySQL as its database
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
- For indices above ~100,000 elements, consider Redis or an external backend for faster query response
