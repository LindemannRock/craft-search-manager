<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\db\Query;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\models\QueryRule;
use yii\base\Component;

/**
 * Query Rule Service
 *
 * Manages query rules for synonyms, boosts, and redirects.
 *
 * @since 5.10.0
 */
class QueryRuleService extends Component
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
     * Get rule by ID
     *
     */
    public function getById(int $id): ?QueryRule
    {
        return QueryRule::findById($id);
    }

    /**
     * Get all rules
     *
     */
    public function getAll(?string $indexHandle = null): array
    {
        return QueryRule::findAll($indexHandle);
    }

    /**
     * Get query rule count
     *
     */
    public function getQueryRuleCount(?bool $enabledOnly = null): int
    {
        $query = (new Query())->from('{{%searchmanager_query_rules}}');

        if ($enabledOnly !== null) {
            $query->where(['enabled' => $enabledOnly ? 1 : 0]);
        }

        return (int)$query->count();
    }

    /**
     * Get rules for an index
     *
     */
    public function getByIndex(?string $indexHandle = null, ?int $siteId = null): array
    {
        return QueryRule::findByIndex($indexHandle, $siteId);
    }

    /**
     * Save a rule
     *
     */
    public function save(QueryRule $rule): bool
    {
        return $rule->save();
    }

    /**
     * Delete a rule
     *
     */
    public function delete(QueryRule $rule): bool
    {
        return $rule->delete();
    }

    /**
     * Delete rule by ID
     *
     */
    public function deleteById(int $id): bool
    {
        $rule = $this->getById($id);
        if (!$rule) {
            return false;
        }
        return $this->delete($rule);
    }

    // =========================================================================
    // SEARCH INTEGRATION
    // =========================================================================

    /**
     * Get matching rules for a search query
     * Returns rules grouped by action type
     *
     */
    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return QueryRule::findMatching($query, $indexHandle, $siteId);
    }

    /**
     * Check if query should redirect
     * Returns redirect URL or null
     *
     * @param QueryRule[]|null $matchedRules
     */
    public function getRedirectUrl(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): ?string {
        $rules = $matchedRules ?? $this->getMatchingRules($query, $indexHandle, $siteId);

        foreach ($rules as $rule) {
            if ($rule->isRedirect()) {
                // Pass siteId so element URLs resolve to the correct site
                $redirectUrl = $rule->getRedirectUrl($siteId);
                $this->logDebug('Redirect rule matched', [
                    'query' => $query,
                    'ruleId' => $rule->id,
                    'siteId' => $siteId,
                    'url' => $redirectUrl,
                ]);
                return $redirectUrl;
            }
        }

        return null;
    }

    /**
     * Expand query with synonyms
     * Returns array of queries to search for
     *
     * @param QueryRule[]|null $matchedRules
     */
    public function expandWithSynonyms(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): array {
        $rules = $matchedRules ?? $this->getMatchingRules($query, $indexHandle, $siteId);
        $queries = [$query];

        foreach ($rules as $rule) {
            if ($rule->actionType === QueryRule::ACTION_SYNONYM) {
                $synonyms = $rule->getSynonyms();
                $this->logDebug('Expanding query with synonyms', [
                    'query' => $query,
                    'synonyms' => $synonyms,
                    'ruleId' => $rule->id,
                ]);
                $queries = array_merge($queries, $synonyms);
            }
        }

        return array_unique($queries);
    }

    /**
     * Get boost multipliers for a query
     * Returns array of [type => [identifier => multiplier]]
     *
     * @param QueryRule[]|null $matchedRules
     */
    public function getBoostMultipliers(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): array {
        $rules = $matchedRules ?? $this->getMatchingRules($query, $indexHandle, $siteId);
        $boosts = [
            'sections' => [],
            'categories' => [],
            'elements' => [],
        ];

        foreach ($rules as $rule) {
            switch ($rule->actionType) {
                case QueryRule::ACTION_BOOST_SECTION:
                    $sectionHandle = $rule->actionValue['sectionHandle'] ?? null;
                    if ($sectionHandle) {
                        $boosts['sections'][$sectionHandle] = $rule->getBoostMultiplier();
                    }
                    break;

                case QueryRule::ACTION_BOOST_CATEGORY:
                    $categoryId = $rule->actionValue['categoryId'] ?? null;
                    $categoryHandle = $rule->actionValue['categoryHandle'] ?? null;
                    if (is_numeric($categoryId) && (int)$categoryId > 0) {
                        $boosts['categories'][(int)$categoryId] = $rule->getBoostMultiplier();
                    } elseif (is_string($categoryHandle) && $categoryHandle !== '') {
                        $this->logWarning('Skipping category boost rule without indexed categoryId target', [
                            'ruleId' => $rule->id,
                            'categoryHandle' => $categoryHandle,
                        ]);
                    }
                    break;

                case QueryRule::ACTION_BOOST_ELEMENT:
                    $elementId = $rule->actionValue['elementId'] ?? null;
                    if ($elementId) {
                        $boosts['elements'][$elementId] = $rule->getBoostMultiplier();
                    }
                    break;
            }
        }

        return $boosts;
    }

    /**
     * Build settings-test attribution targets for boost rules.
     *
     * @param QueryRule[]|null $matchedRules
     * @return array{sections: array<string, array<int, array<string, mixed>>>, categories: array<int, array<int, array<string, mixed>>>, elements: array<int, array<int, array<string, mixed>>>}
     */
    private function getDebugBoostTargets(
        string $query,
        ?string $indexHandle,
        ?int $siteId,
        ?array $matchedRules,
    ): array {
        $rules = $matchedRules ?? $this->getMatchingRules($query, $indexHandle, $siteId);

        $targets = [
            'sections' => [],
            'categories' => [],
            'elements' => [],
        ];

        foreach ($rules as $rule) {
            switch ($rule->actionType) {
                case QueryRule::ACTION_BOOST_SECTION:
                    $sectionHandle = $rule->actionValue['sectionHandle'] ?? null;
                    if (is_string($sectionHandle) && $sectionHandle !== '') {
                        $targets['sections'][$sectionHandle][] = [
                            'ruleId' => (int)$rule->id,
                            'actionType' => $rule->actionType,
                            'multiplier' => $rule->getBoostMultiplier(),
                            'sectionHandle' => $sectionHandle,
                        ];
                    }
                    break;

                case QueryRule::ACTION_BOOST_CATEGORY:
                    $categoryId = null;
                    $categoryHandle = $rule->actionValue['categoryHandle'] ?? null;
                    if (isset($rule->actionValue['categoryId']) && is_numeric($rule->actionValue['categoryId'])) {
                        $categoryId = (int)$rule->actionValue['categoryId'];
                    }

                    if ($categoryId !== null) {
                        $targets['categories'][$categoryId][] = [
                            'ruleId' => (int)$rule->id,
                            'actionType' => $rule->actionType,
                            'multiplier' => $rule->getBoostMultiplier(),
                            'categoryId' => $categoryId,
                            'categoryHandle' => is_string($categoryHandle) && $categoryHandle !== '' ? $categoryHandle : null,
                        ];
                    }
                    break;

                case QueryRule::ACTION_BOOST_ELEMENT:
                    $elementId = $rule->actionValue['elementId'] ?? null;
                    if (is_numeric($elementId)) {
                        $debug = [
                            'ruleId' => (int)$rule->id,
                            'actionType' => $rule->actionType,
                            'multiplier' => $rule->getBoostMultiplier(),
                            'elementId' => (int)$elementId,
                        ];
                        if (isset($rule->actionValue['elementType']) && is_string($rule->actionValue['elementType'])) {
                            $debug['elementType'] = $rule->actionValue['elementType'];
                        }
                        $targets['elements'][(int)$elementId][] = $debug;
                    }
                    break;
            }
        }

        return $targets;
    }

    /**
     * Apply score boosts to search results
     *
     * @param array $results Array of results with 'elementId' and 'score' keys
     * @param string $query Search query
     * @param string|null $indexHandle Index handle
     * @param int|null $siteId Site ID
     * @param QueryRule[]|null $matchedRules
     * @return array Modified results with boosted scores
     */
    public function applyBoosts(
        array $results,
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
        bool $includeDebugAttribution = false,
    ): array {
        $boosts = $this->getBoostMultipliers($query, $indexHandle, $siteId, $matchedRules);
        $debugBoosts = $includeDebugAttribution
            ? $this->getDebugBoostTargets($query, $indexHandle, $siteId, $matchedRules)
            : [
                'sections' => [],
                'categories' => [],
                'elements' => [],
            ];

        if (empty($boosts['sections']) && empty($boosts['categories']) && empty($boosts['elements'])) {
            return $results;
        }

        $this->logDebug('Applying score boosts', [
            'query' => $query,
            'boosts' => $boosts,
        ]);

        $warnMissingSectionMetadata = false;
        $warnMissingCategoryMetadata = false;

        foreach ($results as &$result) {
            $elementId = is_array($result) ? SearchHitIdentityHelper::elementId($result) : $result;
            if (!$elementId) {
                continue;
            }

            $multiplier = 1.0;
            $debugAttributions = [];

            // Check element-specific boost
            if (isset($boosts['elements'][$elementId])) {
                $elementMultiplier = (float)$boosts['elements'][$elementId];
                $multiplier *= $elementMultiplier;
                if ($includeDebugAttribution && isset($debugBoosts['elements'][$elementId])) {
                    foreach ($debugBoosts['elements'][$elementId] as $debugBoost) {
                        $debugAttributions[] = $debugBoost;
                    }
                }
            }

            if (!empty($boosts['sections']) && is_array($result)) {
                $sectionHandle = $result['entrySectionHandle'] ?? null;
                if (is_string($sectionHandle) && $sectionHandle !== '') {
                    if (isset($boosts['sections'][$sectionHandle])) {
                        $sectionMultiplier = (float)$boosts['sections'][$sectionHandle];
                        $multiplier *= $sectionMultiplier;
                        if ($includeDebugAttribution && isset($debugBoosts['sections'][$sectionHandle])) {
                            foreach ($debugBoosts['sections'][$sectionHandle] as $debugBoost) {
                                $debugAttributions[] = $debugBoost;
                            }
                        }
                    }
                } else {
                    $warnMissingSectionMetadata = true;
                }
            }

            if (!empty($boosts['categories']) && is_array($result)) {
                $matchedCategoryId = null;

                if ($this->isCategoryHit($result) && isset($boosts['categories'][$elementId])) {
                    $matchedCategoryId = (int)$elementId;
                } else {
                    $categoryIds = $this->categoryIdsFromHit($result);
                    if (empty($categoryIds)) {
                        $warnMissingCategoryMetadata = true;
                    } else {
                        foreach ($boosts['categories'] as $categoryId => $categoryMultiplier) {
                            if (in_array((int)$categoryId, $categoryIds, true)) {
                                $matchedCategoryId = (int)$categoryId;
                                break;
                            }
                        }
                    }
                }

                if ($matchedCategoryId !== null) {
                    $categoryMultiplier = (float)$boosts['categories'][$matchedCategoryId];
                    $multiplier *= $categoryMultiplier;
                    if ($includeDebugAttribution && isset($debugBoosts['categories'][$matchedCategoryId])) {
                        foreach ($debugBoosts['categories'][$matchedCategoryId] as $debugBoost) {
                            $debugAttributions[] = $debugBoost;
                        }
                    }
                }
            }

            // Apply multiplier to score
            if ($multiplier !== 1.0 && is_array($result) && isset($result['score'])) {
                $result['score'] *= $multiplier;
                $result['boosted'] = true;
                if ($includeDebugAttribution && !empty($debugAttributions)) {
                    $result['_queryRuleDebug']['boosts'] = array_values($debugAttributions);
                }
            }
        }

        // Re-sort by score if any boosts were applied
        if (!empty($boosts['sections']) || !empty($boosts['categories']) || !empty($boosts['elements'])) {
            usort($results, function($a, $b) {
                $scoreA = is_array($a) ? ($a['score'] ?? 0) : 0;
                $scoreB = is_array($b) ? ($b['score'] ?? 0) : 0;
                return $scoreB <=> $scoreA;
            });
        }

        if ($warnMissingSectionMetadata) {
            $this->logWarning('Skipping section boost for results missing indexed entrySectionHandle metadata', [
                'query' => $query,
                'indexHandle' => $indexHandle,
                'siteId' => $siteId,
            ]);
        }

        if ($warnMissingCategoryMetadata) {
            $this->logWarning('Skipping category boost for results missing indexed _categoryIds metadata', [
                'query' => $query,
                'indexHandle' => $indexHandle,
                'siteId' => $siteId,
            ]);
        }

        return $results;
    }

    /**
     * Check whether a result is an indexed category hit.
     */
    private function isCategoryHit(array $result): bool
    {
        $type = $result['type'] ?? ($result['elementType'] ?? null);

        return is_string($type) && strtolower($type) === 'category';
    }

    /**
     * Get indexed category relation IDs for a hit.
     *
     * @return int[]
     */
    private function categoryIdsFromHit(array $result): array
    {
        $categoryIds = $result['_categoryIds'] ?? null;
        if (!is_array($categoryIds)) {
            return [];
        }

        $ids = [];
        foreach ($categoryIds as $categoryId) {
            if (is_numeric($categoryId) && (int)$categoryId > 0) {
                $ids[(int)$categoryId] = true;
            }
        }

        return array_keys($ids);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get available indices for dropdown
     *
     */
    public function getIndexOptions(): array
    {
        $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
        $options = [
            ['label' => 'All Indices', 'value' => ''],
        ];

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

    /**
     * Get section options for dropdown
     *
     */
    public function getSectionOptions(): array
    {
        $sections = Craft::$app->getEntries()->getAllSections();
        $options = [];

        foreach ($sections as $section) {
            $options[] = [
                'label' => $section->name,
                'value' => $section->handle,
            ];
        }

        return $options;
    }

    /**
     * Get category group options for dropdown
     *
     */
    public function getCategoryGroupOptions(): array
    {
        $groups = Craft::$app->getCategories()->getAllGroups();
        $options = [];

        foreach ($groups as $group) {
            $options[] = [
                'label' => $group->name,
                'value' => $group->handle,
            ];
        }

        return $options;
    }
}
