<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\interfaces;

/**
 * Backend capability contract for authoritative index counts.
 *
 * @since 5.54.0
 */
interface IndexCountBackendInterface
{
    /**
     * Return the number of backend documents for an index and optional site.
     *
     * A null result means the backend cannot provide an authoritative count.
     *
     */
    public function getDocumentCount(string $indexName, ?int $siteId = null): ?int;

    /**
     * Return the number of distinct parent elements represented in an index.
     *
     * A null result means the backend cannot provide an authoritative count.
     *
     */
    public function getDistinctParentCount(string $indexName, ?int $siteId = null): ?int;
}
