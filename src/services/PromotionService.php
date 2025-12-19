<?php

namespace lindemannrock\searchmanager\services;

use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\Promotion;
use yii\base\Component;

/**
 * Promotion Service
 *
 * Manages promoted/pinned search results that bypass normal scoring.
 */
class PromotionService extends Component
{
    use LoggingTrait;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

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
     */
    public function getById(int $id): ?Promotion
    {
        return Promotion::findById($id);
    }

    /**
     * Get all promotions
     */
    public function getAll(?string $indexHandle = null): array
    {
        return Promotion::findAll($indexHandle);
    }

    /**
     * Get promotion count
     */
    public function getPromotionCount(?bool $enabledOnly = null): int
    {
        $promotions = Promotion::findAll();
        if ($enabledOnly === null) {
            return count($promotions);
        }
        return count(array_filter($promotions, fn($p) => $p->enabled === $enabledOnly));
    }

    /**
     * Get promotions for an index
     */
    public function getByIndex(string $indexHandle, ?int $siteId = null): array
    {
        return Promotion::findByIndex($indexHandle, $siteId);
    }

    /**
     * Save a promotion
     */
    public function save(Promotion $promotion): bool
    {
        return $promotion->save();
    }

    /**
     * Delete a promotion
     */
    public function delete(Promotion $promotion): bool
    {
        return $promotion->delete();
    }

    /**
     * Delete promotion by ID
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
     * Get promoted element IDs for a search query
     * Returns array of [elementId => position]
     */
    public function getPromotedElements(string $query, string $indexHandle, ?int $siteId = null): array
    {
        $promotions = Promotion::findMatching($query, $indexHandle, $siteId);
        $promoted = [];

        foreach ($promotions as $promotion) {
            // Verify element still exists
            $element = $promotion->getElement();
            if ($element) {
                $promoted[$promotion->elementId] = $promotion->position;
            } else {
                $this->logWarning('Promoted element not found', [
                    'promotionId' => $promotion->id,
                    'elementId' => $promotion->elementId,
                ]);
            }
        }

        return $promoted;
    }

    /**
     * Apply promotions to search results
     * Inserts promoted elements at their specified positions
     *
     * @param array $results Original search results (array of element IDs or result objects)
     * @param string $query Search query
     * @param string $indexHandle Index handle
     * @param int|null $siteId Site ID
     * @return array Modified results with promotions applied
     */
    public function applyPromotions(array $results, string $query, string $indexHandle, ?int $siteId = null): array
    {
        $promoted = $this->getPromotedElements($query, $indexHandle, $siteId);

        if (empty($promoted)) {
            return $results;
        }

        $this->logDebug('Applying promotions', [
            'query' => $query,
            'promotedCount' => count($promoted),
        ]);

        // Remove promoted elements from their current positions (if they exist in results)
        $promotedIds = array_keys($promoted);
        $filteredResults = [];
        foreach ($results as $result) {
            $elementId = is_array($result) ? ($result['elementId'] ?? null) : $result;
            if (!in_array($elementId, $promotedIds)) {
                $filteredResults[] = $result;
            }
        }

        // Sort promoted elements by position
        asort($promoted);

        // Insert promoted elements at their positions
        $finalResults = $filteredResults;
        foreach ($promoted as $elementId => $position) {
            // Convert to 0-indexed position
            $insertPos = max(0, $position - 1);

            // Create result item matching the format of existing results
            if (!empty($results) && is_array($results[0])) {
                // Results are arrays with elementId key
                $promotedItem = ['elementId' => $elementId, 'promoted' => true];
            } else {
                // Results are just element IDs
                $promotedItem = $elementId;
            }

            // Insert at position
            array_splice($finalResults, $insertPos, 0, [$promotedItem]);
        }

        return $finalResults;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Check if an element is already promoted for a query pattern
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
}
