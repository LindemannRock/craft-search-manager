# Logging

Search Manager writes structured, per-day log files through the bundled [Logging Library](https://github.com/LindemannRock/craft-logging-library).

> [!NOTE]
> Logging Library is required by Composer. Install or activate it in Craft to enable log viewing.

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

Use this page when you need to check what Search Manager did: indexing and rebuild runs, pending-sync drains, backend connections and searches, cache activity, analytics cleanup, and debug-level diagnostics.

## Log levels

Four log levels are available, in order of verbosity:

| Level | What is logged |
|-------|----------------|
| `error` | Critical errors only |
| `warning` | Errors and warnings |
| `info` | General informational messages |
| `debug` | Detailed debugging, including timing and step-by-step diagnostics |

Each level includes all messages from the levels above it. `error` is the least verbose; `debug` is the most.

> [!WARNING]
> Debug level requires Craft's `devMode` to be enabled. If `logLevel` is set to `debug` while `devMode` is disabled, Search Manager falls back to `info` and records a warning. Use `debug` for local development or short diagnostic sessions, because it can create much more log output.

## Configuration

```php
// config/search-manager.php
return [
    'logLevel' => 'error', // 'error', 'warning', 'info', or 'debug'
];
```

For environment-specific logging, keep production quieter and enable debug only where Craft's `devMode` is enabled:

```php
// config/search-manager.php
return [
    '*' => [
        'logLevel' => 'error',
    ],
    'production' => [
        'logLevel' => 'error',
    ],
    'staging' => [
        'logLevel' => 'warning',
    ],
    'dev' => [
        'logLevel' => 'debug',
    ],
];
```

## Log file location

```text
storage/logs/search-manager-YYYY-MM-DD.log
```

Log files are rotated daily. Retention is managed by Logging Library, with a 30-day default.

Logs are written as structured JSON with context data alongside each message, so they can be searched in the Control Panel or ingested by external logging tools.

## Viewing logs in the CP

The **Search Manager → Logs** screen reads, filters, and downloads these log files without leaving the Control Panel.

From there you can:

- Browse log entries for the current and recent days
- Filter by log level
- Search log messages and context
- View file sizes and entry counts
- Download individual log files for external analysis

The `searchManager:viewSystemLogs` permission is required to access the Logs section. The `searchManager:downloadSystemLogs` sub-permission is required to download log files. In the Craft permissions UI, both are nested under the `searchManager:viewLogs` parent group.

## What gets logged

The level of detail depends on your configured `logLevel`.

### Error (`error`)

- Backend failures — connection tests, searches, autocomplete, browse, and batch queries that fail (per backend, including Algolia/Meilisearch/Typesense API errors)
- Indexing failures — documents a backend rejected, rebuild errors, and split-section orphan cleanup that could not run
- API key validation failures and analytics export failures

### Warning (`warning`)

- Backend fallbacks — a configured backend that is unavailable or a default backend that is missing or disabled
- Rejected operations — attempts to save or delete config-file-defined indices, widgets, or styles from the CP
- Record-size and capability warnings — backends that do not support a requested operation
- Debug fallback when `logLevel` is set to `debug` without `devMode`

### Info (`info`)

- Lifecycle operations — indices rebuilt or cleared, caches cleared, analytics cleanup runs, pending syncs purged or retried
- Configuration changes — backends, indices, widgets, API keys saved or deleted; default backend/widget auto-assignment

### Debug (`debug`)

- Search execution details — parsed queries, applied promotions and score boosts, cache hits and misses
- Indexing and sync diagnostics — per-step batch sync and rebuild progress, analytics tracking decisions

## Developer usage

Most sites only need the configuration and CP viewer above. Custom modules or integrations can write to the same Search Manager log when they need related diagnostics:

```php
use lindemannrock\searchmanager\SearchManager;

SearchManager::getInstance()->logError('Operation failed', [
    'context' => 'import',
    'error' => $e->getMessage(),
]);
```

## Permissions

| Action | Permission |
|--------|------------|
| Access the Logs section in the CP | `searchManager:viewSystemLogs` |
| Download log files | `searchManager:downloadSystemLogs` |
| Logs group (parent, Craft permissions UI only) | `searchManager:viewLogs` |

See [Permissions](../developers/permissions.md) for the full permission hierarchy.
