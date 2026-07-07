<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Index Event
 *
 * Triggered before and after indexing operations
 * Allows plugins/modules to:
 * - Modify data before indexing
 * - Cancel indexing operations
 * - React to successful indexing
 *
 * @since 5.0.0
 */
class IndexEvent extends Event
{
    /**
     * The element being indexed
     */
    public ?ElementInterface $element = null;

    /**
     * The transformed document data (populated in EVENT_AFTER_INDEX, null in EVENT_BEFORE_INDEX).
     *
     * Named `$document` (not `$data`) because Yii's event system reserves
     * `Event::$data` for per-listener metadata passed to `Event::on()`, and
     * overwrites it during dispatch — so a payload set on the event by the
     * trigger never reaches the listener.
     */
    public ?array $document = null;

    /**
     * The index handle
     */
    public ?string $indexHandle = null;

    /**
     * Whether the indexing should proceed (for BEFORE events)
     * Set to false to cancel the operation
     */
    public bool $isValid = true;
}
