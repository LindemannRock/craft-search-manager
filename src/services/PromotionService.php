<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use craft\db\Query;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Promotion Service
 *
 * Manages promoted/pinned search results that bypass normal scoring.
 *
 * @since 5.10.0
 */
class PromotionService extends Component
{
    use LoggingTrait;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /**
     * Get promotion by ID
     *
     */
    public function getById(int $id): ?Promotion
    {
        return Promotion::findById($id);
    }

    /**
     * Get all promotions
     *
     */
    public function getAll(?string $indexHandle = null): array
    {
        return Promotion::findAll($indexHandle);
    }

    /**
     * Get promotion count
     *
     */
    public function getPromotionCount(?bool $enabledOnly = null): int
    {
        $query = (new Query())->from('{{%searchmanager_promotions}}');

        if ($enabledOnly !== null) {
            $query->where(['enabled' => $enabledOnly ? 1 : 0]);
        }

        return (int)$query->count();
    }

    /**
     * Get promotions for an index
     *
     */
    public function getByIndex(string $indexHandle, ?int $siteId = null): array
    {
        return Promotion::findByIndex($indexHandle, $siteId);
    }

    /**
     * Save a promotion
     *
     */
    public function save(Promotion $promotion): bool
    {
        return $promotion->save();
    }

    /**
     * Delete a promotion
     *
     */
    public function delete(Promotion $promotion): bool
    {
        return $promotion->delete();
    }

    /**
     * Delete promotion by ID
     *
     */
    public function deleteById(int $id): bool
    {
        $promotion = $this->getById($id);
        if (!$promotion) {
            return false;
        }
        return $this->delete($promotion);
    }

    // =========================================================================
    // SEARCH INTEGRATION
    // =========================================================================

    /**
     * Get matching promotions for a search query
     * Returns full Promotion objects sorted by position
     *
     * @return Promotion[]
     */
    public function getPromotedElements(string $query, string $indexHandle, ?int $siteId = null): array
    {
        // findMatching only evaluates the promotion rules; indexed document existence decides promotion validity.
        return Promotion::findMatching($query, $indexHandle, $siteId);
    }

    /**
     * Apply promotions to search results
     * Inserts promoted elements at their specified positions
     *
     * @param array $results Original search results (array of element IDs or result objects)
     * @param string $query Search query
     * @param string $indexHandle Index handle
     * @param int|null $siteId Site ID
     * @param Promotion[]|null $matchedPromotions Already matched promotions for this request
     * @return array Modified results with promotions applied
     */
    public function applyPromotions(
        array $results,
        string $query,
        string $indexHandle,
        ?int $siteId = null,
        ?array $matchedPromotions = null,
    ): array {
        $promotions = $matchedPromotions ?? $this->getPromotedElements($query, $indexHandle, $siteId);

        if (empty($promotions)) {
            return $results;
        }

        $this->logDebug('Applying promotions', [
            'query' => $query,
            'promotedCount' => count($promotions),
        ]);
        // Collect promoted element IDs for filtering
        $promotedIds = $this->promotionElementIds($promotions);
        $indexedDocuments = $this->indexedPromotionDocuments($promotions, $indexHandle, $siteId);

        // Remove promoted elements from their current positions (if they exist in results)
        $filteredResults = [];
        foreach ($results as $result) {
            $elementId = is_array($result) ? SearchHitIdentityHelper::elementId($result) : $result;
            if (!in_array($elementId, $promotedIds, true)) {
                $filteredResults[] = $result;
            }
        }

        // Insert promoted elements at their positions (already sorted by position from findMatching)
        $finalResults = $filteredResults;
        foreach ($promotions as $promotion) {
            $insertPos = max(0, $promotion->position - 1);
            $elementId = (int)$promotion->elementId;
            $promotedItem = $indexedDocuments[$elementId] ?? null;

            if ($promotedItem === null) {
                $this->logWarning('Skipping promotion because target document is not indexed', [
                    'promotionId' => $promotion->id,
                    'index' => $indexHandle,
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                ]);
                continue;
            }

            $promotedItem['promoted'] = true;
            $promotedItem['position'] = $promotion->position;
            $promotedItem['score'] = null;
            if ($promotion->elementType !== null) {
                $promotedItem['_elementType'] = $promotion->elementType;
            }
            array_splice($finalResults, $insertPos, 0, [$promotedItem]);
        }

        return $finalResults;
    }

    /**
     * @return array<int, int>
     */
    private function promotionElementIds(array $promotions): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn(Promotion $promotion): int => (int)$promotion->elementId, $promotions),
            static fn(int $elementId): bool => $elementId > 0,
        )));
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Check if an element is already promoted for a query pattern
     *
     */
    public function isAlreadyPromoted(int $elementId, string $query, string $indexHandle, ?int $siteId = null, ?int $excludeId = null): bool
    {
        $promotions = Promotion::findByIndex($indexHandle, $siteId);

        foreach ($promotions as $promotion) {
            // Skip the promotion we're editing
            if ($excludeId && $promotion->id === $excludeId) {
                continue;
            }

            if ($promotion->elementId === $elementId && mb_strtolower($promotion->query) === mb_strtolower($query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get available indices for dropdown
     *
     */
    public function getIndexOptions(): array
    {
        $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
        $options = [];

        foreach ($indices as $index) {
            if ($index->enabled) {
                $options[] = [
                    'label' => $index->name,
                    'value' => $index->handle,
                ];
            }
        }

        return $options;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * @param array<int, Promotion> $promotions
     * @return array<int, array<string, mixed>>
     */
    private function indexedPromotionDocuments(array $promotions, string $indexHandle, ?int $siteId): array
    {
        $elementIds = $this->promotionElementIds($promotions);
        if ($elementIds === []) {
            return [];
        }

        return SearchManager::$plugin->backend->getDocumentsByElementIds($indexHandle, $elementIds, $siteId);
    }
}
