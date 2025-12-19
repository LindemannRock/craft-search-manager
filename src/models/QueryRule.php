<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Query Rule Model
 *
 * Represents a query rule for synonyms, boosts, filters, and redirects.
 * Rules modify search behavior when queries match specific patterns.
 */
class QueryRule extends Model
{
    use LoggingTrait;

    // Action types
    public const ACTION_SYNONYM = 'synonym';
    public const ACTION_BOOST_SECTION = 'boost_section';
    public const ACTION_BOOST_CATEGORY = 'boost_category';
    public const ACTION_BOOST_ELEMENT = 'boost_element';
    public const ACTION_FILTER = 'filter';
    public const ACTION_REDIRECT = 'redirect';

    // Match types
    public const MATCH_EXACT = 'exact';
    public const MATCH_CONTAINS = 'contains';
    public const MATCH_PREFIX = 'prefix';
    public const MATCH_REGEX = 'regex';

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;
    public string $name = '';
    public ?string $indexHandle = null; // null = applies to all indices
    public string $matchType = self::MATCH_EXACT;
    public string $matchValue = '';
    public string $actionType = self::ACTION_SYNONYM;
    public array $actionValue = []; // Decoded from JSON
    public int $priority = 0; // Higher = applied first
    public ?int $siteId = null;
    public bool $enabled = true;
    public ?\DateTime $dateCreated = null;
    public ?\DateTime $dateUpdated = null;
    public ?string $uid = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    public function rules(): array
    {
        return [
            [['name', 'matchValue', 'actionType'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['indexHandle'], 'string', 'max' => 255],
            [['matchValue'], 'string', 'max' => 500],
            [['matchType'], 'in', 'range' => [self::MATCH_EXACT, self::MATCH_CONTAINS, self::MATCH_PREFIX, self::MATCH_REGEX]],
            [['actionType'], 'in', 'range' => [
                self::ACTION_SYNONYM,
                self::ACTION_BOOST_SECTION,
                self::ACTION_BOOST_CATEGORY,
                self::ACTION_BOOST_ELEMENT,
                self::ACTION_FILTER,
                self::ACTION_REDIRECT,
            ]],
            [['priority', 'siteId'], 'integer'],
            [['enabled'], 'boolean'],
            [['actionValue'], 'validateActionValue'],
        ];
    }

    /**
     * Validate action value based on action type
     */
    public function validateActionValue(string $attribute): void
    {
        $value = $this->actionValue;

        switch ($this->actionType) {
            case self::ACTION_SYNONYM:
                if (empty($value['terms']) || !is_array($value['terms'])) {
                    $this->addError($attribute, 'Synonym action requires a "terms" array.');
                }
                break;

            case self::ACTION_BOOST_SECTION:
                if (empty($value['sectionHandle'])) {
                    $this->addError($attribute, 'Boost section action requires a "sectionHandle".');
                }
                if (!isset($value['multiplier']) || !is_numeric($value['multiplier'])) {
                    $this->addError($attribute, 'Boost section action requires a numeric "multiplier".');
                }
                break;

            case self::ACTION_BOOST_CATEGORY:
                if (empty($value['categoryId']) && empty($value['categoryHandle'])) {
                    $this->addError($attribute, 'Boost category action requires a "categoryId" or "categoryHandle".');
                }
                if (!isset($value['multiplier']) || !is_numeric($value['multiplier'])) {
                    $this->addError($attribute, 'Boost category action requires a numeric "multiplier".');
                }
                break;

            case self::ACTION_BOOST_ELEMENT:
                if (empty($value['elementId'])) {
                    $this->addError($attribute, 'Boost element action requires an "elementId".');
                }
                if (!isset($value['multiplier']) || !is_numeric($value['multiplier'])) {
                    $this->addError($attribute, 'Boost element action requires a numeric "multiplier".');
                }
                break;

            case self::ACTION_FILTER:
                if (empty($value['field']) || !isset($value['value'])) {
                    $this->addError($attribute, 'Filter action requires "field" and "value".');
                }
                break;

            case self::ACTION_REDIRECT:
                if (empty($value['url'])) {
                    $this->addError($attribute, 'Redirect action requires a "url".');
                }
                break;
        }
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Find rule by ID
     */
    public static function findById(int $id): ?self
    {
        $row = (new Query())
            ->from('{{%searchmanager_query_rules}}')
            ->where(['id' => $id])
            ->one();

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Find all rules for an index (including global rules)
     */
    public static function findByIndex(?string $indexHandle = null, ?int $siteId = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_query_rules}}')
            ->where(['enabled' => true])
            ->orderBy(['priority' => SORT_DESC]);

        if ($indexHandle) {
            $query->andWhere(['or', ['indexHandle' => null], ['indexHandle' => $indexHandle]]);
        }

        if ($siteId !== null) {
            $query->andWhere(['or', ['siteId' => null], ['siteId' => $siteId]]);
        }

        $rows = $query->all();
        return array_map([self::class, 'fromRow'], $rows);
    }

    /**
     * Find all rules (for CP listing)
     */
    public static function findAll(?string $indexHandle = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_query_rules}}')
            ->orderBy(['priority' => SORT_DESC, 'name' => SORT_ASC]);

        if ($indexHandle) {
            $query->where(['indexHandle' => $indexHandle]);
        }

        $rows = $query->all();
        return array_map([self::class, 'fromRow'], $rows);
    }

    /**
     * Find rules matching a search query
     */
    public static function findMatching(string $searchQuery, ?string $indexHandle = null, ?int $siteId = null): array
    {
        $searchQuery = mb_strtolower(trim($searchQuery));
        $rules = self::findByIndex($indexHandle, $siteId);
        $matches = [];

        foreach ($rules as $rule) {
            if ($rule->matches($searchQuery)) {
                $matches[] = $rule;
            }
        }

        // Already sorted by priority from query
        return $matches;
    }

    /**
     * Check if this rule matches a search query
     */
    public function matches(string $searchQuery): bool
    {
        $searchQuery = mb_strtolower(trim($searchQuery));
        $pattern = mb_strtolower(trim($this->matchValue));

        return match ($this->matchType) {
            self::MATCH_EXACT => $searchQuery === $pattern,
            self::MATCH_CONTAINS => str_contains($searchQuery, $pattern),
            self::MATCH_PREFIX => str_starts_with($searchQuery, $pattern),
            self::MATCH_REGEX => (bool)@preg_match("/$pattern/i", $searchQuery),
            default => false,
        };
    }

    /**
     * Create model from database row
     */
    private static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int)$row['id'];
        $model->name = $row['name'];
        $model->indexHandle = $row['indexHandle'];
        $model->matchType = $row['matchType'];
        $model->matchValue = $row['matchValue'];
        $model->actionType = $row['actionType'];
        $model->actionValue = json_decode($row['actionValue'], true) ?? [];
        $model->priority = (int)$row['priority'];
        $model->siteId = $row['siteId'] ? (int)$row['siteId'] : null;
        $model->enabled = (bool)$row['enabled'];
        $model->dateCreated = $row['dateCreated'] ? new \DateTime($row['dateCreated']) : null;
        $model->dateUpdated = $row['dateUpdated'] ? new \DateTime($row['dateUpdated']) : null;
        $model->uid = $row['uid'] ?? null;

        return $model;
    }

    /**
     * Save rule to database
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            $this->logError('Query rule validation failed', [
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $attributes = [
                'name' => $this->name,
                'indexHandle' => $this->indexHandle ?: null,
                'matchType' => $this->matchType,
                'matchValue' => $this->matchValue,
                'actionType' => $this->actionType,
                'actionValue' => json_encode($this->actionValue),
                'priority' => $this->priority,
                'siteId' => $this->siteId,
                'enabled' => (int)$this->enabled,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                // Update existing
                Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_query_rules}}', $attributes, ['id' => $this->id])
                    ->execute();
            } else {
                // Insert new
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_query_rules}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();
            }

            $this->logInfo('Query rule saved', [
                'id' => $this->id,
                'name' => $this->name,
                'actionType' => $this->actionType,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to save query rule', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete rule from database
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_query_rules}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->logInfo('Query rule deleted', [
                    'id' => $this->id,
                    'name' => $this->name,
                ]);
            }

            return $result > 0;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete query rule', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get synonyms for synonym action type
     */
    public function getSynonyms(): array
    {
        if ($this->actionType !== self::ACTION_SYNONYM) {
            return [];
        }

        return $this->actionValue['terms'] ?? [];
    }

    /**
     * Get boost multiplier for boost action types
     */
    public function getBoostMultiplier(): float
    {
        return (float)($this->actionValue['multiplier'] ?? 1.0);
    }

    /**
     * Get redirect URL for redirect action type
     */
    public function getRedirectUrl(): ?string
    {
        if ($this->actionType !== self::ACTION_REDIRECT) {
            return null;
        }

        return $this->actionValue['url'] ?? null;
    }

    /**
     * Check if this is a redirect rule
     */
    public function isRedirect(): bool
    {
        return $this->actionType === self::ACTION_REDIRECT;
    }

    /**
     * Get human-readable action description
     */
    public function getActionDescription(): string
    {
        return match ($this->actionType) {
            self::ACTION_SYNONYM => 'Synonyms: ' . implode(', ', $this->getSynonyms()),
            self::ACTION_BOOST_SECTION => 'Boost section "' . ($this->actionValue['sectionHandle'] ?? '') . '" ×' . $this->getBoostMultiplier(),
            self::ACTION_BOOST_CATEGORY => 'Boost category ×' . $this->getBoostMultiplier(),
            self::ACTION_BOOST_ELEMENT => 'Boost element #' . ($this->actionValue['elementId'] ?? '') . ' ×' . $this->getBoostMultiplier(),
            self::ACTION_FILTER => 'Filter: ' . ($this->actionValue['field'] ?? '') . ' = ' . ($this->actionValue['value'] ?? ''),
            self::ACTION_REDIRECT => 'Redirect to ' . $this->getRedirectUrl(),
            default => 'Unknown action',
        };
    }

    /**
     * Get available action types for dropdown
     */
    public static function getActionTypes(): array
    {
        return [
            self::ACTION_SYNONYM => 'Synonyms',
            self::ACTION_BOOST_SECTION => 'Boost Section',
            self::ACTION_BOOST_CATEGORY => 'Boost Category',
            self::ACTION_BOOST_ELEMENT => 'Boost Element',
            self::ACTION_FILTER => 'Filter Results',
            self::ACTION_REDIRECT => 'Redirect',
        ];
    }

    /**
     * Get available match types for dropdown
     */
    public static function getMatchTypes(): array
    {
        return [
            self::MATCH_EXACT => 'Exact Match',
            self::MATCH_CONTAINS => 'Contains',
            self::MATCH_PREFIX => 'Starts With',
            self::MATCH_REGEX => 'Regex',
        ];
    }
}
