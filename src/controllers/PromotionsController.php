<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\db\Query;
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
 *
 * @since 5.10.0
 */
class PromotionsController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all promotions.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:managePromotions');

        $request = Craft::$app->getRequest();
        $settings = SearchManager::$plugin->getSettings();

        $promotions = Promotion::findAll();
        $indices = SearchIndex::findAll();

        $indexLookup = [];
        foreach ($indices as $index) {
            $indexLookup[$index->handle] = $index->name;
        }

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $matchTypeFilter = (string) $request->getQueryParam('matchType', 'all');
        $validMatchTypes = ['all', 'exact', 'contains', 'prefix'];
        if (!in_array($matchTypeFilter, $validMatchTypes, true)) {
            $matchTypeFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['title', 'query', 'matchType', 'position', 'siteId', 'enabled'];
        $sort = (string) $request->getParam('sort', 'position');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'position';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $promotions = array_values(array_filter($promotions, fn(Promotion $p): bool => $p->enabled));
        } elseif ($statusFilter === 'disabled') {
            $promotions = array_values(array_filter($promotions, fn(Promotion $p): bool => !$p->enabled));
        }

        if ($matchTypeFilter !== 'all') {
            $promotions = array_values(array_filter($promotions, fn(Promotion $p): bool => $p->matchType === $matchTypeFilter));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $promotions = array_values(array_filter($promotions, function(Promotion $p) use ($needle): bool {
                return str_contains(mb_strtolower((string) $p->query), $needle)
                    || ($p->indexHandle !== null && str_contains(mb_strtolower($p->indexHandle), $needle))
                    || ($p->title !== null && str_contains(mb_strtolower($p->title), $needle));
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $promotions = $this->sortPromotions($promotions, $sort, $dir);

        $totalCount = count($promotions);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $promotions = array_slice($promotions, $offset, $limit);

        return $this->renderTemplate('search-manager/promotions/index', [
            'promotions' => $promotions,
            'indexLookup' => $indexLookup,
            'statusFilter' => $statusFilter,
            'matchTypeFilter' => $matchTypeFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createPromotions'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editPromotions'),
            'canDelete' => Craft::$app->getUser()->checkPermission('searchManager:deletePromotions'),
        ]);
    }

    /**
     * @param Promotion[] $promotions
     * @return Promotion[]
     */
    private function sortPromotions(array $promotions, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($promotions, function(Promotion $a, Promotion $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'query' => strcasecmp((string) $a->query, (string) $b->query),
                'matchType' => strcmp((string) $a->matchType, (string) $b->matchType),
                'position' => ((int) $a->position) <=> ((int) $b->position),
                // siteId is nullable — null sorts as 0, preserving the prior
                // Twig coalesce behaviour `(a.siteId ?? 0) <=> (b.siteId ?? 0)`.
                'siteId' => ((int) ($a->siteId ?? 0)) <=> ((int) ($b->siteId ?? 0)),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) ($a->title ?? ''), (string) ($b->title ?? '')),
            };

            if ($cmp === 0 && $sort !== 'title') {
                $cmp = strcasecmp((string) ($a->title ?? ''), (string) ($b->title ?? ''));
            }

            return $cmp * $multiplier;
        });

        return $promotions;
    }

    /**
     * Edit or create a promotion
     */
    public function actionEdit(?int $promotionId = null, ?Promotion $promotion = null): Response
    {
        // Require create permission for new, edit permission for existing
        if ($promotionId) {
            $this->requirePermission('searchManager:editPromotions');
        } else {
            $this->requirePermission('searchManager:createPromotions');
        }

        if (!$promotion) {
            if ($promotionId) {
                $promotion = Promotion::findById($promotionId);
                if (!$promotion) {
                    throw new NotFoundHttpException(Craft::t('search-manager', 'Promotion not found'));
                }
            } else {
                $promotion = new Promotion();
            }
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
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $promotionId = $request->getBodyParam('promotionId');

        // Require create permission for new, edit permission for existing
        if ($promotionId) {
            $this->requirePermission('searchManager:editPromotions');
        } else {
            $this->requirePermission('searchManager:createPromotions');
        }

        if ($promotionId) {
            $promotion = Promotion::findById($promotionId);
            if (!$promotion) {
                throw new NotFoundHttpException(Craft::t('search-manager', 'Promotion not found'));
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
        } elseif ($promotedElement) {
            $promotion->elementId = (int)$promotedElement;
        } else {
            $promotion->elementId = null;
        }

        $promotion->position = (int)$request->getBodyParam('position', 1);
        $promotion->siteId = $request->getBodyParam('siteId') ?: null;
        $promotion->enabled = (bool)$request->getBodyParam('enabled', true);

        if (!$promotion->validate() || !$promotion->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save promotion')
            );

            // Return with errors
            Craft::$app->getUrlManager()->setRouteParams([
                'promotion' => $promotion,
            ]);

            return null;
        }

        // Promotions can be global, so clear all search caches like query rules do.
        SearchManager::$plugin->backend->clearAllSearchCache();

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Promotion saved')
        );

        return $this->redirectToPostedUrl($promotion);
    }

    /**
     * Delete a promotion
     */
    public function actionDelete(): Response
    {
        $this->requirePermission('searchManager:deletePromotions');
        $this->requirePostRequest();

        $promotionId = Craft::$app->getRequest()->getRequiredBodyParam('promotionId');
        $promotion = Promotion::findById((int)$promotionId);

        if (!$promotion) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Promotion not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Promotion not found'));
        }

        if ($promotion->delete()) {
            SearchManager::$plugin->backend->clearAllSearchCache();

            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }

            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Promotion deleted')
            );
        } else {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Could not delete promotion')]);
            }

            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not delete promotion')
            );
        }

        return $this->redirect('search-manager/promotions');
    }

    /**
     * Duplicate a promotion.
     *
     * @since 5.53.0
     */
    public function actionDuplicate(): Response
    {
        $this->requirePermission('searchManager:createPromotions');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $promotionId = $request->getRequiredBodyParam('promotionId');
        $source = Promotion::findById((int)$promotionId);

        if (!$source) {
            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Promotion not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Promotion not found'));
        }

        $promotion = new Promotion();
        $promotion->indexHandle = $source->indexHandle;
        $promotion->title = $this->uniqueCopyLabel('{{%searchmanager_promotions}}', 'title', (string)$source->title);
        $promotion->query = $source->query;
        $promotion->matchType = $source->matchType;
        $promotion->elementId = $source->elementId;
        $promotion->elementType = $source->elementType;
        $promotion->position = $source->position;
        $promotion->siteId = $source->siteId;
        $promotion->enabled = false;

        if (!$promotion->save()) {
            $error = Craft::t('search-manager', 'Could not duplicate promotion');
            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/promotions');
        }

        SearchManager::$plugin->backend->clearAllSearchCache();

        $message = Craft::t('search-manager', 'Promotion duplicated');

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);
        return $this->redirect('search-manager/promotions');
    }

    /**
     * Bulk enable promotions
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePermission('searchManager:editPromotions');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $promotionIds = Craft::$app->getRequest()->getRequiredBodyParam('promotionIds');
        $count = 0;

        foreach ($promotionIds as $id) {
            $promotion = Promotion::findById((int)$id);
            if ($promotion) {
                $promotion->enabled = true;
                if ($promotion->save()) {
                    $count++;
                }
            }
        }

        if ($count > 0) {
            SearchManager::$plugin->backend->clearAllSearchCache();
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
        $this->requirePermission('searchManager:editPromotions');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $promotionIds = Craft::$app->getRequest()->getRequiredBodyParam('promotionIds');
        $count = 0;

        foreach ($promotionIds as $id) {
            $promotion = Promotion::findById((int)$id);
            if ($promotion) {
                $promotion->enabled = false;
                if ($promotion->save()) {
                    $count++;
                }
            }
        }

        if ($count > 0) {
            SearchManager::$plugin->backend->clearAllSearchCache();
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
        $this->requirePermission('searchManager:deletePromotions');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $promotionIds = Craft::$app->getRequest()->getRequiredBodyParam('promotionIds');
        $count = 0;

        foreach ($promotionIds as $id) {
            $promotion = Promotion::findById((int)$id);
            if ($promotion) {
                if ($promotion->delete()) {
                    $count++;
                }
            }
        }

        if ($count > 0) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    private function uniqueCopyLabel(string $table, string $column, string $label): string
    {
        $base = trim($label) !== '' ? trim($label) : Craft::t('search-manager', 'Untitled');
        $copyLabel = Craft::t('lindemannrock-base', 'Copy');
        $candidate = mb_substr($base . ' ' . $copyLabel, 0, 255);
        $suffix = 2;

        while ((new Query())->from($table)->where([$column => $candidate])->exists()) {
            $candidate = mb_substr($base . ' ' . $copyLabel . ' ' . $suffix, 0, 255);
            $suffix++;
        }

        return $candidate;
    }
}
