<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Indices Controller
 */
class IndicesController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all indices
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewIndices');

        $indices = SearchIndex::findAll();

        return $this->renderTemplate('search-manager/indices/index', [
            'indices' => $indices,
        ]);
    }

    /**
     * View an index (read-only, for config indices)
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('searchManager:viewIndices');

        if (!$handle) {
            throw new NotFoundHttpException('Index handle required');
        }

        $index = SearchIndex::findByHandle($handle);

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        // If the index is editable (database), redirect to edit page
        if ($index->canEdit()) {
            return $this->redirect('search-manager/indices/edit/' . $index->id);
        }

        return $this->renderTemplate('search-manager/indices/view', [
            'index' => $index,
        ]);
    }

    /**
     * Edit or create an index
     */
    public function actionEdit(?int $indexId = null): Response
    {
        // Require create permission for new, edit permission for existing
        if ($indexId) {
            $this->requirePermission('searchManager:editIndices');
        } else {
            $this->requirePermission('searchManager:createIndices');
        }

        if ($indexId) {
            $index = SearchIndex::findById($indexId);
            if (!$index) {
                throw new NotFoundHttpException('Index not found');
            }

            if (!$index->canEdit()) {
                Craft::$app->getSession()->setError(
                    Craft::t('search-manager', 'This index is defined in config and cannot be edited.')
                );
                return $this->redirect('search-manager/indices');
            }
        } else {
            $index = new SearchIndex();
        }

        return $this->renderTemplate('search-manager/indices/edit', [
            'index' => $index,
            'isNew' => !$indexId,
        ]);
    }

    /**
     * Save an index
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $indexId = $request->getBodyParam('indexId');

        // Require create permission for new, edit permission for existing
        if ($indexId) {
            $this->requirePermission('searchManager:editIndices');
        } else {
            $this->requirePermission('searchManager:createIndices');
        }

        if ($indexId) {
            $index = SearchIndex::findById($indexId);
            if (!$index) {
                throw new NotFoundHttpException('Index not found');
            }
        } else {
            $index = new SearchIndex();
        }

        // Set attributes
        $index->name = $request->getBodyParam('name');
        $index->handle = $request->getBodyParam('handle');
        $index->elementType = $request->getBodyParam('elementType');
        $index->siteId = $request->getBodyParam('siteId') ?: null;
        $index->transformerClass = $request->getBodyParam('transformerClass');
        $index->language = $request->getBodyParam('language') ?: null;
        $index->backend = $request->getBodyParam('backend') ?: null;
        $index->enabled = (bool)$request->getBodyParam('enabled');
        $index->enableAnalytics = (bool)$request->getBodyParam('enableAnalytics', true);
        $index->skipEntriesWithoutUrl = (bool)$request->getBodyParam('skipEntriesWithoutUrl', false);
        $index->criteria = $request->getBodyParam('criteria', []);

        if (!$index->validate() || !$index->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save index.')
            );
            return null;
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Index saved.')
        );

        return $this->redirectToPostedUrl($index);
    }

    /**
     * Delete an index
     */
    public function actionDelete(): Response
    {
        $this->requirePermission('searchManager:deleteIndices');
        $this->requirePostRequest();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');

        // Support both numeric IDs (database indices) and string handles (config indices)
        if (is_numeric($indexId)) {
            $index = SearchIndex::findById((int)$indexId);
        } else {
            $index = SearchIndex::findByHandle($indexId);
        }

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        if (!$index->canEdit()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'This index is defined in config and cannot be deleted.')
            );
            return $this->redirect('search-manager/indices');
        }

        if ($index->delete()) {
            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Index deleted.')
            );
        } else {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not delete index.')
            );
        }

        return $this->redirect('search-manager/indices');
    }

    /**
     * Clear an index (remove all indexed data but keep index definition)
     */
    public function actionClear(): Response
    {
        $this->requirePermission('searchManager:clearIndices');
        $this->requirePostRequest();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');

        // Support both numeric IDs (database indices) and string handles (config indices)
        if (is_numeric($indexId)) {
            $index = SearchIndex::findById((int)$indexId);
        } else {
            $index = SearchIndex::findByHandle($indexId);
        }

        if (!$index) {
            throw new NotFoundHttpException('Index not found');
        }

        // Clear backend storage
        SearchManager::$plugin->backend->clearIndex($index->handle);

        // Update stats to 0
        $index->updateStats(0);

        // Clear caches
        SearchManager::$plugin->backend->clearSearchCache($index->handle);
        SearchManager::$plugin->autocomplete->clearCache($index->handle);

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Index data cleared.')
        );

        return $this->redirectToPostedUrl(null, 'search-manager/indices');
    }

    /**
     * Clear cache for a specific index (search cache + autocomplete cache)
     */
    public function actionClearCache(): Response
    {
        $this->requirePermission('searchManager:clearCache');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');

        // Support both numeric IDs (database indices) and string handles (config indices)
        if (is_numeric($indexId)) {
            $index = SearchIndex::findById((int)$indexId);
        } else {
            $index = SearchIndex::findByHandle($indexId);
        }

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Index not found.'),
            ]);
        }

        try {
            // Clear search cache for this index
            SearchManager::$plugin->backend->clearSearchCache($index->handle);

            // Clear autocomplete cache for this index
            SearchManager::$plugin->autocomplete->clearCache($index->handle);

            $this->logInfo('Index cache cleared', [
                'index' => $index->handle,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'Cache cleared for "{name}".', [
                    'name' => $index->name,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear index cache', [
                'index' => $index->handle,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear cache.'),
            ]);
        }
    }

    /**
     * Sync document count from backend
     *
     * Reads the actual document count from the backend (e.g., Algolia)
     * and updates the local record to match.
     *
     * @since 5.35.0
     */
    public function actionSyncCount(): Response
    {
        $this->requirePermission('searchManager:rebuildIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');

        // Support both numeric IDs (database indices) and string handles (config indices)
        if (is_numeric($indexId)) {
            $index = SearchIndex::findById((int)$indexId);
        } else {
            $index = SearchIndex::findByHandle($indexId);
        }

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Index not found.'),
            ]);
        }

        try {
            // Get the backend for this index
            $backend = SearchManager::$plugin->backend->getBackendForIndex($index->handle);

            if (!$backend) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'No backend configured for this index.'),
                ]);
            }

            // Get all indices from the backend
            $backendIndices = $backend->listIndices();

            // Build full index name with prefix
            $settings = SearchManager::$plugin->getSettings();
            $prefix = $settings->indexPrefix ?? '';
            $fullIndexName = $prefix . $index->handle;

            // Find matching index by full name (with prefix)
            $backendCount = 0;
            $entriesAvailable = true;
            $indexFound = false;
            foreach ($backendIndices as $backendIndex) {
                if (($backendIndex['name'] ?? '') === $fullIndexName) {
                    $indexFound = true;
                    $backendCount = $backendIndex['entries'] ?? 0;
                    $entriesAvailable = $backendIndex['entriesAvailable'] ?? true;
                    break;
                }
            }

            // Check if index was found on backend
            if (!$indexFound) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'Index "{name}" not found on backend.', [
                        'name' => $fullIndexName,
                    ]),
                ]);
            }

            // Check if count is available (stats may fail due to permissions)
            if ($entriesAvailable === false) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'Could not retrieve document count from backend (permission issue).'),
                ]);
            }

            // Update the local document count
            if (!$index->updateStats($backendCount)) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'Failed to update index stats.'),
                ]);
            }

            $this->logInfo('Synced document count from backend', [
                'index' => $index->handle,
                'count' => $backendCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'Count synced for "{name}": {count} documents.', [
                    'name' => $index->name,
                    'count' => number_format($backendCount),
                ]),
                'count' => $backendCount,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to sync count from backend', [
                'index' => $index->handle,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to sync count: {error}', [
                    'error' => $e->getMessage(),
                ]),
            ]);
        }
    }

    /**
     * Rebuild an index
     */
    public function actionRebuild(): Response
    {
        $this->requirePermission('searchManager:rebuildIndices');
        $this->requirePostRequest();

        $indexId = Craft::$app->getRequest()->getRequiredBodyParam('indexId');

        $this->logDebug('Rebuilding index', [
            'indexId' => $indexId,
            'type' => gettype($indexId),
            'isNumeric' => is_numeric($indexId),
        ]);

        // Support both numeric IDs (database indices) and string handles (config indices)
        if (is_numeric($indexId)) {
            $index = SearchIndex::findById((int)$indexId);
        } else {
            $index = SearchIndex::findByHandle($indexId);
        }

        if (!$index) {
            $this->logError('Index not found', [
                'indexId' => $indexId,
                'type' => gettype($indexId),
            ]);
            throw new NotFoundHttpException('Index not found');
        }

        SearchManager::$plugin->indexing->rebuildIndex($index->handle);

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Index rebuild queued.')
        );

        return $this->redirectToPostedUrl(null, 'search-manager/indices');
    }

    /**
     * Bulk enable indices
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePermission('searchManager:editIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexIds = Craft::$app->getRequest()->getRequiredBodyParam('indexIds');
        $count = 0;

        foreach ($indexIds as $id) {
            if (is_numeric($id)) {
                $index = SearchIndex::findById((int)$id);
            } else {
                $index = SearchIndex::findByHandle($id);
            }

            if ($index && $index->canEdit()) {
                $index->enabled = true;
                if ($index->save()) {
                    $count++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Bulk disable indices
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePermission('searchManager:editIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexIds = Craft::$app->getRequest()->getRequiredBodyParam('indexIds');
        $count = 0;

        foreach ($indexIds as $id) {
            if (is_numeric($id)) {
                $index = SearchIndex::findById((int)$id);
            } else {
                $index = SearchIndex::findByHandle($id);
            }

            if ($index && $index->canEdit()) {
                $index->enabled = false;
                if ($index->save()) {
                    $count++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Bulk delete indices
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePermission('searchManager:deleteIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexIds = Craft::$app->getRequest()->getRequiredBodyParam('indexIds');
        $count = 0;

        foreach ($indexIds as $id) {
            if (is_numeric($id)) {
                $index = SearchIndex::findById((int)$id);
            } else {
                $index = SearchIndex::findByHandle($id);
            }

            if ($index && $index->canEdit() && $index->delete()) {
                $count++;
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }
}
