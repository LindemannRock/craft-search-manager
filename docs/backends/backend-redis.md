# Redis Backend

Move your search index into memory for faster query response: the Redis backend stores search data in-memory, which is ideal for multi-server deployments or once an index passes ~50,000 elements and MySQL query times start to climb.

## What you'll use it for

- Derive a compatible Redis endpoint from Craft's Redis cache configuration
- Share search data across a multi-server setup
- Get faster query response than MySQL once an index exceeds ~50,000 elements
- In-memory speed with optional persistence

## Create your first Redis backend

You'll need the PHP Redis extension (`ext-redis`) and a Redis server. Search Manager can derive a compatible endpoint from Craft's Redis cache configuration or use its own four-field settings.

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Craft Redis") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **Redis**.
4. Fill in the Redis fields, or leave them blank to derive compatible settings from Craft:
   - **Host**, **Port**, **Password**, **Database** are the complete Search Manager configuration surface. Leave all four empty to derive the active Craft Redis cache endpoint. Search Manager opens its own non-persistent native client and selects Craft's database number + 1. The edit screen shows the effective database — for example, `DB 6 (5 + 1)` when Craft uses DB 5.
   - Fill in **Host** and optionally **Port**, **Password**, and **Database** to use Search Manager settings instead. See [Search Manager Redis settings](#option-2-search-manager-redis-settings) for the equivalent config-file setup.

   Each field supports environment-variable autosuggest — start typing `$` to pick from your defined environment variables instead of pasting a raw value.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **No** for Redis — see [Built-in vs external backends](backends.md#built-in-vs-external-backends)). Use **Refresh Connection** to retest anytime.

For environment-specific setups—such as Search Manager settings sourced from different environment variables—define the backend in `config/search-manager.php` instead.

## Requirements

- PHP Redis extension (`ext-redis`)
- Redis server (Search Manager can derive supported settings from Craft's Redis cache configuration)

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (front-end `Entry::find()->search()` template queries only — Control Panel search always uses Craft's native search)
- In-memory speed with optional persistence

## Configuration

### Option 1: Derive Craft's Redis cache configuration

If Craft uses the standard Yii Redis connection for caching, leave the Search Manager settings empty:

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

Search Manager reads the supported connection properties without opening, selecting, closing, or reusing Craft's Yii connection. It then creates an independently owned native phpredis client. Supported derived connections preserve representable TCP or TLS transport, Unix sockets, password or ACL authentication, SSL stream context, connection timeout, and read timeout.

If an active Craft option cannot be represented exactly—such as a different Redis connection implementation, conflicting transport/context settings, incomplete ACL credentials, or an invalid timeout—Search Manager reports `unsupported-configuration`. It does not downgrade TLS, discard authentication, or guess at another endpoint.

When the Redis database setting is empty, Search Manager selects Craft's normalized database number + 1. This also applies to an explicit Search Manager host when Craft uses Redis; only the database number is derived in that case, not Craft's host or credentials.

The backend edit screen and Redis-backed index sidebars show the effective database Search Manager will use. For example, if Craft uses DB 5 and no Redis database is set explicitly, Search Manager displays `DB 6 (5 + 1)`.

### Option 2: Search Manager Redis settings

Use the unchanged four-field Search Manager surface when you want to provide the endpoint explicitly:

```php
'backends' => [
    'search-redis' => [
        'name' => 'Search Redis',
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

## Database selection

The automatic database offset (+1) applies whenever no explicit `database` value is set and Craft's cache is Redis-backed—both for fully derived settings and when a Search Manager `host` is configured. Setting an explicit `database` always disables the offset. If Craft's Redis database is `null`, Search Manager treats Craft as DB 0 and selects DB 1.

The selected number is a routing policy, not proof that the database is reserved or dedicated to Search Manager. Confirm the database with whoever operates Redis, especially when sessions, queues, page caches, or other applications share the service.

If your hosting platform uses `FLUSHALL` instead of `FLUSHDB` when clearing cache, the automatic isolation won't help — consider setting an explicit database number or using a different backend.

On managed platforms where Redis may also hold sessions, queue data, or static page-cache data, prefer an explicit `database` value after confirming which DB number is safe for custom application data.

Test cache-clearing behavior in a non-production environment before relying on the separation.

## Safe configuration failures

Environment references must resolve to supported values. Ports accept integers or digits-only strings from `1` to `65535`; databases accept non-negative integers or digits-only strings. An unresolved host, port, password, or database variable is `unsupported-configuration` and never falls back to defaults, Craft credentials, or unauthenticated access. A port or password without a host is also unsupported.

Connection checks use fixed credential-safe classifications: `extension-unavailable`, `not-configured`, `unsupported-configuration`, `connection-failed`, `authentication-failed`, `database-selection-failed`, and `ping-failed`. The backend sidebar and diagnostics retain the effective source, transport, endpoint, selected database, and authentication mode without returning the password, ACL username, SSL context, or provider exception text.

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
