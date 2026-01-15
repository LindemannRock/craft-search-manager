<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\helpers\Db;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Settings Controller
 */
class SettingsController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function actionIndex(): Response
    {
        return $this->actionGeneral();
    }

    public function actionGeneral(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/general', [
            'settings' => $settings,
        ]);
    }

    public function actionBackend(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        // Load configured backends
        $configuredBackends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();
        $enabledBackends = array_filter($configuredBackends, fn($b) => $b->enabled);

        return $this->renderTemplate('search-manager/settings/backend', [
            'settings' => $settings,
            'configuredBackends' => $configuredBackends,
            'enabledBackends' => $enabledBackends,
        ]);
    }

    public function actionIndexing(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/indexing', [
            'settings' => $settings,
        ]);
    }

    public function actionAnalytics(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/analytics', [
            'settings' => $settings,
        ]);
    }

    public function actionSearch(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/search', [
            'settings' => $settings,
        ]);
    }

    public function actionLanguage(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/language', [
            'settings' => $settings,
        ]);
    }

    public function actionHighlighting(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/highlighting', [
            'settings' => $settings,
        ]);
    }

    public function actionCache(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/cache', [
            'settings' => $settings,
        ]);
    }

    public function actionInterface(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/interface', [
            'settings' => $settings,
        ]);
    }

    public function actionTest(): Response
    {
        $this->requirePermission('searchManager:manageSettings');

        $settings = SearchManager::$plugin->getSettings();

        // Get all configured backends for the backend selector
        $configuredBackends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();

        return $this->renderTemplate('search-manager/settings/test', [
            'settings' => $settings,
            'cacheEnabled' => $settings->enableCache ?? true,
            'configuredBackends' => $configuredBackends,
        ]);
    }

    public function actionTestSearch(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getRequiredBodyParam('indexHandle');
        $wildcard = Craft::$app->getRequest()->getBodyParam('wildcard', false);

        try {
            // Get the index
            $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);

            $originalQuery = $query;

            // CP Test: Search across all sites by default
            $searchOptions = [
                'siteId' => '*', // Special value to search all sites
            ];

            // Add wildcard support (auto-append * if enabled and no wildcard present)
            if ($wildcard && !str_contains($query, '*')) {
                // For testing: add * to each term to enable prefix matching
                $query = implode('* ', explode(' ', $query)) . '*';
            }

            $startTime = microtime(true);
            $results = SearchManager::$plugin->backend->search($indexHandle, $query, $searchOptions);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $backend = SearchManager::$plugin->backend->getActiveBackend();
            $backendName = $backend ? $backend->getName() : 'unknown';

            // Check if result was actually cached from metadata
            $cached = $results['cached'] ?? false;

            // Hydrate element data for display (title, url, type, section)
            $elementType = $index->elementType ?? \craft\elements\Entry::class;
            $indexSiteId = $index->siteId ?? null;

            if (!empty($results['hits'])) {
                // Group hits by siteId so we can batch-load elements per site
                $hitsBySite = [];
                foreach ($results['hits'] as $key => $hit) {
                    // Use the siteId from the hit (returned by backend's all-sites search)
                    // Fall back to index site or current site
                    $hitSiteId = $hit['siteId'] ?? $indexSiteId ?? Craft::$app->getSites()->getCurrentSite()->id;
                    $hitsBySite[$hitSiteId][$key] = $hit;
                }

                // Load elements per site to get correct site-specific data
                $elementsById = [];
                foreach ($hitsBySite as $siteId => $siteHits) {
                    $elementIds = array_column($siteHits, 'objectID');
                    $elements = $elementType::find()
                        ->id($elementIds)
                        ->siteId($siteId)
                        ->status(null)
                        ->indexBy('id')
                        ->all();

                    foreach ($elements as $id => $element) {
                        $elementsById[$siteId . ':' . $id] = $element;
                    }
                }

                // Enhance hits with element data including site info
                foreach ($results['hits'] as &$hit) {
                    $hitSiteId = $hit['siteId'] ?? $indexSiteId ?? Craft::$app->getSites()->getCurrentSite()->id;
                    $elementKey = $hitSiteId . ':' . $hit['objectID'];
                    $element = $elementsById[$elementKey] ?? null;

                    if ($element) {
                        $hit['title'] = $element->title ?? 'Untitled';
                        $hit['url'] = $element->url ?? '';
                        $hit['type'] = (new \ReflectionClass($element))->getShortName();

                        // Use site info from the element (which was loaded for the correct site)
                        $site = $element->getSite();
                        $hit['siteId'] = $site->id;
                        $hit['siteName'] = $site->name;
                        $hit['siteHandle'] = $site->handle;
                        $hit['language'] = strtoupper(substr($site->language, 0, 2));

                        if (method_exists($element, 'getSection') && $element->getSection()) {
                            $hit['section'] = $element->getSection()->name;
                        }
                    }
                }
                unset($hit);
            }

            // Get highlighting settings
            $settings = SearchManager::$plugin->getSettings();

            // Enhance results with highlighted content
            $enhancedHits = [];
            if ($settings->enableHighlighting) {
                $highlighter = new \lindemannrock\searchmanager\search\Highlighter([
                    'tag' => $settings->highlightTag ?? 'mark',
                    'class' => $settings->highlightClass ?? '',
                ]);

                // Tokenize query into search terms
                $searchTerms = preg_split('/\s+/', trim($originalQuery), -1, PREG_SPLIT_NO_EMPTY);
                // Remove operators
                $searchTerms = array_filter($searchTerms, fn($t) => !in_array(strtoupper($t), ['AND', 'OR', 'NOT']));
                // Remove quotes and wildcards for highlighting
                $searchTerms = array_map(fn($t) => trim($t, '"*'), $searchTerms);

                foreach ($results['hits'] ?? [] as $hit) {
                    $enhancedHit = $hit;

                    // Add highlighted title if available
                    if (isset($hit['title'])) {
                        $enhancedHit['titleHighlighted'] = $highlighter->highlight(
                            $hit['title'],
                            $searchTerms,
                            false // Don't strip tags from title
                        );
                    }

                    // Add highlighted excerpt if available
                    if (isset($hit['excerpt'])) {
                        $enhancedHit['excerptHighlighted'] = $highlighter->highlight(
                            $hit['excerpt'],
                            $searchTerms,
                            true
                        );
                    } elseif (isset($hit['content'])) {
                        // Generate excerpt from content if no excerpt exists
                        $excerptText = strip_tags($hit['content']);
                        $excerptText = mb_substr($excerptText, 0, 200);
                        $enhancedHit['excerptHighlighted'] = $highlighter->highlight(
                            $excerptText,
                            $searchTerms,
                            false // Already stripped
                        );
                    }

                    $enhancedHits[] = $enhancedHit;
                }
            } else {
                $enhancedHits = $results['hits'] ?? [];
            }

            return $this->asJson([
                'success' => true,
                'total' => $results['total'] ?? 0,
                'hits' => $enhancedHits,
                'backend' => $backendName,
                'executionTime' => $executionTime,
                'cacheEnabled' => $settings->enableCache ?? false,
                'wildcard' => $wildcard,
                'queryUsed' => $query,
                'originalQuery' => $originalQuery,
                'highlightingEnabled' => $settings->enableHighlighting,
                'indexSiteId' => $index->siteId ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function actionClearTestCache(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexHandle = Craft::$app->getRequest()->getBodyParam('indexHandle');

        try {
            if ($indexHandle) {
                // Clear cache for specific index
                SearchManager::$plugin->backend->clearSearchCache($indexHandle);
                $message = Craft::t('search-manager', 'Search cache cleared for index: {handle}', ['handle' => $indexHandle]);
            } else {
                // Clear all search caches
                Craft::$app->getCache()->flush();
                $message = Craft::t('search-manager', 'All search caches cleared');
            }

            $this->logInfo('Test page cache cleared', ['indexHandle' => $indexHandle ?: 'all']);

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear test cache', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear cache: {error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * Test which promotions match a query
     */
    public function actionTestPromotions(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getRequiredBodyParam('indexHandle');

        try {
            // CP Test: Get ALL promotions that match the query pattern (ignoring element status)
            // This shows all promotions for testing, with status info per site
            $allPromotions = \lindemannrock\searchmanager\models\Promotion::findByIndex($indexHandle);

            $promotions = [];
            foreach ($allPromotions as $promotion) {
                // Check if query pattern matches
                if (!$promotion->matches(mb_strtolower(trim($query)))) {
                    continue;
                }

                $element = $promotion->getElement();

                // Get element status per site for display
                $siteStatuses = [];
                if ($element) {
                    foreach (Craft::$app->getSites()->getAllSites() as $site) {
                        $siteElement = \craft\elements\Entry::find()
                            ->id($promotion->elementId)
                            ->siteId($site->id)
                            ->status('live')
                            ->one();
                        $siteStatuses[] = [
                            'siteId' => $site->id,
                            'siteName' => $site->name,
                            'isLive' => $siteElement !== null,
                        ];
                    }
                }

                $promotions[] = [
                    'id' => $promotion->id,
                    'query' => $promotion->query,
                    'matchType' => $promotion->matchType,
                    'position' => $promotion->position,
                    'elementId' => $promotion->elementId,
                    'elementTitle' => $element ? $element->title : 'Element not found',
                    'elementEditUrl' => $element ? $element->getCpEditUrl() : '#',
                    'enabled' => $promotion->enabled,
                    'siteStatuses' => $siteStatuses,
                ];
            }

            return $this->asJson([
                'success' => true,
                'promotions' => $promotions,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Test which query rules match a query
     */
    public function actionTestQueryRules(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getBodyParam('indexHandle');

        try {
            // Get matching rules
            $matchingRules = \lindemannrock\searchmanager\models\QueryRule::findMatching($query, $indexHandle);

            $rules = [];
            $redirect = null;
            $synonyms = [$query]; // Start with original query

            foreach ($matchingRules as $rule) {
                // Build effect description
                $effectDescription = '';
                switch ($rule->actionType) {
                    case 'synonym':
                        $terms = $rule->getSynonyms();
                        $effectDescription = 'Expands to: ' . implode(', ', $terms);
                        $synonyms = array_merge($synonyms, $terms);
                        break;
                    case 'boost_section':
                        $effectDescription = 'Boost section "' . ($rule->actionValue['sectionHandle'] ?? '') . '" by ' . ($rule->actionValue['multiplier'] ?? 2.0) . 'x';
                        break;
                    case 'boost_category':
                        $effectDescription = 'Boost category by ' . ($rule->actionValue['multiplier'] ?? 2.0) . 'x';
                        break;
                    case 'boost_element':
                        $effectDescription = 'Boost element #' . ($rule->actionValue['elementId'] ?? '') . ' by ' . ($rule->actionValue['multiplier'] ?? 2.0) . 'x';
                        break;
                    case 'filter':
                        $effectDescription = 'Filter: ' . ($rule->actionValue['field'] ?? '') . ' = ' . ($rule->actionValue['value'] ?? '');
                        break;
                    case 'redirect':
                        $redirect = $rule->getRedirectUrl();
                        // Check if element-based redirect
                        $elementInfo = null;
                        if (!empty($rule->actionValue['elementId']) && !empty($rule->actionValue['elementType'])) {
                            $element = Craft::$app->getElements()->getElementById(
                                (int)$rule->actionValue['elementId'],
                                $rule->actionValue['elementType']
                            );
                            if ($element) {
                                $elementInfo = [
                                    'id' => $element->id,
                                    'title' => $element->title ?? 'Untitled',
                                    'type' => (new \ReflectionClass($element))->getShortName(),
                                    'url' => $element->getUrl(),
                                    'cpEditUrl' => $element->getCpEditUrl(),
                                ];
                                $effectDescription = 'Redirect to ' . $elementInfo['type'] . ': ' . $elementInfo['title'];
                            } else {
                                $effectDescription = 'Redirect to element (not found)';
                            }
                        } else {
                            $effectDescription = 'Redirect to: ' . $redirect;
                        }
                        break;
                }

                $rules[] = [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'actionType' => $rule->actionType,
                    'matchType' => $rule->matchType,
                    'matchValue' => $rule->matchValue,
                    'effectDescription' => $effectDescription,
                    'elementInfo' => $elementInfo ?? null,
                    'editUrl' => Craft::$app->getUrlManager()->createUrl('search-manager/query-rules/edit/' . $rule->id),
                ];
            }

            return $this->asJson([
                'success' => true,
                'rules' => $rules,
                'redirect' => $redirect,
                'synonyms' => array_unique($synonyms),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function actionSave(): ?Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();

        $settings = SearchManager::$plugin->getSettings();
        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);

        // Convert ngramSizes array to comma-separated string
        if (isset($postedSettings['ngramSizes'])) {
            if (is_array($postedSettings['ngramSizes'])) {
                $postedSettings['ngramSizes'] = !empty($postedSettings['ngramSizes'])
                    ? implode(',', $postedSettings['ngramSizes'])
                    : ''; // Empty array = disable fuzzy
            }
        }

        $settings->setAttributes($postedSettings, false);

        if (!$settings->validate()) {
            $this->logError('Settings validation failed', ['errors' => $settings->getErrors()]);
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings.'));
            return null;
        }

        if (!$settings->saveToDatabase()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings.'));
            return null;
        }

        $this->logInfo('Settings saved successfully');
        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionSaveBackend(): ?Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();

        $settings = SearchManager::$plugin->getSettings();
        $oldBackend = $settings->defaultBackendHandle ?? null;

        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $newBackendHandle = $postedSettings['defaultBackendHandle'] ?? null;

        // Validate that the selected backend exists and is enabled
        if ($newBackendHandle) {
            $configuredBackend = \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($newBackendHandle);
            if (!$configuredBackend) {
                Craft::$app->getSession()->setError(Craft::t('search-manager', 'Selected backend does not exist.'));
                return $this->renderTemplate('search-manager/settings/backend', [
                    'settings' => $settings,
                    'configuredBackends' => \lindemannrock\searchmanager\models\ConfiguredBackend::findAll(),
                    'enabledBackends' => array_filter(\lindemannrock\searchmanager\models\ConfiguredBackend::findAll(), fn($b) => $b->enabled),
                ]);
            }
            if (!$configuredBackend->enabled) {
                Craft::$app->getSession()->setError(Craft::t('search-manager', 'Selected backend is disabled. Enable it first in the Backends section.'));
                return $this->renderTemplate('search-manager/settings/backend', [
                    'settings' => $settings,
                    'configuredBackends' => \lindemannrock\searchmanager\models\ConfiguredBackend::findAll(),
                    'enabledBackends' => array_filter(\lindemannrock\searchmanager\models\ConfiguredBackend::findAll(), fn($b) => $b->enabled),
                ]);
            }
        }

        $settings->defaultBackendHandle = $newBackendHandle;

        if (!$settings->saveToDatabase()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings.'));
            return $this->renderTemplate('search-manager/settings/backend', [
                'settings' => $settings,
                'configuredBackends' => \lindemannrock\searchmanager\models\ConfiguredBackend::findAll(),
                'enabledBackends' => array_filter(\lindemannrock\searchmanager\models\ConfiguredBackend::findAll(), fn($b) => $b->enabled),
            ]);
        }

        $this->logInfo('Default backend setting saved', ['handle' => $newBackendHandle]);

        $backendChanged = $oldBackend !== $newBackendHandle;
        if ($backendChanged && $newBackendHandle) {
            Craft::$app->getSession()->setNotice(Craft::t('search-manager',
                'Default backend changed to "{name}". Rebuild indices in Utilities to migrate data.',
                ['name' => $configuredBackend->name]
            ));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Settings saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionCleanupAnalytics(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();

        $settings = SearchManager::$plugin->getSettings();
        $retention = $settings->analyticsRetention;

        if ($retention <= 0) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Analytics retention must be greater than 0 to perform cleanup.'),
            ]);
        }

        try {
            $cutoffDate = new \DateTime("-{$retention} days");
            $deleted = Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_analytics}}', ['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
                ->execute();

            $this->logInfo('Analytics cleanup completed', [
                'retention_days' => $retention,
                'deleted_count' => $deleted,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'Deleted {count} old analytics records.', ['count' => $deleted]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to cleanup analytics', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to cleanup analytics: {error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
