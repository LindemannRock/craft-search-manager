<?php

namespace lindemannrock\searchmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\ElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\twigextensions\PluginNameExtension;
use lindemannrock\searchmanager\variables\SearchManagerVariable;
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
 * @property-read Settings $settings
 * @method Settings getSettings()
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

        // Configure logging
        $this->configureLogging();

        // Override plugin name from config if set
        $this->overridePluginNameFromConfig();

        // Register services
        $this->registerServices();

        // Register translations
        $this->registerTranslations();

        // Register Twig extension
        $this->registerTwigExtension();

        // Register template variables
        $this->registerTemplateVariables();

        // Register CP routes
        $this->registerCpRoutes();

        // Register permissions
        $this->registerPermissions();

        // Register utilities
        $this->registerUtilities();

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
     * Configure logging library integration
     */
    private function configureLogging(): void
    {
        $settings = $this->getSettings();

        LoggingLibrary::configure([
            'pluginHandle' => $this->handle,
            'pluginName' => $settings->getFullName(),
            'logLevel' => $settings->logLevel ?? 'error',
            'itemsPerPage' => $settings->itemsPerPage ?? 100,
            'permissions' => ['searchManager:viewLogs'],
        ]);

        $this->setLoggingHandle($this->handle);
    }

    /**
     * Override plugin name from config file if set
     */
    private function overridePluginNameFromConfig(): void
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (file_exists($configPath)) {
            try {
                $rawConfig = require $configPath;
                $env = Craft::$app->getConfig()->env;

                // Merge environment config
                $config = $rawConfig['*'] ?? [];
                if ($env && isset($rawConfig[$env])) {
                    $config = array_merge($config, $rawConfig[$env]);
                }

                if (isset($config['pluginName'])) {
                    $this->name = $config['pluginName'];
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to load plugin name from config', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

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
            'transformers' => TransformerService::class,
        ]);
    }

    /**
     * Register translations
     */
    private function registerTranslations(): void
    {
        Craft::$app->i18n->translations['search-manager'] = [
            'class' => \craft\i18n\PhpMessageSource::class,
            'sourceLanguage' => 'en',
            'basePath' => $this->getBasePath() . '/translations',
            'forceTranslation' => true,
            'allowOverrides' => true,
        ];
    }

    /**
     * Register Twig extension
     */
    private function registerTwigExtension(): void
    {
        Craft::$app->view->registerTwigExtension(new PluginNameExtension());
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
                    'search-manager/indices/edit/<indexId:\d+>' => 'search-manager/indices/edit',
                    'search-manager/indices/rebuild/<indexId:\d+>' => 'search-manager/indices/rebuild',
                    'search-manager/indices/clear/<indexId:\d+>' => 'search-manager/indices/clear',
                    'search-manager/indices/delete/<indexId:\d+>' => 'search-manager/indices/delete',
                    'search-manager/analytics' => 'search-manager/analytics/index',
                    'search-manager/analytics/export-csv' => 'search-manager/analytics/export-csv',
                    'search-manager/settings' => 'search-manager/settings/general',
                    'search-manager/settings/general' => 'search-manager/settings/general',
                    'search-manager/settings/backend' => 'search-manager/settings/backend',
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
                $event->permissions[] = [
                    'heading' => Craft::t('search-manager', 'Search Manager'),
                    'permissions' => [
                        'searchManager:viewIndices' => [
                            'label' => Craft::t('search-manager', 'View indices'),
                        ],
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
                            ],
                        ],
                        'searchManager:viewAnalytics' => [
                            'label' => Craft::t('search-manager', 'View analytics'),
                        ],
                        'searchManager:exportAnalytics' => [
                            'label' => Craft::t('search-manager', 'Export analytics'),
                        ],
                        'searchManager:viewLogs' => [
                            'label' => Craft::t('search-manager', 'View logs'),
                        ],
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
                $settings = $this->getSettings();
                $pluginName = $settings->getFullName();

                $event->options[] = [
                    'key' => 'search-manager-search-cache',
                    'label' => Craft::t('search-manager', '{pluginName} caches', ['pluginName' => $pluginName]),
                    'action' => function() {
                        $this->backend->clearAllSearchCache();
                    },
                ];
            }
        );
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
                // We only want to index once after propagation is complete
                if ($event->element->propagating) {
                    return;
                }

                $element = $event->element;

                // Check if element should be indexed
                // Elements must be enabled and have a "live" status (not disabled, expired, or pending)
                $shouldIndex = $this->shouldIndexElement($element);

                // Debug logging
                \Craft::info("Element save: ID={$element->id}, siteId={$element->siteId}, enabled={$element->enabled}, enabledForSite=" . ($element->getEnabledForSite() ? 'true' : 'false') . ", status={$element->getStatus()}, shouldIndex=" . ($shouldIndex ? 'true' : 'false'), 'search-manager');

                if ($shouldIndex) {
                    $this->indexing->indexElement($element);
                } else {
                    // Element is disabled/expired/pending - remove from index
                    $this->indexing->removeElement($element);
                }
            }
        );

        // Listen for element deletes
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function(ElementEvent $event) {
                $this->indexing->removeElement($event->element);
            }
        );

        // DO NOT log here - this is called from init() on every request
    }

    /**
     * Check if an element should be indexed based on its status
     *
     * @param \craft\base\ElementInterface $element
     * @return bool
     */
    private function shouldIndexElement(\craft\base\ElementInterface $element): bool
    {
        // Skip drafts and revisions
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        // Must be enabled globally AND for this site
        if (!$element->enabled || !$element->getEnabledForSite()) {
            return false;
        }

        // Check status based on element type
        $status = $element->getStatus();

        // Entries: must be live (not disabled, pending, or expired)
        if ($element instanceof \craft\elements\Entry) {
            return $status === \craft\elements\Entry::STATUS_LIVE;
        }

        // Assets: must be enabled (default status check)
        if ($element instanceof \craft\elements\Asset) {
            return $status === \craft\base\Element::STATUS_ENABLED;
        }

        // Categories: must be enabled
        if ($element instanceof \craft\elements\Category) {
            return $status === \craft\base\Element::STATUS_ENABLED;
        }

        // Users: must be active
        if ($element instanceof \craft\elements\User) {
            return $status === \craft\elements\User::STATUS_ACTIVE;
        }

        // Default: check if enabled status
        return $status === \craft\base\Element::STATUS_ENABLED;
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

        $settings = $this->getSettings();
        $item['label'] = $settings->getFullName();
        $item['icon'] = '@appicons/magnifying-glass.svg';

        // Add subnav items
        $item['subnav'] = [];

        // Dashboard
        $item['subnav']['dashboard'] = [
            'label' => Craft::t('search-manager', 'Dashboard'),
            'url' => 'search-manager',
        ];

        // Indices
        if (Craft::$app->getUser()->checkPermission('searchManager:viewIndices')) {
            $item['subnav']['indices'] = [
                'label' => Craft::t('search-manager', 'Indices'),
                'url' => 'search-manager/indices',
            ];
        }

        // Analytics
        if (Craft::$app->getUser()->checkPermission('searchManager:viewAnalytics')) {
            $item['subnav']['analytics'] = [
                'label' => Craft::t('search-manager', 'Analytics'),
                'url' => 'search-manager/analytics',
            ];
        }

        // Add logs section if logging library is enabled
        if (Craft::$app->getPlugins()->isPluginEnabled('logging-library')) {
            $item = LoggingLibrary::addLogsNav($item, $this->handle, [
                'searchManager:viewLogs',
            ]);
        }

        // Settings
        if (Craft::$app->getUser()->checkPermission('searchManager:manageSettings')) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('search-manager', 'Settings'),
                'url' => 'search-manager/settings',
            ];
        }

        return $item;
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
