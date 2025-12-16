<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Settings model for Search Manager plugin
 *
 * IMPORTANT: This model is database-backed (NOT project config)
 * - Settings are stored in {{%searchmanager_settings}} table
 * - Config file (config/search-manager.php) can override settings (read-only)
 * - Use isOverriddenByConfig() to check if a setting is locked by config file
 * - Use saveToDatabase() to persist changes (respects config overrides)
 */
class Settings extends Model
{
    use LoggingTrait;

    // =========================================================================
    // PROPERTIES (map to database columns)
    // =========================================================================

    /**
     * @var string Plugin name displayed in the control panel
     */
    public string $pluginName = 'Search Manager';

    /**
     * @var string Log level for plugin operations
     */
    public string $logLevel = 'error';

    /**
     * @var int Number of items per page in CP listings
     */
    public int $itemsPerPage = 100;

    /**
     * @var bool Automatically index elements when saved
     */
    public bool $autoIndex = true;

    /**
     * @var string Active search backend handle
     */
    public string $searchBackend = 'file';

    /**
     * @var int Batch size for bulk indexing operations
     */
    public int $batchSize = 100;

    /**
     * @var bool Use queue for indexing operations
     */
    public bool $queueEnabled = true;

    /**
     * @var bool Replace Craft's native search service (CP and ElementQuery search)
     */
    public bool $replaceNativeSearch = false;

    /**
     * @var bool Enable search analytics tracking
     */
    public bool $enableAnalytics = true;

    /**
     * @var int Analytics data retention period in days
     */
    public int $analyticsRetention = 90;

    /**
     * @var bool Anonymize IP addresses (subnet masking)
     */
    public bool $anonymizeIpAddress = false;

    /**
     * @var string|null IP hash salt (from .env, not saved to database)
     */
    public ?string $ipHashSalt = null;

    /**
     * @var bool Enable geo-location detection
     */
    public bool $enableGeoDetection = false;

    /**
     * @var string|null Default country for local development (when IP is private)
     */
    public ?string $defaultCountry = null;

    /**
     * @var string|null Default city for local development (when IP is private)
     */
    public ?string $defaultCity = null;

    /**
     * @var bool Cache device detection results
     */
    public bool $cacheDeviceDetection = true;

    /**
     * @var int Device detection cache duration in seconds
     */
    public int $deviceDetectionCacheDuration = 3600;

    /**
     * @var string|null Prefix for index names (multi-environment support)
     */
    public ?string $indexPrefix = null;

    /**
     * @var float BM25 K1 parameter (term frequency saturation)
     */
    public float $bm25K1 = 1.5;

    /**
     * @var float BM25 B parameter (document length normalization)
     */
    public float $bm25B = 0.75;

    /**
     * @var float Title boost factor
     */
    public float $titleBoostFactor = 5.0;

    /**
     * @var float Exact match boost factor
     */
    public float $exactMatchBoostFactor = 3.0;

    /**
     * @var string N-gram sizes for fuzzy matching (comma-separated)
     */
    public string $ngramSizes = '2,3';

    /**
     * @var float Similarity threshold for fuzzy search
     */
    public float $similarityThreshold = 0.25;

    /**
     * @var int Maximum fuzzy candidates to process
     */
    public int $maxFuzzyCandidates = 100;

    /**
     * @var bool Enable search results caching
     */
    public bool $enableCache = true;

    /**
     * @var int Cache duration in seconds
     */
    public int $cacheDuration = 3600;

    /**
     * @var string Cache storage method (file or redis)
     */
    public string $cacheStorageMethod = 'file';

    /**
     * @var bool Only cache popular queries
     */
    public bool $cachePopularQueriesOnly = false;

    /**
     * @var int Threshold for popular queries (search count)
     */
    public int $popularQueryThreshold = 5;

    // =========================================================================
    // SEARCH SETTINGS (Advanced Search Features)
    // =========================================================================

    /**
     * @var float Phrase search boost factor (for "exact phrase" searches)
     */
    public float $phraseBoostFactor = 4.0;

    /**
     * @var bool Enable stop words filtering
     */
    public bool $enableStopWords = true;

    /**
     * @var string|null Default language for search (null = auto-detect from site)
     */
    public ?string $defaultLanguage = null;

    // =========================================================================
    // HIGHLIGHTING SETTINGS
    // =========================================================================

    /**
     * @var bool Enable search result highlighting
     */
    public bool $enableHighlighting = true;

    /**
     * @var string HTML tag for highlighted terms
     */
    public string $highlightTag = 'mark';

    /**
     * @var string|null CSS class for highlighted terms
     */
    public ?string $highlightClass = null;

    /**
     * @var int Snippet length in characters
     */
    public int $snippetLength = 200;

    /**
     * @var int Maximum number of snippets per result
     */
    public int $maxSnippets = 3;

    // =========================================================================
    // AUTOCOMPLETE SETTINGS
    // =========================================================================

    /**
     * @var bool Enable autocomplete/suggestions
     */
    public bool $enableAutocomplete = true;

    /**
     * @var int Minimum query length for autocomplete
     */
    public int $autocompleteMinLength = 2;

    /**
     * @var int Maximum number of autocomplete suggestions
     */
    public int $autocompleteLimit = 10;

    /**
     * @var bool Enable fuzzy matching in autocomplete
     */
    public bool $autocompleteFuzzy = false;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');

        // Load IP hash salt from .env if not set by config file
        if ($this->ipHashSalt === null) {
            $this->ipHashSalt = \craft\helpers\App::env('SEARCH_MANAGER_IP_SALT');
        }

        // Load default location from .env if not set by config file
        if ($this->defaultCountry === null) {
            $this->defaultCountry = \craft\helpers\App::env('SEARCH_MANAGER_DEFAULT_COUNTRY');
        }
        if ($this->defaultCity === null) {
            $this->defaultCity = \craft\helpers\App::env('SEARCH_MANAGER_DEFAULT_CITY');
        }
    }

    // =========================================================================
    // VALIDATION RULES
    // =========================================================================

    public function rules(): array
    {
        return [
            [['pluginName', 'searchBackend', 'logLevel'], 'required'],
            [['pluginName'], 'string', 'max' => 255],
            [['indexPrefix'], 'string', 'max' => 50],
            [['autoIndex', 'queueEnabled', 'replaceNativeSearch', 'enableAnalytics', 'enableCache', 'cachePopularQueriesOnly', 'anonymizeIpAddress', 'enableGeoDetection', 'cacheDeviceDetection', 'enableStopWords', 'enableHighlighting', 'enableAutocomplete', 'autocompleteFuzzy'], 'boolean'],
            [['ipHashSalt'], 'string', 'min' => 32, 'skipOnEmpty' => true],
            [['cacheStorageMethod'], 'in', 'range' => ['file', 'redis']],
            [['itemsPerPage', 'batchSize', 'analyticsRetention', 'maxFuzzyCandidates', 'cacheDuration', 'popularQueryThreshold', 'deviceDetectionCacheDuration', 'snippetLength', 'maxSnippets', 'autocompleteMinLength', 'autocompleteLimit'], 'integer', 'min' => 1],
            [['itemsPerPage'], 'integer', 'max' => 500],
            [['batchSize'], 'integer', 'max' => 1000],
            [['maxFuzzyCandidates'], 'integer', 'min' => 10, 'max' => 1000],
            [['snippetLength'], 'integer', 'min' => 50, 'max' => 1000],
            [['maxSnippets'], 'integer', 'min' => 1, 'max' => 10],
            [['autocompleteMinLength'], 'integer', 'min' => 1, 'max' => 5],
            [['autocompleteLimit'], 'integer', 'min' => 1, 'max' => 50],
            [['bm25K1'], 'number', 'min' => 0.1, 'max' => 5.0],
            [['bm25B', 'similarityThreshold'], 'number', 'min' => 0.0, 'max' => 1.0],
            [['titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor'], 'number', 'min' => 1.0, 'max' => 20.0],
            [['ngramSizes', 'highlightTag'], 'string'],
            [['highlightClass', 'defaultLanguage'], 'string', 'skipOnEmpty' => true],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
            [['searchBackend'], 'in', 'range' => ['algolia', 'file', 'meilisearch', 'mysql', 'redis', 'typesense']],
        ];
    }

    // =========================================================================
    // CONFIG FILE OVERRIDE DETECTION
    // =========================================================================

    /**
     * Check if a setting is overridden by config file
     * Supports dot notation for nested settings
     *
     * @param string $attribute The setting attribute name or dot-notation path
     * @return bool
     */
    public function isOverriddenByConfig(string $attribute): bool
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (!file_exists($configPath)) {
            return false;
        }

        try {
            // Load the raw config file
            $rawConfig = require $configPath;

            // Get current environment
            $env = Craft::$app->getConfig()->env;

            // Merge environment-specific config with wildcard config
            $mergedConfig = $rawConfig['*'] ?? [];
            if ($env && isset($rawConfig[$env])) {
                $mergedConfig = array_merge($mergedConfig, $rawConfig[$env]);
            }

            // Handle dot notation for nested config
            if (str_contains($attribute, '.')) {
                $parts = explode('.', $attribute);
                $current = $mergedConfig;

                foreach ($parts as $part) {
                    if (!is_array($current) || !array_key_exists($part, $current)) {
                        return false;
                    }
                    $current = $current[$part];
                }

                return true;
            }

            // Check for the attribute in the merged config
            return array_key_exists($attribute, $mergedConfig);
        } catch (\Throwable $e) {
            $this->logError('Error checking config override', [
                'attribute' => $attribute,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Load settings from database
     * This is the single source of truth for settings
     *
     * @param Settings|null $settings Optional settings model to populate
     * @return self
     */
    public static function loadFromDatabase(?Settings $settings = null): self
    {
        if ($settings === null) {
            $settings = new self();
        }

        try {
            $row = (new Query())
                ->from('{{%searchmanager_settings}}')
                ->where(['id' => 1])
                ->one();

            if ($row) {
                // Type conversion for boolean fields
                $row['autoIndex'] = (bool)$row['autoIndex'];
                $row['queueEnabled'] = (bool)$row['queueEnabled'];
                $row['replaceNativeSearch'] = (bool)$row['replaceNativeSearch'];
                $row['enableAnalytics'] = (bool)$row['enableAnalytics'];
                $row['enableCache'] = (bool)$row['enableCache'];
                $row['cachePopularQueriesOnly'] = (bool)$row['cachePopularQueriesOnly'];
                $row['anonymizeIpAddress'] = (bool)($row['anonymizeIpAddress'] ?? false);
                $row['enableGeoDetection'] = (bool)($row['enableGeoDetection'] ?? false);
                $row['cacheDeviceDetection'] = (bool)($row['cacheDeviceDetection'] ?? true);

                // Type conversion for integer fields
                $row['itemsPerPage'] = (int)$row['itemsPerPage'];
                $row['batchSize'] = (int)$row['batchSize'];
                $row['analyticsRetention'] = (int)$row['analyticsRetention'];
                $row['maxFuzzyCandidates'] = (int)$row['maxFuzzyCandidates'];
                $row['cacheDuration'] = (int)$row['cacheDuration'];
                $row['popularQueryThreshold'] = (int)$row['popularQueryThreshold'];
                $row['deviceDetectionCacheDuration'] = (int)($row['deviceDetectionCacheDuration'] ?? 3600);

                // Type conversion for float fields
                $row['bm25K1'] = (float)$row['bm25K1'];
                $row['bm25B'] = (float)$row['bm25B'];
                $row['titleBoostFactor'] = (float)$row['titleBoostFactor'];
                $row['exactMatchBoostFactor'] = (float)$row['exactMatchBoostFactor'];
                $row['similarityThreshold'] = (float)$row['similarityThreshold'];

                // Set attributes
                $settings->setAttributes($row, false);
            }
        } catch (\Throwable $e) {
            $settings->logError('Failed to load settings from database', [
                'error' => $e->getMessage(),
            ]);
        }

        return $settings;
    }

    /**
     * Save settings to database
     * Respects config file overrides (won't save overridden attributes)
     *
     * @return bool
     */
    public function saveToDatabase(): bool
    {
        if (!$this->validate()) {
            $this->logError('Settings validation failed', [
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $attributes = $this->getAttributes();

            // Remove config-overridden attributes (can't save them)
            foreach (array_keys($attributes) as $attribute) {
                if ($this->isOverriddenByConfig($attribute)) {
                    unset($attributes[$attribute]);
                    $this->logDebug('Skipping config-overridden attribute', [
                        'attribute' => $attribute,
                    ]);
                }
            }

            // Type conversion for database storage
            if (isset($attributes['autoIndex'])) {
                $attributes['autoIndex'] = (int)$attributes['autoIndex'];
            }
            if (isset($attributes['queueEnabled'])) {
                $attributes['queueEnabled'] = (int)$attributes['queueEnabled'];
            }
            if (isset($attributes['replaceNativeSearch'])) {
                $attributes['replaceNativeSearch'] = (int)$attributes['replaceNativeSearch'];
            }
            if (isset($attributes['enableAnalytics'])) {
                $attributes['enableAnalytics'] = (int)$attributes['enableAnalytics'];
            }
            if (isset($attributes['enableCache'])) {
                $attributes['enableCache'] = (int)$attributes['enableCache'];
            }
            if (isset($attributes['cachePopularQueriesOnly'])) {
                $attributes['cachePopularQueriesOnly'] = (int)$attributes['cachePopularQueriesOnly'];
            }
            if (isset($attributes['anonymizeIpAddress'])) {
                $attributes['anonymizeIpAddress'] = (int)$attributes['anonymizeIpAddress'];
            }
            if (isset($attributes['enableGeoDetection'])) {
                $attributes['enableGeoDetection'] = (int)$attributes['enableGeoDetection'];
            }
            if (isset($attributes['cacheDeviceDetection'])) {
                $attributes['cacheDeviceDetection'] = (int)$attributes['cacheDeviceDetection'];
            }

            // Update timestamp
            $attributes['dateUpdated'] = Db::prepareDateForDb(new \DateTime());

            // Remove non-database fields (ipHashSalt, defaultCountry, defaultCity are config/env-only, never saved to DB)
            unset($attributes['id'], $attributes['dateCreated'], $attributes['uid'], $attributes['ipHashSalt'], $attributes['defaultCountry'], $attributes['defaultCity']);

            // Update database (always ID=1)
            $result = Craft::$app->getDb()
                ->createCommand()
                ->update('{{%searchmanager_settings}}', $attributes, ['id' => 1])
                ->execute();

            $this->logInfo('Settings saved successfully');
            return true;
        } catch (\Throwable $e) {
            $this->logError('Exception while saving settings', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // DISPLAY NAME HELPERS (for UI)
    // =========================================================================

    /**
     * Get display name (singular, without "Manager")
     *
     * Strips "Manager" and singularizes the plugin name for use in UI labels.
     * E.g., "Search Manager" → "Search", "Searches" → "Search"
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        // Strip "Manager" or "manager" from the name
        $name = str_replace([' Manager', ' manager'], '', $this->pluginName);

        // Singularize by removing trailing 's' if present
        $singular = preg_replace('/s$/', '', $name) ?: $name;

        return $singular;
    }

    /**
     * Get full plugin name (as configured, with "Manager" if present)
     *
     * Returns the plugin name exactly as configured in settings.
     * E.g., "Search Manager", "Searches", etc.
     *
     * @return string
     */
    public function getFullName(): string
    {
        return $this->pluginName;
    }

    /**
     * Get plural display name (without "Manager")
     *
     * Strips "Manager" from the plugin name but keeps plural form.
     * E.g., "Search Manager" → "Searches", "Searches" → "Searches"
     *
     * @return string
     */
    public function getPluralDisplayName(): string
    {
        // Strip "Manager" or "manager" from the name
        return str_replace([' Manager', ' manager'], '', $this->pluginName);
    }

    /**
     * Get lowercase display name (singular, without "Manager")
     *
     * Lowercase version of getDisplayName() for use in messages, handles, etc.
     * E.g., "Search Manager" → "search", "Searches" → "search"
     *
     * @return string
     */
    public function getLowerDisplayName(): string
    {
        return strtolower($this->getDisplayName());
    }

    /**
     * Get lowercase plural display name (without "Manager")
     *
     * Lowercase version of getPluralDisplayName() for use in messages, handles, etc.
     * E.g., "Search Manager" → "searches", "Searches" → "searches"
     *
     * @return string
     */
    public function getPluralLowerDisplayName(): string
    {
        return strtolower($this->getPluralDisplayName());
    }
}
