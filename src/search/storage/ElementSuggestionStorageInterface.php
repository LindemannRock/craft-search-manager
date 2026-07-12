<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search\storage;

/**
 * Storage capability contract for element-title autocomplete suggestions.
 *
 * @since 5.53.0
 */
interface ElementSuggestionStorageInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getElementSuggestions(string $query, ?int $siteId, int $limit = 10, ?string $elementType = null): array;
}
