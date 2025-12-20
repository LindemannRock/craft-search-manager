<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Promotion Model
 *
 * Represents a pinned/promoted search result that bypasses normal scoring.
 * When a query matches, the promoted element is placed at the specified position.
 */
class Promotion extends Model
{
    use LoggingTrait;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;
    public ?string $indexHandle = null; // null = applies to all indices
    public ?string $title = null;
    public string $query = '';
    public string $matchType = 'exact'; // exact, contains, prefix
    public int $elementId = 0;
    public int $position = 1; // 1 = first position
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
            [['query', 'elementId'], 'required'],
            [['indexHandle', 'query'], 'string', 'max' => 500],
            [['title'], 'string', 'max' => 255],
            [['matchType'], 'in', 'range' => ['exact', 'contains', 'prefix']],
            [['elementId', 'position', 'siteId'], 'integer'],
            [['position'], 'integer', 'min' => 1],
            [['enabled'], 'boolean'],
            [['elementId'], 'validateElement'],
        ];
    }

    /**
     * Validate that element exists
     */
    public function validateElement(string $attribute): void
    {
        if (empty($this->elementId)) {
            return;
        }

        $element = Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId);
        if (!$element) {
            $this->addError($attribute, 'Element not found.');
        }
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Find promotion by ID
     */
    public static function findById(int $id): ?self
    {
        $row = (new Query())
            ->from('{{%searchmanager_promotions}}')
            ->where(['id' => $id])
            ->one();

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Find all promotions for an index (including global promotions)
     */
    public static function findByIndex(?string $indexHandle = null, ?int $siteId = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_promotions}}')
            ->where(['enabled' => 1])
            ->orderBy(['position' => SORT_ASC]);

        // Include promotions for this specific index OR global promotions (null indexHandle)
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
     * Find all promotions (for CP listing)
     */
    public static function findAll(?string $indexHandle = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_promotions}}')
            ->orderBy(['indexHandle' => SORT_ASC, 'position' => SORT_ASC]);

        if ($indexHandle) {
            $query->where(['indexHandle' => $indexHandle]);
        }

        $rows = $query->all();
        return array_map([self::class, 'fromRow'], $rows);
    }

    /**
     * Find promotions matching a search query
     * Only returns promotions where the element is enabled for the given site
     */
    public static function findMatching(string $searchQuery, string $indexHandle, ?int $siteId = null): array
    {
        $searchQuery = mb_strtolower(trim($searchQuery));
        $promotions = self::findByIndex($indexHandle, $siteId);

        $checkSiteId = $siteId ?? \Craft::$app->getSites()->getCurrentSite()->id;

        // Filter promotions that match the query pattern
        $queryMatches = [];
        $elementIds = [];
        foreach ($promotions as $promotion) {
            if ($promotion->matches($searchQuery)) {
                $queryMatches[] = $promotion;
                $elementIds[] = $promotion->elementId;
            }
        }

        if (empty($queryMatches)) {
            return [];
        }

        // Batch query: get all live elements in one query
        $liveElements = \craft\elements\Entry::find()
            ->id($elementIds)
            ->siteId($checkSiteId)
            ->status('live')
            ->indexBy('id')
            ->all();

        // Filter to only promotions with live elements
        $matches = [];
        foreach ($queryMatches as $promotion) {
            if (isset($liveElements[$promotion->elementId])) {
                $matches[] = $promotion;
            }
        }

        // Sort by position
        usort($matches, fn($a, $b) => $a->position <=> $b->position);

        return $matches;
    }

    /**
     * Check if this promotion matches a search query
     */
    public function matches(string $searchQuery): bool
    {
        $searchQuery = mb_strtolower(trim($searchQuery));
        $pattern = mb_strtolower(trim($this->query));

        return match ($this->matchType) {
            'exact' => $searchQuery === $pattern,
            'contains' => str_contains($searchQuery, $pattern),
            'prefix' => str_starts_with($searchQuery, $pattern),
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
        $model->indexHandle = $row['indexHandle'];
        $model->title = $row['title'] ?? null;
        $model->query = $row['query'];
        $model->matchType = $row['matchType'];
        $model->elementId = (int)$row['elementId'];
        $model->position = (int)$row['position'];
        $model->siteId = $row['siteId'] ? (int)$row['siteId'] : null;
        $model->enabled = (bool)$row['enabled'];
        $model->dateCreated = $row['dateCreated'] ? new \DateTime($row['dateCreated']) : null;
        $model->dateUpdated = $row['dateUpdated'] ? new \DateTime($row['dateUpdated']) : null;
        $model->uid = $row['uid'] ?? null;

        return $model;
    }

    /**
     * Save promotion to database
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            $this->logError('Promotion validation failed', [
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $attributes = [
                'indexHandle' => $this->indexHandle ?: null,
                'title' => $this->title,
                'query' => $this->query,
                'matchType' => $this->matchType,
                'elementId' => $this->elementId,
                'position' => $this->position,
                'siteId' => $this->siteId,
                'enabled' => (int)$this->enabled,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                // Update existing
                Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_promotions}}', $attributes, ['id' => $this->id])
                    ->execute();
            } else {
                // Insert new
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_promotions}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();
            }

            $this->logInfo('Promotion saved', [
                'id' => $this->id,
                'query' => $this->query,
                'elementId' => $this->elementId,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to save promotion', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete promotion from database
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_promotions}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->logInfo('Promotion deleted', [
                    'id' => $this->id,
                    'query' => $this->query,
                ]);
            }

            return $result > 0;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete promotion', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get the promoted element (for CP display)
     */
    public function getElement(): ?\craft\base\ElementInterface
    {
        return Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId);
    }
}
