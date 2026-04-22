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
$plugin->enrichment;       // EnrichmentService - result enrichment (snippets, headings, thumbnails)
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

### `removeElement(element)`

Remove an element from all applicable indices.

```php
SearchManager::$plugin->indexing->removeElement($entry);
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

Get how many widget configs reference each style.

```php
$counts = SearchManager::$plugin->widgetStyles->getUsageCountsByHandle();
// ['brand-dark' => 3, 'minimal' => 1]
```

## EnrichmentService @since(5.39.0)

Transforms raw search hits into enriched results with snippets, heading expansion, thumbnails, and metadata. This is what the Search API uses when `enrich=1` is passed.

### `enrichResults(rawHits, query, indexHandles, options)`

Enrich raw search hits with contextual snippets, heading children, and element metadata.

```php
$rawResults = SearchManager::$plugin->backend->search('entries-en', 'craft cms');

$enriched = SearchManager::$plugin->enrichment->enrichResults(
    $rawResults['hits'],
    'craft cms',
    ['entries-en'],
    [
        'snippetMode' => 'balanced',     // 'early', 'balanced', or 'deep'
        'snippetLength' => 150,          // 50–1000
        'showCodeSnippets' => false,
        'parseMarkdownSnippets' => false,
        'hideResultsWithoutUrl' => false,
        'includeDebugMeta' => false,
    ],
);

// $enriched = [['id' => 123, 'title' => '...', 'url' => '...', 'description' => '...', 'headings' => [...]], ...]
```

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

```php
$promotion = new Promotion();
$promotion->query = 'craft cms';
$promotion->indexHandle = 'entries-en';
$promotion->elementId = 123;
SearchManager::$plugin->promotions->save($promotion);
SearchManager::$plugin->promotions->delete($promotion);
```

### `getPromotionCount(enabledOnly)`

```php
$total = SearchManager::$plugin->promotions->getPromotionCount();
$enabled = SearchManager::$plugin->promotions->getPromotionCount(true);
```

## QueryRuleService @since(5.10.0)

Manages query rules — synonyms, boosts, filters, and redirects triggered by search queries.

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

```php
$rule = new QueryRule();
$rule->query = 'laptop';
$rule->actionType = QueryRule::ACTION_SYNONYM;
$rule->actionValue = 'notebook,portable computer';
SearchManager::$plugin->queryRules->save($rule);
SearchManager::$plugin->queryRules->delete($rule);
```

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
