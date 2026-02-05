<?php

namespace lindemannrock\searchmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\ElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\searchmanager\jobs\SyncStatusJob;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\variables\SearchManagerVariable;
use lindemannrock\searchmanager\widgets\AnalyticsSummaryWidget;
use lindemannrock\searchmanager\widgets\ContentGapsWidget;
use lindemannrock\searchmanager\widgets\TopSearchesWidget;
use lindemannrock\searchmanager\widgets\TrendingSearchesWidget;
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
 * @property-read AutocompleteService $autocomplete
 * @property-read DeviceDetectionService $deviceDetection
 * @property-read PromotionService $promotions
 * @property-read QueryRuleService $queryRules
 * @property-read WidgetConfigService $widgetConfigs
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
                        'mysql' => ColorHelper::getPaletteColor('amber'),
                        'pgsql' => ColorHelper::getPaletteColor('blue'),
                        'file' => ColorHelper::getPaletteColor('gray'),
                        'redis' => ColorHelper::getPaletteColor('red'),
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
                        'filter' => ColorHelper::getPaletteColor('orange'),
                        'redirect' => ColorHelper::getPaletteColor('red'),
                    ],
                ],
            ]
        );
        PluginHelper::applyPluginNameFromConfig($this);

        // Register services
        $this->registerServices();

        // Register template variables
        $this->registerTemplateVariables();

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
        if ($this->getSettings()->autoIndex) {
            $this->installEventListeners();
        }

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
            'autocomplete' => AutocompleteService::class,
            'backend' => BackendService::class,
            'deviceDetection' => DeviceDetectionService::class,
            'indexing' => IndexingService::class,
            'promotions' => PromotionService::class,
            'queryRules' => QueryRuleService::class,
            'transformers' => TransformerService::class,
            'widgetConfigs' => WidgetConfigService::class,
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
                    'search-manager/settings' => 'search-manager/settings/general',
                    'search-manager/settings/general' => 'search-manager/settings/general',
                    'search-manager/settings/backend' => 'search-manager/settings/backend',
                    'search-manager/settings/widget' => 'search-manager/settings/widget',
                    'search-manager/settings/indexing' => 'search-manager/settings/indexing',
                    'search-manager/settings/search' => 'search-manager/settings/search',
                    'search-manager/settings/language' => 'search-manager/settings/language',
                    'search-manager/settings/highlighting' => 'search-manager/settings/highlighting',
                    'search-manager/settings/analytics' => 'search-manager/settings/analytics',
                    'search-manager/settings/cache' => 'search-manager/settings/cache',
                    'search-manager/settings/interface' => 'search-manager/settings/interface',
                    'search-manager/settings/test' => 'search-manager/settings/test',
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
                                'searchManager:viewBackends' => [
                                    'label' => Craft::t('search-manager', 'View backends'),
                                ],
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
                                'searchManager:viewIndices' => [
                                    'label' => Craft::t('search-manager', 'View indices'),
                                ],
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
                        // Promotions - grouped
                        'searchManager:managePromotions' => [
                            'label' => Craft::t('search-manager', 'Manage promotions'),
                            'nested' => [
                                'searchManager:viewPromotions' => [
                                    'label' => Craft::t('search-manager', 'View promotions'),
                                ],
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
                        // Query Rules - grouped
                        'searchManager:manageQueryRules' => [
                            'label' => Craft::t('search-manager', 'Manage query rules'),
                            'nested' => [
                                'searchManager:viewQueryRules' => [
                                    'label' => Craft::t('search-manager', 'View query rules'),
                                ],
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
                                'searchManager:viewWidgetConfigs' => [
                                    'label' => Craft::t('search-manager', 'View widget configs'),
                                ],
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
     * Schedule status sync job (for pending→live and live→expired transitions)
     */
    private function scheduleStatusSync(): void
    {
        $settings = $this->getSettings();

        // Only schedule if sync is enabled
        if ($settings->statusSyncInterval <= 0) {
            return;
        }

        // Check if a sync job is already scheduled
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'SyncStatusJob'])
            ->exists();

        if (!$existingJob) {
            // Create sync job with reschedule enabled
            $job = new SyncStatusJob([
                'reschedule' => true,
            ]);

            // Add to queue with a small initial delay (5 minutes)
            Craft::$app->queue->delay(5 * 60)->push($job);

            $this->logInfo('Scheduled initial status sync job', [
                'interval' => $settings->statusSyncInterval . ' minutes',
            ]);
        }
    }

    /**
     * Check if status sync job is running/scheduled
     *
     * @return bool
     * @since 5.0.0
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
     * Schedule analytics cleanup job
     *
     * Respects analyticsRetention setting from config (e.g., 30 days dev, 60 staging, 365 prod)
     */
    private function scheduleAnalyticsCleanup(): void
    {
        $settings = $this->getSettings();

        // Only schedule if analytics is enabled and retention is set
        if (!$settings->enableAnalytics || $settings->analyticsRetention <= 0) {
            return;
        }

        // Check if a cleanup job is already scheduled
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'CleanupAnalyticsJob'])
            ->exists();

        if (!$existingJob) {
            $job = new CleanupAnalyticsJob([
                'reschedule' => true,
            ]);

            // Add to queue with a small initial delay (5 minutes)
            // The job will re-queue itself to run every 24 hours
            Craft::$app->queue->delay(5 * 60)->push($job);

            $this->logInfo('Scheduled initial analytics cleanup job', [
                'retention' => $settings->analyticsRetention . ' days',
                'interval' => '24 hours',
            ]);
        }
    }

    /**
     * Check if analytics cleanup job is running/scheduled
     *
     * @return bool
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
                // Skip if element is currently propagating to other sites
                // We only want to process once after propagation is complete
                if ($event->element->propagating) {
                    return;
                }

                $element = $event->element;

                $this->logDebug('Element save triggered', [
                    'elementId' => $element->id,
                    'requestSiteId' => $element->siteId,
                    'enabled' => $element->enabled,
                ]);

                // Queue sync jobs for ALL sites that have indices for this element type
                // Each job will check that site's actual state and index/remove accordingly
                $this->queueSyncJobs($element);
            }
        );

        // Listen for element deletes
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function(ElementEvent $event) {
                // Queue removal jobs for all sites
                $this->queueSyncJobs($event->element);
            }
        );

        // DO NOT log here - this is called from init() on every request
    }

    /**
     * Queue sync jobs for all sites that have indices for this element type
     */
    private function queueSyncJobs(\craft\base\ElementInterface $element): void
    {
        $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
        $elementClass = get_class($element);
        $queuedSites = [];

        foreach ($indices as $index) {
            if (!$index->enabled) {
                continue;
            }
            if ($index->elementType !== $elementClass) {
                continue;
            }

            // Determine site IDs for this index
            $siteIds = $index->getSiteIds();
            if ($siteIds === null) {
                $siteIds = Craft::$app->getSites()->getAllSiteIds();
            }

            foreach ($siteIds as $siteId) {
                if (!in_array($siteId, $queuedSites, true)) {
                    Craft::$app->getQueue()->push(new \lindemannrock\searchmanager\jobs\SyncElementJob([
                        'elementId' => $element->id,
                        'elementType' => $elementClass,
                        'siteId' => (int)$siteId,
                    ]));
                    $queuedSites[] = $siteId;

                    $this->logDebug('Queued sync job for site', [
                        'elementId' => $element->id,
                        'siteId' => $siteId,
                    ]);
                }
            }
        }
    }

    // =========================================================================
    // CP NAVIGATION
    // =========================================================================

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if (!$item) {
            return null;
        }

        $user = Craft::$app->getUser();
        $settings = $this->getSettings();

        $item['label'] = $settings->getFullName();
        $item['icon'] = '@appicons/magnifying-glass.svg';

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
                'permissionsAll' => ['searchManager:viewIndices'],
                'when' => $hasBackends,
            ];
        }

        $sections[] = [
            'key' => 'backends',
            'label' => Craft::t('search-manager', 'Backends'),
            'url' => 'search-manager/backends',
            'permissionsAll' => ['searchManager:viewBackends'],
        ];

        $sections[] = [
            'key' => 'indices',
            'label' => Craft::t('search-manager', 'Indices'),
            'url' => 'search-manager/indices',
            'permissionsAll' => ['searchManager:viewIndices'],
            'when' => $hasBackends,
        ];

        $sections[] = [
            'key' => 'promotions',
            'label' => Craft::t('search-manager', 'Promotions'),
            'url' => 'search-manager/promotions',
            'permissionsAll' => ['searchManager:viewPromotions'],
            'when' => $hasBackends,
        ];

        $sections[] = [
            'key' => 'query-rules',
            'label' => Craft::t('search-manager', 'Query Rules'),
            'url' => 'search-manager/query-rules',
            'permissionsAll' => ['searchManager:viewQueryRules'],
            'when' => $hasBackends,
        ];

        $sections[] = [
            'key' => 'widgets',
            'label' => Craft::t('search-manager', 'Widgets'),
            'url' => 'search-manager/widgets',
            'permissionsAll' => ['searchManager:viewWidgetConfigs'],
        ];

        $sections[] = [
            'key' => 'analytics',
            'label' => Craft::t('search-manager', 'Analytics'),
            'url' => 'search-manager/analytics',
            'permissionsAll' => ['searchManager:viewAnalytics'],
            'when' => $settings->enableAnalytics && $hasBackends,
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

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        // Override with config file values using Craft's native multi-environment handling
        // This properly merges '*' with environment-specific configs (e.g., 'production')
        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('search-manager');

            if (!empty($config) && is_array($config)) {
                foreach ($config as $key => $value) {
                    // Skip special config keys that are handled elsewhere
                    if ($key !== 'indices' && $key !== 'backends' && $key !== 'transformers') {
                        if (property_exists($settings, $key)) {
                            $settings->$key = $value;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to apply config overrides', [
                'error' => $e->getMessage(),
            ]);
        }

        return $settings;
    }

    public function getSettingsResponse(): mixed
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

    // =========================================================================
    // NATIVE SEARCH REPLACEMENT
    // =========================================================================

    /**
     * Replace Craft's native search service with our adapter
     * Makes CP searches and Entry::find()->search() use our backends
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
