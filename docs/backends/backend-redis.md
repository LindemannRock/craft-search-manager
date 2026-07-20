# Redis Backend

Move your search index into memory for faster query response: the Redis backend stores search data in-memory, which is ideal for multi-server deployments or once an index passes ~50,000 elements and MySQL query times start to climb.

## What you'll use it for

- Reuse Redis you already run for Craft's cache or sessions
- Share search data across a multi-server setup
- Get faster query response than MySQL once an index exceeds ~50,000 elements
- In-memory speed with optional persistence

## Create your first Redis backend

You'll need the PHP Redis extension (`ext-redis`) and a Redis server — you can reuse Craft's existing Redis connection or point at a dedicated one.

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Craft Redis") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **Redis**.
4. Fill in the Redis fields, or leave them blank to reuse Craft's connection:
   - **Host**, **Port**, **Password**, **Database** — leave all four empty and Search Manager reuses Craft's Redis cache settings automatically, storing its data on Craft's Redis database number + 1 (isolated from Craft's cache so a cache flush doesn't wipe your search index). The edit screen shows the effective database it will use — e.g. `DB 6 (5 + 1)` if Craft uses DB 5.
   - Fill in **Host** (and optionally **Port**, **Password**, **Database**) to point at a dedicated Redis connection instead. See [Dedicated Redis connection](#option-2-dedicated-redis-connection) below for the equivalent config-file setup.

   Each field supports environment-variable autosuggest — start typing `$` to pick from your defined environment variables instead of pasting a raw value.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **No** for Redis — see [Built-in vs external backends](backends.md#built-in-vs-external-backends)). Use **Refresh Connection** to retest anytime.

For environment-specific setups — secrets pulled from environment variables, a dedicated connection per environment — define the backend in `config/search-manager.php` instead.

## Requirements

- PHP Redis extension (`ext-redis`)
- Redis server (can reuse Craft's existing Redis connection)

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (front-end `Entry::find()->search()` template queries only — Control Panel search always uses Craft's native search)
- In-memory speed with optional persistence

## Configuration

### Option 1: Reuse Craft's Redis connection

If Craft already uses Redis for caching, you can reuse that connection with no additional config:

```php
'backends' => [
    'craft-redis' => [
        'name' => 'Craft Redis',
        'backendType' => 'redis',
        'enabled' => true,
        'settings' => [],
    ],
],
```

When the Redis database setting is empty, Search Manager automatically stores data in a separate database (Craft's Redis database number + 1) when Craft uses Redis. This applies whether Search Manager is reusing Craft's Redis connection or using an explicitly configured Redis host.

The backend edit screen and Redis-backed index sidebars show the effective database Search Manager will use. For example, if Craft uses DB 5 and no Redis database is set explicitly, Search Manager displays `DB 6 (5 + 1)`.

### Option 2: Dedicated Redis connection

For production, a dedicated Redis connection gives you full control:

```php
'backends' => [
    'dedicated-redis' => [
        'name' => 'Dedicated Redis',
        'backendType' => 'redis',
        'enabled' => true,
        'settings' => [
            'host' => App::env('REDIS_HOST') ?: 'redis',
            'port' => App::env('REDIS_PORT') ?: 6379,
            'password' => App::env('REDIS_PASSWORD'),
            'database' => App::env('REDIS_SEARCH_DATABASE') ?: 1,
        ],
    ],
],
```

Then define the referenced environment variables in your `.env` file:

```bash
# .env
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_SEARCH_DATABASE=1
```

When you explicitly set the `database` value, that exact number is used — no automatic offset.

## Database isolation

The automatic database offset (+1) applies whenever no explicit `database` value is set and Craft's cache is Redis-backed — both when reusing Craft's Redis connection and when a dedicated `host` is configured. Setting an explicit `database` always disables the offset.

If your hosting platform uses `FLUSHALL` instead of `FLUSHDB` when clearing cache, the automatic isolation won't help — consider setting an explicit database number or using a different backend.

On managed platforms where Redis may also hold sessions, queue data, or static page-cache data, prefer an explicit `database` value after confirming which DB number is safe for custom application data.

Test by clearing Craft's cache and checking that your search index is still intact.

## Docker / DDEV environments

In Docker containers, use the service hostname instead of `127.0.0.1`:

For DDEV:

```text
REDIS_HOST=redis
```

For Docker Compose (use your service name):

```text
REDIS_HOST=redis-server
```

`127.0.0.1` refers to localhost inside the container, not your host machine. If you see `Connection refused` errors, this is almost always the issue.

## Memory sizing

Redis stores all index data in memory. As a rough guide, expect ~1–2 KB per indexed element (including term data). A 10,000-element index uses approximately 10–20 MB of RAM; a 100,000-element index uses approximately 100–200 MB. Indices with many searchable fields or very long content will use more.

Check actual usage with `redis-cli INFO memory` after a rebuild.

## Limitations

- Requires PHP Redis extension
- Data is stored in memory — size your Redis server based on index size (see [Memory sizing](#memory-sizing) above)
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
