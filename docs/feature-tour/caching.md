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

Device cache follows the same `cacheStorageMethod` setting as the search and autocomplete caches — files at `@storage/runtime/search-manager/cache/device/` by default, or Redis when `cacheStorageMethod` is `redis`.

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

Good for: multi-server setups, edge networks (Servd, Platform.sh), sites handling 10+ searches per second.

If Redis cache storage is selected but Craft's `cache` component is not Redis-backed, Search Manager logs a cache-component warning and falls back to file-based cache clearing where possible. Configure Redis in `config/app.php`, or switch `cacheStorageMethod` back to `file`.

## Bounding Cache Storage

Search Manager caches each unique search result until its TTL expires or the cache is cleared. On busy sites, storage limits are best handled by the cache layer rather than delaying cache writes inside Search Manager.

> [!NOTE]
> For Redis-backed cache storage, set a Redis `maxmemory` limit with an `allkeys-lfu` or `allkeys-lru` eviction policy so frequently-used entries stay hot while long-tail queries are evicted under memory pressure. File cache storage is bounded by `cacheDuration` TTL and normal cache clearing.

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

Search Manager registers a cache option in Craft's Clear Caches utility. That entry clears the **search-results cache only** — to also clear the autocomplete and device-detection caches, use Search Manager > Settings > Cache (saving that page clears all three) or the CLI `clear-storage` command. Clearing is always safe — caches auto-regenerate on the next search.

## Cache Warming

After an index rebuild, popular queries can be pre-cached automatically:

```php
'enableCacheWarming' => true,
'cacheWarmingQueryCount' => 50,  // Number of queries to warm (validated 1–200; the CP dropdown offers 10–200)
```

Cache warming:
- Pulls popular queries from search analytics data
- Warms both search results and autocomplete caches
- Autocomplete warming includes common prefixes (2–5 characters) for each query
- Runs as a background queue job (doesn't block the rebuild)
- Requires analytics to be enabled for the index

## Performance Impact

Typical response times for a MySQL/PostgreSQL backend with an index of 1,000–10,000 elements on a standard VPS (2 CPU, 4 GB RAM):

| Scenario | Without Cache | With Cache |
|----------|--------------|------------|
| Search response | 50–200ms | 5–10ms |
| Autocomplete | 20–50ms | 2–5ms |
| API costs (external backends) | Per-query billing | Reduced by cache hit rate |

Uncached times increase with index size — a 50,000-element index may take 300–500ms per query on MySQL. Caching eliminates this for repeated queries.

Sites where users frequently search the same terms (e.g., product catalogs, documentation) typically see cache hit rates of 70–90%. Sites with highly unique queries (e.g., support ticket search) see lower hit rates; use a shorter `cacheDuration` or Redis memory eviction if you need tighter storage bounds.

> [!TIP]
> Start with file-based caching. Switch to Redis only if you run multiple servers or need shared cache across load-balanced instances.
