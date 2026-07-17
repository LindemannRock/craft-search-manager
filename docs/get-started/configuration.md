# Configuration

Search Manager can be configured through the Control Panel settings UI or via a config file at `config/search-manager.php`. Settings defined in the config file take precedence and show a lock icon in the CP.

Most settings can be managed from the CP without touching config files. The config file is recommended when you need environment-specific values, version-controlled settings, or backend/index definitions.

## Config File

Copy the sample config file to your project:

```bash
cp vendor/lindemannrock/craft-search-manager/src/config.php config/search-manager.php
```

Or create `config/search-manager.php` manually:

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        'defaultBackendHandle' => 'my-mysql',
        'enableAnalytics' => true,
        'trackingAllowedOrigins' => App::env('SEARCH_MANAGER_TRACKING_ALLOWED_ORIGINS') ?: [],
    ],

    'dev' => [
        'logLevel' => 'debug',
        'indexPrefix' => 'local_',
    ],

    'production' => [
        'logLevel' => 'error',
        'indexPrefix' => 'prod_',
        'defaultBackendHandle' => 'production-algolia',
    ],
];
```

The `*` key applies to all environments. Environment-specific keys (`dev`, `staging`, `production`) override the defaults for that environment.

## Settings Reference

Settings are grouped by area. All settings can be set in the config file or managed via the CP (unless noted otherwise).

### General
**CP:** Settings → General

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `pluginName` | `string` | `'Search Manager'` | Custom display name in the CP sidebar |
| `defaultBackendHandle` | `?string` | `null` | Handle of the default backend (must match a key in `backends`) |
| `defaultWidgetHandle` | `?string` | `null` | Handle of the default widget configuration |
| `requireApiKey` | `bool` | `false` | Require an API key on the public search/autocomplete endpoints (CP: Settings → General → API Access) — see [API Keys](../feature-tour/api-keys.md) |
| `logLevel` | `string` | `'error'` | Log level: `debug`, `info`, `warning`, `error` |

### Interface
**CP:** Settings → Interface

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `itemsPerPage` | `int` | `100` | Items per page in CP listings |

### Indexing
**CP:** Settings → Indexing

These settings control how content gets indexed.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `autoIndex` | `bool` | `true` | Automatically queue elements for indexing when saved and for removal when deleted. Changes apply to the next save/delete event |
| `batchSize` | `int` | `100` | Elements per batch during rebuild. Lower to 25–50 on memory-constrained hosting; increase to 250–500 for faster rebuilds on dedicated servers. See [Troubleshooting](../resources/troubleshooting.md#indexing-is-slow) for tuning tips |
| `lastIndexedDebounceSeconds` | `int` | `60` | Minimum seconds between automatic `lastIndexed` metadata updates during save/delete syncs. Set to `0` to update after every successful auto-sync |
| `syncBatchSize` | `int` | `200` | Pending save/delete sync rows processed by each batch sync job |
| `batchFlushInterval` | `int` | `5` | Seconds to wait before draining pending sync rows. Increase during bulk imports to coalesce more writes |
| `pendingMaxAge` | `int` | `3600` | Seconds to retain abandoned pending sync rows before cleanup |
| `batchMaxAttempts` | `int` | `5` | Failed processing attempts before a pending sync row is abandoned |
| `replaceNativeSearch` | `bool` | `false` | Enhance front-end template `.search()` queries with Search Manager coverage |
| `indexPrefix` | `?string` | `null` | Prefix for index names (useful for multi-environment setups). Use only letters, numbers, underscores, and hyphens |

> [!NOTE]
> Search Manager registers its save/delete listeners at plugin bootstrap, then checks the current `autoIndex` value each time an element event fires. Turning `autoIndex` off stops all automatic Search Manager element content-sync, including saves, deletes, status changes, and the native-search adapter path used when `replaceNativeSearch` is enabled. While it is off, element changes reach search only after a manual rebuild. Saving or editing an index config still queues its automatic rebuild.

> [!NOTE]
> When `replaceNativeSearch` is enabled, front-end template `.search()` queries can use Search Manager when a full-coverage index exists for the element type and site scope. Control Panel searches always stay on Craft's native search. This only works with MySQL, PostgreSQL, Redis, and File backends.

> [!NOTE]
> The `indexPrefix` setting is especially useful when sharing an Algolia or Meilisearch account across environments. See [Indices](../feature-tour/indices.md) for details.

### Search Algorithm
**CP:** Settings → Search

These settings tune the BM25 ranking algorithm and fuzzy matching behavior. The defaults work well for most sites — only adjust these if you understand information retrieval scoring.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `bm25K1` | `float` | `1.5` | Term frequency saturation. Higher = more weight on term frequency |
| `bm25B` | `float` | `0.75` | Document length normalization. 0 = no penalty for long docs, 1 = full penalty |
| `titleBoostFactor` | `float` | `5.0` | Multiplier for matches in the title field |
| `exactMatchBoostFactor` | `float` | `3.0` | Multiplier when normalized query terms appear as an ordered contiguous sequence |
| `phraseBoostFactor` | `float` | `4.0` | Multiplier for exact phrase matches (`"like this"`) |
| `enableFuzzy` | `bool` | `true` | Engine-wide fuzzy matching switch — applies to both search results and autocomplete suggestions |
| `ngramSizes` | `string` | `'2,3'` | N-gram sizes for fuzzy matching (comma-separated) |
| `similarityThreshold` | `float` | `0.25` | Minimum similarity score for fuzzy matches (0.0–1.0) |
| `maxFuzzyCandidates` | `int` | `100` | Maximum fuzzy candidates to evaluate per query |

### Language
**CP:** Settings → Language

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enableStopWords` | `bool` | `true` | Filter out common words (the, a, is, etc.) |
| `defaultLanguage` | `?string` | `null` | Default language code. `null` = auto-detect from site locale |

Search Manager supports 12 languages: English, German, French, Dutch, Spanish, Arabic, Italian, Portuguese, Japanese, Swedish, Danish, and Norwegian. Language is auto-detected from each site's locale setting. See [Multi-Language](../feature-tour/multi-language.md) for details.

### Autocomplete
**CP:** Settings → Autocomplete

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enableAutocomplete` | `bool` | `true` | Enable autocomplete suggestions |
| `autocompleteMinLength` | `int` | `2` | Minimum query length before suggesting |
| `autocompleteLimit` | `int` | `10` | Maximum suggestions returned |

> [!NOTE]
> Typo tolerance in autocomplete is controlled by the engine-wide `enableFuzzy` setting (Search Algorithm section) — autocomplete and search always share the same fuzzy behavior.

See [Autocomplete](../feature-tour/autocomplete.md) for details.

### Highlighting
**CP:** Settings → Highlighting

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `highlightResultsEnabled` | `bool` | `true` | Enable search term highlighting |
| `highlightTag` | `string` | `'mark'` | HTML tag wrapping highlighted terms (`mark`, `em`, `strong`, `b`, `i`, `span`) |
| `highlightClass` | `?string` | `null` | CSS class added to the highlight tag — space-separated plain class tokens (letters, digits, `-`, `_`) |

### Template Helper Snippets
**CP:** Settings → Snippets

These settings apply to `craft.searchManager.snippets()` and `craft.searchManager.highlight()` template-helper output. Search-result snippets for widgets and API requests are configured per widget or request.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `snippetMaxLength` | `int` | `200` | Characters per context snippet |
| `maxSnippets` | `int` | `3` | Maximum snippets per result |

See [Highlighting](../feature-tour/highlighting.md) and the [Highlighting & Snippets](../template-guides/highlighting-snippets.md) template guide.

### Analytics
**CP:** Settings → Analytics

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enableAnalytics` | `bool` | `true` | Enable search analytics tracking |
| `trackingAllowedOrigins` | `array|string` | `[]` | Config-only. Exact frontend origins allowed to post browser-based `track-search` / `track-click` requests from headless sites. Same-origin tracking does not need to be listed; wildcards are not supported |
| `analyticsRetention` | `int` | `90` | Days to keep analytics data (0 = forever) |

Analytics can also be toggled per-index, so you can track searches on your public indices without tracking internal/admin searches. See [Analytics](../feature-tour/analytics.md).

### Privacy & Geo-Detection
**CP:** Settings → Analytics

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `anonymizeIpAddress` | `bool` | `false` | Subnet masking (replace last octet with 0) |
| `ipHashSalt` | `?string` | `null` | Salt for IP hashing (read from `.env` automatically) |
| `enableGeoDetection` | `bool` | `false` | Enable country/city detection |
| `geoProvider` | `string` | `'ip-api.com'` | Geo provider: `ip-api.com`, `ipapi.co`, `ipinfo.io` |
| `geoApiKey` | `?string` | `null` | API key for paid provider tiers |
| `defaultCountry` | `?string` | `null` | Default country for local dev (when IP is private). Falls back to `SEARCH_MANAGER_DEFAULT_COUNTRY` env var. Requires `defaultCity`; otherwise private/local IP geo fields stay empty |
| `defaultCity` | `?string` | `null` | Default city for local dev. Falls back to `SEARCH_MANAGER_DEFAULT_CITY` env var. Requires `defaultCountry`; otherwise private/local IP geo fields stay empty |

The IP hash salt is typically set via `.env` as `SEARCH_MANAGER_IP_SALT` — the plugin reads it automatically. See [Privacy & Security](../feature-tour/privacy-security.md).

### Caching
**CP:** Settings → Cache

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enableCache` | `bool` | `true` | Enable search results caching |
| `cacheDuration` | `int` | `3600` | Cache TTL in seconds (default: 1 hour) |
| `cacheStorageMethod` | `string` | `'file'` | Storage: `file` or `redis` |
| `clearCacheOnSave` | `bool` | `true` | Clear cache when elements are saved |
| `enableCacheWarming` | `bool` | `true` | Pre-cache popular queries after rebuild |
| `cacheWarmingQueryCount` | `int` | `50` | Number of queries to warm (10–200) |
| `enableAutocompleteCache` | `bool` | `true` | Cache autocomplete suggestions separately |
| `autocompleteCacheDuration` | `int` | `300` | Autocomplete cache TTL (default: 5 min) |

> [!NOTE]
> To bound cache storage on busy sites, configure Redis with a `maxmemory` limit and an `allkeys-lfu` or `allkeys-lru` eviction policy. Redis will keep frequently-used cache entries and evict long-tail entries under memory pressure. File and database cache storage are bounded by `cacheDuration` TTL.

Saving any settings section from the CP clears both the search-results cache and the autocomplete cache. This keeps scoring, language, indexing, and backend-related setting changes from serving stale cached results. Element save/delete cache clearing is still controlled separately by `clearCacheOnSave`.

See [Caching](../feature-tour/caching.md) for cache strategies and recommendations.

### Status Sync
**CP:** Settings → Cache

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `statusSyncInterval` | `int` | `15` | Minutes between status sync checks (0 = disabled) |

Status sync automatically indexes entries that become live (postDate passed) or removes expired entries, without needing a manual save. This runs as a periodic queue job.

### Device Detection
**CP:** Settings → Cache

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `cacheDeviceDetection` | `bool` | `true` | Cache parsed user-agent strings |
| `deviceDetectionCacheDuration` | `int` | `3600` | Device cache TTL in seconds |

## Backends Configuration

Backends are defined as named instances in the config file. Each backend has a unique handle, a type, and type-specific settings:

```php
'backends' => [
    'my-mysql' => [
        'name' => 'MySQL Backend',
        'backendType' => 'mysql',
        'enabled' => true,
        'settings' => [],
    ],
    'production-algolia' => [
        'name' => 'Production Algolia',
        'backendType' => 'algolia',
        'enabled' => true,
        'settings' => [
            'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
            'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
            'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
        ],
    ],
],
```

See [Backends](../backends/backends.md) for backend-specific settings and configuration examples.

## Indices Configuration

Indices define what content gets indexed and how it's transformed:

```php
'indices' => [
    'entries-en' => [
        'name' => 'Entries (English)',
        'elementType' => \craft\elements\Entry::class,
        'siteId' => 1,
        'criteria' => function($query) {
            return $query->section(['news', 'blog']);
        },
        'retrievableFields' => ['intro', 'summary'],
        'enabled' => true,
    ],
],
```

See [Indices](../feature-tour/indices.md) for full configuration options.

`retrievableFields` controls the public `fields` payload in REST and GraphQL search hits. Use `['*']` to return all indexed custom field values, `['*', '-wysiwyg']` to return all except `wysiwyg`, `[]` to return none, or an explicit field-handle list. Exclusion entries such as `-wysiwyg` follow Algolia's `attributesToRetrieve` convention and are valid only when `*` is present. Omitting the key defaults to `['*']`. This is not a secrecy boundary: searchable fields can still affect matching and snippets. Search Manager queues a rebuild automatically after saved or config-synced storage-shape changes such as `retrievableFields`, `criteria`, site scope, language, transformer, heading levels, Split Sections, stop-word behavior, or URL-skipping. Renaming an index or switching its backend also queues an automatic rebuild; CP saves clear previous storage before queueing that rebuild. First materialization of an enabled config-defined index also queues an initial build.

Leave `transformer` unset for automatic transformer resolution. If you configure a custom class, it must be autoloadable, constructible without required constructor arguments, and implement Search Manager's `TransformerInterface`:

```php
'transformer' => \modules\search\transformers\ProductTransformer::class,
```

Extending `BaseTransformer` is recommended for custom document shapes; extending `AutoTransformer` works well when you want automatic field extraction plus extra project fields.

## Widgets Configuration

Widget configurations define how the frontend search widget appears and behaves:

```php
'defaultWidgetHandle' => 'brand-search',

'widgets' => [
    'brand-search' => [
        'name' => 'Brand Search',
        'type' => 'modal',     // supported widget type
        'enabled' => true,
        'styleHandle' => 'brand-dark',  // Link to a widget style preset
        'settings' => [
            'apiKeyHandle' => 'main-widget-key', // CP-managed public key handle
            'search' => [
                'indexHandles' => ['entries-en'],
                'placeholder' => 'Search...',
            ],
            'behavior' => [
                'searchDebounceMs' => 200,
                'searchMinChars' => 2,
                'resultsLimit' => 10,
                'triggerHotkey' => 'k',
                'resultsLayout' => 'default',      // 'default' or 'hierarchical'
                'hierarchyGroupBy' => '',          // Empty = source -> entrySection -> type
                'hierarchyStyle' => 'tree',        // 'tree', 'flat', or 'none'
                'hierarchyDisplay' => 'individual', // 'individual' or 'unified'
                'hierarchyMaxHeadings' => 3,       // Heading children per page block (1-50)
                'snippetMode' => 'balanced',       // Passage choice for page/section snippets
                'snippetIncludeCodeBlocks' => false,       // Allow block-level code in page/section snippets
                'snippetCleanMarkdown' => false,  // Clean Markdown markers from snippet display text
            ],
            'analytics' => [
                'analyticsSource' => 'header-search',
                'analyticsIdleTimeoutMs' => 1500,
            ],
        ],
    ],
],
```

See [Widget Configuration](../widget/configuration.md) for all widget options.

## Widget Styles Configuration

Widget styles are reusable appearance presets that control colors, spacing, and dimensions. Define them in the `widgetStyles` key and reference them from widget configs via `styleHandle`:

```php
'widgetStyles' => [
    'brand-dark' => [
        'name' => 'Brand Dark',
        'type' => 'modal',
        'enabled' => true,
        'styles' => [
            'modalBg' => '#1a1a2e',
            'modalBorderColor' => '#4da6ff',
            'modalBorderRadius' => '16',
            'inputBg' => '#2a2a2a',
            'inputTextColor' => '#ffffff',
            'resultActiveBg' => '#333333',
            // Dark mode variants
            'modalBgDark' => '#0f0f1a',
            'modalBorderColorDark' => '#6db8ff',
        ],
    ],
],
```

See [Widget Styles](../widget/styles.md) for all style properties and validation ranges.

### Inline Styles (Alternative to Style Presets)

Instead of referencing a style preset via `styleHandle`, you can define styles directly on the widget config under `settings.styles`. This is useful for one-off widgets that don't share their appearance with others:

```php
'widgets' => [
    'docs-search' => [
        'name' => 'Docs Search',
        'type' => 'modal',
        'enabled' => true,
        // No styleHandle — styles are inline instead
        'settings' => [
            'styles' => [
                'modalBg' => '#ffffff',
                'modalBgDark' => '#0f172a',
                'modalBorderRadius' => '16',
                'inputBg' => '#f8fafc',
                'inputBgDark' => '#1e293b',
                'spinnerColor' => '#6366f1',
                'spinnerColorDark' => '#818cf8',
            ],
            'search' => [
                'indexHandles' => ['docs-manager'],
            ],
        ],
    ],
],
```

> [!NOTE]
> If both `styleHandle` and `settings.styles` are set, the style preset takes priority. See [Widget Styles](../widget/styles.md) for all available style properties.

## Full Multi-Environment Example

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        // General
        'pluginName' => 'Search Manager',
        'logLevel' => 'error',

        // Indexing
        'autoIndex' => true,
        'batchSize' => 100,
        'lastIndexedDebounceSeconds' => 60,
        'syncBatchSize' => 200,
        'batchFlushInterval' => 5,
        'pendingMaxAge' => 3600,
        'batchMaxAttempts' => 5,

        // Analytics
        'enableAnalytics' => true,
        'analyticsRetention' => 90,
        'enableGeoDetection' => true,
        'geoProvider' => 'ip-api.com',
        'ipHashSalt' => App::env('SEARCH_MANAGER_IP_SALT'),

        // Caching
        'enableCache' => true,
        'cacheDuration' => 3600,
        'cacheStorageMethod' => 'file',
        'enableCacheWarming' => true,

        // Backends
        'defaultBackendHandle' => 'craft-mysql',
        'backends' => [
            'craft-mysql' => [
                'name' => 'Craft MySQL',
                'backendType' => 'mysql',
                'enabled' => true,
                'settings' => [],
            ],
            'production-algolia' => [
                'name' => 'Production Algolia',
                'backendType' => 'algolia',
                'enabled' => true,
                'settings' => [
                    'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
                    'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
                    'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
                ],
            ],
        ],

        // Indexing
        'indexPrefix' => App::env('SEARCH_INDEX_PREFIX'),

        // Indices
        'indices' => [
            'entries-en' => [
                'name' => 'Entries (English)',
                'elementType' => \craft\elements\Entry::class,
                'siteId' => 1,
                'criteria' => function($query) {
                    return $query->section(['news', 'blog', 'pages']);
                },
                // Optional. Leave unset for automatic transformer selection.
                'transformer' => \modules\search\transformers\ProductTransformer::class,
                'enabled' => true,
            ],
        ],

        // Widgets
        'defaultWidgetHandle' => 'main-search',
        'widgets' => [
            'main-search' => [
                'name' => 'Main Search',
                'type' => 'modal',
                'enabled' => true,
                'styleHandle' => 'brand-theme',
                'settings' => [
                    'search' => [
                        'indexHandles' => ['entries-en'],
                        'placeholder' => 'Search...',
                    ],
                    'behavior' => [
                        'resultsLimit' => 10,
                        'triggerHotkey' => 'k',
                        'resultsGroupingEnabled' => true,
                    ],
                    'analytics' => [
                        'analyticsSource' => 'header-search',
                    ],
                ],
            ],
        ],

        // Widget Styles
        'widgetStyles' => [
            'brand-theme' => [
                'name' => 'Brand Theme',
                'type' => 'modal',
                'enabled' => true,
                'styles' => [
                    'modalBg' => '#ffffff',
                    'modalBgDark' => '#1f2937',
                    'modalBorderRadius' => '12',
                    'inputBg' => '#f9fafb',
                    'inputBgDark' => '#111827',
                    'spinnerColor' => '#3b82f6',
                    'spinnerColorDark' => '#60a5fa',
                ],
            ],
        ],
    ],

    'dev' => [
        'logLevel' => 'debug',
        'defaultCountry' => App::env('SEARCH_MANAGER_DEFAULT_COUNTRY'),
        'defaultCity' => App::env('SEARCH_MANAGER_DEFAULT_CITY'),
    ],

    'staging' => [
        'logLevel' => 'info',
    ],

    'production' => [
        'defaultBackendHandle' => 'production-algolia',
        'cacheStorageMethod' => 'redis',
    ],
];
```

## Environment Variables

These environment variables are commonly used with Search Manager:

```bash
# IP privacy (required for analytics)
SEARCH_MANAGER_IP_SALT=your-generated-salt-here

# Multi-environment index prefix
SEARCH_INDEX_PREFIX=local_

# Local dev geo defaults
SEARCH_MANAGER_DEFAULT_COUNTRY=US
SEARCH_MANAGER_DEFAULT_CITY="New York"

# Algolia
ALGOLIA_APPLICATION_ID=your-app-id
ALGOLIA_ADMIN_API_KEY=your-admin-key
ALGOLIA_SEARCH_API_KEY=your-search-key

# Meilisearch
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_ADMIN_API_KEY=your-admin-api-key
MEILISEARCH_SEARCH_API_KEY=your-search-key

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_SEARCH_DATABASE=1   # reference from config as 'database' => '$REDIS_SEARCH_DATABASE'

# Typesense
TYPESENSE_ADMIN_API_KEY=your-admin-api-key
TYPESENSE_SEARCH_API_KEY=your-search-key
```

## Translations

Search Manager includes translations for 12 languages. See [Translations](../resources/translations.md) for the full list and override instructions.
