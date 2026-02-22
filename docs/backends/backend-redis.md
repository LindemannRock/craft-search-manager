# Redis Backend

The Redis backend stores search data in-memory for fast access. It's ideal for high-traffic sites or when you already have Redis in your stack.

## When to Use Redis

- You need fast in-memory search with persistence
- You already use Redis for Craft's cache
- You're running a multi-server setup and need shared search data
- You want better performance than MySQL for large indices

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

## Limitations

- Requires PHP Redis extension
- Data is stored in memory — ensure your Redis server has enough RAM
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)
