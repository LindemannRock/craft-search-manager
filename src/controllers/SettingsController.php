<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\helpers\Db;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\BackendSettings;
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

        // Load backend configurations from database
        $algolia = BackendSettings::findByBackend('algolia');
        $meilisearch = BackendSettings::findByBackend('meilisearch');
        $redis = BackendSettings::findByBackend('redis');
        $typesense = BackendSettings::findByBackend('typesense');

        // Detect database driver for dynamic labeling
        $dbDriver = Craft::$app->getDb()->getDriverName();
        $dbLabel = match ($dbDriver) {
            'mysql' => 'Craft Database (MySQL)',
            'pgsql' => 'Craft Database (PostgreSQL)',
            default => 'Craft Database',
        };

        return $this->renderTemplate('search-manager/settings/backend', [
            'settings' => $settings,
            'dbDriver' => $dbDriver,
            'dbLabel' => $dbLabel,
            'algoliaSettings' => $algolia,
            'meilisearchSettings' => $meilisearch,
            'redisSettings' => $redis,
            'typesenseSettings' => $typesense,
            'algoliaConfig' => $algolia ? $algolia->config : [],
            'meilisearchConfig' => $meilisearch ? $meilisearch->config : [],
            'redisConfig' => $redis ? $redis->config : [],
            'typesenseConfig' => $typesense ? $typesense->config : [],
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

        return $this->renderTemplate('search-manager/settings/test', []);
    }

    public function actionTestSearch(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getRequiredBodyParam('indexHandle');

        try {
            $startTime = microtime(true);
            $results = SearchManager::$plugin->backend->search($indexHandle, $query, []);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $backend = SearchManager::$plugin->backend->getActiveBackend();
            $backendName = $backend ? $backend->getName() : 'unknown';

            // Check if result was cached (execution time near 0)
            $cached = $executionTime < 5;

            return $this->asJson([
                'success' => true,
                'total' => $results['total'] ?? 0,
                'hits' => $results['hits'] ?? [],
                'backend' => $backendName,
                'executionTime' => $executionTime,
                'cached' => $cached,
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

        // Detect database driver for dynamic labeling
        $dbDriver = Craft::$app->getDb()->getDriverName();
        $dbLabel = match ($dbDriver) {
            'mysql' => 'Craft Database (MySQL)',
            'pgsql' => 'Craft Database (PostgreSQL)',
            default => 'Craft Database',
        };

        // Save general settings first
        $settings = SearchManager::$plugin->getSettings();
        $oldBackend = $settings->searchBackend;

        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $settings->setAttributes($postedSettings, false);

        $newBackend = $settings->searchBackend;
        $backendChanged = $oldBackend !== $newBackend;

        // Helper function to render template with all required variables
        $renderBackendTemplate = function($settings, $backendModels = []) use ($dbDriver, $dbLabel) {
            return $this->renderTemplate('search-manager/settings/backend', [
                'settings' => $settings,
                'dbDriver' => $dbDriver,
                'dbLabel' => $dbLabel,
                'algoliaSettings' => $backendModels['algolia'] ?? BackendSettings::findByBackend('algolia'),
                'meilisearchSettings' => $backendModels['meilisearch'] ?? BackendSettings::findByBackend('meilisearch'),
                'redisSettings' => $backendModels['redis'] ?? BackendSettings::findByBackend('redis'),
                'typesenseSettings' => $backendModels['typesense'] ?? BackendSettings::findByBackend('typesense'),
                'algoliaConfig' => ($backendModels['algolia'] ?? BackendSettings::findByBackend('algolia'))?->config ?? [],
                'meilisearchConfig' => ($backendModels['meilisearch'] ?? BackendSettings::findByBackend('meilisearch'))?->config ?? [],
                'redisConfig' => ($backendModels['redis'] ?? BackendSettings::findByBackend('redis'))?->config ?? [],
                'typesenseConfig' => ($backendModels['typesense'] ?? BackendSettings::findByBackend('typesense'))?->config ?? [],
            ]);
        };

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings.'));
            return $renderBackendTemplate($settings);
        }

        // Don't save main settings yet - validate backend first

        // Save and validate backend configurations
        $backendData = Craft::$app->getRequest()->getBodyParam('backend', []);
        $backendModels = [];
        $hasValidationError = false;

        // Process all backends (MySQL, PostgreSQL, and File have no config fields)
        $allBackends = ['algolia', 'file', 'meilisearch', 'mysql', 'pgsql', 'redis', 'typesense'];

        foreach ($allBackends as $backendName) {
            $backendSettings = BackendSettings::findByBackend($backendName);

            if (!$backendSettings) {
                $backendSettings = new BackendSettings();
                $backendSettings->backend = $backendName;
            }

            // Update config for the selected backend (even if empty - clears old values)
            if ($backendName === $newBackend) {
                $backendSettings->config = $backendData[$backendName] ?? [];
            } elseif (isset($backendData[$backendName])) {
                // Only update other backends if they were actually in the form
                $backendSettings->config = $backendData[$backendName];
            }

            // Enable the selected backend, disable others
            $backendSettings->enabled = ($backendName === $newBackend);

            $backendModels[$backendName] = $backendSettings;

            // Only validate the selected backend
            if ($backendName === $newBackend) {
                if (!$backendSettings->validate()) {
                    $hasValidationError = true;
                    Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save backend settings. Please check required fields.'));
                } else {
                    // Save first so availability check uses new config
                    $backendSettings->save();

                    // Check if backend is actually available (now uses saved config)
                    $backendInstance = SearchManager::$plugin->backend->getBackend($backendName);
                    if ($backendInstance && !$backendInstance->isAvailable()) {
                        // Rollback: re-enable old backend and disable new one
                        $backendSettings->enabled = false;
                        $backendSettings->save();

                        // Re-enable the old backend
                        if ($oldBackend !== $newBackend) {
                            $oldBackendSettings = $backendModels[$oldBackend] ?? null;
                            if ($oldBackendSettings) {
                                $oldBackendSettings->enabled = true;
                                $oldBackendSettings->save();
                            }
                        }

                        $hasValidationError = true;
                        $status = $backendInstance->getStatus();
                        Craft::$app->getSession()->setError(Craft::t('search-manager',
                            'Cannot switch to {backend} - backend is not available. Please check configuration and connection.',
                            ['backend' => $backendName]
                        ));
                    }
                }
            }
        }

        // Save all other backends only if validation passed
        if (!$hasValidationError) {
            foreach ($backendModels as $backendName => $backendSettings) {
                // Skip selected backend (already saved above)
                if ($backendName !== $newBackend) {
                    $backendSettings->save();
                }
            }
        }

        if ($hasValidationError) {
            // Keep the new backend selected so user sees the form with errors
            return $renderBackendTemplate($settings, $backendModels);
        }

        // Now save main settings (backend validated successfully)
        if (!$settings->saveToDatabase()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings.'));
            return $renderBackendTemplate($settings, $backendModels);
        }

        $this->logInfo('Backend settings saved successfully');

        // Show appropriate message
        if ($backendChanged) {
            Craft::$app->getSession()->setNotice(Craft::t('search-manager',
                'Settings saved. Backend changed from {old} to {new}. Rebuild all indices in Utilities to migrate data.',
                [
                    'old' => $oldBackend,
                    'new' => $newBackend,
                ]
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
