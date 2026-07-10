<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fields\Categories;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
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

        $categoryHandleIds = $this->resolveCategoryHandleBoostIds($rules, $siteId);

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
                    if ($categoryId) {
                        $boosts['categories'][$categoryId] = $rule->getBoostMultiplier();
                    } elseif ($categoryHandle) {
                        $resolvedCategoryId = $categoryHandleIds[$categoryHandle] ?? null;
                        if ($resolvedCategoryId !== null) {
                            $boosts['categories'][$resolvedCategoryId] = $rule->getBoostMultiplier();
                        }
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
     * Resolve legacy category-handle boost rules in one category query.
     *
     * @param array<int, QueryRule> $rules
     * @return array<string, int>
     */
    private function resolveCategoryHandleBoostIds(array $rules, ?int $siteId): array
    {
        $handles = [];
        foreach ($rules as $rule) {
            if ($rule->actionType !== QueryRule::ACTION_BOOST_CATEGORY) {
                continue;
            }
            if (!empty($rule->actionValue['categoryId'])) {
                continue;
            }
            $categoryHandle = $rule->actionValue['categoryHandle'] ?? null;
            if (is_string($categoryHandle) && $categoryHandle !== '') {
                $handles[$categoryHandle] = true;
            }
        }

        if (empty($handles)) {
            return [];
        }

        $query = Category::find()
            ->slug(array_keys($handles))
            ->status(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $idsByHandle = [];
        foreach ($query->all() as $category) {
            $idsByHandle[(string)$category->slug] = (int)$category->id;
        }

        return $idsByHandle;
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
        $categoryHandleIds = $this->resolveCategoryHandleBoostIds($rules, $siteId);
        $categoryHandlesById = $this->resolveCategoryHandlesById($rules, $categoryHandleIds, $siteId);

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
                    } elseif (is_string($categoryHandle) && $categoryHandle !== '') {
                        $categoryId = $categoryHandleIds[$categoryHandle] ?? null;
                    }

                    if ($categoryId !== null) {
                        $targets['categories'][$categoryId][] = [
                            'ruleId' => (int)$rule->id,
                            'actionType' => $rule->actionType,
                            'multiplier' => $rule->getBoostMultiplier(),
                            'categoryId' => $categoryId,
                            'categoryHandle' => is_string($categoryHandle) && $categoryHandle !== ''
                                ? $categoryHandle
                                : ($categoryHandlesById[$categoryId] ?? null),
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
     * @param QueryRule[] $rules
     * @param array<string, int> $categoryHandleIds
     * @return array<int, string>
     */
    private function resolveCategoryHandlesById(array $rules, array $categoryHandleIds, ?int $siteId): array
    {
        $ids = [];
        foreach ($rules as $rule) {
            if ($rule->actionType !== QueryRule::ACTION_BOOST_CATEGORY) {
                continue;
            }

            if (isset($rule->actionValue['categoryId']) && is_numeric($rule->actionValue['categoryId'])) {
                $ids[(int)$rule->actionValue['categoryId']] = true;
                continue;
            }

            $categoryHandle = $rule->actionValue['categoryHandle'] ?? null;
            if (is_string($categoryHandle) && isset($categoryHandleIds[$categoryHandle])) {
                $ids[$categoryHandleIds[$categoryHandle]] = true;
            }
        }

        if (empty($ids)) {
            return [];
        }

        $query = Category::find()
            ->id(array_keys($ids))
            ->status(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $handles = [];
        foreach ($query->all() as $category) {
            $handles[(int)$category->id] = (string)$category->slug;
        }

        return $handles;
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

        $needsElementMetadata = !empty($boosts['sections']) || !empty($boosts['categories']);
        $elementMap = $needsElementMetadata
            ? $this->preloadBoostElements($results, $indexHandle, $siteId)
            : [];
        $categoryBoostsByElement = !empty($boosts['categories'])
            ? $this->preloadCategoryBoostMatches($elementMap, $boosts['categories'])
            : [];

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

            // Check section/category boosts (requires loading element)
            if ($needsElementMetadata) {
                $elementKey = $this->resultElementKey($result, $elementId, $siteId);
                $element = $elementMap[$elementKey] ?? null;

                if ($element) {
                    // Section boost (for entries)
                    if (!empty($boosts['sections']) && $element instanceof Entry) {
                        $sectionHandle = $element->getSection()->handle ?? null;
                        if ($sectionHandle && isset($boosts['sections'][$sectionHandle])) {
                            $sectionMultiplier = (float)$boosts['sections'][$sectionHandle];
                            $multiplier *= $sectionMultiplier;
                            if ($includeDebugAttribution && isset($debugBoosts['sections'][$sectionHandle])) {
                                foreach ($debugBoosts['sections'][$sectionHandle] as $debugBoost) {
                                    $debugAttributions[] = $debugBoost;
                                }
                            }
                        }
                    }

                    // Category boost - check if element is in a boosted category
                    if (!empty($boosts['categories']) && $element instanceof Category && isset($boosts['categories'][$elementId])) {
                        $categoryMultiplier = (float)$boosts['categories'][$elementId];
                        $multiplier *= $categoryMultiplier;
                        if ($includeDebugAttribution && isset($debugBoosts['categories'][$elementId])) {
                            foreach ($debugBoosts['categories'][$elementId] as $debugBoost) {
                                $debugAttributions[] = $this->withCategoryHandle($debugBoost, $element);
                            }
                        }
                    }

                    // Category boost - check if element is related to a boosted category
                    if (isset($categoryBoostsByElement[$elementKey])) {
                        $categoryMatch = $categoryBoostsByElement[$elementKey];
                        $multiplier *= $categoryMatch['multiplier'];
                        if ($includeDebugAttribution && isset($debugBoosts['categories'][$categoryMatch['categoryId']])) {
                            foreach ($debugBoosts['categories'][$categoryMatch['categoryId']] as $debugBoost) {
                                $debugAttributions[] = $debugBoost;
                            }
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

        return $results;
    }

    /**
     * Preload elements needed for section/category boost checks.
     *
     * @param array<int, mixed> $results
     * @return array<string, ElementInterface>
     */
    private function preloadBoostElements(array $results, ?string $indexHandle, ?int $siteId): array
    {
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $elementClassByHandle = $this->elementClassesByIndexHandle($indexHandle);
        $fallbackClass = $indexHandle !== null ? ($elementClassByHandle[$indexHandle] ?? null) : null;

        $groups = [];
        $unresolved = [];

        foreach ($results as $result) {
            $elementId = is_array($result) ? SearchHitIdentityHelper::elementId($result) : (is_numeric($result) ? (int)$result : null);
            if ($elementId === null) {
                continue;
            }

            $resolvedSiteId = is_array($result) && isset($result['siteId'])
                ? (int)$result['siteId']
                : ($siteId ?? $currentSiteId);
            $explicitElementClass = is_array($result) && is_string($result['_elementType'] ?? null)
                ? $result['_elementType']
                : null;
            $handle = is_array($result) ? (string)($result['_index'] ?? '') : '';
            $elementClass = $explicitElementClass ?: ($handle !== ''
                ? ($elementClassByHandle[$handle] ?? null)
                : $fallbackClass);

            if ($elementClass !== null && is_subclass_of($elementClass, ElementInterface::class)) {
                $groups[$elementClass][$resolvedSiteId][$elementId] = true;
            } else {
                $unresolved[$resolvedSiteId][$elementId] = true;
            }
        }

        $map = [];

        /** @var class-string<ElementInterface> $elementClass */
        foreach ($groups as $elementClass => $bySite) {
            foreach ($bySite as $resolvedSiteId => $idSet) {
                /** @var \craft\elements\db\ElementQuery $query */
                $query = $elementClass::find()
                    ->id(array_keys($idSet))
                    ->siteId((int)$resolvedSiteId)
                    ->status(null);

                foreach ($query->all() as $element) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                    unset($unresolved[$resolvedSiteId][(int)$element->id]);
                }
            }
        }

        foreach ($unresolved as $resolvedSiteId => $idSet) {
            /** @var \craft\elements\db\ElementQuery $query */
            $query = Element::find()
                ->id(array_keys($idSet))
                ->siteId((int)$resolvedSiteId)
                ->status(null);

            foreach ($query->all() as $element) {
                $map[$resolvedSiteId . ':' . $element->id] = $element;
            }
        }

        return $map;
    }

    /**
     * Resolve candidate element classes from the selected index scope.
     *
     * @return array<string, class-string<ElementInterface>>
     */
    private function elementClassesByIndexHandle(?string $indexHandle): array
    {
        $indices = $indexHandle !== null
            ? array_filter([SearchIndex::findByHandle($indexHandle)])
            : SearchIndex::findAll();

        $classes = [];
        foreach ($indices as $index) {
            if (!$index->enabled || !is_subclass_of($index->elementType, ElementInterface::class)) {
                continue;
            }
            $classes[$index->handle] = $index->elementType;
        }

        return $classes;
    }

    /**
     * Preload the first matching category boost target per element.
     *
     * @param array<string, ElementInterface> $elements
     * @param array<int|string, float|int> $categoryBoosts
     * @return array<string, array{categoryId: int, multiplier: float}>
     */
    private function preloadCategoryBoostMatches(array $elements, array $categoryBoosts): array
    {
        if (empty($elements)) {
            return [];
        }

        $fieldIds = [];
        $sourcesBySite = [];

        foreach ($elements as $key => $element) {
            $fieldLayout = $element->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                if ($field instanceof Categories && $field->id !== null) {
                    $fieldIds[(int)$field->id] = true;
                    $sourcesBySite[(int)$element->siteId][(int)$element->id] = $key;
                }
            }
        }

        if (empty($fieldIds) || empty($sourcesBySite)) {
            return [];
        }

        $categoryIds = array_map('intval', array_keys($categoryBoosts));
        $rows = (new Query())
            ->select(['sourceId', 'sourceSiteId', 'targetId'])
            ->from(Table::RELATIONS)
            ->where([
                'fieldId' => array_keys($fieldIds),
                'sourceId' => array_values(array_unique(array_merge(...array_map('array_keys', $sourcesBySite)))),
                'targetId' => $categoryIds,
            ])
            ->all();

        $matches = [];
        foreach ($rows as $row) {
            $sourceId = (int)$row['sourceId'];
            $sourceSiteId = $row['sourceSiteId'] !== null ? (int)$row['sourceSiteId'] : null;
            $targetId = (int)$row['targetId'];

            foreach ($sourcesBySite as $siteId => $sourceKeys) {
                if ($sourceSiteId !== null && $sourceSiteId !== (int)$siteId) {
                    continue;
                }
                if (isset($sourceKeys[$sourceId])) {
                    $matches[$sourceKeys[$sourceId]][$targetId] = true;
                }
            }
        }

        $boostsByElement = [];
        foreach ($matches as $elementKey => $categoryMatches) {
            foreach ($categoryBoosts as $categoryId => $boost) {
                if (isset($categoryMatches[(int)$categoryId])) {
                    $boostsByElement[$elementKey] = [
                        'categoryId' => (int)$categoryId,
                        'multiplier' => (float)$boost,
                    ];
                    break;
                }
            }
        }

        return $boostsByElement;
    }

    /**
     * @param array<string, mixed> $debugBoost
     * @return array<string, mixed>
     */
    private function withCategoryHandle(array $debugBoost, Category $category): array
    {
        if (empty($debugBoost['categoryHandle'])) {
            $debugBoost['categoryHandle'] = (string)$category->slug;
        }

        return $debugBoost;
    }

    /**
     * Build the map key used for result element metadata.
     */
    private function resultElementKey(mixed $result, int $elementId, ?int $siteId): string
    {
        $resolvedSiteId = is_array($result) && isset($result['siteId'])
            ? (int)$result['siteId']
            : ($siteId ?? Craft::$app->getSites()->getCurrentSite()->id);

        return $resolvedSiteId . ':' . $elementId;
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
