<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Promotions Controller
 *
 * Manages promoted/pinned search results in the CP
 */
class PromotionsController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all promotions
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageIndices');

        $promotions = Promotion::findAll();
        $indices = SearchIndex::findAll();

        // Build index lookup for display
        $indexLookup = [];
        foreach ($indices as $index) {
            $indexLookup[$index->handle] = $index->name;
        }

        return $this->renderTemplate('search-manager/promotions/index', [
            'promotions' => $promotions,
            'indexLookup' => $indexLookup,
        ]);
    }

    /**
     * Edit or create a promotion
     */
    public function actionEdit(?int $promotionId = null): Response
    {
        $this->requirePermission('searchManager:manageIndices');

        if ($promotionId) {
            $promotion = Promotion::findById($promotionId);
            if (!$promotion) {
                throw new NotFoundHttpException('Promotion not found');
            }
        } else {
            $promotion = new Promotion();
        }

        // Get indices for dropdown
        $indices = SearchIndex::findAll();
        $indexOptions = [
            ['label' => Craft::t('search-manager', 'All Indexes'), 'value' => ''],
        ];
        foreach ($indices as $index) {
            if ($index->enabled) {
                $indexOptions[] = [
                    'label' => $index->name,
                    'value' => $index->handle,
                ];
            }
        }

        // Get sites for dropdown
        $siteOptions = [
            ['label' => Craft::t('search-manager', 'All Sites'), 'value' => ''],
        ];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = [
                'label' => $site->name,
                'value' => $site->id,
            ];
        }

        // Match type options
        $matchTypeOptions = [
            ['label' => Craft::t('search-manager', 'Exact Match'), 'value' => 'exact'],
            ['label' => Craft::t('search-manager', 'Contains'), 'value' => 'contains'],
            ['label' => Craft::t('search-manager', 'Starts With'), 'value' => 'prefix'],
        ];

        return $this->renderTemplate('search-manager/promotions/edit', [
            'promotion' => $promotion,
            'isNew' => !$promotionId,
            'indexOptions' => $indexOptions,
            'siteOptions' => $siteOptions,
            'matchTypeOptions' => $matchTypeOptions,
        ]);
    }

    /**
     * Save a promotion
     */
    public function actionSave(): ?Response
    {
        $this->requirePermission('searchManager:manageIndices');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $promotionId = $request->getBodyParam('promotionId');

        if ($promotionId) {
            $promotion = Promotion::findById($promotionId);
            if (!$promotion) {
                throw new NotFoundHttpException('Promotion not found');
            }
        } else {
            $promotion = new Promotion();
        }

        // Set attributes
        $promotion->indexHandle = $request->getBodyParam('indexHandle') ?: null;
        $promotion->title = $request->getBodyParam('title') ?: null;
        $promotion->query = $request->getBodyParam('query');
        $promotion->matchType = $request->getBodyParam('matchType', 'exact');

        // Handle element select field (comes as array)
        $promotedElement = $request->getBodyParam('promotedElement');
        if (is_array($promotedElement) && !empty($promotedElement)) {
            $promotion->elementId = (int)reset($promotedElement);
        } else {
            $promotion->elementId = (int)$promotedElement;
        }

        $promotion->position = (int)$request->getBodyParam('position', 1);
        $promotion->siteId = $request->getBodyParam('siteId') ?: null;
        $promotion->enabled = (bool)$request->getBodyParam('enabled', true);

        if (!$promotion->validate() || !$promotion->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save promotion.')
            );

            // Return with errors
            Craft::$app->getUrlManager()->setRouteParams([
                'promotion' => $promotion,
            ]);

            return null;
        }

        // Clear search cache for this index (or all if global promotion)
        if ($promotion->indexHandle) {
            SearchManager::$plugin->backend->clearSearchCache($promotion->indexHandle);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Promotion saved.')
        );

        return $this->redirectToPostedUrl($promotion);
    }

    /**
     * Delete a promotion
     */
    public function actionDelete(): Response
    {
        $this->requirePermission('searchManager:manageIndices');
        $this->requirePostRequest();

        $promotionId = Craft::$app->getRequest()->getRequiredBodyParam('promotionId');
        $promotion = Promotion::findById((int)$promotionId);

        if (!$promotion) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => 'Promotion not found']);
            }
            throw new NotFoundHttpException('Promotion not found');
        }

        $indexHandle = $promotion->indexHandle;

        if ($promotion->delete()) {
            // Clear search cache for this index
            SearchManager::$plugin->backend->clearSearchCache($indexHandle);

            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }

            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Promotion deleted.')
            );
        } else {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => 'Could not delete promotion']);
            }

            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not delete promotion.')
            );
        }

        return $this->redirect('search-manager/promotions');
    }

    /**
     * Bulk enable promotions
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePermission('searchManager:manageIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $promotionIds = Craft::$app->getRequest()->getRequiredBodyParam('promotionIds');
        $count = 0;
        $affectedIndices = [];

        foreach ($promotionIds as $id) {
            $promotion = Promotion::findById((int)$id);
            if ($promotion) {
                $promotion->enabled = true;
                if ($promotion->save()) {
                    $count++;
                    $affectedIndices[$promotion->indexHandle] = true;
                }
            }
        }

        // Clear cache for affected indices
        foreach (array_keys($affectedIndices) as $indexHandle) {
            SearchManager::$plugin->backend->clearSearchCache($indexHandle);
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Bulk disable promotions
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePermission('searchManager:manageIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $promotionIds = Craft::$app->getRequest()->getRequiredBodyParam('promotionIds');
        $count = 0;
        $affectedIndices = [];

        foreach ($promotionIds as $id) {
            $promotion = Promotion::findById((int)$id);
            if ($promotion) {
                $promotion->enabled = false;
                if ($promotion->save()) {
                    $count++;
                    $affectedIndices[$promotion->indexHandle] = true;
                }
            }
        }

        // Clear cache for affected indices
        foreach (array_keys($affectedIndices) as $indexHandle) {
            SearchManager::$plugin->backend->clearSearchCache($indexHandle);
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Bulk delete promotions
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePermission('searchManager:manageIndices');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $promotionIds = Craft::$app->getRequest()->getRequiredBodyParam('promotionIds');
        $count = 0;
        $affectedIndices = [];

        foreach ($promotionIds as $id) {
            $promotion = Promotion::findById((int)$id);
            if ($promotion) {
                $affectedIndices[$promotion->indexHandle] = true;
                if ($promotion->delete()) {
                    $count++;
                }
            }
        }

        // Clear cache for affected indices
        foreach (array_keys($affectedIndices) as $indexHandle) {
            SearchManager::$plugin->backend->clearSearchCache($indexHandle);
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }
}
