# API Reference

This page documents the PHP API for interacting with Search Manager programmatically from plugins, modules, or custom code.

## Accessing Services

```php
use lindemannrock\searchmanager\SearchManager;

$plugin = SearchManager::$plugin;

// Available services
$plugin->backend;     // BackendService - search and index operations
$plugin->indexing;    // IndexingService - element indexing
$plugin->analytics;   // AnalyticsService - analytics tracking and queries
$plugin->autocomplete; // AutocompleteService - autocomplete suggestions
```

## BackendService

The primary service for search operations.

### `search(indexName, query, options)`

Search an index and return ranked results.

```php
$results = SearchManager::$plugin->backend->search('entries-en', 'craft cms', [
    'siteId' => 1,
    'source' => 'custom-integration',
    'platform' => 'iOS 17.2',
    'appVersion' => '2.1.0',
]);

// $results = ['hits' => [...], 'total' => 42, 'meta' => [...]]
```

### `searchMultiple(indexNames, query, options)`

Search across multiple indices. Results are merged and sorted by score.

```php
$results = SearchManager::$plugin->backend->searchMultiple(
    ['products', 'blog', 'pages'],
    'laptop'
);

// $results = ['hits' => [...], 'total' => 150, 'indices' => ['products' => 50, ...]]
```

### `index(indexName, data)`

Index a document (array of key-value pairs) into a specific index.

```php
SearchManager::$plugin->backend->index('entries-en', [
    'objectID' => 123,
    'id' => 123,
    'title' => 'My Entry',
    'content' => 'Full text content...',
    'siteId' => 1,
]);
```

### `clearIndex(indexName)`

Clear all data from a specific index.

```php
SearchManager::$plugin->backend->clearIndex('entries-en');
```

### `clearSearchCache(indexName)`

Clear the search cache for a specific index.

```php
SearchManager::$plugin->backend->clearSearchCache('entries-en');
```

### `clearAllSearchCache()`

Clear all search caches.

```php
SearchManager::$plugin->backend->clearAllSearchCache();
```

## IndexingService

Handles element indexing operations.

### `indexElement(element, queue)`

Index a single element. By default, uses the queue if `queueEnabled` is true.

```php
use lindemannrock\searchmanager\SearchManager;

$entry = \craft\elements\Entry::find()->id(123)->one();
SearchManager::$plugin->indexing->indexElement($entry);

// Force immediate indexing (bypass queue)
SearchManager::$plugin->indexing->indexElement($entry, false);

// Force queue-based indexing
SearchManager::$plugin->indexing->indexElement($entry, true);
```

### `indexElementNow(element)`

Index a single element immediately, bypassing the queue.

```php
SearchManager::$plugin->indexing->indexElementNow($entry);
```

### `rebuildIndex(indexHandle)`

Rebuild a specific index — clears all data and re-indexes all matching elements.

```php
SearchManager::$plugin->indexing->rebuildIndex('entries-en');
```

### `rebuildAll()`

Rebuild all configured indices.

```php
SearchManager::$plugin->indexing->rebuildAll();
```

## AutocompleteService

### `suggest(query, indexHandle, options)`

Get autocomplete suggestions.

```php
$suggestions = SearchManager::$plugin->autocomplete->suggest('cra', 'entries-en', [
    'limit' => 5,
    'fuzzy' => true,
    'language' => 'en',
]);

// $suggestions = ['craft', 'craftcms', 'create']
```

### `suggestElements(query, indexHandle, options)`

Get element-based suggestions (returns titles and IDs).

```php
$results = SearchManager::$plugin->autocomplete->suggestElements('test', 'entries-en');
```

### `clearCache(indexHandle)`

Clear autocomplete cache for a specific index, or all indices.

```php
SearchManager::$plugin->autocomplete->clearCache('entries-en');
SearchManager::$plugin->autocomplete->clearCache(); // All indices
```

## AnalyticsService

### `clearAnalytics(siteId)`

Clear analytics data, optionally filtered by site.

```php
$deleted = SearchManager::$plugin->analytics->clearAnalytics();
$deleted = SearchManager::$plugin->analytics->clearAnalytics(1); // Specific site
```

## Events

For extending Search Manager behavior without modifying core code, use [Events](events.md).

## Settings

Access plugin settings:

```php
$settings = SearchManager::$plugin->getSettings();

$settings->enableCache;
$settings->cacheDuration;
$settings->defaultBackendHandle;
// etc.
```
