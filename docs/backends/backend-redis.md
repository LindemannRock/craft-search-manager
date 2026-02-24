# Redis Backend

The Redis backend stores search data in-memory for fast access. It's ideal for multi-server deployments or when your indices exceed ~50,000 elements and MySQL query times start to increase.

## When to Use Redis

- You already use Redis for Craft's cache or sessions
- You're running a multi-server setup and need shared search data
- Your indices exceed ~50,000 elements and you need faster query response than MySQL
- You want in-memory speed with optional persistence

## Requirements

- PHP Redis extension (`ext-redis`)
- Redis server (can reuse Craft's existing Redis connection)

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 5 languages
- Localized boolean operators
- Native search replacement (CP search + `Entry::find()->search()`)
- In-memory speed with optional persistence

## Configuration

### Option 1: Reuse Craft's Redis Connection

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

When settings are empty, Search Manager automatically uses Craft's Redis connection but stores data in a separate database (Craft's database number + 1). This prevents search data from being wiped when Craft's cache is cleared.

### Option 2: Dedicated Redis Connection

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

```bash
# .env
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_SEARCH_DATABASE=1
```

When you explicitly set the `database` value, that exact number is used — no automatic offset.

## Database Isolation

The automatic database offset (+1) only applies when:
- Using Craft's Redis cache fallback (no explicit `host` configured), AND
- No explicit `database` value is set

If your hosting platform uses `FLUSHALL` instead of `FLUSHDB` when clearing cache, the automatic isolation won't help — consider setting an explicit database number or using a different backend.

Test by clearing Craft's cache and checking that your search index is still intact.

## Docker / DDEV Environments

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

## Memory Sizing

Redis stores all index data in memory. As a rough guide, expect ~1–2 KB per indexed element (including term data). A 10,000-element index uses approximately 10–20 MB of RAM; a 100,000-element index uses approximately 100–200 MB. Indices with many searchable fields or very long content will use more.

Check actual usage with `redis-cli INFO memory` after a rebuild.

## Limitations

- Requires PHP Redis extension
- Data is stored in memory — size your Redis server based on index size (see above)
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
