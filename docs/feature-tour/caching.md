# Caching

Search Manager includes multi-layer caching to reduce backend load and improve response times. Each cache layer can be configured independently.

## Cache Layers

### Search Results Cache

Caches complete search results so repeated queries don't hit the backend:

```php
'enableCache' => true,
'cacheDuration' => 3600,        // 1 hour
'cacheStorageMethod' => 'file', // 'file' or 'redis'
```

### Autocomplete Cache

Separate cache for autocomplete suggestions with a shorter TTL:

```php
'enableAutocompleteCache' => true,
'autocompleteCacheDuration' => 300,  // 5 minutes
```

Autocomplete is cached per query prefix, index, and language. Uses the same storage method as the search cache.

### Device Detection Cache

Caches parsed user-agent strings to avoid re-parsing:

```php
'cacheDeviceDetection' => true,
'deviceDetectionCacheDuration' => 3600,  // 1 hour
```

Device cache is always stored as files at `@storage/runtime/search-manager/cache/device/`.

## Storage Options

### File Storage (Default)

Cache files are stored in:
- Search: `@storage/runtime/search-manager/cache/search/`
- Autocomplete: `@storage/runtime/search-manager/cache/autocomplete/`
- Device: `@storage/runtime/search-manager/cache/device/`

Good for: single-server setups, shared hosting, development.

### Redis Storage

Uses Craft's Redis cache connection:

```php
'cacheStorageMethod' => 'redis',
```

Good for: multi-server setups, edge networks (Servd, Platform.sh), high-traffic sites.

## Popular Queries Only

By default, every unique query is cached. For sites with many unique searches, you can limit caching to frequently-searched queries:

```php
'cachePopularQueriesOnly' => false,
'popularQueryThreshold' => 5,  // Cache after 5 searches
```

When enabled, a query must be searched at least N times before it gets cached. This saves storage space while still caching the queries that matter most.

```text
Query: "craft cms"
Search #1–4: Not cached (below threshold)
Search #5: Cached!
Search #6+: Served from cache
```

## Cache Invalidation

### Clear on Save

When `clearCacheOnSave` is enabled (default), relevant caches are cleared when elements are saved. Cache is cleared per-index, not globally.

```php
'clearCacheOnSave' => true,
```

For high-traffic sites where content changes are rare, you may want to disable this and rely on TTL expiry:

```php
'clearCacheOnSave' => false,
```

### Manual Clearing

- **CP**: Search Manager > Settings > Cache, or Craft's Clear Caches utility
- **Per-index**: Clear cache for a specific index without affecting others
- **CLI**:

```bash title="PHP"
php craft search-manager/maintenance/clear-storage --type=database
```

```bash title="DDEV"
ddev craft search-manager/maintenance/clear-storage --type=database
```

Valid types: `database`, `redis`, `file`.

### Craft Integration

Search Manager registers its caches in Craft's Clear Caches utility. Clearing from there is safe — caches auto-regenerate on the next search.

## Cache Warming

After an index rebuild, popular queries can be pre-cached automatically:

```php
'enableCacheWarming' => true,
'cacheWarmingQueryCount' => 50,  // Number of queries to warm (10–200)
```

Cache warming:
- Pulls popular queries from search analytics data
- Warms both search results and autocomplete caches
- Autocomplete warming includes common prefixes (2–5 characters) for each query
- Runs as a background queue job (doesn't block the rebuild)
- Requires analytics to be enabled for the index

## Performance Impact

| Scenario | Without Cache | With Cache |
|----------|--------------|------------|
| Search response | 50–200ms | 5–10ms |
| Autocomplete | 20–50ms | 2–5ms |
| API costs (external backends) | Per-query billing | Reduced by cache hit rate |

Cache hit rates of 70–90% are common for sites with recurring search patterns.

> [!TIP]
> Start with file-based caching. Switch to Redis only if you run multiple servers or need shared cache across load-balanced instances.
