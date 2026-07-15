<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\services\LoggingService;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\RedisConnectionHelper;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchIndexCriteriaHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\traits\ConfigSourceTrait;

/**
 * Search Index Model
 *
 * Represents a search index configuration
 * Can be defined in config file OR database (hybrid approach)
 * Database-backed model ({{%searchmanager_indices}} table)
 *
 * @since 5.0.0
 */
class SearchIndex extends Model
{
    use LoggingTrait;
    use ConfigSourceTrait;

    private const PLUGIN_HANDLE = 'search-manager';

    /**
     * @var self[]|null Request-scoped index cache.
     */
    private static ?array $allCache = null;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;

    public string $name = '';

    public string $handle = '';

    public string $elementType = '';

    public int|array|null $siteId = null;

    /**
     * @var array|\Closure Decoded from criteria (array) or callable from config (Closure)
     */
    public array|\Closure $criteria = [];

    public ?string $transformerClass = null;
    /**
     * @var array<int>|null Heading levels to extract (e.g. [2,3,4])
     */
    public ?array $headingLevels = null;

    /**
     * @var string|null Language code (en, ar, fr, es, de) - null = auto-detect from site
     */
    public ?string $language = null;

    /**
     * @var string|null Handle of configured backend to use - null means use global default from settings
     */
    public ?string $backend = null;

    public bool $enabled = true;

    /**
     * @var bool Whether to track analytics for searches on this index
     */
    public bool $enableAnalytics = true;

    /**
     * @var bool Whether to disable stop words for this index
     */
    public bool $disableStopWords = false;

    /**
     * @var bool Whether to skip indexing entries that don't have a URL
     */
    public bool $skipEntriesWithoutUrl = false;

    /**
     * @var bool Whether eligible SourceDoc pages are indexed as section records.
     */
    public bool $splitSections = false;

    /**
     * Custom field handles returned under public hit `fields`.
     *
     * `['*']` returns every public `_fields` value, `['*', '-body']`
     * returns every public `_fields` value except `body`, `[]` returns none,
     * and any other list is an explicit handle allowlist.
     *
     * @var list<string>
     * @since 5.53.0
     */
    public array $retrievableFields = ['*'];

    public ?\DateTime $lastIndexed = null;

    public ?\DateTime $dateCreated = null;

    public ?\DateTime $dateUpdated = null;

    /**
     * @var int Number of documents in the index.
     *
     * **Eventually consistent.** As of 5.45.0, automatic save/delete syncs
     * (`PendingSyncProcessor`, batch path) deliberately do not increment or
     * decrement this counter — doing so would require a per-row
     * `documentExists` probe to the backend, which would re-introduce the
     * API amplification L3 set out to eliminate.
     *
     * Accurate values come from:
     *   - Full rebuild (`SearchIndex::updateStats()`)
     *   - Explicit recount actions exposed in the CP / console
     *
     * Treat this as advisory metadata for operators, not as a correctness
     * signal for search behaviour.
     */
    public int $documentCount = 0;

    private bool $rebuildQueuedOnLastSave = false;

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
            [['name', 'handle', 'elementType'], 'required'],
            [['name', 'handle', 'elementType', 'transformerClass'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => Craft::t('search-manager', 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.')],
            [['handle'], 'validateUniqueHandle'],
            [['language'], 'string', 'max' => 10],
            [['language'], 'match', 'pattern' => '/^[a-z]{2}(-[a-z]{2})?$/i', 'skipOnEmpty' => true, 'message' => Craft::t('search-manager', 'Language must be a valid language code (e.g., en, ar, fr-ca)')],
            [['backend'], 'string', 'max' => 255],
            [['backend'], 'validateBackendHandle'],
            [['enabled', 'enableAnalytics', 'disableStopWords', 'skipEntriesWithoutUrl', 'splitSections'], 'boolean'],
            [['splitSections'], 'validateSplitSectionsSupport'],
            [['splitSections'], 'validateSplitSectionsStorage'],
            [['retrievableFields'], 'validateRetrievableFields'],
            [['documentCount'], 'integer'],
            [['siteId'], 'validateSiteId'],
            [['source'], 'in', 'range' => ['config', 'database']],
            [['criteria'], 'safe'],
            [['headingLevels'], 'validateHeadingLevels'],
            [['transformerClass'], 'validateTransformerClass'],
        ];
    }

    /** @inheritdoc */
    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('search-manager', 'Name'),
            'handle' => Craft::t('search-manager', 'Handle'),
            'elementType' => Craft::t('search-manager', 'Element Type'),
            'siteId' => Craft::t('search-manager', 'Sites'),
            'transformerClass' => Craft::t('search-manager', 'Transformer Class'),
            'headingLevels' => Craft::t('search-manager', 'Heading Levels'),
            'language' => Craft::t('search-manager', 'Language (Search Processing)'),
            'backend' => Craft::t('search-manager', 'Search Backend'),
            'enabled' => Craft::t('search-manager', 'Enabled'),
            'enableAnalytics' => Craft::t('search-manager', 'Track Analytics'),
            'disableStopWords' => Craft::t('search-manager', 'Disable Stop Words'),
            'skipEntriesWithoutUrl' => Craft::t('search-manager', 'Skip Entries Without URL'),
            'splitSections' => Craft::t('search-manager', 'Split Sections'),
            'retrievableFields' => Craft::t('search-manager', 'Retrievable Fields'),
            'documentCount' => Craft::t('search-manager', 'Documents'),
            'source' => Craft::t('search-manager', 'Source'),
        ];
    }

    /**
     * Validate handle is unique among database-backed indices.
     */
    public function validateUniqueHandle(string $attribute): void
    {
        if (SlugHandleHelper::exists('{{%searchmanager_indices}}', 'handle', $this->handle, [
            'excludeId' => $this->id,
        ])) {
            $this->addError($attribute, Craft::t('search-manager', 'Handle must be unique.'));
        }
    }

    /**
     * Validate transformer class exists and matches the runtime contract.
     */
    public function validateTransformerClass($attribute): void
    {
        $transformerClass = trim((string) $this->$attribute);

        if ($transformerClass === '') {
            return; // Null/empty is allowed
        }

        // Validate format: must look like a PHP fully-qualified class name
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $transformerClass)) {
            $this->addError($attribute, Craft::t('search-manager', 'Transformer class must be a valid PHP class name (e.g., modules\\search\\transformers\\MyTransformer).'));
            return;
        }

        // Check if class exists
        if (!class_exists($transformerClass)) {
            $this->addError($attribute, Craft::t('search-manager', 'Transformer class does not exist: {class}', [
                'class' => $transformerClass,
            ]));
            $this->logWarning('Invalid transformer class in config', [
                'handle' => $this->handle,
                'transformer' => $transformerClass,
            ]);
            return;
        }

        $contractError = self::transformerClassContractError($transformerClass);
        if ($contractError !== null) {
            $this->addError($attribute, $contractError);
            $this->logWarning('Invalid transformer class contract in config', [
                'handle' => $this->handle,
                'transformer' => $transformerClass,
            ]);
        }
    }

    /**
     * Return the CP-facing validation error for a configured transformer class.
     */
    private static function transformerClassContractError(string $transformerClass): ?string
    {
        if (!is_subclass_of($transformerClass, TransformerInterface::class)) {
            return Craft::t('search-manager', 'Transformer class must implement TransformerInterface: {class}', [
                'class' => $transformerClass,
            ]);
        }

        $reflection = new \ReflectionClass($transformerClass);
        $constructor = $reflection->getConstructor();

        if (!$reflection->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            return Craft::t('search-manager', 'Transformer class must be constructible without arguments: {class}', [
                'class' => $transformerClass,
            ]);
        }

        return null;
    }

    /**
     * Validate a config transformer value before storing config metadata.
     */
    private function validateConfigTransformerClass(?string $transformerClass): bool
    {
        if ($transformerClass === null || trim($transformerClass) === '') {
            return true;
        }

        $model = new self();
        $model->handle = $this->handle;
        $model->transformerClass = $transformerClass;
        $model->validateTransformerClass('transformerClass');

        foreach ($model->getErrors('transformerClass') as $error) {
            $this->logError('Invalid transformer class in config', [
                'handle' => $this->handle,
                'transformer' => $transformerClass,
                'error' => $error,
            ]);
        }

        return !$model->hasErrors('transformerClass');
    }

    /**
     * Validate siteId (int, array of ints, or null)
     */
    public function validateSiteId($attribute): void
    {
        $value = $this->$attribute;

        if ($value === null || $value === '') {
            $this->$attribute = null;
            return;
        }

        if (is_array($value)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $value), fn($id) => $id > 0)));
            if (empty($ids)) {
                $this->addError($attribute, Craft::t('search-manager', 'siteId array must contain at least one valid site ID.'));
                return;
            }

            $this->$attribute = $ids;
            return;
        }

        if (is_numeric($value)) {
            $this->$attribute = (int)$value;
            return;
        }

        $this->addError($attribute, Craft::t('search-manager', 'siteId must be an integer, an array of integers, or null.'));
    }

    /**
     * Validate backend handle exists and is enabled when provided.
     */
    public function validateBackendHandle(string $attribute): void
    {
        $handle = $this->$attribute;
        if ($handle === null || $handle === '') {
            return;
        }

        $backend = ConfiguredBackend::findByHandle($handle);
        if (!$backend) {
            $this->addError($attribute, Craft::t('search-manager', 'Selected backend does not exist.'));
            return;
        }

        if (!$backend->enabled) {
            $this->addError($attribute, Craft::t('search-manager', 'Selected backend is disabled.'));
        }
    }

    /**
     * Validate heading levels are integers between 1 and 6.
     */
    public function validateHeadingLevels(string $attribute): void
    {
        if ($this->$attribute === null) {
            return;
        }

        if (!is_array($this->$attribute)) {
            $this->addError($attribute, Craft::t('search-manager', 'Heading levels must be an array.'));
            return;
        }

        $normalized = [];
        foreach ($this->$attribute as $level) {
            if (!is_numeric($level)) {
                $this->addError($attribute, Craft::t('search-manager', 'Heading levels must be numbers between 1 and 6.'));
                return;
            }
            $intLevel = (int)$level;
            if ($intLevel < 1 || $intLevel > 6) {
                $this->addError($attribute, Craft::t('search-manager', 'Heading levels must be between 1 and 6.'));
                return;
            }
            $normalized[] = $intLevel;
        }

        $this->$attribute = array_values(array_unique($normalized));
    }

    /**
     * Normalize the public fields allowlist.
     *
     * @since 5.53.0
     */
    public function validateRetrievableFields(string $attribute): void
    {
        $this->$attribute = self::normalizeRetrievableFields($this->$attribute);
        if (self::hasRetrievableFieldExclusions($this->$attribute) && !self::hasRetrievableFieldWildcard($this->$attribute)) {
            $this->addError($attribute, Craft::t('search-manager', 'Retrievable field exclusions (for example -wysiwyg) can only be used with *.'));
        }
    }

    /**
     * Normalize an index-level retrievableFields setting.
     *
     * @return list<string>
     * @since 5.53.0
     */
    public static function normalizeRetrievableFields(mixed $value): array
    {
        if ($value === null) {
            return ['*'];
        }

        if (is_string($value)) {
            $value = str_replace(["\r\n", "\r", "\n"], ',', $value);
            $value = array_map('trim', explode(',', $value));
        }

        if (!is_array($value)) {
            return ['*'];
        }

        $wildcard = false;
        $handles = [];
        $exclusions = [];
        foreach ($value as $handle) {
            if (!is_scalar($handle)) {
                continue;
            }

            $handle = trim((string)$handle);
            if ($handle === '') {
                continue;
            }

            if ($handle === '*') {
                $wildcard = true;
                continue;
            }

            if (preg_match('/^-[a-zA-Z][a-zA-Z0-9_:-]*$/', $handle)) {
                $exclusions[] = $handle;
                continue;
            }

            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_:-]*$/', $handle)) {
                continue;
            }

            $handles[] = $handle;
        }

        $exclusions = array_values(array_unique($exclusions));
        if ($wildcard) {
            return array_merge(['*'], $exclusions);
        }

        return array_values(array_unique(array_merge($handles, $exclusions)));
    }

    /**
     * Build the effective public fields allowlist for an index and optional request.
     *
     * Request-level retrievableFields can narrow the index allowlist but never
     * widen it.
     *
     * @param list<string>|null $requested
     * @return list<string>
     * @since 5.53.0
     */
    public function effectiveRetrievableFields(?array $requested = null): array
    {
        return self::narrowRetrievableFields($this->retrievableFields, $requested);
    }

    /**
     * @param list<string> $indexFields
     * @param list<string>|null $requested
     * @return list<string>
     * @since 5.53.0
     */
    public static function narrowRetrievableFields(array $indexFields, ?array $requested): array
    {
        $indexFields = self::normalizeRetrievableFields($indexFields);
        if ($requested === null) {
            return $indexFields;
        }

        $requested = self::normalizeRetrievableFields($requested);
        if ($indexFields === []) {
            return [];
        }

        if ($requested === ['*']) {
            return $indexFields;
        }

        $indexWildcard = self::hasRetrievableFieldWildcard($indexFields);
        $requestWildcard = self::hasRetrievableFieldWildcard($requested);

        if ($indexWildcard && $requestWildcard) {
            return self::normalizeRetrievableFields(array_merge(
                ['*'],
                self::retrievableFieldExclusions($indexFields),
                self::retrievableFieldExclusions($requested),
            ));
        }

        if ($indexWildcard) {
            return array_values(array_diff(
                self::retrievableFieldAllowlist($requested),
                self::retrievableFieldExclusionHandles($indexFields),
            ));
        }

        if ($requestWildcard) {
            return array_values(array_diff(
                self::retrievableFieldAllowlist($indexFields),
                self::retrievableFieldExclusionHandles($requested),
            ));
        }

        if ($indexFields === ['*']) {
            return $requested;
        }

        return array_values(array_intersect(
            self::retrievableFieldAllowlist($indexFields),
            self::retrievableFieldAllowlist($requested),
        ));
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>|null $retrievableFields
     * @return array<string, mixed>
     * @since 5.53.0
     */
    public static function filterRetrievableFieldValues(array $fields, ?array $retrievableFields = null): array
    {
        if ($retrievableFields === null) {
            return $fields;
        }

        $retrievableFields = self::normalizeRetrievableFields($retrievableFields);
        if ($retrievableFields === []) {
            return [];
        }

        if (self::hasRetrievableFieldWildcard($retrievableFields)) {
            return array_diff_key($fields, array_flip(self::retrievableFieldExclusionHandles($retrievableFields)));
        }

        return array_intersect_key($fields, array_flip(self::retrievableFieldAllowlist($retrievableFields)));
    }

    /**
     * Parse a request-time retrievableFields value.
     *
     * Null means the caller omitted the parameter. Empty strings/lists mean
     * "return no custom fields".
     *
     * @return list<string>|null
     * @since 5.53.0
     */
    public static function requestedRetrievableFields(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return self::normalizeRetrievableFields($value);
    }

    /**
     * @param list<string> $fields
     */
    private static function hasRetrievableFieldWildcard(array $fields): bool
    {
        return in_array('*', $fields, true);
    }

    /**
     * @param list<string> $fields
     */
    private static function hasRetrievableFieldExclusions(array $fields): bool
    {
        return self::retrievableFieldExclusions($fields) !== [];
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private static function retrievableFieldAllowlist(array $fields): array
    {
        return array_values(array_filter(
            self::normalizeRetrievableFields($fields),
            static fn(string $field): bool => $field !== '*' && !str_starts_with($field, '-'),
        ));
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private static function retrievableFieldExclusions(array $fields): array
    {
        return array_values(array_filter(
            self::normalizeRetrievableFields($fields),
            static fn(string $field): bool => str_starts_with($field, '-'),
        ));
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private static function retrievableFieldExclusionHandles(array $fields): array
    {
        return array_map(
            static fn(string $field): string => substr($field, 1),
            self::retrievableFieldExclusions($fields),
        );
    }

    /**
     * Resolve effective retrievable fields for multiple public indices.
     *
     * @param array<int, string> $indexHandles
     * @param list<string>|null $requested
     * @return array<string, list<string>>
     * @since 5.53.0
     */
    public static function retrievableFieldsByIndex(array $indexHandles, ?array $requested = null): array
    {
        $resolved = [];

        foreach (array_values(array_unique($indexHandles)) as $handle) {
            $index = self::findByHandle($handle);
            if ($index === null) {
                continue;
            }

            $resolved[$handle] = $index->effectiveRetrievableFields($requested);
        }

        return $resolved;
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

            return self::fromRow($row, self::loadSiteIdsForIndexId((int)$row['id']));
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find index by handle
     * For config indices, config is the source of truth
     */
    public static function findByHandle(string $handle): ?self
    {
        // 1. Check config file FIRST (prevents loading stale database metadata)
        $configData = self::loadConfigForHandle($handle);

        if ($configData) {
            try {
                $model = self::buildConfigIndexModel($handle, $configData);
            } catch (\Throwable $e) {
                LoggingService::log('Failed to build config index model', 'warning', 'search-manager', [
                    'handle' => $handle,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }

            // Load stats from database if metadata record exists
            try {
                $metadataRow = (new Query())
                    ->from('{{%searchmanager_indices}}')
                    ->where(['handle' => $handle, 'source' => 'config'])
                    ->one();

                if ($metadataRow) {
                    $model->id = (int)$metadataRow['id'];
                    $model->lastIndexed = self::convertToLocalTime($metadataRow['lastIndexed']);
                    $model->documentCount = (int)$metadataRow['documentCount'];
                }
            } catch (\Throwable $e) {
                LoggingService::log('Failed to load metadata for config index', 'error', 'search-manager', [
                    'handle' => $handle,
                    'error' => $e->getMessage(),
                ]);
            }

            return $model;
        }

        // 2. Not in config - check database for database-source indices
        try {
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handle])
                ->one();

            if ($row) {
                return self::fromRow($row, self::loadSiteIdsForIndexId((int)$row['id']));
            }
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index from database', 'error', 'search-manager', [
                'handle' => $handle,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Find index by numeric ID or string handle
     *
     * @since 5.39.0
     */
    public static function findByIdOrHandle(int|string $idOrHandle): ?self
    {
        return is_numeric($idOrHandle)
            ? self::findById((int)$idOrHandle)
            : self::findByHandle((string)$idOrHandle);
    }

    /**
     * Parse and validate requested index handles from request parameters.
     *
     * Handles the comma-separated 'indices' param, caps the count to prevent
     * fan-out attacks, and filters to enabled-only indices.
     *
     * @param string $indicesParam Comma-separated index handles (from 'indices' param)
     * @param int $maxCount Maximum number of indices allowed
     * @return array{0: array<string>, 1: bool} [validatedHandles, wereIndicesProvided]
     * @since 5.39.0
     */
    public static function resolveRequestedIndices(string $indicesParam, int $maxCount = 5): array
    {
        $indexHandles = [];
        $indicesProvided = false;

        if (!empty($indicesParam)) {
            $indicesProvided = true;
            $indexHandles = array_filter(array_map('trim', explode(',', $indicesParam)));
        }

        // Cap indices count to prevent fan-out attacks
        if (count($indexHandles) > $maxCount) {
            $indexHandles = array_slice($indexHandles, 0, $maxCount);
        }

        // Validate - only allow enabled indices
        if (!empty($indexHandles)) {
            $enabledIndices = self::findAll();
            $enabledHandles = array_map(
                fn(self $idx) => $idx->handle,
                array_filter($enabledIndices, fn(self $idx) => $idx->enabled),
            );
            $indexHandles = array_values(array_intersect($indexHandles, $enabledHandles));
        }

        return [$indexHandles, $indicesProvided];
    }

    /**
     * Get all indices (database + config)
     */
    public static function findAll(): array
    {
        if (self::$allCache !== null) {
            return self::$allCache;
        }

        $indices = [];

        // 1. Load config file indices first (source of truth)
        $configIndices = self::loadFromConfig();
        foreach ($configIndices as $configIndex) {
            $indices[$configIndex->handle] = $configIndex;
        }

        // 2. Load database indices (only source='database'), excluding config handles
        try {
            $rows = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['source' => 'database'])
                ->orderBy(['name' => SORT_ASC])
                ->all();

            $indexIds = array_map(fn($row) => (int)$row['id'], $rows);
            $siteIdMap = self::loadSiteIdsForIndexIds($indexIds);

            foreach ($rows as $row) {
                if (isset($indices[$row['handle']])) {
                    continue;
                }
                $rowId = (int)$row['id'];
                $indices[$row['handle']] = self::fromRow($row, $siteIdMap[$rowId] ?? null);
            }
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load database indices', 'error', 'search-manager', ['error' => $e->getMessage()]);
        }

        self::$allCache = array_values($indices);

        return self::$allCache;
    }

    /**
     * Load indices from config file
     */
    public static function loadFromConfig(): array
    {
        try {
            $configIndices = BaseConfigFileHelper::getConfigSection(self::PLUGIN_HANDLE, 'indices');
            $indices = [];

            // Fetch ALL config metadata in one query (instead of N queries)
            $allMetadata = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['source' => 'config'])
                ->indexBy('handle')
                ->all();

            foreach ($configIndices as $handle => $indexConfig) {
                $model = self::buildConfigIndexModel($handle, $indexConfig);

                // Check if database metadata exists for this config index (array lookup)
                if (isset($allMetadata[$handle])) {
                    $metadataRow = $allMetadata[$handle];

                    // Use database metadata for stats
                    $model->id = (int)$metadataRow['id'];
                    $model->lastIndexed = self::convertToLocalTime($metadataRow['lastIndexed']);
                    $model->documentCount = (int)$metadataRow['documentCount'];
                } else {
                    // No metadata yet - will be created on first rebuild
                    $model->lastIndexed = null;
                    $model->documentCount = 0;
                }

                $indices[] = $model;
            }

            return $indices;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load config indices', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Clear the config cache
     * Useful for testing or when config file changes during runtime
     */
    public static function clearConfigCache(): void
    {
        BaseConfigFileHelper::clearCache(self::PLUGIN_HANDLE);
        self::clearCache();
    }

    /**
     * Clear request-scoped index model caches.
     *
     * @since 5.45.0
     */
    public static function clearCache(): void
    {
        self::$allCache = null;
    }

    /**
     * Load a specific index configuration by handle (efficient version)
     * Only parses config file once, no database queries
     *
     * @param string $handle Index handle to load
     * @return array|null Config array or null if not found
     */
    private static function loadConfigForHandle(string $handle): ?array
    {
        return BaseConfigFileHelper::getConfigByHandle(self::PLUGIN_HANDLE, 'indices', $handle);
    }

    /**
     * Build a config-backed index model from the source-of-truth config array.
     *
     * @param array<string, mixed> $configData
     */
    private static function buildConfigIndexModel(string $handle, array $configData): self
    {
        $model = new self();
        $model->handle = $handle;
        $model->name = $configData['name'] ?? $handle;
        $model->elementType = $configData['elementType'] ?? Entry::class;
        $model->siteId = isset($configData['siteId']) ? self::normalizeSiteIdValue($configData['siteId']) : null;
        $model->criteria = self::normalizeConfigCriteria($handle, $configData['criteria'] ?? []);
        $model->transformerClass = $configData['transformer'] ?? null;
        $model->language = $configData['language'] ?? null;
        $model->headingLevels = $configData['headingLevels'] ?? null;
        $model->backend = $configData['backend'] ?? null;
        $model->enabled = $configData['enabled'] ?? true;
        $model->enableAnalytics = $configData['enableAnalytics'] ?? true;
        $model->disableStopWords = $configData['disableStopWords'] ?? false;
        $model->skipEntriesWithoutUrl = $configData['skipEntriesWithoutUrl'] ?? false;
        $model->splitSections = (bool)($configData['splitSections'] ?? false);
        $model->retrievableFields = self::normalizeRetrievableFields($configData['retrievableFields'] ?? null);
        $model->source = 'config';

        return $model;
    }

    /**
     * @return array|\Closure
     */
    private static function normalizeConfigCriteria(string $handle, mixed $criteria): array|\Closure
    {
        if (is_array($criteria) || $criteria instanceof \Closure) {
            return $criteria;
        }

        LoggingService::log('Invalid criteria value in config index; using empty criteria', 'warning', 'search-manager', [
            'handle' => $handle,
            'type' => get_debug_type($criteria),
        ]);

        return [];
    }

    /**
     * Convert UTC datetime string to local timezone
     *
     * @param string|null $utcDateTime UTC datetime string or null
     * @return \DateTime|null Datetime in user's timezone or null
     */
    private static function convertToLocalTime(?string $utcDateTime): ?\DateTime
    {
        if (!$utcDateTime) {
            return null;
        }

        $utcDate = new \DateTime($utcDateTime, new \DateTimeZone('UTC'));
        $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
        return $utcDate;
    }

    /**
     * Create model from database row
     */
    private static function fromRow(array $row, ?array $siteIds = null): self
    {
        $model = new self();
        $model->id = (int)$row['id'];
        $model->name = $row['name'];
        $model->handle = $row['handle'];
        $model->elementType = $row['elementType'];
        if ($siteIds !== null) {
            $model->siteId = count($siteIds) === 1 ? (int)$siteIds[0] : $siteIds;
        } else {
            $model->siteId = $row['siteId'] ? (int)$row['siteId'] : null;
        }
        $model->criteria = self::normalizeRowCriteria($model->handle, $row['criteria'] ?? null);
        $model->transformerClass = $row['transformerClass'];
        $model->headingLevels = self::normalizeRowHeadingLevels($model->handle, $row['headingLevels'] ?? null);
        $model->language = $row['language'] ?? null;
        $model->backend = $row['backend'] ?? null;
        $model->enabled = (bool)$row['enabled'];
        $model->enableAnalytics = (bool)($row['enableAnalytics'] ?? true);
        $model->disableStopWords = (bool)($row['disableStopWords'] ?? false);
        $model->skipEntriesWithoutUrl = (bool)($row['skipEntriesWithoutUrl'] ?? false);
        $model->splitSections = (bool)($row['splitSections'] ?? false);
        $model->retrievableFields = self::normalizeRetrievableFields(!empty($row['retrievableFields'])
            ? json_decode((string)$row['retrievableFields'], true)
            : null);
        $model->source = $row['source'];
        $model->lastIndexed = self::convertToLocalTime($row['lastIndexed']);
        $model->dateCreated = self::parseDate($row['dateCreated'] ?? null);
        $model->dateUpdated = self::parseDate($row['dateUpdated'] ?? null);
        $model->documentCount = (int)$row['documentCount'];

        return $model;
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function normalizeRowCriteria(string $handle, mixed $value): array
    {
        $decoded = self::decodeRowJson($value, []);
        if (is_array($decoded)) {
            return $decoded;
        }

        LoggingService::log('Invalid criteria value in index row; using empty criteria', 'warning', 'search-manager', [
            'handle' => $handle,
            'type' => get_debug_type($decoded),
        ]);

        return [];
    }

    private static function normalizeRowHeadingLevels(string $handle, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = self::decodeRowJson($value, null);
        if (is_array($decoded)) {
            return $decoded;
        }

        LoggingService::log('Invalid headingLevels value in index row; using null', 'warning', 'search-manager', [
            'handle' => $handle,
            'type' => get_debug_type($decoded),
        ]);

        return null;
    }

    private static function decodeRowJson(mixed $value, mixed $fallback): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $fallback;
        }

        $decoded = json_decode((string)$value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $fallback;
        }

        return $decoded ?? $fallback;
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
     * Save index to database
     */
    public function save(): bool
    {
        $this->rebuildQueuedOnLastSave = false;

        // Prevent saving config indices - they should only be modified via config file
        if ($this->source === 'config') {
            $this->logError('Cannot save config index - modify config file instead', [
                'handle' => $this->handle,
                'source' => $this->source,
            ]);
            return false;
        }

        if (!$this->validate()) {
            $this->logError('Index validation failed', [
                'handle' => $this->handle ?? 'unknown',
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        $db = Craft::$app->getDb();
        $originalId = $this->id;
        $previousRow = $this->id ? $this->existingPersistenceRow() : null;
        $transaction = $db->beginTransaction();

        try {
            $attributes = [
                'name' => $this->name,
                'handle' => $this->handle,
                'elementType' => $this->elementType,
                'siteId' => is_array($this->siteId) ? null : $this->siteId,
                'criteria' => json_encode($this->criteria),
                'transformerClass' => $this->transformerClass,
                'headingLevels' => $this->headingLevels ? json_encode($this->headingLevels) : null,
                'language' => $this->language,
                'backend' => $this->backend ?: null,
                'enabled' => (int)$this->enabled,
                'enableAnalytics' => (int)$this->enableAnalytics,
                'disableStopWords' => (int)$this->disableStopWords,
                'skipEntriesWithoutUrl' => (int)$this->skipEntriesWithoutUrl,
                'splitSections' => (int)$this->splitSections,
                'retrievableFields' => json_encode(self::normalizeRetrievableFields($this->retrievableFields)),
                'source' => $this->source,
                'lastIndexed' => $this->lastIndexed ? Db::prepareDateForDb($this->lastIndexed) : null,
                'documentCount' => $this->documentCount,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                $queueRebuild = $this->shouldQueueRebuildAfterSave($previousRow, $attributes);

                // Update existing
                $db
                    ->createCommand()
                    ->update('{{%searchmanager_indices}}', $attributes, ['id' => $this->id])
                    ->execute();

                $this->saveIndexSites($this->getSiteIds());
                $transaction->commit();
                $this->clearPreviousStorageAfterIdentityChange($previousRow);
                self::clearCache();
                if ($queueRebuild) {
                    $this->queueRebuildAfterSave();
                }
                return true;
            } else {
                $queueRebuild = $this->enabled;

                // Insert new
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                $db
                    ->createCommand()
                    ->insert('{{%searchmanager_indices}}', $attributes)
                    ->execute();

                $this->id = (int)$db->getLastInsertID();

                $this->saveIndexSites($this->getSiteIds());
                $transaction->commit();
                self::clearCache();
                if ($queueRebuild) {
                    $this->queueRebuildAfterSave();
                }
                return true;
            }
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            $this->id = $originalId;

            $this->logError('Failed to save index', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Whether the most recent successful save queued a full index rebuild.
     */
    public function wasRebuildQueuedOnLastSave(): bool
    {
        return $this->rebuildQueuedOnLastSave;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingPersistenceRow(): ?array
    {
        $row = (new Query())
            ->select([
                'id',
                'name',
                'handle',
                'elementType',
                'siteId',
                'criteria',
                'transformerClass',
                'headingLevels',
                'language',
                'backend',
                'enabled',
                'enableAnalytics',
                'disableStopWords',
                'skipEntriesWithoutUrl',
                'splitSections',
                'retrievableFields',
            ])
            ->from('{{%searchmanager_indices}}')
            ->where(['id' => $this->id])
            ->one();

        if ($row === false) {
            return null;
        }

        return [
            'name' => (string)$row['name'],
            'handle' => (string)$row['handle'],
            'elementType' => (string)$row['elementType'],
            'siteId' => $row['siteId'],
            'siteIds' => $this->previousSiteIds((int)$row['id'], $row['siteId']),
            'criteria' => $row['criteria'],
            'transformerClass' => $row['transformerClass'],
            'headingLevels' => $row['headingLevels'],
            'language' => $row['language'],
            'backend' => $row['backend'] !== null && $row['backend'] !== '' ? (string)$row['backend'] : null,
            'enabled' => $row['enabled'],
            'enableAnalytics' => $row['enableAnalytics'],
            'disableStopWords' => $row['disableStopWords'],
            'skipEntriesWithoutUrl' => $row['skipEntriesWithoutUrl'],
            'splitSections' => $row['splitSections'],
            'retrievableFields' => $row['retrievableFields'],
        ];
    }

    /**
     * @param array<string, mixed>|null $previousRow
     */
    private function clearPreviousStorageAfterIdentityChange(?array $previousRow): void
    {
        if ($previousRow === null) {
            return;
        }

        $previousHandle = $previousRow['handle'];
        $previousBackend = $previousRow['backend'];
        $currentBackend = $this->backend ?: null;

        if ($previousHandle === $this->handle && $previousBackend === $currentBackend) {
            return;
        }

        $backend = $this->createBackendForStoredHandle($previousBackend);
        if ($backend === null) {
            $this->logWarning('Unable to clear previous index storage after index identity change', [
                'previousHandle' => $previousHandle,
                'previousBackend' => $previousBackend,
                'currentHandle' => $this->handle,
                'currentBackend' => $currentBackend,
            ]);
            $this->updateStats(0);
            return;
        }

        if (!$backend->clearIndex($previousHandle)) {
            $this->logWarning('Previous index storage clear reported failure after index identity change', [
                'previousHandle' => $previousHandle,
                'previousBackend' => $previousBackend,
                'currentHandle' => $this->handle,
                'currentBackend' => $currentBackend,
            ]);
        }

        $this->updateStats(0);
    }

    /**
     * @param array<string, mixed>|null $previousRow
     * @param array<string, mixed> $attributes
     */
    private function shouldQueueRebuildAfterSave(?array $previousRow, array $attributes): bool
    {
        if ($previousRow === null) {
            return false;
        }

        if (!(bool)$previousRow['enabled'] && $this->enabled) {
            return true;
        }

        if (!$this->enabled) {
            return false;
        }

        return $this->shapeComparableFromPreviousRow($previousRow) !== $this->shapeComparableFromCurrentAttributes($attributes);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeComparableFromPreviousRow(array $row): array
    {
        return [
            'handle' => (string)$row['handle'],
            'elementType' => (string)$row['elementType'],
            'siteIds' => self::normalizeSiteIdsComparable($row['siteIds'] ?? null),
            'criteria' => self::decodeJsonComparable($row['criteria'] ?? null, []),
            'transformerClass' => self::normalizeOptionalString($row['transformerClass'] ?? null),
            'headingLevels' => self::decodeJsonComparable($row['headingLevels'] ?? null, null),
            'language' => self::normalizeOptionalString($row['language'] ?? null),
            'backend' => self::normalizeOptionalString($row['backend'] ?? null),
            'disableStopWords' => (bool)$row['disableStopWords'],
            'skipEntriesWithoutUrl' => (bool)$row['skipEntriesWithoutUrl'],
            'splitSections' => (bool)$row['splitSections'],
            'retrievableFields' => self::normalizeRetrievableFields(
                self::decodeJsonComparable($row['retrievableFields'] ?? null, null),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function shapeComparableFromCurrentAttributes(array $attributes): array
    {
        return [
            'handle' => (string)$attributes['handle'],
            'elementType' => (string)$attributes['elementType'],
            'siteIds' => self::normalizeSiteIdsComparable($this->getSiteIds()),
            'criteria' => self::decodeJsonComparable($attributes['criteria'] ?? null, []),
            'transformerClass' => self::normalizeOptionalString($attributes['transformerClass'] ?? null),
            'headingLevels' => self::decodeJsonComparable($attributes['headingLevels'] ?? null, null),
            'language' => self::normalizeOptionalString($attributes['language'] ?? null),
            'backend' => self::normalizeOptionalString($attributes['backend'] ?? null),
            'disableStopWords' => (bool)$attributes['disableStopWords'],
            'skipEntriesWithoutUrl' => (bool)$attributes['skipEntriesWithoutUrl'],
            'splitSections' => (bool)$attributes['splitSections'],
            'retrievableFields' => self::normalizeRetrievableFields(
                self::decodeJsonComparable($attributes['retrievableFields'] ?? null, null),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function shapeComparableFromConfigAttributes(array $attributes): array
    {
        return [
            'handle' => (string)$attributes['handle'],
            'elementType' => (string)$attributes['elementType'],
            'siteIds' => self::normalizeSiteIdsComparable($attributes['siteIds'] ?? null),
            'criteria' => self::decodeJsonComparable($attributes['criteria'] ?? null, []),
            'transformerClass' => self::normalizeOptionalString($attributes['transformerClass'] ?? null),
            'headingLevels' => self::decodeJsonComparable($attributes['headingLevels'] ?? null, null),
            'language' => self::normalizeOptionalString($attributes['language'] ?? null),
            'backend' => self::normalizeOptionalString($attributes['backend'] ?? null),
            'disableStopWords' => (bool)$attributes['disableStopWords'],
            'skipEntriesWithoutUrl' => (bool)$attributes['skipEntriesWithoutUrl'],
            'splitSections' => (bool)$attributes['splitSections'],
            'retrievableFields' => self::normalizeRetrievableFields(
                self::decodeJsonComparable($attributes['retrievableFields'] ?? null, null),
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $previousRow
     * @param array<string, mixed> $attributes
     */
    private function shouldQueueRebuildAfterConfigSync(?array $previousRow, array $attributes): bool
    {
        if (!(bool)$attributes['enabled']) {
            return false;
        }

        if ($previousRow === null) {
            return true;
        }

        return $this->shapeComparableFromPreviousRow($previousRow) !== $this->shapeComparableFromConfigAttributes($attributes);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metadataComparableFromPreviousRow(array $row): array
    {
        return [
            'name' => (string)$row['name'],
            'elementType' => (string)$row['elementType'],
            'siteIds' => self::normalizeSiteIdsComparable($row['siteIds'] ?? null),
            'criteria' => self::decodeJsonComparable($row['criteria'] ?? null, []),
            'transformerClass' => self::normalizeOptionalString($row['transformerClass'] ?? null),
            'headingLevels' => self::decodeJsonComparable($row['headingLevels'] ?? null, null),
            'language' => self::normalizeOptionalString($row['language'] ?? null),
            'backend' => self::normalizeOptionalString($row['backend'] ?? null),
            'enabled' => (bool)$row['enabled'],
            'enableAnalytics' => (bool)$row['enableAnalytics'],
            'disableStopWords' => (bool)$row['disableStopWords'],
            'skipEntriesWithoutUrl' => (bool)$row['skipEntriesWithoutUrl'],
            'splitSections' => (bool)$row['splitSections'],
            'retrievableFields' => self::normalizeRetrievableFields(
                self::decodeJsonComparable($row['retrievableFields'] ?? null, null),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function metadataComparableFromConfigAttributes(array $attributes): array
    {
        return [
            'name' => (string)$attributes['name'],
            'elementType' => (string)$attributes['elementType'],
            'siteIds' => self::normalizeSiteIdsComparable($attributes['siteIds'] ?? null),
            'criteria' => self::decodeJsonComparable($attributes['criteria'] ?? null, []),
            'transformerClass' => self::normalizeOptionalString($attributes['transformerClass'] ?? null),
            'headingLevels' => self::decodeJsonComparable($attributes['headingLevels'] ?? null, null),
            'language' => self::normalizeOptionalString($attributes['language'] ?? null),
            'backend' => self::normalizeOptionalString($attributes['backend'] ?? null),
            'enabled' => (bool)$attributes['enabled'],
            'enableAnalytics' => (bool)$attributes['enableAnalytics'],
            'disableStopWords' => (bool)$attributes['disableStopWords'],
            'skipEntriesWithoutUrl' => (bool)$attributes['skipEntriesWithoutUrl'],
            'splitSections' => (bool)$attributes['splitSections'],
            'retrievableFields' => self::normalizeRetrievableFields(
                self::decodeJsonComparable($attributes['retrievableFields'] ?? null, null),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $configData
     * @return array<string, mixed>
     */
    private function configPersistenceAttributes(array $configData): array
    {
        $siteId = isset($configData['siteId']) ? self::normalizeSiteIdValue($configData['siteId']) : null;
        $siteIds = is_array($siteId) ? $siteId : ($siteId ? [(int)$siteId] : null);
        $headingLevels = $configData['headingLevels'] ?? null;
        $retrievableFields = self::normalizeRetrievableFields($configData['retrievableFields'] ?? null);

        return [
            'name' => $configData['name'] ?? $this->handle,
            'handle' => $this->handle,
            'elementType' => $configData['elementType'] ?? Entry::class,
            'siteId' => is_array($siteId) ? null : $siteId,
            'siteIds' => $siteIds,
            'criteria' => json_encode($configData['criteria'] ?? []),
            'transformerClass' => ($configData['transformer'] ?? null) ?: '',
            'headingLevels' => $headingLevels ? json_encode($headingLevels) : null,
            'language' => $configData['language'] ?? null,
            'backend' => ($configData['backend'] ?? null) ?: null,
            'enabled' => (int)($configData['enabled'] ?? true),
            'enableAnalytics' => (int)($configData['enableAnalytics'] ?? true),
            'disableStopWords' => (int)($configData['disableStopWords'] ?? false),
            'skipEntriesWithoutUrl' => (int)($configData['skipEntriesWithoutUrl'] ?? false),
            'splitSections' => (int)(bool)($configData['splitSections'] ?? false),
            'retrievableFields' => json_encode($retrievableFields),
            'source' => 'config',
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function applyConfigPersistenceAttributesToModel(array $attributes): void
    {
        $siteIds = $attributes['siteIds'] ?? null;

        $this->name = (string)$attributes['name'];
        $this->elementType = (string)$attributes['elementType'];
        $this->siteId = is_array($siteIds)
            ? (count($siteIds) === 1 ? (int)$siteIds[0] : $siteIds)
            : null;
        $this->criteria = self::decodeJsonComparable($attributes['criteria'] ?? null, []);
        $this->transformerClass = self::normalizeOptionalString($attributes['transformerClass'] ?? null);
        $this->headingLevels = self::decodeJsonComparable($attributes['headingLevels'] ?? null, null);
        $this->language = self::normalizeOptionalString($attributes['language'] ?? null);
        $this->backend = self::normalizeOptionalString($attributes['backend'] ?? null);
        $this->enabled = (bool)$attributes['enabled'];
        $this->enableAnalytics = (bool)$attributes['enableAnalytics'];
        $this->disableStopWords = (bool)$attributes['disableStopWords'];
        $this->skipEntriesWithoutUrl = (bool)$attributes['skipEntriesWithoutUrl'];
        $this->splitSections = (bool)$attributes['splitSections'];
        $this->retrievableFields = self::normalizeRetrievableFields(
            self::decodeJsonComparable($attributes['retrievableFields'] ?? null, null),
        );
    }

    private function queueRebuildAfterSave(): void
    {
        try {
            $this->rebuildQueuedOnLastSave = SearchManager::$plugin->indexing->rebuildIndex($this->handle);
        } catch (\Throwable $e) {
            $this->logWarning('Unable to queue index rebuild after index save', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            $this->rebuildQueuedOnLastSave = false;
        }
    }

    private function previousSiteIds(int $indexId, mixed $siteId): ?array
    {
        $siteIds = self::loadSiteIdsForIndexId($indexId);
        if ($siteIds !== null) {
            return $siteIds;
        }

        if ($siteId === null || $siteId === '') {
            return null;
        }

        return [(int)$siteId];
    }

    private static function decodeJsonComparable(mixed $value, mixed $emptyValue): mixed
    {
        if ($value === null || $value === '') {
            return $emptyValue;
        }

        if (is_array($value)) {
            return self::normalizeComparableValue($value);
        }

        $decoded = json_decode((string)$value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return (string)$value;
        }

        return self::normalizeComparableValue($decoded ?? $emptyValue);
    }

    private static function normalizeComparableValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = self::normalizeComparableValue($nestedValue);
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private static function normalizeSiteIdsComparable(mixed $siteIds): ?array
    {
        if ($siteIds === null) {
            return null;
        }

        if (!is_array($siteIds)) {
            $siteIds = [$siteIds];
        }

        $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds), fn($id) => $id > 0)));
        sort($siteIds);

        return $siteIds;
    }

    private function createBackendForStoredHandle(?string $backendHandle): ?BackendInterface
    {
        $backendService = SearchManager::$plugin->backend;

        if ($backendHandle === null || $backendHandle === '') {
            return $backendService->getActiveBackend();
        }

        $configuredBackend = ConfiguredBackend::findByHandle($backendHandle);
        if ($configuredBackend !== null) {
            return $backendService->createBackendFromConfig($configuredBackend);
        }

        return $backendService->getBackend($backendHandle);
    }

    /**
     * Delete index from database
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        // Prevent deleting config index metadata - remove from config file instead
        if ($this->source === 'config') {
            $this->logError('Cannot delete config index - remove from config file instead', [
                'handle' => $this->handle,
                'source' => $this->source,
            ]);
            return false;
        }

        try {
            // Clear backend storage first (MySQL tables, Redis keys, files, etc.)
            \lindemannrock\searchmanager\SearchManager::$plugin->backend->clearIndex($this->handle);

            // Then delete the database record
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->clearIndexSites();
                self::clearCache();
                $this->logInfo('Index deleted successfully', [
                    'handle' => $this->handle,
                    'name' => $this->name,
                ]);
            }

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
     * Sync metadata from config file (for config indices)
     * Updates persisted metadata from config without changing stats.
     */
    public function syncMetadataFromConfig(): bool
    {
        if ($this->source !== 'config') {
            $this->logDebug('Sync skipped - not config', [
                'source' => $this->source,
                'id' => $this->id,
                'handle' => $this->handle,
            ]);
            return false;
        }

        try {
            // Load fresh config values efficiently (no database queries, targeted config load)
            $configData = self::loadConfigForHandle($this->handle);

            if (!$configData) {
                $this->logError('Config not found for handle', ['handle' => $this->handle]);
                return false;
            }

            $attributes = $this->configPersistenceAttributes($configData);

            // Validate transformer class before syncing
            if (!$this->validateConfigTransformerClass($attributes['transformerClass'])) {
                return false;
            }

            $previousRow = $this->id ? $this->existingPersistenceRow() : null;
            $metadataChanged = $previousRow === null
                || $this->metadataComparableFromPreviousRow($previousRow) !== $this->metadataComparableFromConfigAttributes($attributes);
            $queueRebuild = $this->shouldQueueRebuildAfterConfigSync($previousRow, $attributes);

            if (!$metadataChanged) {
                $this->applyConfigPersistenceAttributesToModel($attributes);
                return true;
            }

            $this->logInfo('Syncing metadata from config', [
                'handle' => $this->handle,
                'old_name' => $this->name,
                'new_name' => $attributes['name'],
                'old_transformer' => $this->transformerClass,
                'new_transformer' => $attributes['transformerClass'],
            ]);

            $siteIds = $attributes['siteIds'];
            unset($attributes['siteIds']);
            $attributes['dateUpdated'] = Db::prepareDateForDb(new \DateTime());

            if ($this->id) {
                Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_indices}}', $attributes, ['id' => $this->id])
                    ->execute();
            } else {
                $attributes['lastIndexed'] = $this->lastIndexed ? Db::prepareDateForDb($this->lastIndexed) : null;
                $attributes['documentCount'] = $this->documentCount;
                $attributes['dateCreated'] = $attributes['dateUpdated'];
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_indices}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();
            }

            $this->saveIndexSites($siteIds);
            $attributes['siteIds'] = $siteIds;
            $this->applyConfigPersistenceAttributesToModel($attributes);

            $this->logInfo('Metadata synced successfully', ['handle' => $this->handle]);
            self::clearCache();
            if ($queueRebuild) {
                $this->queueRebuildAfterSave();
            }
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to sync config metadata', [
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
        // Config indices: create/update database record for stats only
        if (!$this->id && $this->source === 'config') {
            // Load fresh config values to avoid saving stale metadata
            $configData = self::loadConfigForHandle($this->handle);

            if (!$configData) {
                $this->logError('Config not found for handle in updateStats', ['handle' => $this->handle]);
                return false;
            }

            // Extract fresh values from config
            $freshName = $configData['name'] ?? $this->handle;
            $freshTransformer = $configData['transformer'] ?? null;
            $freshLanguage = $configData['language'] ?? null;
            $freshHeadingLevels = $configData['headingLevels'] ?? null;
            $freshEnabled = $configData['enabled'] ?? true;
            $freshDisableStopWords = $configData['disableStopWords'] ?? false;
            $freshRetrievableFields = self::normalizeRetrievableFields($configData['retrievableFields'] ?? null);

            // Validate transformer class before updating stats
            if (!$this->validateConfigTransformerClass($freshTransformer)) {
                return false;
            }

            $now = new \DateTime();
            $nowDb = Db::prepareDateForDb($now);
            $headingLevelsJson = $freshHeadingLevels ? json_encode($freshHeadingLevels) : null;
            $retrievableFieldsJson = json_encode($freshRetrievableFields);

            Craft::$app->getDb()
                ->createCommand()
                ->upsert(
                    '{{%searchmanager_indices}}',
                    [
                        'name' => $freshName,
                        'handle' => $this->handle,
                        'elementType' => $this->elementType,
                        'siteId' => is_array($this->siteId) ? null : $this->siteId,
                        'criteria' => '{}', // Empty - actual criteria is in config
                        'transformerClass' => $freshTransformer ?: '',
                        'headingLevels' => $headingLevelsJson,
                        'language' => $freshLanguage,
                        'enabled' => (int)$freshEnabled,
                        'disableStopWords' => (int)$freshDisableStopWords,
                        'retrievableFields' => $retrievableFieldsJson,
                        'source' => 'config',
                        'lastIndexed' => $nowDb,
                        'documentCount' => $documentCount,
                        'dateCreated' => $nowDb,
                        'dateUpdated' => $nowDb,
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ],
                    [
                        'name' => $freshName,
                        'transformerClass' => $freshTransformer ?: '',
                        'headingLevels' => $headingLevelsJson,
                        'language' => $freshLanguage,
                        'enabled' => (int)$freshEnabled,
                        'disableStopWords' => (int)$freshDisableStopWords,
                        'retrievableFields' => $retrievableFieldsJson,
                        'lastIndexed' => $nowDb,
                        'documentCount' => $documentCount,
                        'dateUpdated' => $nowDb,
                    ],
                )
                ->execute();

            // Update current object with fresh values
            $this->name = $freshName;
            $this->transformerClass = $freshTransformer;
            $this->headingLevels = $freshHeadingLevels;
            $this->language = $freshLanguage;
            $this->enabled = $freshEnabled;
            $this->disableStopWords = (bool)$freshDisableStopWords;
            $this->retrievableFields = $freshRetrievableFields;
            $this->lastIndexed = $now;
            $this->documentCount = $documentCount;
            self::clearCache();
            return true;
        }

        // Database indices: save stats to database
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

            self::clearCache();

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
     * Increment document count by 1
     * Used when a single element is added to the index
     */
    public static function incrementDocumentCount(string $handle): bool
    {
        return self::adjustDocumentCount($handle, 1);
    }

    /**
     * Decrement document count by 1
     * Used when a single element is removed from the index
     */
    public static function decrementDocumentCount(string $handle): bool
    {
        return self::adjustDocumentCount($handle, -1);
    }

    /**
     * Touch lastIndexed for automatic sync paths, debounced to avoid metadata write amplification.
     * Updates the database row only; loaded SearchIndex instances are refreshed on the next load.
     *
     * @since 5.45.0
     */
    public static function touchLastIndexedDebounced(string $handle): bool
    {
        $debounceSeconds = max(0, SearchManager::$plugin->getSettings()->lastIndexedDebounceSeconds);
        $now = new \DateTime();

        try {
            $db = Craft::$app->getDb();
            $condition = ['handle' => $handle];

            if ($debounceSeconds > 0) {
                $cutoff = (clone $now)->modify("-{$debounceSeconds} seconds");
                $condition = [
                    'and',
                    $condition,
                    [
                        'or',
                        ['lastIndexed' => null],
                        ['<', 'lastIndexed', Db::prepareDateForDb($cutoff)],
                    ],
                ];
            }

            $result = $db->createCommand()
                ->update(
                    '{{%searchmanager_indices}}',
                    [
                        'lastIndexed' => Db::prepareDateForDb($now),
                        'dateUpdated' => Db::prepareDateForDb($now),
                    ],
                    $condition
                )
                ->execute();

            if ($result > 0) {
                self::clearCache();
            }

            return $result > 0;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to touch lastIndexed', 'error', 'search-manager', [
                'handle' => $handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Adjust document count by a delta value
     */
    private static function adjustDocumentCount(string $handle, int $delta): bool
    {
        try {
            $db = Craft::$app->getDb();

            // Use SQL expression to atomically increment/decrement
            // This avoids race conditions when multiple requests update simultaneously
            $result = $db->createCommand()
                ->update(
                    '{{%searchmanager_indices}}',
                    [
                        'documentCount' => new \yii\db\Expression("GREATEST(0, [[documentCount]] + :delta)", [':delta' => $delta]),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ],
                    ['handle' => $handle]
                )
                ->execute();

            if ($result > 0) {
                self::clearCache();
            }

            return $result > 0;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to adjust document count', 'error', 'search-manager', [
                'handle' => $handle,
                'delta' => $delta,
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
        $config = [
            'name' => $this->name,
            'elementType' => $this->elementType,
            'siteId' => $this->siteId,
            'criteria' => $this->criteria,
            'transformer' => $this->transformerClass,
            'headingLevels' => $this->headingLevels,
            'language' => $this->language,
            'enabled' => $this->enabled,
        ];

        if ($this->disableStopWords) {
            $config['disableStopWords'] = true;
        }

        if ($this->splitSections) {
            $config['splitSections'] = true;
        }

        if ($this->retrievableFields !== ['*']) {
            $config['retrievableFields'] = $this->retrievableFields;
        }

        // Only include backend if set (optional override)
        if ($this->backend) {
            $config['backend'] = $this->backend;
        }

        return $config;
    }

    /**
     * Check if index is from config file
     */
    public function isFromConfig(): bool
    {
        return $this->source === 'config';
    }

    public function usesSplitSections(): bool
    {
        if (!$this->splitSections) {
            return false;
        }

        return $this->supportsSplitSections();
    }

    public function validateSplitSectionsSupport(string $attribute): void
    {
        if (!$this->splitSections || $this->supportsSplitSections()) {
            return;
        }

        $this->addError($attribute, Craft::t('search-manager', 'Split Sections supports AutoTransformer-family indices, plus SourceDoc indices with DocsManagerTransformer-family transformers.'));
    }

    /**
     * @since 5.53.0
     */
    public function supportsSplitSections(): bool
    {
        return SearchManager::$plugin->transformers->supportsSplitSections($this->elementType, $this->transformerClass);
    }

    public function validateSplitSectionsStorage(string $attribute): void
    {
        if (!$this->usesSplitSections()) {
            return;
        }

        $backendType = $this->getEffectiveBackendType();
        if (self::backendTypeSupportsDocumentKeys($backendType)) {
            return;
        }

        $this->addError($attribute, Craft::t('search-manager', 'Split Sections requires a backend that supports document keys.'));
    }

    public static function backendTypeSupportsDocumentKeys(?string $backendType): bool
    {
        return in_array($backendType, [
            'algolia',
            'meilisearch',
            'typesense',
            'mysql',
            'pgsql',
            'redis',
            'file',
        ], true);
    }

    /**
     * Check if this index has a custom backend override
     */
    public function hasBackendOverride(): bool
    {
        return !empty($this->backend);
    }

    /**
     * Get the effective backend handle for this index
     * Returns the index-specific backend if set, otherwise the global default
     */
    public function getEffectiveBackend(): ?string
    {
        if ($this->backend) {
            return $this->backend;
        }

        // Fall back to global default backend handle
        return \lindemannrock\searchmanager\SearchManager::$plugin->getSettings()->defaultBackendHandle;
    }

    /**
     * Get the effective backend TYPE for this index (e.g., 'algolia', 'mysql', 'meilisearch')
     * Resolves the configured backend handle to its type
     */
    public function getEffectiveBackendType(): ?string
    {
        $backendHandle = $this->getEffectiveBackend();

        if (!$backendHandle) {
            return null;
        }

        // Look up the configured backend to get its type
        $configuredBackend = ConfiguredBackend::findByHandle($backendHandle);
        if ($configuredBackend) {
            return $configuredBackend->backendType;
        }

        // If not found as a configured backend, it might be a legacy backend type directly
        // (for backwards compatibility during migration)
        $validTypes = ['algolia', 'meilisearch', 'typesense', 'mysql', 'pgsql', 'redis', 'file'];
        if (in_array($backendHandle, $validTypes, true)) {
            return $backendHandle;
        }

        return null;
    }

    /**
     * Get the configured backend for this index
     */
    public function getConfiguredBackend(): ?ConfiguredBackend
    {
        $backendHandle = $this->getEffectiveBackend();

        if (!$backendHandle) {
            return null;
        }

        return ConfiguredBackend::findByHandle($backendHandle);
    }

    /**
     * Get the effective Redis connection info when this index uses Redis.
     *
     * @return array<string, mixed>|null
     * @since 5.52.0
     */
    public function getRedisConnectionInfo(): ?array
    {
        if ($this->getEffectiveBackendType() !== 'redis') {
            return null;
        }

        $configuredBackend = $this->getConfiguredBackend();

        if ($configuredBackend !== null) {
            return $configuredBackend->getRedisConnectionInfo();
        }

        return RedisConnectionHelper::resolve([]);
    }

    /**
     * Get raw config display string for config indices
     * Returns a formatted representation of the config file definition
     */
    public function getRawConfigDisplay(): ?string
    {
        if ($this->source !== 'config') {
            return null;
        }

        $configData = self::loadConfigForHandle($this->handle);
        if (!$configData) {
            return null;
        }

        $lines = ["'{$this->handle}' => ["];

        // Name
        if (isset($configData['name'])) {
            $lines[] = "    'name' => '{$configData['name']}',";
        }

        // Element type
        if (isset($configData['elementType'])) {
            $lines[] = "    'elementType' => " . self::formatClassConfigValue((string)$configData['elementType']) . ',';
        }

        // Site ID
        if (isset($configData['siteId'])) {
            if (is_array($configData['siteId'])) {
                $siteIds = array_map('intval', $configData['siteId']);
                $lines[] = "    'siteId' => [" . implode(', ', $siteIds) . "],";
            } else {
                $lines[] = "    'siteId' => {$configData['siteId']},";
            }
        }

        // Transformer
        if (!empty($configData['transformer'])) {
            $transformer = $configData['transformer'];
            $lines[] = "    'transformer' => '{$transformer}',";
        }

        // Heading levels
        if (!empty($configData['headingLevels'])) {
            $levels = array_map('intval', $configData['headingLevels']);
            $lines[] = "    'headingLevels' => [" . implode(', ', $levels) . "],";
        }

        // Language
        if (!empty($configData['language'])) {
            $lines[] = "    'language' => '{$configData['language']}',";
        }

        // Disable stop words
        if (!empty($configData['disableStopWords'])) {
            $lines[] = "    'disableStopWords' => true,";
        }

        if (!empty($configData['splitSections'])) {
            $lines[] = "    'splitSections' => true,";
        }

        if (isset($configData['retrievableFields'])) {
            $lines[] = "    'retrievableFields' => " . self::formatStringListConfigValue(
                self::normalizeRetrievableFields($configData['retrievableFields'])
            ) . ',';
        }

        // Criteria - show as closure placeholder if it's a closure
        if (isset($configData['criteria'])) {
            if ($configData['criteria'] instanceof \Closure) {
                $lines[] = "    'criteria' => function(\$query) { ... },";
            } elseif (is_array($configData['criteria']) && !empty($configData['criteria'])) {
                $criteriaCode = json_encode($configData['criteria'], JSON_PRETTY_PRINT);
                $criteriaCode = str_replace("\n", "\n        ", $criteriaCode);
                $lines[] = "    'criteria' => {$criteriaCode},";
            }
        }

        // Enabled
        $enabled = ($configData['enabled'] ?? true) ? 'true' : 'false';
        $lines[] = "    'enabled' => {$enabled},";

        $lines[] = "],";

        return implode("\n", $lines);
    }

    private static function formatClassConfigValue(string $className): string
    {
        if (class_exists($className)) {
            return '\\' . ltrim($className, '\\') . '::class';
        }

        return var_export($className, true);
    }

    /**
     * @param list<string> $values
     */
    private static function formatStringListConfigValue(array $values): string
    {
        return '[' . implode(', ', array_map(static fn(string $value): string => var_export($value, true), $values)) . ']';
    }

    /**
     * Get expected element count based on index criteria
     * Runs the element query with count() to determine how many elements should be indexed
     * Matches the logic in RebuildIndexJob for accurate comparison
     *
     * @return int Expected number of elements matching the index criteria
     */
    public function getExpectedCount(): int
    {
        try {
            // Get the element type class
            $elementType = $this->elementType;
            if (!class_exists($elementType)) {
                $this->logError('Element type class not found', ['elementType' => $elementType]);
                return 0;
            }

            $totalCount = 0;

            // Handle multi-site indices (siteId = null means all sites)
            $sitesToCount = $this->getSiteIds();
            if ($sitesToCount === null) {
                $sitesToCount = [];
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    $sitesToCount[] = $site->id;
                }
            }

            foreach ($sitesToCount as $siteId) {
                // Create base query matching RebuildIndexJob logic
                /** @var \craft\elements\db\ElementQuery $query */
                $query = $elementType::find()
                    ->siteId((int)$siteId)
                    ->drafts(false)
                    ->revisions(false);

                $this->logDebug('Building expected count query', [
                    'indexHandle' => $this->handle,
                    'indexSiteId' => $this->siteId,
                    'querySiteId' => $siteId,
                ]);

                // Apply criteria
                $hasClosure = false;
                if (!empty($this->criteria)) {
                    $hasClosure = $this->criteria instanceof \Closure;
                    $query = SearchIndexCriteriaHelper::apply($query, $elementType, $this->criteria);
                }

                SearchElementAvailabilityHelper::applyToQuery($query, $elementType);

                // If skipEntriesWithoutUrl is enabled, filter Entry URI in SQL.
                if ($this->skipEntriesWithoutUrl && $elementType === Entry::class) {
                    $query->andWhere(['not', ['elements_sites.uri' => null]])
                        ->andWhere(['<>', 'elements_sites.uri', '']);

                    if ($hasClosure) {
                        $ids = $query->ids();
                        $siteCount = count($ids);
                    } else {
                        $siteCount = (int) $query->count();
                    }

                    $totalCount += $siteCount;

                    $this->logDebug('Expected count result (skip URL)', [
                        'indexHandle' => $this->handle,
                        'siteId' => $siteId,
                        'count' => $siteCount,
                    ]);
                } elseif ($hasClosure) {
                    // Use ids() for Closure criteria to ensure custom query scopes are properly evaluated
                    // Some custom scopes may not work correctly with count() but work with ids()
                    $ids = $query->ids();
                    $siteCount = count($ids);
                    $totalCount += $siteCount;

                    $this->logDebug('Expected count result (closure)', [
                        'indexHandle' => $this->handle,
                        'siteId' => $siteId,
                        'count' => $siteCount,
                    ]);
                } else {
                    // Use count() for array criteria or no criteria (more efficient for large indices)
                    $siteCount = (int) $query->count();
                    $totalCount += $siteCount;

                    $this->logDebug('Expected count result', [
                        'indexHandle' => $this->handle,
                        'siteId' => $siteId,
                        'count' => $siteCount,
                    ]);
                }
            }

            return $totalCount;
        } catch (\Throwable $e) {
            $this->logError('Failed to get expected count', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Load site IDs for a single index ID.
     */
    private static function loadSiteIdsForIndexId(int $indexId): ?array
    {
        try {
            $rows = (new Query())
                ->select(['siteId'])
                ->from('{{%searchmanager_index_sites}}')
                ->where(['indexId' => $indexId])
                ->orderBy(['siteId' => SORT_ASC])
                ->all();

            if (empty($rows)) {
                return null;
            }

            return array_map(fn($row) => (int)$row['siteId'], $rows);
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index sites', 'error', 'search-manager', [
                'indexId' => $indexId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Load site IDs for multiple index IDs.
     */
    private static function loadSiteIdsForIndexIds(array $indexIds): array
    {
        if (empty($indexIds)) {
            return [];
        }

        try {
            $rows = (new Query())
                ->select(['indexId', 'siteId'])
                ->from('{{%searchmanager_index_sites}}')
                ->where(['indexId' => $indexIds])
                ->orderBy(['indexId' => SORT_ASC, 'siteId' => SORT_ASC])
                ->all();

            $map = [];
            foreach ($rows as $row) {
                $idx = (int)$row['indexId'];
                $map[$idx][] = (int)$row['siteId'];
            }

            return $map;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index sites', 'error', 'search-manager', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Save persisted index site mappings.
     */
    private function saveIndexSites(?array $siteIds): void
    {
        if (!$this->id) {
            return;
        }

        $db = Craft::$app->getDb();
        $this->clearIndexSites();

        if ($siteIds === null) {
            return;
        }

        foreach ($siteIds as $siteId) {
            $db->createCommand()
                ->insert('{{%searchmanager_index_sites}}', [
                    'indexId' => $this->id,
                    'siteId' => (int)$siteId,
                ])
                ->execute();
        }
    }

    /**
     * Clear index site mappings.
     */
    private function clearIndexSites(): void
    {
        if (!$this->id) {
            return;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_index_sites}}', ['indexId' => $this->id])
            ->execute();
    }

    /**
     * Get normalized site IDs for this index.
     * Returns null for "all sites".
     */
    public function getSiteIds(): ?array
    {
        if ($this->siteId === null) {
            return null;
        }

        if (is_array($this->siteId)) {
            return array_values(array_unique(array_filter(array_map('intval', $this->siteId), fn($id) => $id > 0)));
        }

        return [(int)$this->siteId];
    }

    /**
     * Check whether this index applies to the given site ID.
     */
    public function appliesToSiteId(int $siteId): bool
    {
        $siteIds = $this->getSiteIds();
        if ($siteIds === null) {
            return true;
        }

        return in_array($siteId, $siteIds, true);
    }

    /**
     * Check whether an element matches this index's element type, site, AND criteria.
     *
     * Combines all three checks: structural (enabled / element type / site) and
     * criteria. The buffer path (`PendingSyncProcessor`) uses this as the
     * single is-this-row-eligible gate.
     *
     * @since 5.45.0
     */
    public function matchesElement(ElementInterface $element): bool
    {
        $elementType = get_class($element);
        if (!$this->enabled || $this->elementType !== $elementType || !$this->appliesToSiteId((int)$element->siteId)) {
            return false;
        }

        return $this->matchesCriteria($element);
    }

    /**
     * Whether the Entry-only "skip entries without URL" rule excludes an element.
     *
     * @since 5.53.0
     */
    public function shouldSkipElementWithoutUrl(ElementInterface $element): bool
    {
        return $this->skipEntriesWithoutUrl
            && $this->elementType === Entry::class
            && $element instanceof Entry
            && $element->url === null;
    }

    /**
     * Check whether an element passes this index's criteria, regardless of
     * structural matches (element type, site, enabled). Callers that have
     * already done structural filtering use this directly to avoid redundant
     * checks; otherwise use `matchesElement()`.
     *
     * Wraps Closure evaluation in try/catch — a misbehaving Closure must not
     * crash the indexing pipeline, and the safe default on error is "does
     * not match" so we don't silently include things the criteria meant to
     * exclude.
     *
     * @since 5.46.0
     */
    public function matchesCriteria(ElementInterface $element): bool
    {
        if (empty($this->criteria)) {
            return true;
        }

        $elementType = get_class($element);
        if (!class_exists($elementType)) {
            // Element class isn't loadable (e.g., a plugin that defined it
            // is uninstalled). Safe default: assume no match — better to
            // miss than to misindex.
            return false;
        }

        try {
            /** @var \craft\elements\db\ElementQuery $query */
            $query = $elementType::find()
                ->id($element->id)
                ->siteId($element->siteId)
                ->status(null);

            $query->drafts(false);
            $query->revisions(false);

            $query = SearchIndexCriteriaHelper::apply($query, $elementType, $this->criteria);

            return (bool)$query->exists();
        } catch (\Throwable $e) {
            \Craft::warning(
                sprintf(
                    'SearchIndex::matchesCriteria failed for index %s, element #%d: %s',
                    $this->handle,
                    (int)$element->id,
                    $e->getMessage(),
                ),
                'search-manager',
            );
            return false;
        }
    }

    /**
     * Normalize siteId values from config.
     */
    private static function normalizeSiteIdValue(mixed $value): int|array|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $value), fn($id) => $id > 0)));
            return $ids;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        LoggingService::log('Invalid siteId value in config', 'warning', 'search-manager', [
            'siteId' => $value,
        ]);
        return null;
    }
}
