<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\ElementEvent;
use craft\events\ExecuteGqlQueryEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\types\AutocompleteResponseType as GqlAutocompleteResponseType;
use lindemannrock\searchmanager\gql\types\AutocompleteResultType as GqlAutocompleteResultType;
use lindemannrock\searchmanager\gql\types\IndexCountType as GqlIndexCountType;
use lindemannrock\searchmanager\gql\types\MatchedTermsType as GqlMatchedTermsType;
use lindemannrock\searchmanager\gql\types\SearchAncestorType as GqlSearchAncestorType;
use lindemannrock\searchmanager\gql\types\SearchFieldValueType as GqlSearchFieldValueType;
use lindemannrock\searchmanager\gql\types\SearchHeadingType as GqlSearchHeadingType;
use lindemannrock\searchmanager\gql\types\SearchHitType as GqlSearchHitType;
use lindemannrock\searchmanager\gql\types\SearchMetaType as GqlSearchMetaType;
use lindemannrock\searchmanager\gql\types\SearchResponseType as GqlSearchResponseType;
use lindemannrock\searchmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\searchmanager\jobs\SyncStatusJob;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\ApiKeyService;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\services\IndexedSnippetService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\LiveComparisonService;
use lindemannrock\searchmanager\services\NativeSearchCoverageService;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\services\SetupService;
use lindemannrock\searchmanager\services\sync\PendingSyncProcessor;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\variables\SearchManagerVariable;
use lindemannrock\searchmanager\widgets\AnalyticsSummaryWidget;
use lindemannrock\searchmanager\widgets\ContentGapsWidget;
use lindemannrock\searchmanager\widgets\TopSearchesWidget;
use lindemannrock\searchmanager\widgets\TrendingSearchesWidget;
use yii\base\Application as YiiApplication;
use yii\base\Event;

/**
 * Search Manager Plugin
 *
 * Advanced multi-backend search management for Craft CMS
 * Supports: Algolia, Meilisearch, MySQL, and Typesense
 *
 * @property-read BackendService $backend
 * @property-read IndexingService $indexing
 * @property-read TransformerService $transformers
 * @property-read AnalyticsService $analytics
 * @property-read ApiKeyService $apiKeys
 * @property-read AutocompleteService $autocomplete
 * @property-read DeviceDetectionService $deviceDetection
 * @property-read IndexedSnippetService $indexedSnippets
 * @property-read LiveComparisonService $liveComparison
 * @property-read NativeSearchCoverageService $nativeSearchCoverage
 * @property-read PromotionService $promotions
 * @property-read QueryRuleService $queryRules
 * @property-read SetupService $setup
 * @property-read PendingSyncRepository $pendingSyncs
 * @property-read PendingSyncProcessor $pendingSyncProcessor
 * @property-read WidgetConfigService $widgetConfigs
 * @property-read \lindemannrock\searchmanager\services\WidgetStyleService $widgetStyles
 * @property-read Settings $settings
 * @method Settings getSettings()
 * @since 5.0.0
 */
class SearchManager extends Plugin
{
    use LoggingTrait;

    // =========================================================================
    // STATIC PROPERTIES
    // =========================================================================

    /**
     * @var SearchManager|null Singleton plugin instance
     */
    public static ?SearchManager $plugin = null;

    // =========================================================================
    // PLUGIN PROPERTIES
    // =========================================================================

    /**
     * @var string Schema version for database migrations
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool Whether the plugin exposes a control panel settings page
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool Whether the plugin settings page is accessible when allowAdminChanges is false
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @var bool Whether the plugin registers a control panel section
     */
    public bool $hasCpSection = true;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Set alias for plugin path
        Craft::setAlias('@searchmanager', $this->getBasePath());

        // Register template roots for frontend templates
        $this->registerTemplateRoots();

        // Bootstrap: configure logging, register Twig extension, apply plugin name from config
        PluginHelper::bootstrap(
            $this,
            'searchHelper',
            ['searchManager:viewSystemLogs'],
            ['searchManager:downloadSystemLogs'],
            [
                'colorSets' => [
                    'backendType' => [
                        'mysql' => ColorHelper::getPaletteColor('lime'),
                        'pgsql' => ColorHelper::getPaletteColor('sky'),
                        'file' => ColorHelper::getPaletteColor('gray'),
                        'redis' => ColorHelper::getPaletteColor('fuchsia'),
                        'typesense' => ColorHelper::getPaletteColor('violet'),
                        'algolia' => ColorHelper::getPaletteColor('cyan'),
                        'meilisearch' => ColorHelper::getPaletteColor('pink'),
                    ],
                    'matchType' => [
                        'exact' => ColorHelper::getPaletteColor('indigo'),
                        'contains' => ColorHelper::getPaletteColor('purple'),
                        'prefix' => ColorHelper::getPaletteColor('amber'),
                        'regex' => ColorHelper::getPaletteColor('pink'),
                    ],
                    'actionType' => [
                        'synonym' => ColorHelper::getPaletteColor('blue'),
                        'boost_section' => ColorHelper::getPaletteColor('green'),
                        'boost_category' => ColorHelper::getPaletteColor('teal'),
                        'boost_element' => ColorHelper::getPaletteColor('lime'),
                        'redirect' => ColorHelper::getPaletteColor('red'),
                    ],
                    'widgetType' => [
                        'modal' => ColorHelper::getPaletteColor('violet'),
                        'page' => ColorHelper::getPaletteColor('sky'),
                        'inline' => ColorHelper::getPaletteColor('rose'),
                    ],
                    'pendingSyncStatus' => [
                        'pending' => ColorHelper::getPaletteColor('amber'),
                        'processing' => ColorHelper::getPaletteColor('blue'),
                        'failed' => ColorHelper::getPaletteColor('red'),
                        'abandoned' => ColorHelper::getPaletteColor('gray'),
                    ],
                    'pendingSyncOp' => [
                        'upsert' => ColorHelper::getPaletteColor('teal'),
                        'delete' => ColorHelper::getPaletteColor('red'),
                    ],
                    'nativeSearchCoverage' => [
                        'searchManager' => ColorHelper::getPaletteColor('teal'),
                        'craftNative' => ColorHelper::getPaletteColor('blue'),
                    ],
                    // Public is browser-embeddable; server is trusted server-side
                    // only. Blue + pink gives clear visual separation without
                    // overloading the `status` set's enabled/disabled hues.
                    'apiKeyType' => [
                        'public' => ColorHelper::getPaletteColor('blue'),
                        'server' => ColorHelper::getPaletteColor('pink'),
                    ],
                ],
                'installExperience' => [
                    'headline' => Craft::t('search-manager', 'Search Manager'),
                    'body' => Craft::t('search-manager', 'Configure backends, tune indexing, and manage search behavior from one control panel workspace.'),
                    'ctaLabel' => Craft::t('search-manager', 'Complete setup'),
                    'ctaUrl' => 'search-manager/setup',
                    'redirectUri' => 'search-manager/setup',
                    'confettiPreset' => 'surprise',
                ],
            ]
        );
        PluginHelper::applyPluginNameFromConfig($this);

        // Register services
        $this->registerServices();

        // Register template variables
        $this->registerTemplateVariables();

        // Register GraphQL types, queries, permissions, and cache behavior
        $this->registerGraphql();

        // Register CP routes
        $this->registerCpRoutes();

        // Register permissions
        $this->registerPermissions();

        // Register utilities
        $this->registerUtilities();

        // Register dashboard widgets
        $this->registerWidgets();

        // Register cache clearing options
        $this->registerCacheClearingOptions();

        // Replace Craft's native search service if enabled
        if ($this->getSettings()->replaceNativeSearch) {
            $this->replaceNativeSearchService();
        }

        // Install event listeners (auto-indexing, etc.)
        $this->installEventListeners();

        // Schedule status sync job (for pending→live and live→expired)
        $this->scheduleStatusSync();

        // Schedule analytics cleanup job (respects analyticsRetention setting)
        $this->scheduleAnalyticsCleanup();

        // Register console controllers
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lindemannrock\\searchmanager\\console\\controllers';
        }

        // DO NOT log in init() - it's called on every request
    }

    // =========================================================================
    // CONFIGURATION METHODS
    // =========================================================================

    /**
     * Register plugin services
     */
    private function registerServices(): void
    {
        $this->setComponents([
            'analytics' => AnalyticsService::class,
            'apiKeys' => ApiKeyService::class,
            'autocomplete' => AutocompleteService::class,
            'backend' => BackendService::class,
            'deviceDetection' => DeviceDetectionService::class,
            'indexedSnippets' => IndexedSnippetService::class,
            'indexing' => IndexingService::class,
            'liveComparison' => LiveComparisonService::class,
            'nativeSearchCoverage' => NativeSearchCoverageService::class,
            'pendingSyncs' => PendingSyncRepository::class,
            'pendingSyncProcessor' => PendingSyncProcessor::class,
            'promotions' => PromotionService::class,
            'queryRules' => QueryRuleService::class,
            'setup' => SetupService::class,
            'transformers' => TransformerService::class,
            'widgetConfigs' => WidgetConfigService::class,
            'widgetStyles' => \lindemannrock\searchmanager\services\WidgetStyleService::class,
        ]);
    }

    /**
     * Register template roots for frontend templates
     *
     * This allows frontend templates to include plugin templates using:
     * {% include 'search-manager/_widget/search-modal' %}
     */
    private function registerTemplateRoots(): void
    {
        // Register for site (frontend) requests
        if (Craft::$app->request->getIsSiteRequest()) {
            Event::on(
                View::class,
                View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
                function(RegisterTemplateRootsEvent $event) {
                    $event->roots['search-manager'] = $this->getBasePath() . '/templates';
                }
            );
        }

        // Register for CP requests (in case widget is used in CP)
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['search-manager'] = $this->getBasePath() . '/templates';
            }
        );
    }

    /**
     * Register template variables
     */
    private function registerTemplateVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('searchManager', SearchManagerVariable::class);
            }
        );
    }

    /**
     * Register Search Manager GraphQL types, queries, permissions, and cache behavior.
     */
    private function registerGraphql(): void
    {
        $graphqlCacheSetting = null;

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event) {
                $event->types[] = GqlAutocompleteResponseType::class;
                $event->types[] = GqlAutocompleteResultType::class;
                $event->types[] = GqlIndexCountType::class;
                $event->types[] = GqlMatchedTermsType::class;
                $event->types[] = GqlSearchAncestorType::class;
                $event->types[] = GqlSearchFieldValueType::class;
                $event->types[] = GqlSearchHeadingType::class;
                $event->types[] = GqlSearchHitType::class;
                $event->types[] = GqlSearchMetaType::class;
                $event->types[] = GqlSearchResponseType::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event) {
                foreach (SearchQuery::getQueries() as $key => $value) {
                    $event->queries[$key] = $value;
                }
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function(RegisterGqlSchemaComponentsEvent $event) {
                if (self::$plugin === null) {
                    return;
                }

                $pluginName = self::$plugin->getSettings()->getFullName();

                $event->queries[$pluginName]['searchManager.all:read'] = [
                    'label' => Craft::t('search-manager', 'Query {name} data', ['name' => $pluginName]),
                ];
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY,
            static function(ExecuteGqlQueryEvent $event) use (&$graphqlCacheSetting) {
                if (!self::queryRunsSearch($event->query)) {
                    self::restoreGraphqlCacheSetting($graphqlCacheSetting);

                    return;
                }

                $generalConfig = Craft::$app->getConfig()->getGeneral();
                if ($graphqlCacheSetting === null) {
                    $graphqlCacheSetting = $generalConfig->enableGraphqlCaching;
                }
                $generalConfig->enableGraphqlCaching = false;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_AFTER_EXECUTE_GQL_QUERY,
            static function(ExecuteGqlQueryEvent $event) use (&$graphqlCacheSetting) {
                if ($graphqlCacheSetting === null || !self::queryRunsSearch($event->query)) {
                    return;
                }

                self::restoreGraphqlCacheSetting($graphqlCacheSetting);
            }
        );

        Event::on(
            YiiApplication::class,
            YiiApplication::EVENT_AFTER_REQUEST,
            static function() use (&$graphqlCacheSetting) {
                self::restoreGraphqlCacheSetting($graphqlCacheSetting);
            }
        );
    }

    /**
     * Return whether a GraphQL operation includes the side-effecting search resolver.
     *
     * @param string $query
     * @return bool
     * @since 5.53.0
     */
    private static function queryRunsSearch(string $query): bool
    {
        return str_contains($query, 'searchManagerSearch');
    }

    /**
     * Restore Craft's GraphQL cache toggle after Search Manager temporarily
     * disables it for side-effecting search queries.
     *
     * @param bool|null $graphqlCacheSetting
     */
    private static function restoreGraphqlCacheSetting(?bool &$graphqlCacheSetting): void
    {
        if ($graphqlCacheSetting === null) {
            return;
        }

        Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = $graphqlCacheSetting;
        $graphqlCacheSetting = null;
    }

    /**
     * Register CP routes
     */
    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'search-manager' => 'search-manager/dashboard/index',
                    'search-manager/dashboard' => 'search-manager/dashboard/index',
                    'search-manager/indices' => 'search-manager/indices/index',
                    'search-manager/indices/create' => 'search-manager/indices/edit',
                    'search-manager/indices/view/<handle>' => 'search-manager/indices/view',
                    'search-manager/indices/edit/<indexId:\d+>' => 'search-manager/indices/edit',
                    'search-manager/indices/rebuild/<indexId:\d+>' => 'search-manager/indices/rebuild',
                    'search-manager/indices/clear/<indexId:\d+>' => 'search-manager/indices/clear',
                    'search-manager/indices/delete/<indexId:\d+>' => 'search-manager/indices/delete',
                    // API Keys
                    'search-manager/api-keys' => 'search-manager/api-keys/index',
                    'search-manager/api-keys/create' => 'search-manager/api-keys/edit',
                    'search-manager/api-keys/edit/<keyId:\d+>' => 'search-manager/api-keys/edit',
                    'search-manager/api-keys/delete/<keyId:\d+>' => 'search-manager/api-keys/delete',
                    'search-manager/api-keys/bulk-enable' => 'search-manager/api-keys/bulk-enable',
                    'search-manager/api-keys/bulk-disable' => 'search-manager/api-keys/bulk-disable',
                    'search-manager/api-keys/bulk-delete' => 'search-manager/api-keys/bulk-delete',
                    // Pending Syncs
                    'search-manager/pending-syncs' => 'search-manager/pending-syncs/index',
                    // Promotions
                    'search-manager/promotions' => 'search-manager/promotions/index',
                    'search-manager/promotions/create' => 'search-manager/promotions/edit',
                    'search-manager/promotions/edit/<promotionId:\d+>' => 'search-manager/promotions/edit',
                    'search-manager/promotions/delete/<promotionId:\d+>' => 'search-manager/promotions/delete',
                    // Query Rules
                    'search-manager/query-rules' => 'search-manager/query-rules/index',
                    'search-manager/query-rules/create' => 'search-manager/query-rules/edit',
                    'search-manager/query-rules/edit/<ruleId:\d+>' => 'search-manager/query-rules/edit',
                    'search-manager/query-rules/delete/<ruleId:\d+>' => 'search-manager/query-rules/delete',
                    // Widgets
                    'search-manager/widgets' => 'search-manager/widgets/index',
                    'search-manager/widgets/new' => 'search-manager/widgets/edit',
                    'search-manager/widgets/view/<handle>' => 'search-manager/widgets/view',
                    'search-manager/widgets/edit/<configId:\d+>' => 'search-manager/widgets/edit',
                    // Widget Styles
                    'search-manager/widgets/styles' => 'search-manager/widgets/styles-index',
                    'search-manager/widgets/styles/view/<handle>' => 'search-manager/widgets/view-style',
                    'search-manager/widgets/styles/new' => 'search-manager/widgets/edit-style',
                    'search-manager/widgets/styles/edit/<styleId:\d+>' => 'search-manager/widgets/edit-style',
                    // Backends
                    'search-manager/backends' => 'search-manager/backends/index',
                    'search-manager/backends/new' => 'search-manager/backends/edit',
                    'search-manager/backends/view/<backendId>' => 'search-manager/backends/view',
                    'search-manager/backends/<backendId:\d+>' => 'search-manager/backends/edit',
                    // Analytics
                    'search-manager/analytics' => 'search-manager/analytics/index',
                    'search-manager/analytics/export' => 'search-manager/analytics/export',
                    'search-manager/analytics/export-rule-analytics' => 'search-manager/analytics/export-rule-analytics',
                    'search-manager/analytics/export-promotion-analytics' => 'search-manager/analytics/export-promotion-analytics',
                    'search-manager/setup' => 'search-manager/settings/setup',
                    'search-manager/settings' => 'search-manager/settings/general',
                    'search-manager/settings/general' => 'search-manager/settings/general',
                    'search-manager/settings/backend' => 'search-manager/settings/backend',
                    'search-manager/settings/widget' => 'search-manager/settings/widget',
                    'search-manager/settings/indexing' => 'search-manager/settings/indexing',
                    'search-manager/settings/search' => 'search-manager/settings/search',
                    'search-manager/settings/language' => 'search-manager/settings/language',
                    'search-manager/settings/highlighting' => 'search-manager/settings/highlighting',
                    'search-manager/settings/snippets' => 'search-manager/settings/snippets',
                    'search-manager/settings/analytics' => 'search-manager/settings/analytics',
                    'search-manager/settings/cache' => 'search-manager/settings/cache',
                    'search-manager/settings/interface' => 'search-manager/settings/interface',
                    'search-manager/settings/test' => 'search-manager/settings/test',
                    'search-manager/settings/download-postman-collection' => 'search-manager/settings/download-postman-collection',
                    'search-manager/utilities/rebuild-all-indices' => 'search-manager/utilities/rebuild-all-indices',
                    'search-manager/utilities/clear-backend-storage' => 'search-manager/utilities/clear-backend-storage',
                    'search-manager/utilities/clear-device-cache' => 'search-manager/utilities/clear-device-cache',
                    'search-manager/utilities/clear-search-cache' => 'search-manager/utilities/clear-search-cache',
                    'search-manager/utilities/clear-all-caches' => 'search-manager/utilities/clear-all-caches',
                    'search-manager/utilities/clear-all-analytics' => 'search-manager/utilities/clear-all-analytics',
                ]);
            }
        );
    }

    /**
     * Register user permissions
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $settings = $this->getSettings();
                $fullName = $settings->getFullName();

                $event->permissions[] = [
                    'heading' => $fullName,
                    'permissions' => [
                        // Backends - grouped (first, as indices depend on backends)
                        'searchManager:manageBackends' => [
                            'label' => Craft::t('search-manager', 'Manage backends'),
                            'nested' => [
                                'searchManager:createBackends' => [
                                    'label' => Craft::t('search-manager', 'Create backends'),
                                ],
                                'searchManager:editBackends' => [
                                    'label' => Craft::t('search-manager', 'Edit backends'),
                                ],
                                'searchManager:deleteBackends' => [
                                    'label' => Craft::t('search-manager', 'Delete backends'),
                                ],
                            ],
                        ],
                        // Indices - grouped
                        'searchManager:manageIndices' => [
                            'label' => Craft::t('search-manager', 'Manage indices'),
                            'nested' => [
                                'searchManager:createIndices' => [
                                    'label' => Craft::t('search-manager', 'Create indices'),
                                ],
                                'searchManager:editIndices' => [
                                    'label' => Craft::t('search-manager', 'Edit indices'),
                                ],
                                'searchManager:deleteIndices' => [
                                    'label' => Craft::t('search-manager', 'Delete indices'),
                                ],
                                'searchManager:rebuildIndices' => [
                                    'label' => Craft::t('search-manager', 'Rebuild indices'),
                                ],
                                'searchManager:clearIndices' => [
                                    'label' => Craft::t('search-manager', 'Clear indices'),
                                ],
                            ],
                        ],
                        // Pending Syncs - grouped (parent grants page access, destructive actions nested)
                        'searchManager:managePendingSyncs' => [
                            'label' => Craft::t('search-manager', 'Manage pending syncs'),
                            'nested' => [
                                'searchManager:retryPendingSyncs' => [
                                    'label' => Craft::t('search-manager', 'Retry pending syncs'),
                                ],
                                'searchManager:purgePendingSyncs' => [
                                    'label' => Craft::t('search-manager', 'Purge pending syncs'),
                                ],
                            ],
                        ],
                        // Promotions - grouped
                        'searchManager:managePromotions' => [
                            'label' => Craft::t('search-manager', 'Manage promotions'),
                            'nested' => [
                                'searchManager:createPromotions' => [
                                    'label' => Craft::t('search-manager', 'Create promotions'),
                                ],
                                'searchManager:editPromotions' => [
                                    'label' => Craft::t('search-manager', 'Edit promotions'),
                                ],
                                'searchManager:deletePromotions' => [
                                    'label' => Craft::t('search-manager', 'Delete promotions'),
                                ],
                            ],
                        ],
                        // API Keys - grouped (parent grants page access + view, destructive actions nested)
                        'searchManager:manageApiKeys' => [
                            'label' => Craft::t('search-manager', 'Manage API keys'),
                            'nested' => [
                                'searchManager:createApiKeys' => [
                                    'label' => Craft::t('search-manager', 'Create API keys'),
                                ],
                                'searchManager:editApiKeys' => [
                                    'label' => Craft::t('search-manager', 'Edit API keys'),
                                ],
                                'searchManager:revokeApiKeys' => [
                                    'label' => Craft::t('search-manager', 'Revoke API keys'),
                                ],
                            ],
                        ],
                        // Query Rules - grouped
                        'searchManager:manageQueryRules' => [
                            'label' => Craft::t('search-manager', 'Manage query rules'),
                            'nested' => [
                                'searchManager:createQueryRules' => [
                                    'label' => Craft::t('search-manager', 'Create query rules'),
                                ],
                                'searchManager:editQueryRules' => [
                                    'label' => Craft::t('search-manager', 'Edit query rules'),
                                ],
                                'searchManager:deleteQueryRules' => [
                                    'label' => Craft::t('search-manager', 'Delete query rules'),
                                ],
                            ],
                        ],
                        // Widget Configs - grouped
                        'searchManager:manageWidgetConfigs' => [
                            'label' => Craft::t('search-manager', 'Manage widget configs'),
                            'nested' => [
                                'searchManager:createWidgetConfigs' => [
                                    'label' => Craft::t('search-manager', 'Create widget configs'),
                                ],
                                'searchManager:editWidgetConfigs' => [
                                    'label' => Craft::t('search-manager', 'Edit widget configs'),
                                ],
                                'searchManager:deleteWidgetConfigs' => [
                                    'label' => Craft::t('search-manager', 'Delete widget configs'),
                                ],
                            ],
                        ],
                        // Widget Styles - grouped
                        'searchManager:manageWidgetStyles' => [
                            'label' => Craft::t('search-manager', 'Manage widget styles'),
                            'nested' => [
                                'searchManager:createWidgetStyles' => [
                                    'label' => Craft::t('search-manager', 'Create widget styles'),
                                ],
                                'searchManager:editWidgetStyles' => [
                                    'label' => Craft::t('search-manager', 'Edit widget styles'),
                                ],
                                'searchManager:deleteWidgetStyles' => [
                                    'label' => Craft::t('search-manager', 'Delete widget styles'),
                                ],
                            ],
                        ],
                        // Analytics - grouped
                        'searchManager:viewAnalytics' => [
                            'label' => Craft::t('search-manager', 'View analytics'),
                            'nested' => [
                                'searchManager:exportAnalytics' => [
                                    'label' => Craft::t('search-manager', 'Export analytics'),
                                ],
                                'searchManager:clearAnalytics' => [
                                    'label' => Craft::t('search-manager', 'Clear analytics'),
                                ],
                            ],
                        ],
                        // Cache
                        'searchManager:clearCache' => [
                            'label' => Craft::t('search-manager', 'Clear cache'),
                        ],
                        // Debug (for testing search in production without devMode)
                        'searchManager:viewDebug' => [
                            'label' => Craft::t('search-manager', 'View debug info in search responses'),
                        ],
                        // Logs - grouped
                        'searchManager:viewLogs' => [
                            'label' => Craft::t('search-manager', 'View logs'),
                            'nested' => [
                                'searchManager:viewSystemLogs' => [
                                    'label' => Craft::t('search-manager', 'View system logs'),
                                    'nested' => [
                                        'searchManager:downloadSystemLogs' => [
                                            'label' => Craft::t('search-manager', 'Download system logs'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        // Settings
                        'searchManager:manageSettings' => [
                            'label' => Craft::t('search-manager', 'Manage settings'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Register utilities
     */
    private function registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = \lindemannrock\searchmanager\utilities\ClearSearchCache::class;
            }
        );
    }

    /**
     * Register dashboard widgets
     */
    private function registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = AnalyticsSummaryWidget::class;
                $event->types[] = TopSearchesWidget::class;
                $event->types[] = ContentGapsWidget::class;
                $event->types[] = TrendingSearchesWidget::class;
            }
        );
    }

    /**
     * Register cache clearing options
     *
     * Note: Only search caches are registered here. Indices are not included
     * because clearing them breaks search until rebuilt. Use the plugin's
     * "Rebuild All Indices" utility action instead.
     */
    private function registerCacheClearingOptions(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                // Only show cache option if user has permission to clear cache
                if (!Craft::$app->getUser()->checkPermission('searchManager:clearCache')) {
                    return;
                }

                $settings = $this->getSettings();
                $displayName = $settings->getDisplayName();

                $event->options[] = [
                    'key' => 'search-manager-search-cache',
                    'label' => Craft::t('search-manager', '{displayName} caches', ['displayName' => $displayName]),
                    'action' => function() {
                        $this->backend->clearAllSearchCache();
                    },
                ];
            }
        );
    }

    /**
     * Schedule status sync job (for pending→live and live→expired transitions).
     */
    private function scheduleStatusSync(): void
    {
        $settings = $this->getSettings();

        if ($settings->statusSyncInterval <= 0) {
            return;
        }

        $initialDelay = 5 * 60;
        $initialRun = (clone DateFormatHelper::now())->modify("+{$initialDelay} seconds");

        RecurringQueueHelper::ensurePending(
            pluginToken: 'searchmanager',
            jobClass: SyncStatusJob::class,
            delay: $initialDelay,
            jobFactory: fn() => new SyncStatusJob([
                'reschedule' => true,
                'nextRunTime' => DateFormatHelper::formatCompactDatetimeFromSettings(
                    $initialRun,
                    $settings,
                    null,
                    false,
                    pluginHandle: 'search-manager',
                ),
            ]),
        );
    }

    /**
     * Check if status sync job is running/scheduled.
     */
    public function isStatusSyncRunning(): bool
    {
        return (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'SyncStatusJob'])
            ->exists();
    }

    /**
     * Schedule analytics cleanup job.
     *
     * Respects analyticsRetention setting from config (e.g., 30 days dev, 60 staging, 365 prod).
     */
    private function scheduleAnalyticsCleanup(): void
    {
        $settings = $this->getSettings();

        if (!$settings->enableAnalytics || $settings->analyticsRetention <= 0) {
            return;
        }

        $nextRun = ScheduleHelper::calculateNext('daily');
        if ($nextRun === null) {
            return;
        }

        $delay = max(0, $nextRun->getTimestamp() - DateFormatHelper::now()->getTimestamp());
        $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'search-manager',
        );

        RecurringQueueHelper::ensurePending(
            pluginToken: 'searchmanager',
            jobClass: CleanupAnalyticsJob::class,
            delay: $delay,
            jobFactory: fn() => new CleanupAnalyticsJob([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]),
        );
    }

    /**
     * Check if analytics cleanup job is running/scheduled.
     *
     * @since 5.34.0
     */
    public function isAnalyticsCleanupRunning(): bool
    {
        return (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'CleanupAnalyticsJob'])
            ->exists();
    }

    /**
     * Install event listeners for auto-indexing
     */
    private function installEventListeners(): void
    {
        // Listen for element saves
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event) {
                if (!$this->getSettings()->autoIndex) {
                    return;
                }

                // Skip if element is currently propagating to other sites
                // We only want to process once after propagation is complete
                if ($event->element->propagating) {
                    return;
                }

                $element = $event->element;
                if ($element->getIsDraft() || $element->getIsRevision()) {
                    return;
                }

                $this->logDebug('Element save triggered', [
                    'elementId' => $element->id,
                    'requestSiteId' => $element->siteId,
                    'enabled' => $element->enabled,
                ]);

                $this->queuePendingSync($element, PendingSyncRepository::OP_UPSERT);
            }
        );

        // Listen for element deletes
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function(ElementEvent $event) {
                if (!$this->getSettings()->autoIndex) {
                    return;
                }

                $element = $event->element;
                if ($element->getIsDraft() || $element->getIsRevision()) {
                    return;
                }

                $this->queuePendingSync($element, PendingSyncRepository::OP_DELETE);
            }
        );

        // DO NOT log here - this is called from init() on every request
    }

    /**
     * Queue pending sync rows for all indices/sites that have this element type
     */
    private function queuePendingSync(\craft\base\ElementInterface $element, string $op): void
    {
        $submitted = $this->pendingSyncs->queueForElement($element, $op);

        $this->logDebug('Submitted pending sync rows', [
            'elementId' => $element->id,
            'elementType' => get_class($element),
            'op' => $op,
            'submitted' => $submitted,
        ]);
    }

    // =========================================================================
    // CP NAVIGATION
    // =========================================================================

    /** @inheritdoc */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if (!$item) {
            return null;
        }

        $user = Craft::$app->getUser();
        $settings = $this->getSettings();

        $item['label'] = $settings->getFullName();

        $sections = $this->getCpSections($settings);
        $item['subnav'] = CpNavHelper::buildSubnav($user, $settings, $sections);

        // Add logs section if logging library is enabled (always show)
        if (PluginHelper::isPluginEnabled('logging-library')) {
            $item = LoggingLibrary::addLogsNav($item, $this->handle, [
                'searchManager:viewSystemLogs',
            ]);
        }

        // Hide from nav if no accessible subnav items
        if (empty($item['subnav'])) {
            return null;
        }

        return $item;
    }

    /**
     * Get CP sections for nav + default route resolution
     *
     * @param Settings $settings
     * @param bool $includeDashboard
     * @param bool $includeLogs
     * @return array
     * @since 5.37.0
     */
    public function getCpSections(Settings $settings, bool $includeDashboard = true, bool $includeLogs = false): array
    {
        $sections = [];

        // Check if any backends are configured
        $hasBackends = !empty(\lindemannrock\searchmanager\models\ConfiguredBackend::findAllEnabled());

        if ($includeDashboard) {
            $sections[] = [
                'key' => 'dashboard',
                'label' => Craft::t('search-manager', 'Dashboard'),
                'url' => 'search-manager',
                'permissionsAll' => ['searchManager:manageIndices'],
                'when' => $hasBackends,
            ];
        }

        $sections[] = [
            'key' => 'backends',
            'label' => Craft::t('search-manager', 'Backends'),
            'url' => 'search-manager/backends',
            'permissionsAll' => ['searchManager:manageBackends'],
        ];

        $sections[] = [
            'key' => 'indices',
            'label' => Craft::t('search-manager', 'Indices'),
            'url' => 'search-manager/indices',
            'permissionsAll' => ['searchManager:manageIndices'],
            'when' => $hasBackends,
        ];

        $sections[] = [
            'key' => 'pending-syncs',
            'label' => Craft::t('search-manager', 'Pending Syncs'),
            'url' => 'search-manager/pending-syncs',
            'permissionsAll' => ['searchManager:managePendingSyncs'],
            'when' => $hasBackends,
        ];

        $sections[] = [
            'key' => 'promotions',
            'label' => Craft::t('search-manager', 'Promotions'),
            'url' => 'search-manager/promotions',
            'permissionsAll' => ['searchManager:managePromotions'],
            'when' => $hasBackends,
        ];

        $sections[] = [
            'key' => 'query-rules',
            'label' => Craft::t('search-manager', 'Query Rules'),
            'url' => 'search-manager/query-rules',
            'permissionsAll' => ['searchManager:manageQueryRules'],
            'when' => $hasBackends,
        ];

        // API Keys is visible without `$hasBackends` so operators can provision
        // keys ahead of the first backend (and so the CI/headless bootstrap
        // command's CP confirmation is reachable on a fresh install).
        $sections[] = [
            'key' => 'api-keys',
            'label' => Craft::t('search-manager', 'API Keys'),
            'url' => 'search-manager/api-keys',
            'permissionsAll' => ['searchManager:manageApiKeys'],
        ];

        $sections[] = [
            'key' => 'widgets',
            'label' => Craft::t('search-manager', 'Widgets'),
            'url' => 'search-manager/widgets',
            'permissionsAny' => ['searchManager:manageWidgetConfigs', 'searchManager:manageWidgetStyles'],
        ];

        $sections[] = [
            'key' => 'analytics',
            'label' => Craft::t('search-manager', 'Analytics'),
            'url' => 'search-manager/analytics',
            'permissionsAll' => ['searchManager:viewAnalytics'],
            'when' => $settings->enableAnalytics && $hasBackends,
        ];

        $sections[] = [
            'key' => 'setup',
            'label' => Craft::t('search-manager', 'Setup'),
            'url' => 'search-manager/setup',
            'permissionsAll' => ['searchManager:manageSettings'],
        ];

        if ($includeLogs) {
            $sections[] = [
                'key' => 'logs',
                'label' => Craft::t('search-manager', 'Logs'),
                'url' => 'search-manager/logs',
                'permissionsAll' => ['searchManager:viewSystemLogs'],
                'when' => fn() => PluginHelper::isPluginEnabled('logging-library'),
            ];
        }

        $sections[] = [
            'key' => 'settings',
            'label' => Craft::t('search-manager', 'Settings'),
            'url' => 'search-manager/settings',
            'permissionsAll' => ['searchManager:manageSettings'],
        ];

        return $sections;
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Prevent Craft from overwriting settings with stale project config / plugins table data.
     * Settings are managed via SettingsPersistenceTrait (custom DB table), not project config.
     */
    public function setSettings(array|Model $settings): void
    {
        // No-op: settings come from loadFromDatabase() in createSettingsModel(),
        // not from Craft's plugins table or project config.
    }

    protected function createSettingsModel(): ?Model
    {
        try {
            return Settings::loadFromDatabase();
        } catch (\Throwable $e) {
            $this->logError('Could not load settings from database', [
                'error' => $e->getMessage(),
            ]);
            return new Settings();
        }
    }

    /** @inheritdoc */
    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        try {
            PluginHelper::applyConfigOverridesToSettings($settings, 'search-manager', [
                'indices',
                'backends',
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to apply config overrides', [
                'error' => $e->getMessage(),
            ]);
        }

        return $settings;
    }

    /** @inheritdoc */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('search-manager/settings')
        );
    }

    /** @inheritdoc */
    public function getReadOnlySettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('search-manager/settings')
        );
    }

    /**
     * Get a configured backend by handle
     * Convenience method for templates
     *
     * @param string $handle
     * @return \lindemannrock\searchmanager\models\ConfiguredBackend|null
     * @since 5.28.0
     */
    public function getConfiguredBackend(string $handle): ?\lindemannrock\searchmanager\models\ConfiguredBackend
    {
        return \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($handle);
    }

    /**
     * Resolve effective Redis connection info for CP templates.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     * @since 5.52.0
     */
    public function getRedisConnectionInfo(array $settings = []): array
    {
        return \lindemannrock\searchmanager\helpers\RedisConnectionHelper::resolve($settings);
    }

    // =========================================================================
    // NATIVE SEARCH REPLACEMENT
    // =========================================================================

    /**
     * Replace Craft's native search service with our adapter.
     *
     * Site ElementQuery::search() requests can use Search Manager coverage;
     * Control Panel searches remain on Craft's native search path.
     */
    private function replaceNativeSearchService(): void
    {
        try {
            Craft::$app->set('search', new \lindemannrock\searchmanager\adapters\CraftSearchAdapter());
        } catch (\Throwable $e) {
            $this->logError('Failed to replace native search service', [
                'error' => $e->getMessage(),
            ]);

            // Don't throw - let plugin continue without replacement
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not replace native search service. Check logs for details.')
            );
        }
    }
}
