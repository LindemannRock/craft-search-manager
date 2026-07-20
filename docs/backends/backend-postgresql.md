# PostgreSQL Backend

If your Craft installation runs on PostgreSQL, this is the natural choice: the PostgreSQL backend uses your Craft database for search storage, just like the MySQL backend does for MySQL sites, so there's no external service to stand up.

## What you'll use it for

- Zero-configuration search on the PostgreSQL database you already run
- Sites with up to ~50,000 elements per index
- Native search replacement for front-end `Entry::find()->search()` template queries
- All the built-in search features (BM25 ranking, operators, fuzzy matching) without a separate search service

## Create your first PostgreSQL backend

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Craft PostgreSQL") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **Craft Database (PostgreSQL)**. If your Craft install uses MySQL instead, the CP marks this option "Not available" — use the [MySQL backend](backend-mysql.md) instead.
4. There's nothing else to fill in. PostgreSQL backends use your existing Craft database connection automatically — no settings fields appear.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **No** for PostgreSQL — see [Built-in vs external backends](backends.md#built-in-vs-external-backends)). Use **Refresh Connection** to retest anytime.

For environment-specific setups, define the backend in `config/search-manager.php` instead — see [Configuration](#configuration) below.

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (front-end `Entry::find()->search()` template queries only — Control Panel search always uses Craft's native search)
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

## Sizing guidance

Performance characteristics are similar to the MySQL backend. See [MySQL Backend — Sizing guidance](backend-mysql.md#sizing-guidance) for element count thresholds and row estimates. For indices above ~100,000 elements, consider Redis or an external backend.

## Limitations

- Only available when Craft uses PostgreSQL as its database
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
- For indices above ~100,000 elements, consider Redis or an external backend for faster query response
