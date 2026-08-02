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
        SearchManager::$plugin->requireEdition(SearchManager::EDITION_PRO, 'Promotions');

        $saved = $promotion->save();
        if ($saved) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        return $saved;
    }

    /**
     * Delete a promotion
     *
     */
    public function delete(Promotion $promotion): bool
    {
        SearchManager::$plugin->requireEdition(SearchManager::EDITION_PRO, 'Promotions');

        $deleted = $promotion->delete();
        if ($deleted) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        return $deleted;
    }

    /**
     * Delete promotion by ID
     *
     */
    public function deleteById(int $id): bool
    {
        SearchManager::$plugin->requireEdition(SearchManager::EDITION_PRO, 'Promotions');

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
        return $this->applyPromotionsWithOutcome(
            $results,
            $query,
            $indexHandle,
            $siteId,
            $matchedPromotions,
        )['hits'];
    }

    /**
     * Apply promotions and return the exact promotions represented in the
     * final hit list.
     *
     * @param array<int, mixed> $results
     * @param Promotion[]|null $matchedPromotions
     * @param callable(array<int, mixed>): array<int, mixed>|null $finalizeHits
     * @return array{hits: array<int, mixed>, presentedPromotions: list<Promotion>}
     * @internal
     * @since 5.54.0
     */
    public function applyPromotionsWithOutcome(
        array $results,
        string $query,
        string $indexHandle,
        ?int $siteId = null,
        ?array $matchedPromotions = null,
        ?callable $finalizeHits = null,
    ): array {
        $promotions = $matchedPromotions ?? $this->getPromotedElements($query, $indexHandle, $siteId);

        if (empty($promotions)) {
            return [
                'hits' => $finalizeHits !== null ? $finalizeHits($results) : $results,
                'presentedPromotions' => [],
            ];
        }

        $promotions = $this->prioritizedPromotions($promotions, $siteId);

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

        // Insert promoted elements using the deterministic priority order.
        $finalResults = $filteredResults;
        $promotionByIdentity = [];
        $lastPromotionInsertPos = -1;
        foreach ($promotions as $promotion) {
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

            $promotedItem = $this->promotedPageHit($promotedItem);
            $promotedItem['promoted'] = true;
            $promotedItem['position'] = $promotion->position;
            $promotedItem['score'] = null;
            if ($promotion->elementType !== null) {
                $promotedItem['_elementType'] = $promotion->elementType;
            }

            $insertPos = max(0, $promotion->position - 1, $lastPromotionInsertPos + 1);
            array_splice($finalResults, $insertPos, 0, [$promotedItem]);
            $promotionByIdentity[$this->promotionIdentity($elementId, $siteId)] = $promotion;
            $lastPromotionInsertPos = $insertPos;
        }

        if ($finalizeHits !== null) {
            $finalResults = $finalizeHits($finalResults);
        }

        $presentedPromotions = [];
        foreach ($finalResults as $hit) {
            if (!is_array($hit) || ($hit['promoted'] ?? false) !== true) {
                continue;
            }

            $elementId = SearchHitIdentityHelper::elementId($hit);
            if ($elementId === null) {
                continue;
            }

            $identity = $this->promotionIdentity($elementId, $siteId);
            if (isset($promotionByIdentity[$identity])) {
                $presentedPromotions[] = $promotionByIdentity[$identity];
                unset($promotionByIdentity[$identity]);
            }
        }

        return [
            'hits' => $finalResults,
            'presentedPromotions' => $presentedPromotions,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function promotedPageHit(array $document): array
    {
        if (($document['sectionId'] ?? null) !== 'intro' && ($document['sectionType'] ?? null) !== 'intro') {
            return $document;
        }

        $document['sectionType'] = 'promoted-page';
        $document['sectionId'] = 'promoted-page';
        $document['sectionTitle'] = $document['title'] ?? $document['sectionTitle'] ?? '';
        $document['sectionLevel'] = null;
        $document['sectionAnchor'] = null;
        $document['sectionUrl'] = $document['url'] ?? $document['sectionUrl'] ?? null;
        $document['sectionIndex'] = 0;
        $elementId = SearchHitIdentityHelper::elementId($document);
        if ($elementId !== null) {
            $document['backendId'] = SearchHitIdentityHelper::sectionDocumentId(
                $elementId,
                isset($document['siteId']) ? (int)$document['siteId'] : null,
                'promoted-page',
            );
        }
        unset(
            $document['snippet'],
            $document['sectionBody'],
            $document['_bodyClean'],
            $document['_sectionBodyWithCode'],
            $document['_headings'],
            $document['headings'],
        );

        return $document;
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

    /**
     * @param array<int, Promotion> $promotions
     * @return list<Promotion>
     */
    private function prioritizedPromotions(array $promotions, ?int $siteId): array
    {
        $prioritized = [];
        foreach (array_values($promotions) as $order => $promotion) {
            $prioritized[] = [
                'promotion' => $promotion,
                'order' => $order,
            ];
        }

        usort($prioritized, static function(array $a, array $b): int {
            /** @var Promotion $promotionA */
            $promotionA = $a['promotion'];
            /** @var Promotion $promotionB */
            $promotionB = $b['promotion'];

            return $promotionA->position <=> $promotionB->position
                ?: ($promotionA->id ?? PHP_INT_MAX) <=> ($promotionB->id ?? PHP_INT_MAX)
                ?: $a['order'] <=> $b['order'];
        });

        $seen = [];
        $deduplicated = [];
        foreach ($prioritized as $candidate) {
            /** @var Promotion $promotion */
            $promotion = $candidate['promotion'];
            $elementId = (int)$promotion->elementId;
            if ($elementId <= 0) {
                continue;
            }

            $identity = $this->promotionIdentity($elementId, $siteId);
            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $deduplicated[] = $promotion;
        }

        return $deduplicated;
    }

    private function promotionIdentity(int $elementId, ?int $siteId): string
    {
        return $elementId . ':' . ($siteId ?? 'all');
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
