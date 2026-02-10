# Events

Search Manager dispatches events that let you modify indexing behavior, cancel operations, or react to successful indexing from your own plugin or module.

## Index Events

These events are triggered by `IndexingService` during element indexing.

### `EVENT_BEFORE_INDEX`

Fired before an element is indexed. You can modify the data or cancel the operation.

```php
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\services\IndexingService;
use yii\base\Event;

Event::on(
    IndexingService::class,
    IndexingService::EVENT_BEFORE_INDEX,
    function(IndexEvent $event) {
        // Access the element being indexed
        $element = $event->element;

        // Access the index handle
        $indexHandle = $event->indexHandle;

        // Cancel indexing for specific elements
        if ($element->section->handle === 'drafts') {
            $event->isValid = false;
            return;
        }

        // Modify the data before it's indexed
        $event->data['customField'] = 'custom value';
    }
);
```

### `EVENT_AFTER_INDEX`

Fired after an element has been successfully indexed.

```php
Event::on(
    IndexingService::class,
    IndexingService::EVENT_AFTER_INDEX,
    function(IndexEvent $event) {
        $element = $event->element;
        $data = $event->data;          // The indexed data
        $indexHandle = $event->indexHandle;

        // Log successful indexing
        Craft::info(
            "Indexed element #{$element->id} into {$indexHandle}",
            'my-module'
        );

        // Trigger other actions after indexing
        // e.g., clear an external cache, notify a service, etc.
    }
);
```

## IndexEvent Properties

| Property | Type | Description |
|----------|------|-------------|
| `element` | `ElementInterface\|null` | The element being indexed |
| `data` | `array` | The transformed document data |
| `indexHandle` | `string\|null` | The index handle |
| `isValid` | `bool` | Set to `false` in `EVENT_BEFORE_INDEX` to cancel indexing |

## Registering Event Listeners

Register your event listeners in a Craft module's `init()` method:

```php
<?php

namespace modules;

use Craft;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\services\IndexingService;
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
    }
}
```

## Practical Use Cases

### Add Computed Fields

```php
Event::on(
    IndexingService::class,
    IndexingService::EVENT_BEFORE_INDEX,
    function(IndexEvent $event) {
        $element = $event->element;

        // Add a computed field to indexed data
        $event->data['readingTime'] = ceil(
            str_word_count(strip_tags($element->body ?? '')) / 200
        );
    }
);
```

### Skip Specific Content

```php
Event::on(
    IndexingService::class,
    IndexingService::EVENT_BEFORE_INDEX,
    function(IndexEvent $event) {
        $element = $event->element;

        // Don't index entries marked as "noindex"
        if ($element->noIndex ?? false) {
            $event->isValid = false;
        }
    }
);
```

### Sync with External Service

```php
Event::on(
    IndexingService::class,
    IndexingService::EVENT_AFTER_INDEX,
    function(IndexEvent $event) {
        // Push to an external analytics or monitoring service
        MyExternalService::notifyIndexed(
            $event->element->id,
            $event->indexHandle
        );
    }
);
```
