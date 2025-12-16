<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\services\LoggingService;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Search Index Model
 *
 * Represents a search index configuration
 * Can be defined in config file OR database (hybrid approach)
 * Database-backed model ({{%searchmanager_indices}} table)
 */
class SearchIndex extends Model
{
    use LoggingTrait;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $elementType = '';
    public ?int $siteId = null;

    /**
     * @var array Decoded from criteriaJson
     */
    public array $criteria = [];

    public string $transformerClass = '';

    /**
     * @var string|null Language code (en, ar, fr, es, de) - null = auto-detect from site
     */
    public ?string $language = null;

    public bool $enabled = true;

    /**
     * @var string Source (config|database)
     */
    public string $source = 'database';
    public ?\DateTime $lastIndexed = null;
    public int $documentCount = 0;
    public int $sortOrder = 0;

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
            [['name', 'handle', 'elementType'], 'required'],
            [['name', 'handle', 'elementType', 'transformerClass'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z0-9_-]+$/'],
            [['language'], 'string', 'max' => 10],
            [['language'], 'match', 'pattern' => '/^[a-z]{2}(-[a-z]{2})?$/i', 'skipOnEmpty' => true, 'message' => 'Language must be a valid language code (e.g., en, ar, fr-ca)'],
            [['enabled'], 'boolean'],
            [['siteId', 'documentCount', 'sortOrder'], 'integer'],
            [['source'], 'in', 'range' => ['config', 'database']],
            [['criteria'], 'safe'],
        ];
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Find index by ID
     */
    public static function findById(int $id): ?self
    {
        try {
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['id' => $id])
                ->one();

            if (!$row) {
                return null;
            }

            return self::fromRow($row);
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find index by handle
     */
    public static function findByHandle(string $handle): ?self
    {
        try {
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handle])
                ->one();

            if (!$row) {
                return null;
            }

            return self::fromRow($row);
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get all indices (database only)
     */
    public static function findAll(): array
    {
        try {
            $rows = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            $indices = [];
            foreach ($rows as $row) {
                $indices[] = self::fromRow($row);
            }

            return $indices;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load indices', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create model from database row
     */
    private static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int)$row['id'];
        $model->name = $row['name'];
        $model->handle = $row['handle'];
        $model->elementType = $row['elementType'];
        $model->siteId = $row['siteId'] ? (int)$row['siteId'] : null;
        $model->criteria = json_decode($row['criteriaJson'], true) ?? [];
        $model->transformerClass = $row['transformerClass'];
        $model->language = $row['language'] ?? null;
        $model->enabled = (bool)$row['enabled'];
        $model->source = $row['source'];
        $model->lastIndexed = $row['lastIndexed'] ? new \DateTime($row['lastIndexed']) : null;
        $model->documentCount = (int)$row['documentCount'];
        $model->sortOrder = (int)$row['sortOrder'];

        return $model;
    }

    /**
     * Save index to database
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            $this->logError('Index validation failed', [
                'handle' => $this->handle ?? 'unknown',
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $attributes = [
                'name' => $this->name,
                'handle' => $this->handle,
                'elementType' => $this->elementType,
                'siteId' => $this->siteId,
                'criteriaJson' => json_encode($this->criteria),
                'transformerClass' => $this->transformerClass,
                'language' => $this->language,
                'enabled' => (int)$this->enabled,
                'source' => $this->source,
                'lastIndexed' => $this->lastIndexed ? Db::prepareDateForDb($this->lastIndexed) : null,
                'documentCount' => $this->documentCount,
                'sortOrder' => $this->sortOrder,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                // Update existing
                $result = Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_indices}}', $attributes, ['id' => $this->id])
                    ->execute();

                return $result !== false;
            } else {
                // Insert new
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_indices}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();

                return true;
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to save index', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete index from database
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => $this->id])
                ->execute();

            return $result > 0;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete index', [
                'id' => $this->id,
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update last indexed timestamp and document count
     */
    public function updateStats(int $documentCount): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            $this->lastIndexed = new \DateTime();
            $this->documentCount = $documentCount;

            $result = Craft::$app->getDb()
                ->createCommand()
                ->update(
                    '{{%searchmanager_indices}}',
                    [
                        'lastIndexed' => Db::prepareDateForDb($this->lastIndexed),
                        'documentCount' => $this->documentCount,
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ],
                    ['id' => $this->id]
                )
                ->execute();

            return $result !== false;
        } catch (\Throwable $e) {
            $this->logError('Failed to update index stats', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Convert to config array format (for export)
     */
    public function toConfigArray(): array
    {
        return [
            'name' => $this->name,
            'elementType' => $this->elementType,
            'siteId' => $this->siteId,
            'criteria' => $this->criteria,
            'transformer' => $this->transformerClass,
            'language' => $this->language,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Check if index is from config file
     */
    public function isFromConfig(): bool
    {
        return $this->source === 'config';
    }

    /**
     * Check if index can be edited
     */
    public function canEdit(): bool
    {
        return $this->source === 'database';
    }
}
