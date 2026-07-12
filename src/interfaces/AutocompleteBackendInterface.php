<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\interfaces;

/**
 * Backend capability contract for native autocomplete support.
 *
 * @since 5.53.0
 */
interface AutocompleteBackendInterface
{
    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function autocomplete(string $indexName, string $query, array $options = []): array;

    public function supportsAutocomplete(): bool;
}
