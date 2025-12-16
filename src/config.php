<?php

/**
 * Search Manager plugin configuration file
 *
 * IMPORTANT: This config file acts as an OVERRIDE layer only
 * - Settings are stored in the database ({{%searchmanager_settings}} table)
 * - Values defined here will override database settings (read-only)
 * - Settings overridden by this file cannot be changed in the Control Panel
 * - A warning will be displayed in the CP when a setting is overridden
 *
 * Multi-environment support:
 * - Use '*' for settings that apply to all environments
 * - Use 'dev', 'staging', 'production' for environment-specific overrides
 * - Environment-specific settings will be merged with '*' settings
 *
 * Copy this file to config/search-manager.php to use it
 *
 * @since 5.0.0
 */

use craft\elements\Entry;
use craft\helpers\App;

return [
    // ========================================
    // GLOBAL SETTINGS (All Environments)
    // ========================================
    '*' => [
        // ========================================
        // GENERAL SETTINGS
        // ========================================

        /**
         * Plugin name (displayed in Control Panel)
         * Default: 'Search Manager'
         */
        // 'pluginName' => 'Search Manager',

        /**
         * Log level for plugin operations
         * Options: 'debug', 'info', 'warning', 'error'
         * Default: 'error'
         */
        // 'logLevel' => 'error',

        /**
         * Number of items per page in CP listings
         * Default: 100
         */
        // 'itemsPerPage' => 100,

        // ========================================
        // INDEXING SETTINGS
        // ========================================

        /**
         * Automatically index elements when saved
         * Default: true
         */
        // 'autoIndex' => true,

        /**
         * Use queue for indexing operations
         * Recommended: true for production
         * Default: true
         */
        // 'queueEnabled' => true,

        /**
         * Replace Craft's native search service
         * When true, CP searches and Entry::find()->search() use your backend
         * When false, use craft.searchManager.search() in templates
         * Default: false
         */
        // 'replaceNativeSearch' => false,

        /**
         * Batch size for bulk indexing operations
         * Default: 100
         */
        // 'batchSize' => 100,

        /**
         * Prefix for index names
         * Useful for multi-environment setups
         * Example: 'dev_', 'staging_', 'prod_'
         * Default: null
         */
        // 'indexPrefix' => App::env('SEARCH_INDEX_PREFIX'),

        // ========================================
        // CACHE SETTINGS
        // ========================================

        /**
         * Enable search results caching
         * Default: true
         */
        // 'enableCache' => true,

        /**
         * Cache duration in seconds
         * Default: 3600 (1 hour)
         */
        // 'cacheDuration' => 3600,

        /**
         * Only cache popular queries
         * Default: false
         */
        // 'cachePopularQueriesOnly' => false,

        /**
         * Threshold for popular queries (search count)
         * Default: 5
         */
        // 'popularQueryThreshold' => 5,

        /**
         * Cache device detection results
         * Parses user agents to identify devices/browsers
         * Default: true
         */
        // 'cacheDeviceDetection' => true,

        /**
         * Device detection cache duration in seconds
         * Default: 3600 (1 hour)
         */
        // 'deviceDetectionCacheDuration' => 3600,

        // ========================================
        // SEARCH ALGORITHM SETTINGS (MySQL, File, Redis backends)
        // ========================================

        /**
         * BM25 K1 parameter (term frequency weight)
         * Default: 1.5
         */
        // 'bm25K1' => 1.5,

        /**
         * BM25 B parameter (document length impact)
         * Default: 0.75
         */
        // 'bm25B' => 0.75,

        /**
         * Title boost factor
         * Default: 5.0
         */
        // 'titleBoostFactor' => 5.0,

        /**
         * Exact match boost factor
         * Default: 3.0
         */
        // 'exactMatchBoostFactor' => 3.0,

        /**
         * Phrase boost factor (for "exact phrase" searches with quotes)
         * Default: 4.0
         */
        // 'phraseBoostFactor' => 4.0,

        /**
         * N-gram sizes for fuzzy matching (comma-separated)
         * Default: '2,3'
         */
        // 'ngramSizes' => '2,3',

        /**
         * Similarity threshold for fuzzy search
         * Default: 0.25
         */
        // 'similarityThreshold' => 0.25,

        /**
         * Maximum fuzzy candidates to process
         * Default: 100
         */
        // 'maxFuzzyCandidates' => 100,

        // ========================================
        // LANGUAGE & STOP WORDS
        // ========================================

        /**
         * Enable stop words filtering
         * Filters common words (the, a, is, etc.) during indexing
         * Default: true
         */
        // 'enableStopWords' => true,

        /**
         * Default language (fallback when auto-detection fails)
         * null = use 'en', or specify: 'en', 'ar', 'de', 'fr', 'es'
         * Default: null (auto-detect from site language)
         */
        // 'defaultLanguage' => null,

        // ========================================
        // HIGHLIGHTING & SNIPPETS
        // ========================================

        /**
         * Enable search result highlighting
         * Default: true
         */
        // 'enableHighlighting' => true,

        /**
         * HTML tag for highlighted terms
         * Common tags: mark, em, strong, span
         * Default: 'mark'
         */
        // 'highlightTag' => 'mark',

        /**
         * CSS class for highlighted terms (optional)
         * Default: null
         */
        // 'highlightClass' => 'search-highlight',

        /**
         * Snippet length in characters
         * Default: 200
         */
        // 'snippetLength' => 200,

        /**
         * Maximum number of snippets per result
         * Default: 3
         */
        // 'maxSnippets' => 3,

        // ========================================
        // AUTOCOMPLETE / SUGGESTIONS
        // ========================================

        /**
         * Enable autocomplete/suggestions
         * Default: true
         */
        // 'enableAutocomplete' => true,

        /**
         * Minimum query length for autocomplete
         * Default: 2
         */
        // 'autocompleteMinLength' => 2,

        /**
         * Maximum number of autocomplete suggestions
         * Default: 10
         */
        // 'autocompleteLimit' => 10,

        /**
         * Enable fuzzy matching in autocomplete (typo-tolerance)
         * Default: false (exact prefix matching only)
         */
        // 'autocompleteFuzzy' => false,

        // ========================================
        // ANALYTICS SETTINGS
        // ========================================

        /**
         * Enable search analytics tracking
         * Tracks queries, results, device info, and performance
         * Default: true
         */
        // 'enableAnalytics' => true,

        /**
         * Analytics data retention period in days
         * Set to 0 for unlimited retention
         * Default: 90
         */
        // 'analyticsRetention' => 90,

        /**
         * Anonymize IP addresses (subnet masking)
         * IPv4: masks last octet (192.168.1.123 → 192.168.1.0)
         * IPv6: masks last 80 bits
         * Default: false
         */
        // 'anonymizeIpAddress' => false,

        /**
         * Enable geo-location detection
         * Detects country, city, region from IP address
         * Default: false
         */
        // 'enableGeoDetection' => false,

        /**
         * IP hash salt (REQUIRED for analytics)
         * Use App::env() to reference .env variable
         * Generate via: php craft search-manager/security/generate-salt
         * Default: null
         */
        // 'ipHashSalt' => App::env('SEARCH_MANAGER_IP_SALT'),

        /**
         * Default country for local development
         * Used when IP address is private/local (127.0.0.1, 192.168.x.x, etc.)
         * 2-letter country code (US, GB, AE, etc.)
         * Default: 'AE' (Dubai, UAE)
         */
        // 'defaultCountry' => App::env('SEARCH_MANAGER_DEFAULT_COUNTRY') ?: 'AE',

        /**
         * Default city for local development
         * Used when IP address is private/local
         * Must match a city in the predefined locations list
         * Default: 'Dubai'
         */
        // 'defaultCity' => App::env('SEARCH_MANAGER_DEFAULT_CITY') ?: 'Dubai',

        /**
         * Cache device detection results
         * Caches user agent parsing for performance
         * Default: true
         */
        // 'cacheDeviceDetection' => true,

        /**
         * Device detection cache duration in seconds
         * Default: 3600 (1 hour)
         */
        // 'deviceDetectionCacheDuration' => 3600,

        // ========================================
        // BACKEND CONFIGURATION
        // ========================================

        /**
         * Active search backend
         * Options: 'algolia', 'meilisearch', 'mysql', 'typesense'
         * Default: 'algolia'
         */
        // 'searchBackend' => 'algolia',

        /**
         * Backend-specific settings
         * Configure credentials and options for each backend
         */
        'backends' => [
            // Algolia Configuration
            'algolia' => [
                'enabled' => false,
                'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
                'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
                'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
                'timeout' => 5, // seconds
                'connectTimeout' => 1, // seconds
            ],

            // File Configuration (built-in, stores in @storage/runtime/search-manager)
            'file' => [
                'enabled' => false,
                // No additional configuration needed
                // Files stored in: storage/runtime/search-manager/
            ],

            // Meilisearch Configuration
            'meilisearch' => [
                'enabled' => false,
                'host' => App::env('MEILISEARCH_HOST') ?: 'http://localhost:7700',
                'apiKey' => App::env('MEILISEARCH_API_KEY'),
                'timeout' => 5, // seconds
            ],

            // MySQL Configuration (built-in, no external service required)
            'mysql' => [
                'enabled' => false,
                'rankingAlgorithm' => 'bm25', // Options: 'bm25', 'tf-idf'
                'fuzzySearch' => true,
                'maxEditDistance' => 2, // Levenshtein distance for fuzzy matching
                'minWordLength' => 3, // Minimum word length to index
            ],

            // Redis Configuration
            'redis' => [
                'enabled' => false,
                'host' => App::env('REDIS_HOST') ?: '127.0.0.1',
                'port' => App::env('REDIS_PORT') ?: 6379,
                'password' => App::env('REDIS_PASSWORD'),
                'database' => App::env('REDIS_DATABASE') ?: 0,
                'timeout' => 2.0, // seconds
            ],

            // Typesense Configuration
            'typesense' => [
                'enabled' => false,
                'host' => App::env('TYPESENSE_HOST') ?: 'localhost',
                'port' => App::env('TYPESENSE_PORT') ?: '8108',
                'protocol' => App::env('TYPESENSE_PROTOCOL') ?: 'http',
                'apiKey' => App::env('TYPESENSE_API_KEY'),
                'connectionTimeout' => 5, // seconds
            ],
        ],

        // ========================================
        // INDEX DEFINITIONS
        // ========================================

        /**
         * Define search indices
         * These will be merged with indices created via Control Panel
         * Indices defined here are marked as source='config' and cannot be edited in CP
         *
         * Example structure:
         */
        'indices' => [
            // Example: English entries index
            // 'entries-en' => [
            //     'name' => 'Entries (English)',
            //     'elementType' => Entry::class,
            //     'siteId' => 1,
            //     'criteria' => function(\craft\elements\db\EntryQuery $query) {
            //         return $query->section(['news', 'blog'])->status('enabled');
            //     },
            //     'transformer' => \modules\searchmodule\transformers\EntryEnTransformer::class,
            //     'enabled' => true,
            // ],

            // Example: Arabic entries index
            // 'entries-ar' => [
            //     'name' => 'Entries (Arabic)',
            //     'elementType' => Entry::class,
            //     'siteId' => 2,
            //     'criteria' => function(\craft\elements\db\EntryQuery $query) {
            //         return $query->section(['news', 'blog'])->status('enabled');
            //     },
            //     'transformer' => \modules\searchmodule\transformers\EntryArTransformer::class,
            //     'enabled' => true,
            // ],
        ],

        // ========================================
        // TRANSFORMER SETTINGS
        // ========================================

        /**
         * Default transformers for element types
         * Maps element types to transformer classes
         */
        // 'transformers' => [
        //     Entry::class => \lindemannrock\searchmanager\transformers\EntryTransformer::class,
        //     \craft\elements\Asset::class => \lindemannrock\searchmanager\transformers\AssetTransformer::class,
        //     \craft\elements\Category::class => \lindemannrock\searchmanager\transformers\CategoryTransformer::class,
        // ],
    ],

    // ========================================
    // DEVELOPMENT ENVIRONMENT
    // ========================================
    'dev' => [
        'logLevel' => 'debug',
        'indexPrefix' => 'dev_',
        'queueEnabled' => false, // Process immediately in dev
        'backends' => [
            'meilisearch' => [
                'enabled' => true,
                'host' => 'http://localhost:7700',
            ],
        ],
    ],

    // ========================================
    // STAGING ENVIRONMENT
    // ========================================
    'staging' => [
        'logLevel' => 'info',
        'indexPrefix' => 'staging_',
        'backends' => [
            'meilisearch' => [
                'enabled' => true,
            ],
        ],
    ],

    // ========================================
    // PRODUCTION ENVIRONMENT
    // ========================================
    'production' => [
        'logLevel' => 'error',
        'indexPrefix' => 'prod_',
        'queueEnabled' => true,
        'backends' => [
            'algolia' => [
                'enabled' => true,
            ],
        ],
    ],
];
