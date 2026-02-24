# Utilities

The Utilities page provides index management, storage cleanup, cache clearing, and analytics data management. Access it from **Utilities > Search Manager** in the Craft CP.

## Overview Cards

The top of the page shows three status cards:

- **Search Indices** — Total configured indices and document count
- **Backend Distribution** — How many indices use each backend type, plus the default backend
- **Cache Status** — Active cache types (search, autocomplete, device detection) with counts

## Index Management

### Rebuild All Indices

Queues a rebuild of every configured index. Each index is cleared and re-indexed from scratch. This runs via Craft's queue, so it won't block the CP.

### Clear Storage by Type

Clear ALL search index data from a specific storage type. The dropdown shows three options:

| Storage Type | What It Clears |
|---|---|
| **Database** (MySQL/PostgreSQL) | All rows from search tables (`search_documents`, `search_terms`, `search_titles`, `search_ngrams`, etc.) |
| **Redis** | All Search Manager keys (`sm:idx:*`) from the configured Redis database |
| **File** | All index files from `@storage/runtime/search-manager/indices/` |

Each option shows a live count (rows, keys, or files) loaded via AJAX.

> [!WARNING]
> This deletes all search index data stored in the selected storage type across **all indices** using that storage — including orphaned data from indices that no longer exist. You'll need to rebuild affected indices afterwards.

**When to use this:**

- **Switching backends** — You moved from Redis to MySQL. The old Redis keys are orphaned. Select "Redis" and clear them.
- **Troubleshooting** — An index rebuild fails or produces stale results. Clear the storage type and rebuild fresh.
- **Removing deleted indices** — You deleted an index in the CP, but its data remains in storage. This cleans it up.

The "Database" option automatically detects whether you're running MySQL or PostgreSQL and labels accordingly.

## Cache Management

Clear temporary cached data. Only shows cache types that are currently enabled in settings.

| Button | What It Clears |
|---|---|
| **Clear Search Cache** | Cached search results |
| **Clear Autocomplete Cache** | Cached autocomplete suggestions |
| **Clear Device Cache** | Cached device detection results (user-agent parsing) |
| **Clear All Caches** | All of the above at once |

Caches auto-regenerate on the next request, so clearing is always safe.

Cache storage depends on your `cacheStorageMethod` setting — either file-based (default) or Redis. File counts are shown next to each button when using file-based caching.

See [Caching](caching.md) for configuration details.

## Analytics Data Management

Permanently deletes all search analytics tracking data (queries, clicks, performance metrics). This cannot be undone.

Use this when:
- Resetting analytics after testing
- Clearing data before a site launch
- GDPR data deletion requests

## Permissions

Each section requires specific permissions:

| Section | Permission |
|---|---|
| Rebuild indices, clear storage | `searchManager:rebuildIndices` |
| Clear caches | `searchManager:clearCache` |
| Clear analytics | `searchManager:clearAnalytics` |

Sections are hidden from users who don't have the required permission. See [Permissions](../developers/permissions.md) for the full permission tree.

## Console Alternatives

All utility actions are also available as console commands:

```bash
# Rebuild all indices
php craft search-manager/index/rebuild

# Rebuild a specific index
php craft search-manager/index/rebuild entries-en

# Clear search cache
php craft search-manager/cache/clear
```

See [Console Commands](../developers/console-commands.md) for the full list.
