# MySQL Backend

Get full-text search running with nothing beyond what you already have: the MySQL backend stores search data directly in your Craft database, so there's no external service to stand up and no additional infrastructure to manage.

## What you'll use it for

- Zero-configuration search on the Craft database you already run
- Evaluating Search Manager for the first time without adding infrastructure
- Sites with up to ~50,000 elements per index — see [Sizing guidance](#sizing-guidance) below
- Native search replacement for front-end `Entry::find()->search()` template queries

## Create your first MySQL backend

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Craft MySQL") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **Craft Database (MySQL)**. If your Craft install uses PostgreSQL instead, the CP marks this option "Not available" — use the [PostgreSQL backend](backend-postgresql.md) instead.
4. There's nothing else to fill in. MySQL backends use your existing Craft database connection automatically — no settings fields appear.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **No** for MySQL — see [Built-in vs external backends](backends.md#built-in-vs-external-backends)). Use **Refresh Connection** to retest anytime.

For environment-specific setups, define the backend in `config/search-manager.php` instead — see [Configuration](#configuration) below.

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (front-end `Entry::find()->search()` template queries only — Control Panel search always uses Craft's native search)
- No external dependencies

## How it works

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

## Sizing guidance

Each indexed element produces multiple rows in the database — typically 50–100+ term rows per element depending on content length and the number of searchable fields. A site with 2,700 elements across 3 indices can have ~200,000 document rows and ~190,000 term rows — this is completely normal and performs well on standard MySQL servers.

| Index size (elements) | Approximate DB rows | MySQL performance |
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
