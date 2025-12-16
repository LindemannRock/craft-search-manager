<?php

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
 */
class IndexEvent extends Event
{
    /**
     * The element being indexed
     */
    public ?ElementInterface $element = null;

    /**
     * The transformed data (available in AFTER event)
     * @var array
     */
    public $data = [];

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
