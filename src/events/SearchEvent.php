<?php

namespace lindemannrock\searchmanager\events;

use yii\base\Event;

/**
 * Search Event
 *
 * Triggered before and after search operations
 * Allows plugins/modules to:
 * - Modify search queries
 * - Track search analytics
 * - Filter results
 *
 * @since 5.0.0
 */
class SearchEvent extends Event
{
    /**
     * The search query string
     */
    public string $query = '';

    /**
     * Search filters/options
     */
    public array $options = [];

    /**
     * Search results (available in AFTER event)
     */
    public array $results = [];

    /**
     * Search execution time in milliseconds (available in AFTER event)
     */
    public ?float $executionTime = null;

    /**
     * The backend used for search
     */
    public ?string $backend = null;
}
