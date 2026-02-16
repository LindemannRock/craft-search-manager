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
        'pluginName' => 'Search Manager',

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
         * Cache Storage Method
         * 'file' = File system (default, single server)
         * 'redis' = Redis/Database (load-balanced, multi-server, cloud hosting)
         * Default: 'file'
         */
        // 'cacheStorageMethod' => 'file',

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
         * Only applies when cachePopularQueriesOnly is true
         * Default: 5
         */
        // 'popularQueryThreshold' => 5,

        /**
         * Clear cache when elements are saved
         * Disable for high-traffic sites to reduce cache thrashing
         * Default: true
         */
        // 'clearCacheOnSave' => true,

        /**
         * Status sync interval in minutes
         * Syncs entries that become live (postDate) or expire (expiryDate)
         * Set to 0 to disable
         * Default: 15
         */
        // 'statusSyncInterval' => 15,

        // ========================================
        // AUTOCOMPLETE CACHE SETTINGS
        // ========================================

        /**
         * Enable autocomplete result caching
         * Separate from search results cache, uses shorter TTL
         * Default: true
         */
        // 'enableAutocompleteCache' => true,

        /**
         * Autocomplete cache duration in seconds
         * Recommended: shorter than search cache (60-3600)
         * Default: 300 (5 minutes)
         */
        // 'autocompleteCacheDuration' => 300,

        // ========================================
        // CACHE WARMING SETTINGS
        // ========================================

        /**
         * Enable cache warming after index rebuild
         * Pre-caches popular queries for faster first visits
         * Default: true
         */
        // 'enableCacheWarming' => true,

        /**
         * Number of popular queries to warm after rebuild
         * Queries are pulled from analytics data
         * Default: 50
         */
        // 'cacheWarmingQueryCount' => 50,

        // ========================================
        // DEVICE DETECTION CACHE SETTINGS
        // ========================================

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
         * Geo IP lookup provider
         * Options: 'ip-api.com', 'ipapi.co', 'ipinfo.io'
         * - ip-api.com: HTTP free (45/min), HTTPS requires paid key (default, backward compatible)
         * - ipapi.co: HTTPS, 1,000 requests/day free
         * - ipinfo.io: HTTPS, 50,000 requests/month free
         * Default: 'ip-api.com'
         */
        // 'geoProvider' => 'ip-api.com',

        /**
         * Geo provider API key
         * Required for ip-api.com HTTPS (Pro tier)
         * Optional for ipapi.co and ipinfo.io (increases rate limits)
         * Default: null
         */
        // 'geoApiKey' => App::env('SEARCH_MANAGER_GEO_API_KEY'),

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

        // ========================================
        // BACKEND CONFIGURATION
        // ========================================

        /**
         * Default backend handle
         * Must match a handle from backends (or database)
         * Auto-assigned: If not set, deleted, or disabled, automatically assigns first enabled backend
         * Default: null
         */
        // 'defaultBackendHandle' => 'production-algolia',

        /**
         * Backend instances
         * Define named backend instances with their own credentials
         * These are marked as source='config' and cannot be edited in CP
         *
         * Available backend types: 'algolia', 'meilisearch', 'typesense', 'mysql', 'pgsql', 'redis', 'file'
         */
        'backends' => [
            // Example: Algolia for production
            // 'production-algolia' => [
            //     'name' => 'Production Algolia',
            //     'backendType' => 'algolia',
            //     'enabled' => true,
            //     'settings' => [
            //         'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
            //         'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
            //         'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
            //     ],
            // ],

            // Example: Meilisearch for development
            // 'dev-meilisearch' => [
            //     'name' => 'Dev Meilisearch',
            //     'backendType' => 'meilisearch',
            //     'enabled' => true,
            //     'settings' => [
            //         'host' => App::env('MEILISEARCH_HOST') ?: 'http://localhost:7700',
            //         'adminApiKey' => App::env('MEILISEARCH_ADMIN_API_KEY'),
            //         'searchApiKey' => App::env('MEILISEARCH_SEARCH_API_KEY'), // Optional: for frontend
            //     ],
            // ],

            // Example: Typesense
            // 'typesense-cloud' => [
            //     'name' => 'Typesense Cloud',
            //     'backendType' => 'typesense',
            //     'enabled' => true,
            //     'settings' => [
            //         'host' => App::env('TYPESENSE_HOST'),
            //         'port' => App::env('TYPESENSE_PORT') ?: 8108,
            //         'protocol' => 'https',
            //         'apiKey' => App::env('TYPESENSE_API_KEY'),
            //     ],
            // ],

            // Example: MySQL (uses Craft's database)
            // 'mysql-backend' => [
            //     'name' => 'MySQL Backend',
            //     'backendType' => 'mysql',
            //     'enabled' => true,
            //     'settings' => [], // No settings needed, uses Craft's DB
            // ],

            // Example: PostgreSQL (uses Craft's database)
            // 'pgsql-backend' => [
            //     'name' => 'PostgreSQL Backend',
            //     'backendType' => 'pgsql',
            //     'enabled' => true,
            //     'settings' => [], // No settings needed, uses Craft's DB
            // ],

            // Example: Redis
            // 'redis-backend' => [
            //     'name' => 'Redis Backend',
            //     'backendType' => 'redis',
            //     'enabled' => true,
            //     'settings' => [
            //         'host' => App::env('REDIS_HOST') ?: 'redis',
            //         'port' => App::env('REDIS_PORT') ?: 6379,
            //         'password' => App::env('REDIS_PASSWORD'),
            //         'database' => 0,
            //     ],
            // ],

            // Example: File backend (local JSON files)
            // 'file-backend' => [
            //     'name' => 'File Backend',
            //     'backendType' => 'file',
            //     'enabled' => true,
            //     'settings' => [
            //         'storagePath' => '', // Empty = @storage/runtime/search-manager/indices/
            //     ],
            // ],
        ],

        // ========================================
        // INDEX DEFINITIONS
        // ========================================

        /**
         * Define search indices
         * These will be merged with indices created via Control Panel
         * Indices defined here are marked as source='config' and cannot be edited in CP
         *
         * Available options:
         * - name: Display name for the index
         * - elementType: Element class (Entry::class, Asset::class, etc.)
         * - siteId: Site ID - int for single site, array of ints for multiple sites, null for all sites
         * - criteria: Closure to filter elements
         * - transformer: Custom transformer class (optional)
         * - headingLevels: Array of heading levels to extract (optional, default: [2,3,4])
         * - language: Language code for stemming/stop words (optional, auto-detected from site)
         * - backend: Handle of configured backend (optional, uses defaultBackendHandle if not set)
         * - enabled: Whether the index is active
         * - enableAnalytics: Whether to track search analytics for this index (default: true)
         * - disableStopWords: Disable stop words filtering for this index (default: false)
         * - skipEntriesWithoutUrl: Skip indexing entries that don't have a URL (default: false)
         */
        'indices' => [
            // Example: English entries index using default backend
            // 'entries-en' => [
            //     'name' => 'Entries (English)',
            //     'elementType' => Entry::class,
            //     'siteId' => 1,
            //     'criteria' => function(\craft\elements\db\EntryQuery $query) {
            //         return $query->section(['news', 'blog'])->status('enabled');
            //     },
            //     'transformer' => \modules\searchmodule\transformers\EntryEnTransformer::class,
            //     'language' => 'en',
            //     'enabled' => true,
            // ],

            // Example: Arabic entries index with specific backend
            // 'entries-ar' => [
            //     'name' => 'Entries (Arabic)',
            //     'elementType' => Entry::class,
            //     'siteId' => 2,
            //     'criteria' => function(\craft\elements\db\EntryQuery $query) {
            //         return $query->section(['news', 'blog'])->status('enabled');
            //     },
            //     'transformer' => \modules\searchmodule\transformers\EntryArTransformer::class,
            //     'language' => 'ar',
            //     'backend' => 'production-algolia', // Use specific backend for this index
            //     'enabled' => true,
            //     'enableAnalytics' => true, // Track search analytics for this index
            //     'skipEntriesWithoutUrl' => false, // Skip entries without a URL
            // ],

            // Example: Multi-site index (specific sites only)
            // 'entries-regional' => [
            //     'name' => 'Entries (Regional)',
            //     'elementType' => Entry::class,
            //     'siteId' => [1, 3], // Multiple specific sites
            //     'criteria' => function(\craft\elements\db\EntryQuery $query) {
            //         return $query->section('news');
            //     },
            // ],
        ],

        // ========================================
        // WIDGETS
        // ========================================

        /**
         * Default widget handle
         * Must match a handle from widgets (or database)
         * Auto-assigned: If not set, deleted, or disabled, automatically assigns first enabled widget
         * Default: null
         */
        // 'defaultWidgetHandle' => 'brand-search',

        /**
         * Widget configurations
         * Define search widget configurations with custom styles and behavior
         * These are marked as source='config' and cannot be edited in CP
         *
         * Each widget controls WHAT to search and HOW it behaves. Visual appearance
         * can be set two ways:
         *   1. styleHandle — reference a reusable style preset from widgetStyles (recommended)
         *   2. settings.styles — define inline styles directly on the widget (standalone)
         * If both are set, styleHandle takes priority. If neither is set, defaults apply.
         *
         * SERVER-ENFORCED LIMITS (security):
         * - Query length: Max 256 characters (widget input enforces this client-side)
         * - Max results: Capped at 100 (behavior.maxResults values above 100 are silently capped)
         * - Max indices: Max 5 indices per search (search.indexHandles arrays with >5 items are truncated)
         * - Analytics resultsCount: Capped at 1000
         * - Analytics source: Alphanumeric + dash/underscore only, max 64 chars
         *
         * Available options:
         * - name: Display name for the config
         * - type: Widget type ('modal', 'page', 'inline') — default: 'modal'
         * - enabled: Whether the config is active
         * - styleHandle: Handle of a widget style preset (from widgetStyles below or CP)
         * - settings: Widget settings (merged with defaults)
         *   - search.indexHandles: Array of index handles to search (empty = all, max 5)
         *   - highlighting: Highlight settings (enabled, tag, class)
         *   - backdrop: Modal backdrop (opacity, blur)
         *   - behavior: Widget behavior settings
         *     - debounce: Delay before search triggers in ms (default: 200)
         *     - minChars: Minimum chars before search (default: 2)
         *     - maxResults: Max results to show, capped at 100 (default: 10)
         *     - showRecent: Show recent searches (default: true)
         *     - maxRecentSearches: Max recent searches stored (default: 5)
         *     - groupResults: Group results by section/type (default: true)
         *     - hotkey: Keyboard shortcut key, e.g. 'k' for Cmd/Ctrl+K (default: 'k')
         *     - hideResultsWithoutUrl: Hide results that don't have a URL (default: false)
         *     - showLoadingIndicator: Show spinner while searching (default: true)
         *     - preventBodyScroll: Prevent page scroll when modal is open (default: true)
         *     - resultLayout: 'default' (flat list) or 'hierarchical' (parent/child) (default: 'default')
         *     - showCodeSnippets: Show code block snippets in results (default: false)
         *     - snippetMode: How snippets find the best passage — 'early' (first match),
         *       'balanced' (best density), 'deep' (exhaustive scan) (default: 'balanced')
         *     - resultTitleLines: Max lines for result title, 1-5 (default: 1)
         *     - resultDescLines: Max lines for result description, 1-5 (default: 1)
         *     - snippetLength: Snippet length in characters, 50-500 (default: 150)
         *     - parseMarkdownSnippets: Parse markdown in snippets (default: false)
         *   - trigger: Trigger button (showTrigger, triggerText)
         *   - analytics: Analytics tracking
         *     - source: Identifier in analytics reports (alphanumeric, max 64 chars)
         *     - idleTimeout: Track search after idle in ms, 0 = disabled (default: 1500)
         *   - styles: Inline visual styles (alternative to styleHandle, see widgetStyles for keys)
         */
        'widgets' => [
            // Example 1: Widget with a style preset (recommended for shared styles)
            // 'main-search' => [
            //     'name' => 'Main Search',
            //     'type' => 'modal',
            //     'enabled' => true,
            //     'styleHandle' => 'brand-theme', // References a style from widgetStyles below
            //     'settings' => [
            //         'search' => [
            //             'indexHandles' => ['entries-en', 'products'],
            //         ],
            //         'highlighting' => [
            //             'enabled' => true,
            //             'tag' => 'mark',
            //             'class' => null,
            //         ],
            //         'backdrop' => [
            //             'opacity' => 50,
            //             'blur' => true,
            //         ],
            //         'behavior' => [
            //             'preventBodyScroll' => true,
            //             'debounce' => 200,
            //             'minChars' => 2,
            //             'maxResults' => 10,
            //             'showRecent' => true,
            //             'maxRecentSearches' => 5,
            //             'groupResults' => true,
            //             'hotkey' => 'k',
            //             'hideResultsWithoutUrl' => false,
            //             'showLoadingIndicator' => true,
            //             'resultLayout' => 'default',
            //             'showCodeSnippets' => false,
            //             'snippetMode' => 'balanced',
            //             'resultTitleLines' => 1,
            //             'resultDescLines' => 1,
            //             'snippetLength' => 150,
            //             'parseMarkdownSnippets' => false,
            //         ],
            //         'trigger' => [
            //             'showTrigger' => true,
            //             'triggerText' => 'Search',
            //         ],
            //         'analytics' => [
            //             'source' => 'header-search',
            //             'idleTimeout' => 1500,
            //         ],
            //     ],
            // ],

            // Example 2: Widget with inline styles (standalone, no preset needed)
            // Useful for a one-off widget that doesn't share its look with others.
            // 'docs-search' => [
            //     'name' => 'Docs Search',
            //     'type' => 'modal',
            //     'enabled' => true,
            //     'settings' => [
            //         'search' => [
            //             'indexHandles' => ['docs-manager'],
            //         ],
            //         'behavior' => [
            //             'resultLayout' => 'hierarchical',
            //             'snippetMode' => 'deep',
            //             'resultDescLines' => 2,
            //             'snippetLength' => 200,
            //         ],
            //         // Inline styles — same keys as widgetStyles.*.styles
            //         // These apply directly to this widget without a shared preset
            //         'styles' => [
            //             'modalBg' => '#ffffff',
            //             'modalBgDark' => '#0f172a',
            //             'modalBorderRadius' => '16',
            //             'inputBg' => '#f8fafc',
            //             'inputBgDark' => '#1e293b',
            //             'spinnerColor' => '#6366f1',
            //             'spinnerColorDark' => '#818cf8',
            //         ],
            //     ],
            // ],

            // Example 3: Minimal widget (hotkey-only, no trigger button)
            // Only overrides what differs from defaults — everything else inherits.
            // 'minimal' => [
            //     'name' => 'Minimal',
            //     'enabled' => true,
            //     'settings' => [
            //         'behavior' => [
            //             'showRecent' => false,
            //             'groupResults' => false,
            //         ],
            //         'trigger' => [
            //             'showTrigger' => false,
            //         ],
            //     ],
            // ],
        ],

        // ========================================
        // WIDGET STYLES
        // ========================================

        /**
         * Widget style presets
         * Reusable visual themes that can be shared across multiple widgets.
         * Reference a style by its handle via styleHandle on a widget config.
         * These are marked as source='config' and cannot be edited in CP.
         * Styles can also be created in the CP under Widgets > Styles.
         *
         * Available options:
         * - name: Display name for the style
         * - type: Widget type this style applies to ('modal', 'page', 'inline') — default: 'modal'
         * - enabled: Whether the style is active (default: true)
         * - styles: Visual style properties — all values are strings
         *   Each property has a light mode key and a dark mode key (suffixed with 'Dark').
         *   Only override what you need — unset properties use built-in defaults.
         *
         * Style property groups:
         *   Modal:        modalBg, modalBorderColor, modalBorderWidth, modalBorderRadius,
         *                 modalShadow, modalMaxWidth (px), modalMaxHeight (vh),
         *                 modalPaddingX (px), modalPaddingY (px)
         *   Input:        inputBg, inputTextColor, inputPlaceholderColor,
         *                 inputBorderColor, inputFontSize (px)
         *   Results:      resultBg, resultBorderColor, resultTextColor, resultDescColor,
         *                 resultMutedColor, resultActiveBg, resultActiveBorderColor,
         *                 resultActiveTextColor, resultActiveDescColor, resultActiveMutedColor
         *   Spinner:      spinnerColor
         *   Highlighting: highlightBgLight, highlightColorLight (+ Dark variants)
         *   Backdrop:     backdropOpacity (0-100), backdropBlur (px, 0 = disabled)
         *   Trigger:      triggerBg, triggerTextColor, triggerBorderColor,
         *                 triggerBorderRadius, triggerFontSize
         *   Kbd Badge:    kbdBg, kbdBorderColor, kbdTextColor
         */
        'widgetStyles' => [
            // Example: Brand theme style
            // 'brand-theme' => [
            //     'name' => 'Brand Theme',
            //     'type' => 'modal',
            //     'enabled' => true,
            //     'styles' => [
            //         // Modal
            //         'modalBg' => '#ffffff',
            //         'modalBgDark' => '#1f2937',
            //         'modalBorderColor' => '#e5e7eb',
            //         'modalBorderColorDark' => '#374151',
            //         'modalBorderWidth' => '1',
            //         'modalBorderRadius' => '12',
            //         'modalShadow' => '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
            //         'modalMaxWidth' => '700',       // px
            //         'modalMaxHeight' => '80',       // vh units
            //         'modalPaddingX' => '20',        // px
            //         'modalPaddingY' => '20',        // px
            //         // Input
            //         'inputBg' => '#f9fafb',
            //         'inputBgDark' => '#111827',
            //         'inputTextColor' => '#111827',
            //         'inputTextColorDark' => '#f9fafb',
            //         'inputPlaceholderColor' => '#9ca3af',
            //         'inputPlaceholderColorDark' => '#6b7280',
            //         'inputBorderColor' => '#e5e7eb',
            //         'inputBorderColorDark' => '#374151',
            //         'inputFontSize' => '16',        // px
            //         // Results
            //         'resultTextColor' => '#111827',
            //         'resultTextColorDark' => '#f9fafb',
            //         'resultDescColor' => '#4b5563',
            //         'resultDescColorDark' => '#d1d5db',
            //         // Spinner
            //         'spinnerColor' => '#3b82f6',
            //         'spinnerColorDark' => '#60a5fa',
            //         // Highlighting
            //         'highlightBgLight' => '#fef08a',
            //         'highlightColorLight' => '#854d0e',
            //         'highlightBgDark' => '#854d0e',
            //         'highlightColorDark' => '#fef08a',
            //         // Backdrop
            //         'backdropOpacity' => '50',      // 0-100
            //         'backdropBlur' => '4',          // px, 0 = disabled
            //     ],
            // ],

            // Example: Minimal dark style
            // 'minimal-dark' => [
            //     'name' => 'Minimal Dark',
            //     'type' => 'modal',
            //     'enabled' => true,
            //     'styles' => [
            //         'modalBg' => '#0f172a',
            //         'modalBorderWidth' => '0',
            //         'modalBorderRadius' => '16',
            //         'inputBg' => '#1e293b',
            //         'inputTextColor' => '#e2e8f0',
            //     ],
            // ],
        ],

    ],

    // ========================================
    // DEVELOPMENT ENVIRONMENT
    // ========================================
    'dev' => [
        'logLevel' => 'debug',
        'indexPrefix' => 'dev_',
        'queueEnabled' => false, // Process immediately in dev
        'enableCache' => false, // Disable cache for testing
        'enableAutocompleteCache' => false, // Disable autocomplete cache for testing
        'enableCacheWarming' => false, // No need to warm cache in dev
        'cacheDuration' => 300, // 5 minutes (if enabled)
        'autocompleteCacheDuration' => 60, // 1 minute (if enabled)
        'deviceDetectionCacheDuration' => 1800, // 30 minutes
        // 'defaultBackendHandle' => 'dev-meilisearch', // Use Meilisearch in dev
    ],

    // ========================================
    // STAGING ENVIRONMENT
    // ========================================
    'staging' => [
        'logLevel' => 'info',
        'indexPrefix' => 'staging_',
        'enableCache' => true,
        'enableAutocompleteCache' => true,
        'enableCacheWarming' => true,
        'cacheDuration' => 1800, // 30 minutes
        'autocompleteCacheDuration' => 300, // 5 minutes
        'deviceDetectionCacheDuration' => 3600, // 1 hour
        'cacheWarmingQueryCount' => 25, // Fewer queries for staging
        // 'defaultBackendHandle' => 'staging-algolia', // Use staging Algolia
    ],

    // ========================================
    // PRODUCTION ENVIRONMENT
    // ========================================
    'production' => [
        'logLevel' => 'error',
        'indexPrefix' => 'prod_',
        'queueEnabled' => true,
        'enableCache' => true,
        'enableAutocompleteCache' => true,
        'enableCacheWarming' => true,
        'cacheStorageMethod' => 'redis',  // Use Redis for production (Servd/AWS/Platform.sh)
        'cacheDuration' => 7200, // 2 hours (optimize for performance)
        'autocompleteCacheDuration' => 600, // 10 minutes
        'deviceDetectionCacheDuration' => 86400, // 24 hours (user agents rarely change)
        'cachePopularQueriesOnly' => true, // Save cache space
        'popularQueryThreshold' => 3, // Cache after 3 searches
        'cacheWarmingQueryCount' => 100, // Warm more queries in production
        'clearCacheOnSave' => true, // Keep cache fresh
        'statusSyncInterval' => 15, // Check for scheduled entries every 15 min
        // 'defaultBackendHandle' => 'production-algolia', // Use production Algolia
    ],
];
