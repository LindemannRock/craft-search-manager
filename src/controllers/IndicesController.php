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
     * Edit or create an index
     */
    public function actionEdit(?int $indexId = null): Response
    {
        $this->requirePermission('searchManager:manageIndices');

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
        $this->requirePermission('searchManager:manageIndices');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $indexId = $request->getBodyParam('indexId');

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
        $index->enabled = (bool)$request->getBodyParam('enabled');
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
        $this->requirePermission('searchManager:manageIndices');
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

        // Clear search cache
        SearchManager::$plugin->backend->clearSearchCache($index->handle);

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Index data cleared.')
        );

        return $this->redirect('search-manager/indices');
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

        return $this->redirect('search-manager/indices');
    }

    /**
     * Bulk enable indices
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePermission('searchManager:manageIndices');
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
        $this->requirePermission('searchManager:manageIndices');
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
