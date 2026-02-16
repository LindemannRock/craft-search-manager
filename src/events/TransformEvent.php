<?php

namespace lindemannrock\searchmanager\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Transform Event
 *
 * Fired before and after an element is transformed into a search document.
 *
 * **EVENT_BEFORE_TRANSFORM** — fired before the transformer runs. Listeners
 * can inspect the element and set `$handled = true` to skip transformation
 * (the element won't be indexed).
 *
 * **EVENT_AFTER_TRANSFORM** — fired after the transformer produces the
 * document data. Listeners can modify `$data` to add custom fields, remove
 * sensitive content, or enrich the document before it's sent to the backend.
 *
 * This is especially useful with AutoTransformer, where you don't control
 * the transform logic. Instead of writing a full custom transformer just
 * to add one field, you can listen to the after event:
 *
 * ```php
 * use lindemannrock\searchmanager\events\TransformEvent;
 * use lindemannrock\searchmanager\services\TransformerService;
 * use yii\base\Event;
 *
 * // Add a computed field to all indexed documents
 * Event::on(
 *     TransformerService::class,
 *     TransformerService::EVENT_AFTER_TRANSFORM,
 *     function (TransformEvent $event) {
 *         // Add average rating from a reviews plugin
 *         $event->document['averageRating'] = Reviews::getRating($event->element->id);
 *     }
 * );
 *
 * // Skip indexing draft entries
 * Event::on(
 *     TransformerService::class,
 *     TransformerService::EVENT_BEFORE_TRANSFORM,
 *     function (TransformEvent $event) {
 *         if ($event->element->getIsDraft()) {
 *             $event->handled = true;
 *         }
 *     }
 * );
 * ```
 *
 * @since 5.39.0
 */
class TransformEvent extends Event
{
    /**
     * The element being transformed
     *
     * Available in both BEFORE and AFTER events.
     */
    public ?ElementInterface $element = null;

    /**
     * The search index handle this element is being indexed into
     *
     * Available in both BEFORE and AFTER events. An element may be
     * indexed into multiple indices — the event fires per index.
     */
    public string $indexName = '';

    /**
     * The transformer class being used (e.g., AutoTransformer, DocsManagerTransformer)
     *
     * Available in both BEFORE and AFTER events.
     */
    public ?string $transformerClass = null;

    /**
     * The transformed document data
     *
     * Only populated in EVENT_AFTER_TRANSFORM. Modify to add custom fields,
     * remove sensitive content, or enrich the document before indexing.
     *
     * Common fields: `title`, `content`, `excerpt`, `url`, `siteId`,
     * `elementType`, `_headings`, etc.
     */
    public ?array $document = null;
}
