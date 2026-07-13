<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\UrlSafetyHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\TargetElementTypeHelper;

/**
 * Query Rule Model
 *
 * Represents a query rule for synonyms, boosts, and redirects.
 * Rules modify search behavior when queries match specific patterns.
 *
 * @since 5.10.0
 */
class QueryRule extends Model
{
    use LoggingTrait;

    private const MAX_REGEX_PATTERN_LENGTH = 500;
    private const MAX_REGEX_SUBJECT_LENGTH = 256;
    private const REGEX_MATCH_LIMIT = 10000;
    private const REGEX_RECURSION_LIMIT = 1000;

    // Action types
    public const ACTION_SYNONYM = 'synonym';

    public const ACTION_BOOST_SECTION = 'boost_section';

    public const ACTION_BOOST_CATEGORY = 'boost_category';

    public const ACTION_BOOST_ELEMENT = 'boost_element';

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

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /** @inheritdoc */
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
                self::ACTION_REDIRECT,
            ]],
            [['priority', 'siteId'], 'integer'],
            [['priority'], 'integer', 'min' => -10, 'max' => 10],
            [['enabled'], 'boolean'],
            [['actionValue'], 'validateActionValue'],
            [['matchValue'], 'validateRegexPattern', 'when' => fn() => $this->matchType === self::MATCH_REGEX],
        ];
    }

    /** @inheritdoc */
    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('search-manager', 'Name'),
            'indexHandle' => Craft::t('search-manager', 'Index'),
            'matchType' => Craft::t('search-manager', 'Match Type'),
            'matchValue' => Craft::t('search-manager', 'Match Pattern'),
            'actionType' => Craft::t('search-manager', 'Action Type'),
            'actionValue' => Craft::t('search-manager', 'Action'),
            'priority' => Craft::t('search-manager', 'Priority'),
            'siteId' => Craft::t('search-manager', 'Site'),
            'enabled' => Craft::t('search-manager', 'Enabled'),
        ];
    }

    /**
     * Validate action value based on action type
     *
     * @param string $attribute
     */
    public function validateActionValue(string $attribute): void
    {
        $value = $this->actionValue;

        switch ($this->actionType) {
            case self::ACTION_SYNONYM:
                if (empty($value['terms']) || !is_array($value['terms'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Synonym action requires at least one term.'));
                } elseif (count($value['terms']) > 10) {
                    $this->addError($attribute, Craft::t('search-manager', 'A synonym rule can have a maximum of 10 terms. You have {count}.', [
                        'count' => count($value['terms']),
                    ]));
                }
                break;

            case self::ACTION_BOOST_SECTION:
                if (empty($value['sectionHandle'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Boost section action requires a "sectionHandle".'));
                }
                if (!isset($value['multiplier']) || !is_numeric($value['multiplier'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Boost section action requires a numeric "multiplier".'));
                }
                break;

            case self::ACTION_BOOST_CATEGORY:
                if (empty($value['categoryId']) && empty($value['categoryHandle'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Boost category action requires a "categoryId" or "categoryHandle".'));
                }
                if (!isset($value['multiplier']) || !is_numeric($value['multiplier'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Boost category action requires a numeric "multiplier".'));
                }
                break;

            case self::ACTION_BOOST_ELEMENT:
                if (empty($value['elementId'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Boost element action requires an "elementId".'));
                }
                if (!empty($value['elementType']) && !TargetElementTypeHelper::isSupportedElementType((string)$value['elementType'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Element not found.'));
                }
                if (!isset($value['multiplier']) || !is_numeric($value['multiplier'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Boost element action requires a numeric "multiplier".'));
                }
                break;

            case self::ACTION_REDIRECT:
                // Either URL or element must be provided
                $hasUrl = !empty($value['url']);
                $hasElement = !empty($value['elementId']) && !empty($value['elementType']);
                if (!$hasUrl && !$hasElement) {
                    $this->addError($attribute, Craft::t('search-manager', 'Redirect action requires a URL or an element.'));
                }
                if ($hasElement && !TargetElementTypeHelper::isSupportedElementType((string)$value['elementType'])) {
                    $this->addError($attribute, Craft::t('search-manager', 'Element not found.'));
                }
                // Validate URL protocol when URL-based redirect
                if ($hasUrl) {
                    $url = trim($value['url']);
                    if (!UrlSafetyHelper::isSafeRedirectUrl($url)) {
                        $this->addError($attribute, Craft::t('search-manager', 'Redirect URL must start with https://, http://, or / (relative path).'));
                    }
                }
                break;
        }
    }

    /**
     * Validate that the match value is a valid regex pattern
     *
     * @param string $attribute
     */
    public function validateRegexPattern(string $attribute): void
    {
        $pattern = trim($this->matchValue);
        if ($pattern === '') {
            return;
        }

        if (mb_strlen($pattern) > self::MAX_REGEX_PATTERN_LENGTH) {
            $this->addError($attribute, Craft::t('search-manager', 'Regex pattern must be 500 characters or less.'));
            return;
        }

        // Escape delimiter character and test compile — use a short subject to avoid side effects
        $compiledPattern = self::compileAdminRegex($pattern);
        if (@preg_match($compiledPattern, '') === false) {
            $this->addError($attribute, Craft::t('search-manager', 'Invalid regex pattern: {error}', [
                'error' => preg_last_error_msg(),
            ]));
            return;
        }

        if (!self::regexPassesSafetyProbe($compiledPattern)) {
            $this->addError($attribute, Craft::t('search-manager', 'Invalid regex pattern: {error}', [
                'error' => preg_last_error_msg() ?: 'pattern exceeds safe complexity limits',
            ]));
        }
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Find rule by ID
     *
     * @param int $id
     * @return self|null
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
     *
     * @param string|null $indexHandle
     * @param int|null $siteId
     * @return self[]
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
     *
     * @param string|null $indexHandle
     * @return self[]
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
     *
     * @param string $searchQuery
     * @param string|null $indexHandle
     * @param int|null $siteId
     * @return self[]
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
     * Supports comma-separated patterns for exact/contains/prefix (not regex)
     *
     * @param string $searchQuery
     * @return bool
     */
    public function matches(string $searchQuery): bool
    {
        $searchQuery = mb_strtolower(trim($searchQuery));

        // Regex doesn't support comma-separated (commas could be in pattern)
        if ($this->matchType === self::MATCH_REGEX) {
            $pattern = trim($this->matchValue);

            // Reject excessively long patterns
            if (mb_strlen($pattern) > self::MAX_REGEX_PATTERN_LENGTH) {
                $this->logWarning('Query rule regex too long', [
                    'ruleId' => $this->id,
                    'length' => mb_strlen($pattern),
                ]);
                return false;
            }

            $compiledPattern = self::compileAdminRegex($pattern);
            $searchQuery = mb_substr($searchQuery, 0, self::MAX_REGEX_SUBJECT_LENGTH);
            $result = @preg_match($compiledPattern, $searchQuery);

            if ($result === false) {
                $this->logWarning('Query rule regex failed', [
                    'ruleId' => $this->id,
                    'pattern' => $compiledPattern,
                    'error' => preg_last_error_msg(),
                ]);
                return false;
            }

            return (bool) $result;
        }

        // Split by comma and check each pattern
        $patterns = array_map('trim', explode(',', $this->matchValue));

        foreach ($patterns as $pattern) {
            $pattern = mb_strtolower($pattern);
            if (empty($pattern)) {
                continue;
            }

            $matched = match ($this->matchType) {
                self::MATCH_EXACT => $searchQuery === $pattern,
                self::MATCH_CONTAINS => str_contains($searchQuery, $pattern),
                self::MATCH_PREFIX => str_starts_with($searchQuery, $pattern),
                default => false,
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    private static function compileAdminRegex(string $pattern): string
    {
        $pattern = str_replace('~', '\\~', $pattern);

        return sprintf('~(*LIMIT_MATCH=%d)(*LIMIT_RECURSION=%d)%s~i', self::REGEX_MATCH_LIMIT, self::REGEX_RECURSION_LIMIT, $pattern);
    }

    private static function regexPassesSafetyProbe(string $compiledPattern): bool
    {
        $subjects = [
            '',
            str_repeat('a', self::MAX_REGEX_SUBJECT_LENGTH),
            str_repeat('a', self::MAX_REGEX_SUBJECT_LENGTH) . '!',
        ];

        foreach ($subjects as $subject) {
            if (@preg_match($compiledPattern, $subject) === false) {
                return false;
            }
        }

        return true;
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
        $model->dateCreated = self::parseDate($row['dateCreated'] ?? null);
        $model->dateUpdated = self::parseDate($row['dateUpdated'] ?? null);
        $model->uid = $row['uid'] ?? null;

        return $model;
    }

    private static function parseDate(mixed $value): ?\DateTime
    {
        if (empty($value)) {
            return null;
        }

        try {
            return new \DateTime((string)$value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Save rule to database
     *
     * @return bool
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
     *
     * @return bool
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
     *
     * @return string[]
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
     *
     * @return float
     */
    public function getBoostMultiplier(): float
    {
        return (float)($this->actionValue['multiplier'] ?? 1.0);
    }

    /**
     * Get redirect URL for redirect action type
     * Resolves element URLs if an element is linked
     *
     * @param int|null $siteId Optional site ID - if provided, will get element URL for that site
     * @return string|null
     */
    public function getRedirectUrl(?int $siteId = null): ?string
    {
        if ($this->actionType !== self::ACTION_REDIRECT) {
            return null;
        }

        // Check for custom URL first
        if (!empty($this->actionValue['url'])) {
            return $this->actionValue['url'];
        }

        // This is the single search-time live lookup exception: redirects may target valid content outside any search index,
        // and resolving the URL does not shape public hit fields.
        if (!empty($this->actionValue['elementId']) && !empty($this->actionValue['elementType'])) {
            $elementType = $this->actionValue['elementType'];
            $elementId = (int)$this->actionValue['elementId'];

            /** @var \craft\base\Element|null $element */
            $element = \Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);

            if ($element) {
                return $element->getUrl();
            }
        }

        return null;
    }

    /**
     * Check if this is a redirect rule
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->actionType === self::ACTION_REDIRECT;
    }

    /**
     * Get human-readable action description
     *
     * @return string
     */
    public function getActionDescription(?string $redirectUrl = null): string
    {
        return match ($this->actionType) {
            self::ACTION_SYNONYM => Craft::t('search-manager', 'Synonyms: {terms}', [
                'terms' => implode(', ', $this->getSynonyms()),
            ]),
            self::ACTION_BOOST_SECTION => Craft::t('search-manager', 'Boost section "{section}" ×{multiplier}', [
                'section' => $this->actionValue['sectionHandle'] ?? '',
                'multiplier' => $this->getBoostMultiplier(),
            ]),
            self::ACTION_BOOST_CATEGORY => Craft::t('search-manager', 'Boost category ×{multiplier}', [
                'multiplier' => $this->getBoostMultiplier(),
            ]),
            self::ACTION_BOOST_ELEMENT => Craft::t('search-manager', 'Boost element #{elementId} ×{multiplier}', [
                'elementId' => $this->actionValue['elementId'] ?? '',
                'multiplier' => $this->getBoostMultiplier(),
            ]),
            self::ACTION_REDIRECT => Craft::t('search-manager', 'Redirect to {url}', [
                'url' => $redirectUrl ?? $this->getRedirectUrl(),
            ]),
            default => Craft::t('search-manager', 'Unknown action'),
        };
    }

    /**
     * Get available action types for dropdown
     *
     * @return array<string, string>
     */
    public static function getActionTypes(): array
    {
        return [
            self::ACTION_SYNONYM => Craft::t('search-manager', 'Synonyms'),
            self::ACTION_BOOST_SECTION => Craft::t('search-manager', 'Boost Section'),
            self::ACTION_BOOST_CATEGORY => Craft::t('search-manager', 'Boost Category'),
            self::ACTION_BOOST_ELEMENT => Craft::t('search-manager', 'Boost Element'),
            self::ACTION_REDIRECT => Craft::t('search-manager', 'Redirect'),
        ];
    }

    /**
     * Get available match types for dropdown
     *
     * @return array<string, string>
     */
    public static function getMatchTypes(): array
    {
        return [
            self::MATCH_EXACT => Craft::t('search-manager', 'Exact Match'),
            self::MATCH_CONTAINS => Craft::t('search-manager', 'Contains'),
            self::MATCH_PREFIX => Craft::t('search-manager', 'Starts With'),
            self::MATCH_REGEX => Craft::t('search-manager', 'Regex'),
        ];
    }
}
