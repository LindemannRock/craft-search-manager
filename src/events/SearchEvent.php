<?php

namespace lindemannrock\searchmanager\events;

use yii\base\Event;

/**
 * Search Event
 *
 * Fired before and after search operations in [[BackendService::search()]].
 *
 * **EVENT_BEFORE_SEARCH** — fired after query rules are resolved but before
 * the search executes. Listeners can modify the query or options. Set
 * `$handled = true` to skip the search entirely and return `$results` directly.
 *
 * **EVENT_AFTER_SEARCH** — fired after the search completes and promotions
 * are applied, before results are returned. Listeners can filter, enrich,
 * or reorder the results array.
 *
 * ```php
 * use lindemannrock\searchmanager\events\SearchEvent;
 * use lindemannrock\searchmanager\services\BackendService;
 * use yii\base\Event;
 *
 * // Modify query before search
 * Event::on(
 *     BackendService::class,
 *     BackendService::EVENT_BEFORE_SEARCH,
 *     function (SearchEvent $event) {
 *         // Add a site filter to all searches
 *         $event->options['siteId'] = 1;
 *     }
 * );
 *
 * // Filter results after search
 * Event::on(
 *     BackendService::class,
 *     BackendService::EVENT_AFTER_SEARCH,
 *     function (SearchEvent $event) {
 *         // Remove results the current user shouldn't see
 *         $event->results['hits'] = array_values(array_filter(
 *             $event->results['hits'],
 *             fn($hit) => userCanView($hit['id'])
 *         ));
 *         $event->results['total'] = count($event->results['hits']);
 *     }
 * );
 * ```
 *
 * @since 5.0.0
 */
class SearchEvent extends Event
{
    /**
     * The search index handle (e.g., 'blog', 'products', 'plugin-docs')
     *
     * Available in both BEFORE and AFTER events.
     */
    public string $indexName = '';

    /**
     * The search query string entered by the user
     *
     * Available in both BEFORE and AFTER events. Modify in BEFORE to
     * change what gets searched (e.g., add terms, normalize input).
     */
    public string $query = '';

    /**
     * Search options (limit, offset, siteId, type, language, etc.)
     *
     * Available in both BEFORE and AFTER events. Modify in BEFORE to
     * change search behavior (e.g., force a siteId, adjust limit).
     */
    public array $options = [];

    /**
     * Search results array with 'hits' and 'total' keys
     *
     * Only populated in EVENT_AFTER_SEARCH. Modify to filter, enrich,
     * or reorder results before they are returned to the caller.
     *
     * Structure: `['hits' => [...], 'total' => int, 'meta' => [...]]`
     */
    public array $results = [];

    /**
     * Search execution time in milliseconds
     *
     * Only populated in EVENT_AFTER_SEARCH. Includes backend query time
     * plus synonym expansion and score boost processing.
     */
    public ?float $executionTime = null;

    /**
     * The backend adapter name (e.g., 'mysql', 'redis', 'algolia')
     *
     * Available in both BEFORE and AFTER events.
     */
    public ?string $backend = null;
}
