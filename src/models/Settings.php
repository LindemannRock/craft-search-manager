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
use lindemannrock\base\traits\DateFormatSettingsTrait;
use lindemannrock\base\traits\DateRangeSettingsTrait;
use lindemannrock\base\traits\ExportFormatSettingsTrait;
use lindemannrock\base\traits\GeoSettingsTrait;
use lindemannrock\base\traits\ItemsPerPageSettingsTrait;
use lindemannrock\base\traits\LogLevelSettingsTrait;
use lindemannrock\base\traits\PluginNameSettingsTrait;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\search\Highlighter;
use lindemannrock\searchmanager\SearchManager;

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
    use DateFormatSettingsTrait;
    use DateRangeSettingsTrait;
    use ExportFormatSettingsTrait;
    use GeoSettingsTrait;
    use ItemsPerPageSettingsTrait;
    use LogLevelSettingsTrait;
    use LoggingTrait;
    use PluginNameSettingsTrait;
    use SettingsConfigTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;

    // =========================================================================
    // PROPERTIES (map to database columns)
    // =========================================================================

    /**
     * @var string Plugin name displayed in the control panel
     */
    public string $pluginName = 'Search Manager';

    /**
     * @var bool Automatically index elements when saved
     */
    public bool $autoIndex = true;

    /**
     * @var string|null Handle of the default configured backend
     */
    public ?string $defaultBackendHandle = null;

    /**
     * @var string|null Handle of the default widget config
     */
    public ?string $defaultWidgetHandle = null;

    /**
     * @var int Batch size for bulk indexing operations
     */
    public int $batchSize = 100;

    /**
     * @var int Minimum seconds between automatic lastIndexed metadata updates
     * @since 5.45.0
     */
    public int $lastIndexedDebounceSeconds = 60;

    /**
     * @var int Max pending sync rows processed by each batch sync job
     * @since 5.45.0
     */
    public int $syncBatchSize = 200;

    /**
     * @var int Delay in seconds before pending sync rows are drained
     * @since 5.45.0
     */
    public int $batchFlushInterval = 5;

    /**
     * @var int Seconds to keep abandoned pending sync rows before purging
     * @since 5.45.0
     */
    public int $pendingMaxAge = 3600;

    /**
     * @var int Attempts before a pending sync row is abandoned
     * @since 5.45.0
     */
    public int $batchMaxAttempts = 5;

    /**
     * @var bool Replace Craft's native search service (CP and ElementQuery search)
     */
    public bool $replaceNativeSearch = false;

    /**
     * @var bool Require a valid API key on the public search, autocomplete, and
     *           analytics tracking (track-search / track-click) endpoints. When
     *           false (default), those endpoints stay anonymous — backward
     *           compatible.
     * @since 5.47.0
     */
    public bool $requireApiKey = false;

    /**
     * @var array<int, string>|string Browser origins allowed to send public
     *     cross-origin analytics tracking pings. Same-origin pings are always
     *     allowed. Config-only; not persisted to the settings table.
     * @since 5.53.0
     */
    public array|string $trackingAllowedOrigins = [];

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
     * @var string Geo IP lookup provider (ip-api.com, ipapi.co, ipinfo.io)
     */
    public string $geoProvider = 'ip-api.com';

    /**
     * @var string|null API key for paid provider tiers (enables HTTPS for ip-api.com)
     */
    public ?string $geoApiKey = null;

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
     * @var bool Clear search cache when elements are saved
     */
    public bool $clearCacheOnSave = true;

    /**
     * @var int Status sync interval in minutes (0 = disabled)
     * Syncs entries that became live (postDate passed) or expired (expiryDate passed)
     */
    public int $statusSyncInterval = 15;

    /**
     * @var bool Enable cache warming after index rebuild
     */
    public bool $enableCacheWarming = true;

    /**
     * @var int Number of popular queries to warm after rebuild
     */
    public int $cacheWarmingQueryCount = 50;

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
    public bool $highlightResultsEnabled = true;

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
    public int $snippetMaxLength = 200;

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

    /**
     * @var bool Enable autocomplete result caching
     */
    public bool $enableAutocompleteCache = true;

    /**
     * @var int Autocomplete cache duration in seconds (default: 5 minutes)
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
            'replaceNativeSearch',
            'requireApiKey',
            'enableAnalytics',
            'anonymizeIpAddress',
            'enableGeoDetection',
            'cacheDeviceDetection',
            'enableCache',
            'clearCacheOnSave',
            'enableStopWords',
            'highlightResultsEnabled',
            'enableAutocomplete',
            'autocompleteFuzzy',
            'enableAutocompleteCache',
            'enableCacheWarming',
            'showSeconds',
            'exportsCsv',
            'exportsJson',
            'exportsExcel',
        ];
    }

    protected static function integerFields(): array
    {
        return [
            'itemsPerPage',
            'batchSize',
            'lastIndexedDebounceSeconds',
            'syncBatchSize',
            'batchFlushInterval',
            'pendingMaxAge',
            'batchMaxAttempts',
            'analyticsRetention',
            'deviceDetectionCacheDuration',
            'maxFuzzyCandidates',
            'cacheDuration',
            'statusSyncInterval',
            'snippetMaxLength',
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
            'trackingAllowedOrigins',
        ];
    }

    // =========================================================================
    // VALIDATION RULES
    // =========================================================================

    /** @inheritdoc */
    public function rules(): array
    {
        return array_merge([
            [['indexPrefix'], 'string', 'max' => 50],
            [['indexPrefix'], 'match', 'pattern' => '/^[a-zA-Z0-9_-]+$/', 'skipOnEmpty' => true, 'message' => Craft::t('search-manager', 'Index Prefix may contain only letters, numbers, underscores, and hyphens.')],
            [['autoIndex', 'replaceNativeSearch', 'requireApiKey', 'enableAnalytics', 'enableCache', 'clearCacheOnSave', 'anonymizeIpAddress', 'enableGeoDetection', 'cacheDeviceDetection', 'enableStopWords', 'highlightResultsEnabled', 'enableAutocomplete', 'autocompleteFuzzy', 'enableAutocompleteCache', 'enableCacheWarming'], 'boolean'],
            [['statusSyncInterval'], 'integer', 'min' => 0, 'max' => 1440],
            [['ipHashSalt'], 'string', 'min' => 32, 'skipOnEmpty' => true],
            [['cacheStorageMethod'], 'in', 'range' => ['file', 'redis']],
            [['batchSize', 'maxFuzzyCandidates', 'cacheDuration', 'deviceDetectionCacheDuration', 'snippetMaxLength', 'maxSnippets', 'autocompleteMinLength', 'autocompleteLimit'], 'integer', 'min' => 1],
            [['lastIndexedDebounceSeconds'], 'integer', 'min' => 0, 'max' => 3600],
            [['syncBatchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['batchFlushInterval'], 'integer', 'min' => 0, 'max' => 300],
            [['pendingMaxAge'], 'integer', 'min' => 60, 'max' => 604800],
            [['batchMaxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['analyticsRetention'], 'integer', 'min' => 0, 'max' => 3650],
            [['batchSize'], 'integer', 'max' => 1000],
            [['maxFuzzyCandidates'], 'integer', 'min' => 10, 'max' => 1000],
            [['snippetMaxLength'], 'integer', 'min' => 50, 'max' => 1000],
            [['maxSnippets'], 'integer', 'min' => 1, 'max' => 10],
            [['autocompleteMinLength'], 'integer', 'min' => 1, 'max' => 5],
            [['autocompleteLimit'], 'integer', 'min' => 1, 'max' => 50],
            [['cacheWarmingQueryCount'], 'integer', 'min' => 1, 'max' => 200],
            [['cacheDuration'], 'integer', 'min' => 60, 'max' => 86400],
            [['autocompleteCacheDuration'], 'integer', 'min' => 60, 'max' => 3600],
            [['deviceDetectionCacheDuration'], 'integer', 'min' => 60, 'max' => 604800],
            [['bm25K1'], 'number', 'min' => 0.1, 'max' => 5.0],
            [['bm25B', 'similarityThreshold'], 'number', 'min' => 0.0, 'max' => 1.0],
            [['titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor'], 'number', 'min' => 1.0, 'max' => 20.0],
            [['ngramSizes', 'highlightTag'], 'string'],
            [['highlightTag'], 'in', 'range' => Highlighter::ALLOWED_TAGS],
            [['trackingAllowedOrigins'], 'safe'],
            [['highlightClass', 'defaultLanguage'], 'string', 'skipOnEmpty' => true],
            [['defaultBackendHandle', 'defaultWidgetHandle'], 'string', 'max' => 255, 'skipOnEmpty' => true],
            [['defaultBackendHandle'], 'validateDefaultBackendHandle'],
            [['defaultWidgetHandle'], 'validateDefaultWidgetHandle'],
            [['ngramSizes'], 'validateNgramSizes'],
        ], $this->pluginNameSettingsRules(), $this->logLevelSettingsRules(), $this->dateFormatSettingsRules(), $this->dateRangeSettingsRules(), $this->exportFormatSettingsRules(), $this->geoSettingsRules(), $this->itemsPerPageSettingsRules());
    }

    /** @inheritdoc */
    public function attributeLabels(): array
    {
        return array_merge([
            // Indexing
            'autoIndex' => Craft::t('search-manager', 'Auto-Index Elements'),
            'defaultBackendHandle' => Craft::t('search-manager', 'Default Backend'),
            'defaultWidgetHandle' => Craft::t('search-manager', 'Default Widget'),
            'batchSize' => Craft::t('search-manager', 'Batch Size'),
            'lastIndexedDebounceSeconds' => Craft::t('search-manager', 'Last Indexed Debounce'),
            'syncBatchSize' => Craft::t('search-manager', 'Sync Batch Size'),
            'batchFlushInterval' => Craft::t('search-manager', 'Batch Flush Interval'),
            'pendingMaxAge' => Craft::t('search-manager', 'Pending Max Age'),
            'batchMaxAttempts' => Craft::t('search-manager', 'Batch Max Attempts'),
            'replaceNativeSearch' => Craft::t('search-manager', 'Replace Native Search'),
            'requireApiKey' => Craft::t('search-manager', 'Require API Key'),
            'statusSyncInterval' => Craft::t('search-manager', 'Status Sync Interval'),
            'indexPrefix' => Craft::t('search-manager', 'Index Prefix'),
            // Analytics + Geo (geoProvider/geoApiKey live on GeoSettingsTrait)
            'enableAnalytics' => Craft::t('search-manager', 'Enable Analytics'),
            'analyticsRetention' => Craft::t('search-manager', 'Analytics Retention'),
            'anonymizeIpAddress' => Craft::t('search-manager', 'Anonymize IP Addresses'),
            'enableGeoDetection' => Craft::t('search-manager', 'Enable Geographic Detection'),
            'cacheDeviceDetection' => Craft::t('search-manager', 'Cache Device Detection'),
            'deviceDetectionCacheDuration' => Craft::t('search-manager', 'Device Detection Cache Duration'),
            // Ranking (BM25 + boost factors)
            'bm25K1' => Craft::t('search-manager', 'Term Frequency Weight (K1)'),
            'bm25B' => Craft::t('search-manager', 'Document Length Impact (B)'),
            'titleBoostFactor' => Craft::t('search-manager', 'Title Boost'),
            'exactMatchBoostFactor' => Craft::t('search-manager', 'Exact Match Boost'),
            'phraseBoostFactor' => Craft::t('search-manager', 'Phrase Boost'),
            // Fuzzy matching
            'ngramSizes' => Craft::t('search-manager', 'Fuzzy Match Precision'),
            'similarityThreshold' => Craft::t('search-manager', 'Fuzzy Match Threshold'),
            'maxFuzzyCandidates' => Craft::t('search-manager', 'Fuzzy Candidate Limit'),
            // Cache (search results)
            'enableCache' => Craft::t('search-manager', 'Cache Search Results'),
            'cacheDuration' => Craft::t('search-manager', 'Search Results Cache Duration'),
            'cacheStorageMethod' => Craft::t('search-manager', 'Cache Storage Method'),
            'clearCacheOnSave' => Craft::t('search-manager', 'Clear Cache on Element Save'),
            'enableCacheWarming' => Craft::t('search-manager', 'Enable Cache Warming'),
            'cacheWarmingQueryCount' => Craft::t('search-manager', 'Popular Queries to Warm'),
            // Stop words + language
            'enableStopWords' => Craft::t('search-manager', 'Enable Stop Words'),
            'defaultLanguage' => Craft::t('search-manager', 'Default Language'),
            // Highlighting + snippets
            'highlightResultsEnabled' => Craft::t('search-manager', 'Result Highlighting Enabled'),
            'highlightTag' => Craft::t('search-manager', 'HTML Tag'),
            'highlightClass' => Craft::t('search-manager', 'CSS Class'),
            'snippetMaxLength' => Craft::t('search-manager', 'Snippet Max Length'),
            'maxSnippets' => Craft::t('search-manager', 'Max Snippets'),
            // Autocomplete
            'enableAutocomplete' => Craft::t('search-manager', 'Enable Autocomplete'),
            'autocompleteMinLength' => Craft::t('search-manager', 'Minimum Length'),
            'autocompleteLimit' => Craft::t('search-manager', 'Suggestion Limit'),
            'autocompleteFuzzy' => Craft::t('search-manager', 'Fuzzy Autocomplete'),
            'enableAutocompleteCache' => Craft::t('search-manager', 'Cache Autocomplete Results'),
            'autocompleteCacheDuration' => Craft::t('search-manager', 'Autocomplete Cache Duration'),
        ],
            $this->pluginNameSettingsLabel(),
            $this->logLevelSettingsLabel(),
            $this->dateFormatSettingsLabels(),
            $this->dateRangeSettingsLabel(),
            $this->exportFormatSettingsLabels(),
            $this->geoSettingsLabel(),
            $this->itemsPerPageSettingsLabel(),
        );
    }

    /**
     * Ensure default backend exists and is enabled.
     */
    public function validateDefaultBackendHandle(string $attribute): void
    {
        $handle = $this->$attribute;
        if (!$handle) {
            return;
        }

        $backend = ConfiguredBackend::findByHandle($handle);
        if (!$backend) {
            $this->addError($attribute, Craft::t(static::pluginHandle(), 'Selected backend does not exist.'));
            return;
        }

        if (!$backend->enabled) {
            $this->addError($attribute, Craft::t(static::pluginHandle(), 'Selected backend is disabled.'));
        }
    }

    /**
     * Ensure default widget exists and is enabled.
     */
    public function validateDefaultWidgetHandle(string $attribute): void
    {
        $handle = $this->$attribute;
        if (!$handle) {
            return;
        }

        $widget = SearchManager::$plugin->widgetConfigs->getByHandle($handle);
        if (!$widget) {
            $this->addError($attribute, Craft::t(static::pluginHandle(), 'Selected widget does not exist.'));
            return;
        }

        if (!$widget->enabled) {
            $this->addError($attribute, Craft::t(static::pluginHandle(), 'Selected widget is disabled.'));
        }
    }

    /**
     * Validate n-gram size configuration.
     */
    public function validateNgramSizes(string $attribute): void
    {
        $value = trim((string)$this->$attribute);
        if ($value === '') {
            return;
        }

        $tokens = array_filter(array_map('trim', explode(',', $value)), static fn(string $token): bool => $token !== '');
        if ($tokens === []) {
            return;
        }

        $allowed = ['1', '2', '3', '4', '5'];
        foreach ($tokens as $token) {
            if (!in_array($token, $allowed, true)) {
                $this->addError($attribute, Craft::t(static::pluginHandle(), 'Invalid n-gram size "{size}". Allowed values: 1, 2, 3, 4, 5.', ['size' => $token]));
                return;
            }
        }

        if (count($tokens) !== count(array_unique($tokens))) {
            $this->addError($attribute, Craft::t(static::pluginHandle(), 'Duplicate n-gram sizes are not allowed.'));
        }
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
