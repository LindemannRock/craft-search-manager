<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\CacheStorageService;
use yii\web\Response;

/**
 * Utilities Controller
 *
 * @since 5.0.0
 */
class UtilitiesController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Permission checks based on action
        switch ($action->id) {
            case 'rebuild-all-indices':
            case 'clear-storage-by-type':
            case 'get-storage-stats':
                $this->requirePermission('searchManager:rebuildIndices');
                break;
            case 'clear-device-cache':
            case 'clear-search-cache':
            case 'clear-autocomplete-cache':
            case 'clear-all-caches':
                $this->requirePermission('searchManager:clearCache');
                break;
            case 'clear-all-analytics':
                $this->requirePermission('searchManager:clearAnalytics');
                break;
        }

        return true;
    }

    /**
     * Rebuild all indices
     */
    public function actionRebuildAllIndices(): Response
    {
        $this->requirePostRequest();

        try {
            $result = SearchManager::$plugin->indexing->rebuildAllResult();
            if (!$result['queued']) {
                $error = $result['reason'];
                if ($result['reasonCode'] === 'index-handle-collision') {
                    $error = Craft::t('search-manager', 'Cannot rebuild indices: Handle collision detected. The following handles exist in both config and database: {handles}. Please resolve these conflicts first.', [
                        'handles' => implode(', ', $result['collisions']),
                    ]);
                }
                Craft::$app->getSession()->setError((string)$error);
                return $this->redirectToPostedUrl();
            }

            $this->logInfo('All indices rebuild queued via utility');

            $notice = $result['skips'] === []
                ? Craft::t('search-manager', 'All indices rebuild has been queued.')
                : Craft::t('search-manager', 'Eligible indices were queued. Some indices were skipped because they are disabled or structurally invalid.');
            Craft::$app->getSession()->setNotice($notice);
        } catch (\Throwable $e) {
            $this->logError('Failed to queue index rebuild', [
                'error' => $e->getMessage(),
            ]);

            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Failed to queue index rebuild')
            );
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Clear device detection cache
     */
    public function actionClearDeviceCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $cacheStorage = new CacheStorageService();
            $fileCount = $cacheStorage->getEffectiveStorage() === CacheStorageService::STORAGE_FILE
                ? $cacheStorage->countFiles('device')
                : null;
            SearchManager::$plugin->deviceDetection->clearCache();
            $message = $fileCount === null
                ? Craft::t('search-manager', 'Device cache cleared successfully')
                : Craft::t('search-manager', 'Device cache cleared successfully ({count} files)', ['count' => $fileCount]);

            $this->logInfo('Device cache cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear device cache', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear device cache'),
            ]);
        }
    }

    /**
     * Clear search results cache
     */
    public function actionClearSearchCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $cacheStorage = new CacheStorageService();
            $fileCount = $cacheStorage->getEffectiveStorage() === CacheStorageService::STORAGE_FILE
                ? $cacheStorage->countFiles('search')
                : null;
            SearchManager::$plugin->backend->clearAllSearchCache();
            $message = $fileCount === null
                ? Craft::t('search-manager', 'Search cache cleared successfully')
                : Craft::t('search-manager', 'Search cache cleared successfully ({count} files)', ['count' => $fileCount]);

            $this->logInfo('Search cache cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear search cache', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear search cache'),
            ]);
        }
    }

    /**
     * Clear autocomplete cache
     */
    public function actionClearAutocompleteCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $cacheStorage = new CacheStorageService();
            $fileCount = $cacheStorage->getEffectiveStorage() === CacheStorageService::STORAGE_FILE
                ? $cacheStorage->countFiles('autocomplete')
                : null;
            SearchManager::$plugin->autocomplete->clearCache();
            $message = $fileCount === null
                ? Craft::t('search-manager', 'Autocomplete cache cleared successfully')
                : Craft::t('search-manager', 'Autocomplete cache cleared successfully ({count} files)', ['count' => $fileCount]);

            $this->logInfo('Autocomplete cache cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear autocomplete cache', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear autocomplete cache'),
            ]);
        }
    }

    /**
     * Clear all caches (device + search + autocomplete)
     */
    public function actionClearAllCaches(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $cacheStorage = new CacheStorageService();
            $totalFiles = null;
            if ($cacheStorage->getEffectiveStorage() === CacheStorageService::STORAGE_FILE) {
                $totalFiles = $cacheStorage->countFiles('search')
                    + $cacheStorage->countFiles('autocomplete')
                    + $cacheStorage->countFiles('device');
            }

            SearchManager::$plugin->backend->clearAllSearchCache();
            SearchManager::$plugin->autocomplete->clearCache();
            SearchManager::$plugin->deviceDetection->clearCache();
            $message = $totalFiles === null
                ? Craft::t('search-manager', 'All caches cleared successfully')
                : Craft::t('search-manager', 'All caches cleared successfully ({count} files)', ['count' => $totalFiles]);

            $this->logInfo('All caches cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear all caches', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear all caches'),
            ]);
        }
    }

    /**
     * Clear all analytics data
     */
    public function actionClearAllAnalytics(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $effectiveSiteIds = Craft::$app->getSites()->getEditableSiteIds();
            $rowCount = SearchManager::$plugin->analytics->clearAnalytics($effectiveSiteIds);

            $this->logInfo('All analytics data cleared via utility', [
                'rowsDeleted' => $rowCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'All analytics data cleared successfully ({count} records deleted)', [
                    'count' => $rowCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear analytics data', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear analytics data'),
            ]);
        }
    }

    /**
     * Clear ALL data from a specific backend storage type (database, redis, or file)
     *
     * This is a maintenance function to clear orphaned data when backends change
     */
    public function actionClearStorageByType(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type = strtolower($this->request->getRequiredBodyParam('type'));
        $validTypes = ['database', 'redis', 'file'];

        if (!in_array($type, $validTypes, true)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Invalid storage type: {type}', ['type' => $type]),
            ]);
        }

        try {
            $result = SearchManager::$plugin->storageMaintenance->clearStorageByType($type);

            if ($result['success']) {
                $this->logInfo('Storage cleared by type via utility', [
                    'type' => $type,
                    'details' => $result,
                ]);

                return $this->asJson($result);
            }

            return $this->asJson($result);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear storage by type', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear {type} storage', ['type' => $type]),
            ]);
        }
    }

    /**
     * Get storage statistics for all backend types
     */
    public function actionGetStorageStats(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $projection = SearchManager::$plugin->storageMaintenance->getProjection();

            return $this->asJson([
                'success' => true,
                'stats' => $projection['stats'],
                'storageOptions' => $projection['storageOptions'],
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage stats', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to get storage statistics'),
            ]);
        }
    }
}
