# API Reference

This page documents the PHP API for interacting with Search Manager programmatically from plugins, modules, or custom code.

## Accessing Services

```php
use lindemannrock\searchmanager\SearchManager;

$plugin = SearchManager::$plugin;

// Available services
$plugin->backend;          // BackendService - search and index operations
$plugin->indexing;         // IndexingService - element indexing
$plugin->analytics;        // AnalyticsService - analytics tracking and queries
$plugin->autocomplete;     // AutocompleteService - autocomplete suggestions
$plugin->widgetConfigs;    // WidgetConfigService - widget configuration CRUD
$plugin->widgetStyles;     // WidgetStyleService - widget style preset CRUD
$plugin->promotions;       // PromotionService - search promotions
$plugin->queryRules;       // QueryRuleService - query rules management
$plugin->deviceDetection;  // DeviceDetectionService - user-agent parsing
$plugin->indexedSnippets;  // IndexedSnippetService - snippets and headings from indexed hit data
$plugin->transformers;     // TransformerService - index transformers
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

Search across multiple indices. Results are merged using the backend relevance signal when available. Scores are backend-specific; built-in backends use Search Manager's BM25 score, while external providers use their own ranking models.

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
    'id' => 123,
    'elementId' => 123,
    'backendId' => '123_1',
    'objectID' => 123,
    'title' => 'My Entry',
    'content' => 'Full text content...',
    'siteId' => 1,
]);
```

Indexed backend records are unique by `backendId`, not by the provider `id` or `objectID` field. Whole-page records use a backend ID such as `{elementId}_{siteId}`; split section records use `{elementId}_{siteId}_{sectionId}` while keeping the same parent `elementId`. Public REST and GraphQL hits expose `elementId`, `backendId`, and `siteId`; provider-level `id` and `objectID` do not appear in public hit responses.

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

### `indexElement(element)`

Index a single element through the pending-sync buffer. `BatchSyncJob` drains that buffer so rapid repeated saves collapse into one pending row per index/site/element/op. Use `indexElementNow()` only when a caller genuinely needs inline indexing.

```php
use lindemannrock\searchmanager\SearchManager;

$entry = \craft\elements\Entry::find()->id(123)->one();
SearchManager::$plugin->indexing->indexElement($entry);
```

### `indexElementNow(element)`

Index a single element immediately, bypassing the pending-sync buffer.

```php
SearchManager::$plugin->indexing->indexElementNow($entry);
```

### `rebuildIndex(indexHandle)`

Rebuild a specific index. This queues a background `RebuildIndexJob` and returns immediately — `true` means the job was queued, not that the rebuild finished. The job clears the index data and re-indexes all matching elements.

```php
SearchManager::$plugin->indexing->rebuildIndex('entries-en');
```

### `batchIndex(elements, indexHandle)`

Batch-index multiple elements at once.

```php
$entries = \craft\elements\Entry::find()->section('blog')->all();
SearchManager::$plugin->indexing->batchIndex($entries, 'entries-en');
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

Get element-based suggestions (returns titles, IDs, site IDs, and element types).

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

## WidgetConfigService

Manage widget configurations programmatically.

### `getAll()`

Get all widget configs (database + config file).

```php
$configs = SearchManager::$plugin->widgetConfigs->getAll();
```

### `getByHandle(handle)`

Get a widget config by handle.

```php
$config = SearchManager::$plugin->widgetConfigs->getByHandle('main-search');
```

### `save(config)`

Save a widget config.

```php
$config = new \lindemannrock\searchmanager\models\WidgetConfig();
$config->handle = 'new-search';
$config->name = 'New Search Widget';
SearchManager::$plugin->widgetConfigs->save($config);
```

## WidgetStyleService

Manage widget style presets programmatically.

### `getAll()`

Get all widget styles (database + config file).

```php
$styles = SearchManager::$plugin->widgetStyles->getAll();
```

### `getByHandle(handle)`

Get a widget style by handle.

```php
$style = SearchManager::$plugin->widgetStyles->getByHandle('brand-dark');
```

### `save(style)`

Save a widget style.

```php
$style = new \lindemannrock\searchmanager\models\WidgetStyle();
$style->handle = 'brand-dark';
$style->name = 'Brand Dark';
$style->type = 'modal';
SearchManager::$plugin->widgetStyles->save($style);
```

### `getUsageCountsByHandle()`

Get how many database-defined widget configs reference each style. Config-file-defined widgets are not counted.

```php
$counts = SearchManager::$plugin->widgetStyles->getUsageCountsByHandle();
// ['brand-dark' => 3, 'minimal' => 1]
```

## IndexedSnippetService @since(5.54.0)

Builds plain-text snippets and heading matches from saved indexed hit data. The REST API and GraphQL search use this path for their public response shape.

### `prepareHitSnippets(hit, query, indexHandle, options)`

Generate `snippet` and `headings` from an indexed backend hit without loading a Craft element.

## PromotionService @since(5.10.0)

Manages search promotions — pinned results that appear for specific queries.

### `getAll(indexHandle)`

Get all promotions, optionally filtered by index.

```php
$all = SearchManager::$plugin->promotions->getAll();
$forIndex = SearchManager::$plugin->promotions->getAll('entries-en');
```

### `getPromotedElements(query, indexHandle, siteId)`

Get promoted elements matching a query.

```php
$promoted = SearchManager::$plugin->promotions->getPromotedElements('craft cms', 'entries-en');
```

### `save(promotion)` / `delete(promotion)`

Use these service methods for promotion writes; successful saves and deletes clear Search Manager's search-result cache.

```php
$promotion = new Promotion();
$promotion->title = 'Craft CMS';
$promotion->query = 'craft cms';
$promotion->indexHandle = 'entries-en';
$promotion->elementId = 123;
SearchManager::$plugin->promotions->save($promotion);
SearchManager::$plugin->promotions->delete($promotion);
```

`title`, `query`, and `elementId` are required — `save()` validates first and returns `false` (with errors on the model) when any of them is missing.

### `getPromotionCount(enabledOnly)`

```php
$total = SearchManager::$plugin->promotions->getPromotionCount();
$enabled = SearchManager::$plugin->promotions->getPromotionCount(true);
```

## QueryRuleService @since(5.10.0)

Manages query rules — synonyms, boosts, and redirects triggered by search queries.

### `getAll(indexHandle)`

Get all query rules, optionally filtered by index.

```php
$all = SearchManager::$plugin->queryRules->getAll();
$forIndex = SearchManager::$plugin->queryRules->getAll('entries-en');
```

### `getMatchingRules(query, indexHandle, siteId)`

Get rules that match a given query.

```php
$rules = SearchManager::$plugin->queryRules->getMatchingRules('laptop', 'products');
```

### `getRedirectUrl(query, indexHandle, siteId)`

Check if a query triggers a redirect rule. Returns the URL or `null`.

```php
$url = SearchManager::$plugin->queryRules->getRedirectUrl('contact us');
if ($url) {
    return $this->redirect($url);
}
```

### `expandWithSynonyms(query, indexHandle, siteId)`

Expand a query with synonym rules.

```php
$expanded = SearchManager::$plugin->queryRules->expandWithSynonyms('laptop');
// ['laptop', 'notebook', 'portable computer']
```

### `save(rule)` / `delete(rule)`

Use these service methods for query-rule writes; successful saves and deletes clear Search Manager's search-result cache.

```php
$rule = new QueryRule();
$rule->name = 'Laptop synonyms';
$rule->matchValue = 'laptop';
$rule->actionType = QueryRule::ACTION_SYNONYM;
$rule->actionValue = ['terms' => ['notebook', 'portable computer']];
SearchManager::$plugin->queryRules->save($rule);
SearchManager::$plugin->queryRules->delete($rule);
```

`name`, `matchValue`, and `actionType` are required, and `actionValue` is a typed array whose shape depends on the action type — see [Query Rules → API Response](../feature-tour/query-rules.md#api-response) for the per-action shapes.

### `getQueryRuleCount(enabledOnly)`

```php
$total = SearchManager::$plugin->queryRules->getQueryRuleCount();
$enabled = SearchManager::$plugin->queryRules->getQueryRuleCount(true);
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

### `getFullIndexName(handle)`

Get the full prefixed index name (combines `indexPrefix` setting with the handle):

```php
$fullName = SearchManager::$plugin->getSettings()->getFullIndexName('entries-en');
// Returns: "prod_entries-en" (when indexPrefix is "prod_")
```
