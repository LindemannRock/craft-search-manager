# Utilities

Rebuild a broken index, clear out orphaned storage after switching backends, flush a stale cache, or reset analytics data — all from one page, no console access required.

**CP:** Utilities → Search Manager

The Utilities page gathers Search Manager's maintenance tools in one place: index management, storage cleanup, cache clearing, and analytics data management.

## What you'll use it for

- Rebuild every index after a bulk content change or a schema update
- Clear orphaned storage after moving an index from Redis to MySQL (or the reverse)
- Clear a stuck or stale cache without waiting for it to expire
- Wipe analytics data before a site launch, after testing, or for a GDPR deletion request
- Grab the bundled Postman collection to test the API outside Craft

## Overview cards

The top of the page shows three status cards:

- **Search Indices** — Total configured indices and document count
- **Backend Distribution** — How many indices use each backend type, plus the default backend
- **Cache Status** — Active cache types (search, autocomplete, device detection) with counts

## Index management

### Rebuild All Indices

Queues a rebuild of every configured index. Each index is cleared and re-indexed from scratch. This runs via Craft's queue, so it won't block the CP.

### Clear storage by type

Clear ALL search index data from a specific local storage type. The dropdown only shows a storage type when Search Manager can safely reach it and either an effective configured backend uses it or clearable data remains there.

| Storage Type | What It Clears |
|---|---|
| **Database** (MySQL/PostgreSQL) | All rows from Search Manager index tables (`searchmanager_search_documents`, `searchmanager_search_terms`, `searchmanager_search_titles`, `searchmanager_search_ngrams`, etc.) |
| **Redis** | All Search Manager keys (`sm:idx:*`) from the configured Redis database |
| **File** | All index files from the default runtime path and configured File backend storage paths |

Each visible option shows its final storage name and current row, key, or file count when the page loads. A configured local backend remains visible when it is empty, including a disabled backend that still establishes storage ownership. An unconfigured type also remains visible when orphaned data can be cleared. Available but unconfigured and empty types are hidden, as are unavailable types that cannot be cleared safely.

Those displayed counts and the later clear use the same target inventory. Database storage is removed from all eight Search Manager index tables in one transaction. Redis statistics, clear operations, orphan scans, and backend runtime storage use the same normalized native connection authority. Redis targets are deduplicated from equivalent effective configuration, with a supported Craft-derived Search Manager database retained as the fallback when no Redis backend exists. The presentation shows a safe endpoint and database label; credentials, SSL context, provider exception text, and internal target identity never enter the page or response. File maintenance covers both the default runtime path and valid configured File paths.

After a target clears successfully, Search Manager resets the stored document counts for the indices mapped to it and clears their search-results and autocomplete caches. A failed Redis connect, authentication, database selection, or scan performs no delete or reconciliation work for that target. For storage types with several targets, safe failures do not prevent later targets from being attempted; an irreversible partial result stops the operation and leaves later targets unattempted. Every temporary Redis maintenance client is closed after its operation.

> [!NOTE]
> Clear Storage only covers local storage types: Database, Redis, and File. External search backends run on shared provider accounts, and a matching index-name prefix does not prove Search Manager ownership. For Algolia, Meilisearch, and Typesense, rebuild or clear configured indices individually; delete old or renamed provider indices in the provider dashboard.

> [!WARNING]
> This deletes all search index data stored in the selected storage type across **all indices** using that storage — including orphaned data from indices that no longer exist. You'll need to rebuild affected indices afterwards.

**When to use this:**

- **Switching backends** — You moved from Redis to MySQL. The old Redis keys are orphaned. Select "Redis" and clear them.
- **Troubleshooting** — An index rebuild fails or produces stale results. Clear the storage type and rebuild fresh.
- **Resetting a storage driver** — You want to wipe one storage driver completely before rebuilding the affected indices.

The database option automatically detects whether you're running MySQL or PostgreSQL and labels itself accordingly. If no local storage type is currently eligible, the section shows a safe empty state with no clear action.

### Orphaned handles

If you only need to remove data for handles that no longer exist, use the console command instead of clearing an entire storage type:

```bash
php craft search-manager/maintenance/purge-orphaned-storage --dry-run
```

This is useful after removing a config-file index or renaming an index handle. The command only considers stored handles that carry the current environment's `indexPrefix`, and it compares them against both database-backed and config-file indices before deleting anything.

Every planned handle is attempted. The command reports successful and failed handles separately and exits non-zero if any handle fails, while dry runs, cancellation, and a plan with no candidates remain successful no-op outcomes.

## Cache management

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

## Analytics data management

Permanently deletes search, query-rule, and promotion analytics for sites the current user can edit. The displayed count and the deletion use that same site scope. Administrators retain all-site behavior; a user with no editable sites sees a zero count and deletes nothing. This cannot be undone.

Use this when:
- Resetting analytics after testing
- Clearing data before a site launch
- GDPR data deletion requests

## Developer resources

Download the bundled Postman collection and environment from the Utilities page, or from **Settings → Test**. The ZIP contains the collection, environment template, and README so developers can test the Search Manager API outside Craft. See [Testing tools](../resources/testing-tools.md) for the full Settings → Test workflow, including live search, autocomplete, promotions, query rules, debug metadata, and backend diagnostics.

## Permissions

Each section requires specific permissions:

| Section | Permission |
|---|---|
| Rebuild indices, clear storage | `searchManager:rebuildIndices` |
| Clear caches | `searchManager:clearCache` |
| Clear analytics | `searchManager:clearAnalytics` |
| Developer Resources | `searchManager:manageSettings` |

Sections are hidden from users who don't have the required permission. See [Permissions](../developers/permissions.md) for the full permission tree.

## Console alternatives

Every action above has a console equivalent, for scripts and CI:

```bash
# Rebuild all indices
php craft search-manager/index/rebuild

# Rebuild a specific index
php craft search-manager/index/rebuild --handle=entries-en

# Clear search cache
php craft search-manager/maintenance/clear-storage --type=database

# Preview orphaned storage handles
php craft search-manager/maintenance/purge-orphaned-storage --dry-run
```

See [Console Commands](../developers/console-commands.md) for the full list.
