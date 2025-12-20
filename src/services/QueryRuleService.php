<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\QueryRule;
use yii\base\Component;

/**
 * Query Rule Service
 *
 * Manages query rules for synonyms, boosts, filters, and redirects.
 */
class QueryRuleService extends Component
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
     * Get rule by ID
     */
    public function getById(int $id): ?QueryRule
    {
        return QueryRule::findById($id);
    }

    /**
     * Get all rules
     */
    public function getAll(?string $indexHandle = null): array
    {
        return QueryRule::findAll($indexHandle);
    }

    /**
     * Get query rule count
     */
    public function getQueryRuleCount(?bool $enabledOnly = null): int
    {
        $rules = QueryRule::findAll();
        if ($enabledOnly === null) {
            return count($rules);
        }
        return count(array_filter($rules, fn($r) => $r->enabled === $enabledOnly));
    }

    /**
     * Get rules for an index
     */
    public function getByIndex(?string $indexHandle = null, ?int $siteId = null): array
    {
        return QueryRule::findByIndex($indexHandle, $siteId);
    }

    /**
     * Save a rule
     */
    public function save(QueryRule $rule): bool
    {
        return $rule->save();
    }

    /**
     * Delete a rule
     */
    public function delete(QueryRule $rule): bool
    {
        return $rule->delete();
    }

    /**
     * Delete rule by ID
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
     */
    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return QueryRule::findMatching($query, $indexHandle, $siteId);
    }

    /**
     * Check if query should redirect
     * Returns redirect URL or null
     */
    public function getRedirectUrl(string $query, ?string $indexHandle = null, ?int $siteId = null): ?string
    {
        $rules = $this->getMatchingRules($query, $indexHandle, $siteId);

        foreach ($rules as $rule) {
            if ($rule->isRedirect()) {
                $this->logDebug('Redirect rule matched', [
                    'query' => $query,
                    'ruleId' => $rule->id,
                    'url' => $rule->getRedirectUrl(),
                ]);
                return $rule->getRedirectUrl();
            }
        }

        return null;
    }

    /**
     * Expand query with synonyms
     * Returns array of queries to search for
     */
    public function expandWithSynonyms(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        $rules = $this->getMatchingRules($query, $indexHandle, $siteId);
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
     */
    public function getBoostMultipliers(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        $rules = $this->getMatchingRules($query, $indexHandle, $siteId);
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
                    if ($categoryId) {
                        $boosts['categories'][$categoryId] = $rule->getBoostMultiplier();
                    } elseif ($categoryHandle) {
                        // Resolve handle to ID
                        $category = \craft\elements\Category::find()->slug($categoryHandle)->one();
                        if ($category) {
                            $boosts['categories'][$category->id] = $rule->getBoostMultiplier();
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
     * Get filters for a query
     * Returns array of [field => value] pairs
     */
    public function getFilters(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        $rules = $this->getMatchingRules($query, $indexHandle, $siteId);
        $filters = [];

        foreach ($rules as $rule) {
            if ($rule->actionType === QueryRule::ACTION_FILTER) {
                $field = $rule->actionValue['field'] ?? null;
                $value = $rule->actionValue['value'] ?? null;
                if ($field !== null && $value !== null) {
                    $filters[$field] = $value;
                }
            }
        }

        return $filters;
    }

    /**
     * Apply score boosts to search results
     *
     * @param array $results Array of results with 'elementId' and 'score' keys
     * @param string $query Search query
     * @param string|null $indexHandle Index handle
     * @param int|null $siteId Site ID
     * @return array Modified results with boosted scores
     */
    public function applyBoosts(array $results, string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        $boosts = $this->getBoostMultipliers($query, $indexHandle, $siteId);

        if (empty($boosts['sections']) && empty($boosts['categories']) && empty($boosts['elements'])) {
            return $results;
        }

        $this->logDebug('Applying score boosts', [
            'query' => $query,
            'boosts' => $boosts,
        ]);

        // Cache element metadata for boost lookups
        $elementCache = [];

        foreach ($results as &$result) {
            $elementId = is_array($result) ? ($result['objectID'] ?? $result['elementId'] ?? null) : $result;
            if (!$elementId) {
                continue;
            }

            $multiplier = 1.0;

            // Check element-specific boost
            if (isset($boosts['elements'][$elementId])) {
                $multiplier *= $boosts['elements'][$elementId];
            }

            // Check section/category boosts (requires loading element)
            if (!empty($boosts['sections']) || !empty($boosts['categories'])) {
                if (!isset($elementCache[$elementId])) {
                    $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
                    $elementCache[$elementId] = $element;
                }

                $element = $elementCache[$elementId];

                if ($element) {
                    // Section boost (for entries)
                    if (!empty($boosts['sections']) && $element instanceof \craft\elements\Entry) {
                        $sectionHandle = $element->getSection()->handle ?? null;
                        if ($sectionHandle && isset($boosts['sections'][$sectionHandle])) {
                            $multiplier *= $boosts['sections'][$sectionHandle];
                        }
                    }

                    // Category boost - check if element is in a boosted category
                    // This requires the element to have a categories field
                    if (!empty($boosts['categories'])) {
                        foreach ($boosts['categories'] as $categoryId => $boost) {
                            // Check all relation fields for this category
                            if ($this->elementHasCategory($element, $categoryId)) {
                                $multiplier *= $boost;
                                break; // Only apply once per element
                            }
                        }
                    }
                }
            }

            // Apply multiplier to score
            if ($multiplier !== 1.0 && is_array($result) && isset($result['score'])) {
                $result['score'] *= $multiplier;
                $result['boosted'] = true;
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
     * Check if an element belongs to a category
     */
    private function elementHasCategory(\craft\base\ElementInterface $element, int $categoryId): bool
    {
        // Get all category fields
        $fieldLayout = $element->getFieldLayout();
        if (!$fieldLayout) {
            return false;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof \craft\fields\Categories) {
                $categories = $element->getFieldValue($field->handle);
                if ($categories) {
                    foreach ($categories->all() as $category) {
                        if ($category->id === $categoryId) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get available indices for dropdown
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
