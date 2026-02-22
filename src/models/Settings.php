<?php

namespace lindemannrock\searchmanager\models;

use craft\base\Model;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Settings model for Search Manager plugin
 *
 * IMPORTANT: This model is database-backed (NOT project config)
 * - Settings are stored in {{%searchmanager_settings}} table
 * - Config file (config/search-manager.php) can override settings (read-only)
 * - Use isOverriddenByConfig() to check if a setting is locked by config file
 * - Use saveToDatabase() to persist changes (respects config overrides)
 *
 * @since 5.0.0
 */
class Settings extends Model
{
    use LoggingTrait;
    use SettingsConfigTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;

    // =========================================================================
    // PROPERTIES (map to database columns)
    // =========================================================================

    /**
     * @var string Plugin name displayed in the control panel
     * @since 5.0.0
     */
    public string $pluginName = 'Search Manager';

    /**
     * @var string Log level for plugin operations
     * @since 5.0.0
     */
    public string $logLevel = 'error';

    /**
     * @var int Number of items per page in CP listings
     * @since 5.0.0
     */
    public int $itemsPerPage = 100;

    /**
     * @var bool Automatically index elements when saved
     * @since 5.0.0
     */
    public bool $autoIndex = true;

    /**
     * @var string|null Handle of the default configured backend
     * @since 5.0.0
     */
    public ?string $defaultBackendHandle = null;

    /**
     * @var string|null Handle of the default widget config
     * @since 5.0.0
     */
    public ?string $defaultWidgetHandle = null;

    /**
     * @var int Batch size for bulk indexing operations
     * @since 5.0.0
     */
    public int $batchSize = 100;

    /**
     * @var bool Use queue for indexing operations
     * @since 5.0.0
     */
    public bool $queueEnabled = true;

    /**
     * @var bool Replace Craft's native search service (CP and ElementQuery search)
     * @since 5.0.0
     */
    public bool $replaceNativeSearch = false;

    /**
     * @var bool Enable search analytics tracking
     * @since 5.0.0
     */
    public bool $enableAnalytics = true;

    /**
     * @var int Analytics data retention period in days
     * @since 5.0.0
     */
    public int $analyticsRetention = 90;

    /**
     * @var bool Anonymize IP addresses (subnet masking)
     * @since 5.0.0
     */
    public bool $anonymizeIpAddress = false;

    /**
     * @var string|null IP hash salt (from .env, not saved to database)
     * @since 5.0.0
     */
    public ?string $ipHashSalt = null;

    /**
     * @var bool Enable geo-location detection
     * @since 5.0.0
     */
    public bool $enableGeoDetection = false;

    /**
     * @var string Geo IP lookup provider (ip-api.com, ipapi.co, ipinfo.io)
     * @since 5.0.0
     */
    public string $geoProvider = 'ip-api.com';

    /**
     * @var string|null API key for paid provider tiers (enables HTTPS for ip-api.com)
     * @since 5.0.0
     */
    public ?string $geoApiKey = null;

    /**
     * @var string|null Default country for local development (when IP is private)
     * @since 5.0.0
     */
    public ?string $defaultCountry = null;

    /**
     * @var string|null Default city for local development (when IP is private)
     * @since 5.0.0
     */
    public ?string $defaultCity = null;

    /**
     * @var bool Cache device detection results
     * @since 5.0.0
     */
    public bool $cacheDeviceDetection = true;

    /**
     * @var int Device detection cache duration in seconds
     * @since 5.0.0
     */
    public int $deviceDetectionCacheDuration = 3600;

    /**
     * @var string|null Prefix for index names (multi-environment support)
     * @since 5.0.0
     */
    public ?string $indexPrefix = null;

    /**
     * @var float BM25 K1 parameter (term frequency saturation)
     * @since 5.0.0
     */
    public float $bm25K1 = 1.5;

    /**
     * @var float BM25 B parameter (document length normalization)
     * @since 5.0.0
     */
    public float $bm25B = 0.75;

    /**
     * @var float Title boost factor
     * @since 5.0.0
     */
    public float $titleBoostFactor = 5.0;

    /**
     * @var float Exact match boost factor
     * @since 5.0.0
     */
    public float $exactMatchBoostFactor = 3.0;

    /**
     * @var string N-gram sizes for fuzzy matching (comma-separated)
     * @since 5.0.0
     */
    public string $ngramSizes = '2,3';

    /**
     * @var float Similarity threshold for fuzzy search
     * @since 5.0.0
     */
    public float $similarityThreshold = 0.25;

    /**
     * @var int Maximum fuzzy candidates to process
     * @since 5.0.0
     */
    public int $maxFuzzyCandidates = 100;

    /**
     * @var bool Enable search results caching
     * @since 5.0.0
     */
    public bool $enableCache = true;

    /**
     * @var int Cache duration in seconds
     * @since 5.0.0
     */
    public int $cacheDuration = 3600;

    /**
     * @var string Cache storage method (file or redis)
     * @since 5.0.0
     */
    public string $cacheStorageMethod = 'file';

    /**
     * @var bool Only cache popular queries
     * @since 5.0.0
     */
    public bool $cachePopularQueriesOnly = false;

    /**
     * @var int Threshold for popular queries (search count)
     * @since 5.0.0
     */
    public int $popularQueryThreshold = 5;

    /**
     * @var bool Clear search cache when elements are saved
     * @since 5.0.0
     */
    public bool $clearCacheOnSave = true;

    /**
     * @var int Status sync interval in minutes (0 = disabled)
     * Syncs entries that became live (postDate passed) or expired (expiryDate passed)
     * @since 5.0.0
     */
    public int $statusSyncInterval = 15;

    /**
     * @var bool Enable cache warming after index rebuild
     * @since 5.0.0
     */
    public bool $enableCacheWarming = true;

    /**
     * @var int Number of popular queries to warm after rebuild
     * @since 5.0.0
     */
    public int $cacheWarmingQueryCount = 50;

    // =========================================================================
    // SEARCH SETTINGS (Advanced Search Features)
    // =========================================================================

    /**
     * @var float Phrase search boost factor (for "exact phrase" searches)
     * @since 5.0.0
     */
    public float $phraseBoostFactor = 4.0;

    /**
     * @var bool Enable stop words filtering
     * @since 5.0.0
     */
    public bool $enableStopWords = true;

    /**
     * @var string|null Default language for search (null = auto-detect from site)
     * @since 5.0.0
     */
    public ?string $defaultLanguage = null;

    // =========================================================================
    // HIGHLIGHTING SETTINGS
    // =========================================================================

    /**
     * @var bool Enable search result highlighting
     * @since 5.0.0
     */
    public bool $enableHighlighting = true;

    /**
     * @var string HTML tag for highlighted terms
     * @since 5.0.0
     */
    public string $highlightTag = 'mark';

    /**
     * @var string|null CSS class for highlighted terms
     * @since 5.0.0
     */
    public ?string $highlightClass = null;

    /**
     * @var int Snippet length in characters
     * @since 5.0.0
     */
    public int $snippetLength = 200;

    /**
     * @var int Maximum number of snippets per result
     * @since 5.0.0
     */
    public int $maxSnippets = 3;

    // =========================================================================
    // AUTOCOMPLETE SETTINGS
    // =========================================================================

    /**
     * @var bool Enable autocomplete/suggestions
     * @since 5.0.0
     */
    public bool $enableAutocomplete = true;

    /**
     * @var int Minimum query length for autocomplete
     * @since 5.0.0
     */
    public int $autocompleteMinLength = 2;

    /**
     * @var int Maximum number of autocomplete suggestions
     * @since 5.0.0
     */
    public int $autocompleteLimit = 10;

    /**
     * @var bool Enable fuzzy matching in autocomplete
     * @since 5.0.0
     */
    public bool $autocompleteFuzzy = false;

    /**
     * @var bool Enable autocomplete result caching
     * @since 5.0.0
     */
    public bool $enableAutocompleteCache = true;

    /**
     * @var int Autocomplete cache duration in seconds (default: 5 minutes)
     * @since 5.0.0
     */
    public int $autocompleteCacheDuration = 300;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
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
    // TRAIT CONFIGURATION
    // =========================================================================

    protected static function tableName(): string
    {
        return 'searchmanager_settings';
    }

    protected static function pluginHandle(): string
    {
        return 'search-manager';
    }

    protected static function booleanFields(): array
    {
        return [
            'autoIndex',
            'queueEnabled',
            'replaceNativeSearch',
            'enableAnalytics',
            'anonymizeIpAddress',
            'enableGeoDetection',
            'cacheDeviceDetection',
            'enableCache',
            'cachePopularQueriesOnly',
            'clearCacheOnSave',
            'enableStopWords',
            'enableHighlighting',
            'enableAutocomplete',
            'autocompleteFuzzy',
            'enableAutocompleteCache',
            'enableCacheWarming',
        ];
    }

    protected static function integerFields(): array
    {
        return [
            'itemsPerPage',
            'batchSize',
            'analyticsRetention',
            'deviceDetectionCacheDuration',
            'maxFuzzyCandidates',
            'cacheDuration',
            'popularQueryThreshold',
            'statusSyncInterval',
            'snippetLength',
            'maxSnippets',
            'autocompleteMinLength',
            'autocompleteLimit',
            'autocompleteCacheDuration',
            'cacheWarmingQueryCount',
        ];
    }

    protected static function floatFields(): array
    {
        return [
            'bm25K1',
            'bm25B',
            'titleBoostFactor',
            'exactMatchBoostFactor',
            'similarityThreshold',
            'phraseBoostFactor',
        ];
    }

    protected static function excludeFromSave(): array
    {
        return [
            'ipHashSalt',
            'defaultCountry',
            'defaultCity',
        ];
    }

    // =========================================================================
    // VALIDATION RULES
    // =========================================================================

    /** @inheritdoc */
    public function rules(): array
    {
        return [
            [['pluginName', 'logLevel'], 'required'],
            [['pluginName'], 'string', 'max' => 255],
            [['indexPrefix'], 'string', 'max' => 50],
            [['autoIndex', 'queueEnabled', 'replaceNativeSearch', 'enableAnalytics', 'enableCache', 'cachePopularQueriesOnly', 'clearCacheOnSave', 'anonymizeIpAddress', 'enableGeoDetection', 'cacheDeviceDetection', 'enableStopWords', 'enableHighlighting', 'enableAutocomplete', 'autocompleteFuzzy', 'enableAutocompleteCache', 'enableCacheWarming'], 'boolean'],
            [['statusSyncInterval'], 'integer', 'min' => 0, 'max' => 1440],
            [['ipHashSalt'], 'string', 'min' => 32, 'skipOnEmpty' => true],
            [['cacheStorageMethod'], 'in', 'range' => ['file', 'redis']],
            [['geoProvider'], 'in', 'range' => ['ip-api.com', 'ipapi.co', 'ipinfo.io']],
            [['geoApiKey'], 'string', 'max' => 255, 'skipOnEmpty' => true],
            [['itemsPerPage', 'batchSize', 'analyticsRetention', 'maxFuzzyCandidates', 'cacheDuration', 'popularQueryThreshold', 'deviceDetectionCacheDuration', 'snippetLength', 'maxSnippets', 'autocompleteMinLength', 'autocompleteLimit'], 'integer', 'min' => 1],
            [['itemsPerPage'], 'integer', 'max' => 500],
            [['batchSize'], 'integer', 'max' => 1000],
            [['maxFuzzyCandidates'], 'integer', 'min' => 10, 'max' => 1000],
            [['snippetLength'], 'integer', 'min' => 50, 'max' => 1000],
            [['maxSnippets'], 'integer', 'min' => 1, 'max' => 10],
            [['autocompleteMinLength'], 'integer', 'min' => 1, 'max' => 5],
            [['autocompleteLimit'], 'integer', 'min' => 1, 'max' => 50],
            [['cacheWarmingQueryCount'], 'integer', 'min' => 1, 'max' => 200],
            [['cacheDuration'], 'integer', 'min' => 60, 'max' => 86400],
            [['autocompleteCacheDuration'], 'integer', 'min' => 60, 'max' => 3600],
            [['deviceDetectionCacheDuration'], 'integer', 'min' => 60, 'max' => 604800],
            [['popularQueryThreshold'], 'integer', 'min' => 2, 'max' => 1000],
            [['bm25K1'], 'number', 'min' => 0.1, 'max' => 5.0],
            [['bm25B', 'similarityThreshold'], 'number', 'min' => 0.0, 'max' => 1.0],
            [['titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor'], 'number', 'min' => 1.0, 'max' => 20.0],
            [['ngramSizes', 'highlightTag'], 'string'],
            [['highlightClass', 'defaultLanguage'], 'string', 'skipOnEmpty' => true],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
            [['defaultBackendHandle', 'defaultWidgetHandle'], 'string', 'max' => 255, 'skipOnEmpty' => true],
        ];
    }

    /**
     * Get the full index name with prefix applied
     *
     * @since 5.39.0
     * @param string $indexName The short index handle
     * @return string The prefixed index name
     */
    public function getFullIndexName(string $indexName): string
    {
        return ($this->indexPrefix ?? '') . $indexName;
    }
}
