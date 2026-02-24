<?php

namespace lindemannrock\searchmanager\services;

use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\Promotion;
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
        $promotions = Promotion::findAll();
        if ($enabledOnly === null) {
            return count($promotions);
        }
        return count(array_filter($promotions, fn($p) => $p->enabled === $enabledOnly));
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
        // findMatching already filters by element live status and sorts by position
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
     * @return array Modified results with promotions applied
     */
    public function applyPromotions(array $results, string $query, string $indexHandle, ?int $siteId = null): array
    {
        $promotions = $this->getPromotedElements($query, $indexHandle, $siteId);

        if (empty($promotions)) {
            return $results;
        }

        $this->logDebug('Applying promotions', [
            'query' => $query,
            'promotedCount' => count($promotions),
        ]);

        // Collect promoted element IDs for filtering
        $promotedIds = array_map(fn(Promotion $p) => $p->elementId, $promotions);

        // Remove promoted elements from their current positions (if they exist in results)
        $filteredResults = [];
        foreach ($results as $result) {
            $elementId = is_array($result) ? ($result['objectID'] ?? $result['elementId'] ?? null) : $result;
            if (!in_array($elementId, $promotedIds)) {
                $filteredResults[] = $result;
            }
        }

        // Batch-fetch promoted elements grouped by type
        $resultsAreArrays = !empty($results) && is_array($results[0]);
        $elements = [];
        if ($resultsAreArrays) {
            $byType = [];
            foreach ($promotions as $promotion) {
                $type = $promotion->elementType ?? \craft\elements\Entry::class;
                $byType[$type][] = $promotion->elementId;
            }

            foreach ($byType as $elementClass => $ids) {
                if (!is_subclass_of($elementClass, \craft\base\ElementInterface::class)) {
                    continue;
                }
                $found = $elementClass::find()
                    ->id($ids)
                    ->siteId($siteId)
                    ->status(null)
                    ->indexBy('id')
                    ->all();
                $elements += $found;
            }
        }

        // Insert promoted elements at their positions (already sorted by position from findMatching)
        $finalResults = $filteredResults;
        foreach ($promotions as $promotion) {
            $insertPos = max(0, $promotion->position - 1);

            if ($resultsAreArrays) {
                $element = $elements[$promotion->elementId] ?? null;
                $promotedItem = [
                    'objectID' => $promotion->elementId,
                    'id' => $promotion->elementId,
                    'promoted' => true,
                    'position' => $promotion->position,
                    'score' => null,
                    'type' => $this->resolveElementType($element),
                    'title' => $element?->title,
                ];
            } else {
                $promotedItem = $promotion->elementId;
            }

            array_splice($finalResults, $insertPos, 0, [$promotedItem]);
        }

        return $finalResults;
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
     * Resolve a human-readable type string for a promoted element
     */
    private function resolveElementType(?\craft\base\ElementInterface $element): ?string
    {
        if (!$element) {
            return null;
        }

        if ($element instanceof \craft\elements\Entry) {
            return $element->getSection()?->handle;
        }

        if ($element instanceof \craft\elements\Category) {
            return $element->getGroup()->handle;
        }

        if ($element instanceof \craft\elements\Asset) {
            return $element->getVolume()->handle;
        }

        // Fallback: use the element's display name (e.g., "User")
        return $element::displayName();
    }
}
