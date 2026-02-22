# Events

Search Manager dispatches events that let you hook into indexing, transformation, and search operations from your own plugin or module.

## Overview

| Service | Event | Since | Use Case |
|---------|-------|-------|----------|
| `IndexingService` | `EVENT_BEFORE_INDEX` | 5.0.0 | Cancel indexing for specific elements |
| `IndexingService` | `EVENT_AFTER_INDEX` | 5.0.0 | React to successful indexing (logging, cache clearing, notifications) |
| `TransformerService` | `EVENT_BEFORE_TRANSFORM` | 5.39.0 | Skip transformation for specific elements |
| `TransformerService` | `EVENT_AFTER_TRANSFORM` | 5.39.0 | Add/modify fields in the search document |
| `BackendService` | `EVENT_BEFORE_SEARCH` | 5.39.0 | Modify queries, add filters, or short-circuit searches |
| `BackendService` | `EVENT_AFTER_SEARCH` | 5.39.0 | Filter, enrich, or reorder search results |

## Index Events

Triggered by `IndexingService` during element indexing.

### `EVENT_BEFORE_INDEX`

Fired before an element is indexed. The element has not been transformed yet, and no index handle is available at this point. Set `$event->isValid = false` to cancel the entire indexing operation for this element.

```php
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\services\IndexingService;
use yii\base\Event;

Event::on(
    IndexingService::class,
    IndexingService::EVENT_BEFORE_INDEX,
    function(IndexEvent $event) {
        // Cancel indexing for specific elements
        if ($event->element->section->handle === 'drafts') {
            $event->isValid = false;
        }
    }
);
```

> [!NOTE]
> The `data` and `indexHandle` properties are not populated in the BEFORE event. To modify the document data before indexing, use `EVENT_AFTER_TRANSFORM` instead.

### `EVENT_AFTER_INDEX`

Fired after an element has been successfully indexed into a specific backend. Fires once per index handle the element matches.

```php
Event::on(
    IndexingService::class,
    IndexingService::EVENT_AFTER_INDEX,
    function(IndexEvent $event) {
        $element = $event->element;
        $data = $event->data;             // The indexed document
        $indexHandle = $event->indexHandle; // e.g., 'entries-en'

        // Log successful indexing
        Craft::info(
            "Indexed element #{$element->id} into {$indexHandle}",
            'my-module'
        );
    }
);
```

### IndexEvent Properties

| Property | Type | BEFORE | AFTER | Description |
|----------|------|--------|-------|-------------|
| `element` | `ElementInterface\|null` | Yes | Yes | The element being indexed |
| `data` | `array` | — | Yes | The transformed document data |
| `indexHandle` | `string\|null` | — | Yes | The index handle (e.g., `'entries-en'`) |
| `isValid` | `bool` | Yes | — | Set to `false` to cancel indexing |

## Transform Events

@since(5.39.0)

Triggered by `TransformerService` when an element is transformed into a search document. These fire inside the indexing pipeline — after `EVENT_BEFORE_INDEX` passes, but before the document is sent to the backend.

This is especially useful with AutoTransformer, where you don't control the transform logic. Instead of writing a full custom transformer just to add one field, listen to the after event.

### `EVENT_BEFORE_TRANSFORM`

Fired before the transformer runs. Set `$event->handled = true` to skip transformation entirely — the element won't be indexed for this index.

```php
use lindemannrock\searchmanager\events\TransformEvent;
use lindemannrock\searchmanager\services\TransformerService;
use yii\base\Event;

Event::on(
    TransformerService::class,
    TransformerService::EVENT_BEFORE_TRANSFORM,
    function(TransformEvent $event) {
        // Skip indexing draft entries
        if ($event->element->getIsDraft()) {
            $event->handled = true;
        }
    }
);
```

### `EVENT_AFTER_TRANSFORM`

Fired after the transformer produces the document data. Modify `$event->document` to add custom fields, remove sensitive content, or enrich the document before it's sent to the backend.

```php
Event::on(
    TransformerService::class,
    TransformerService::EVENT_AFTER_TRANSFORM,
    function(TransformEvent $event) {
        // Add a computed field to all indexed documents
        $event->document['averageRating'] = Reviews::getRating($event->element->id);

        // Add reading time
        $content = $event->document['content'] ?? '';
        $event->document['readingTime'] = ceil(
            str_word_count(strip_tags($content)) / 200
        );
    }
);
```

### TransformEvent Properties

| Property | Type | BEFORE | AFTER | Description |
|----------|------|--------|-------|-------------|
| `element` | `ElementInterface\|null` | Yes | Yes | The element being transformed |
| `indexName` | `string` | Yes | Yes | The index handle this element is being indexed into |
| `transformerClass` | `string\|null` | Yes | Yes | The transformer class name (e.g., `AutoTransformer`) |
| `document` | `array\|null` | — | Yes | The transformed document data (modify to enrich) |
| `handled` | `bool` | Yes | — | Set to `true` to skip transformation |

## Search Events

@since(5.39.0)

Triggered by `BackendService` during search operations. `EVENT_BEFORE_SEARCH` fires after query rules are resolved but before the search executes. `EVENT_AFTER_SEARCH` fires after the search completes and promotions are applied.

### `EVENT_BEFORE_SEARCH`

Modify the query, options, or short-circuit the search entirely. Set `$event->handled = true` to skip the search and return `$event->results` directly.

```php
use lindemannrock\searchmanager\events\SearchEvent;
use lindemannrock\searchmanager\services\BackendService;
use yii\base\Event;

Event::on(
    BackendService::class,
    BackendService::EVENT_BEFORE_SEARCH,
    function(SearchEvent $event) {
        // Force a site filter on all searches
        $event->options['siteId'] = 1;
    }
);
```

### `EVENT_AFTER_SEARCH`

Filter, enrich, or reorder results before they are returned to the caller.

```php
Event::on(
    BackendService::class,
    BackendService::EVENT_AFTER_SEARCH,
    function(SearchEvent $event) {
        // Remove results the current user shouldn't see
        $event->results['hits'] = array_values(array_filter(
            $event->results['hits'],
            fn($hit) => userCanView($hit['id'])
        ));
        $event->results['total'] = count($event->results['hits']);
    }
);
```

### SearchEvent Properties

| Property | Type | BEFORE | AFTER | Description |
|----------|------|--------|-------|-------------|
| `indexName` | `string` | Yes | Yes | The search index handle (e.g., `'blog'`, `'products'`) |
| `query` | `string` | Yes | Yes | The search query string (modify in BEFORE to change what gets searched) |
| `options` | `array` | Yes | Yes | Search options — limit, offset, siteId, type, language, etc. |
| `results` | `array` | — | Yes | Results with `hits`, `total`, and `meta` keys |
| `executionTime` | `float\|null` | — | Yes | Search execution time in milliseconds |
| `backend` | `string\|null` | Yes | Yes | Backend adapter name (e.g., `'mysql'`, `'redis'`, `'algolia'`) |
| `handled` | `bool` | Yes | — | Set to `true` to skip the search and return `$results` directly |

## Registering Event Listeners

Register your event listeners in a Craft module's `init()` method:

```php
<?php

namespace modules;

use Craft;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\events\SearchEvent;
use lindemannrock\searchmanager\events\TransformEvent;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\TransformerService;
use yii\base\Event;
use yii\base\Module;

class MyModule extends Module
{
    public function init(): void
    {
        parent::init();

        // Skip indexing for elements without URLs
        Event::on(
            IndexingService::class,
            IndexingService::EVENT_BEFORE_INDEX,
            function(IndexEvent $event) {
                if ($event->element && !$event->element->getUrl()) {
                    $event->isValid = false;
                }
            }
        );

        // Add a computed field to all search documents
        Event::on(
            TransformerService::class,
            TransformerService::EVENT_AFTER_TRANSFORM,
            function(TransformEvent $event) {
                $event->document['readingTime'] = ceil(
                    str_word_count(strip_tags($event->document['content'] ?? '')) / 200
                );
            }
        );

        // Log slow searches
        Event::on(
            BackendService::class,
            BackendService::EVENT_AFTER_SEARCH,
            function(SearchEvent $event) {
                if ($event->executionTime > 500) {
                    Craft::warning(
                        "Slow search ({$event->executionTime}ms): \"{$event->query}\" on {$event->indexName} [{$event->backend}]",
                        'my-module'
                    );
                }
            }
        );
    }
}
```

## Practical Use Cases

### Add Computed Fields

Use `EVENT_AFTER_TRANSFORM` instead of writing a full custom transformer:

```php
Event::on(
    TransformerService::class,
    TransformerService::EVENT_AFTER_TRANSFORM,
    function(TransformEvent $event) {
        $element = $event->element;

        // Add reading time
        $event->document['readingTime'] = ceil(
            str_word_count(strip_tags($element->body ?? '')) / 200
        );

        // Add custom taxonomy
        $event->document['department'] = $element->department->one()?->title ?? '';
    }
);
```

### Skip Specific Content

Cancel at the index level (skips all indices) or the transform level (skips per-index):

```php
// Skip element entirely (before any transformation)
Event::on(
    IndexingService::class,
    IndexingService::EVENT_BEFORE_INDEX,
    function(IndexEvent $event) {
        if ($event->element->noIndex ?? false) {
            $event->isValid = false;
        }
    }
);

// Skip only for a specific index
Event::on(
    TransformerService::class,
    TransformerService::EVENT_BEFORE_TRANSFORM,
    function(TransformEvent $event) {
        if ($event->indexName === 'public-search' && $event->element->getIsDraft()) {
            $event->handled = true;
        }
    }
);
```

### Filter Search Results by Permission

```php
Event::on(
    BackendService::class,
    BackendService::EVENT_AFTER_SEARCH,
    function(SearchEvent $event) {
        $user = Craft::$app->getUser()->getIdentity();

        $event->results['hits'] = array_values(array_filter(
            $event->results['hits'],
            function($hit) use ($user) {
                // Only show entries the current user can view
                $entry = \craft\elements\Entry::find()->id($hit['objectID'])->one();
                return $entry && $user->can("viewentries:{$entry->section->uid}");
            }
        ));
        $event->results['total'] = count($event->results['hits']);
    }
);
```

### Sync with External Service

```php
Event::on(
    IndexingService::class,
    IndexingService::EVENT_AFTER_INDEX,
    function(IndexEvent $event) {
        MyExternalService::notifyIndexed(
            $event->element->id,
            $event->indexHandle
        );
    }
);
```
