<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\TargetElementTypeHelper;
use lindemannrock\searchmanager\models\BulkMutationResult;
use lindemannrock\searchmanager\models\Promotion;
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

    /** @inheritdoc */
    public function beforeAction($action): bool
    {
        if (SearchManager::$plugin->requireEditionOrPrompt(SearchManager::EDITION_PRO, 'Promotions') !== null) {
            return false;
        }

        return parent::beforeAction($action);
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
        $indexReferences = SearchManager::$plugin->dependencies->resolveIndexReferences(
            array_map(static fn(Promotion $promotion): ?string => $promotion->indexHandle, $promotions),
        );
        $effectiveStatuses = [];
        foreach ($promotions as $promotion) {
            $effectiveStatuses[(int)$promotion->id] = SearchManager::$plugin->dependencies->resolveEffectiveStatus(
                (bool)$promotion->enabled,
                $indexReferences[trim((string)$promotion->indexHandle)],
            );
        }

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'error', 'enabled', 'disabled'];
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

        if ($statusFilter !== 'all') {
            $promotions = array_values(array_filter(
                $promotions,
                static fn(Promotion $promotion): bool => ($effectiveStatuses[(int)$promotion->id]['value'] ?? null) === $statusFilter,
            ));
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

        $promotions = $this->sortPromotions($promotions, $sort, $dir, $effectiveStatuses);

        $totalCount = count($promotions);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $promotions = array_slice($promotions, $offset, $limit);
        $promotionElements = $this->preloadPromotionElements($promotions);

        return $this->renderTemplate('search-manager/promotions/index', [
            'promotions' => $promotions,
            'promotionElements' => $promotionElements,
            'indexReferences' => $indexReferences,
            'effectiveStatuses' => $effectiveStatuses,
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
    private function sortPromotions(array $promotions, string $sort, string $dir, array $effectiveStatuses): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($promotions, function(Promotion $a, Promotion $b) use ($sort, $multiplier, $effectiveStatuses): int {
            $cmp = match ($sort) {
                'query' => strcasecmp((string) $a->query, (string) $b->query),
                'matchType' => strcmp((string) $a->matchType, (string) $b->matchType),
                'position' => ((int) $a->position) <=> ((int) $b->position),
                // siteId is nullable — null sorts as 0, preserving the prior
                // Twig coalesce behaviour `(a.siteId ?? 0) <=> (b.siteId ?? 0)`.
                'siteId' => ((int) ($a->siteId ?? 0)) <=> ((int) ($b->siteId ?? 0)),
                'enabled' => SearchManager::$plugin->dependencies->compareEffectiveStatuses(
                    $effectiveStatuses[(int)$a->id],
                    $effectiveStatuses[(int)$b->id],
                ),
                default => strcasecmp((string) ($a->title ?? ''), (string) ($b->title ?? '')),
            };

            if ($cmp === 0 && $sort !== 'title') {
                $cmp = strcasecmp((string) ($a->title ?? ''), (string) ($b->title ?? ''));
            }
            if ($cmp === 0) {
                $cmp = ((int)$a->id) <=> ((int)$b->id);
            }

            return $cmp * $multiplier;
        });

        return $promotions;
    }

    /**
     * Resolve listing targets using the element type stored with each promotion.
     *
     * @param Promotion[] $promotions
     * @return array<int, ElementInterface>
     */
    private function preloadPromotionElements(array $promotions): array
    {
        $groups = [];
        foreach ($promotions as $promotion) {
            if ($promotion->id === null || $promotion->elementId === null) {
                continue;
            }

            $elementType = TargetElementTypeHelper::isSupportedElementType($promotion->elementType)
                ? $promotion->elementType
                : null;
            if ($elementType === null) {
                continue;
            }

            $siteKey = $promotion->siteId === null ? 'all' : (string)$promotion->siteId;
            $groups[$elementType][$siteKey]['siteId'] = $promotion->siteId;
            $groups[$elementType][$siteKey]['promotionIds'][$promotion->elementId][] = $promotion->id;
        }

        $elements = [];
        foreach ($groups as $elementType => $siteGroups) {
            foreach ($siteGroups as $group) {
                $query = $elementType::find()
                    ->id(array_keys($group['promotionIds']))
                    ->status(null);
                if ($group['siteId'] !== null) {
                    $query->siteId($group['siteId']);
                }

                foreach ($query->all() as $element) {
                    if (!$element instanceof ElementInterface || $element->id === null) {
                        continue;
                    }

                    foreach ($group['promotionIds'][$element->id] ?? [] as $promotionId) {
                        $elements[$promotionId] = $element;
                    }
                }
            }
        }

        return $elements;
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

        $indexOptions = SearchManager::$plugin->dependencies->getIndexOptions($promotion->indexHandle);
        $indexReferences = SearchManager::$plugin->dependencies->resolveIndexReferences([$promotion->indexHandle]);
        $indexReference = $indexReferences[trim((string)$promotion->indexHandle)];
        $effectiveStatus = SearchManager::$plugin->dependencies->resolveEffectiveStatus(
            (bool)$promotion->enabled,
            $indexReference,
        );

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
            'indexReference' => $indexReference,
            'effectiveStatus' => $effectiveStatus,
            'siteOptions' => $siteOptions,
            'matchTypeOptions' => $matchTypeOptions,
            'targetTypeOptions' => TargetElementTypeHelper::options(),
            'selectedTargetType' => TargetElementTypeHelper::keyForElementType($this->resolveTargetElementType($promotion->elementId, $promotion->elementType, $promotion->siteId)),
            'selectedTargetElements' => $this->selectedTargetElements($promotion->elementId, $promotion->elementType, $promotion->siteId),
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

        $targetType = (string)$request->getBodyParam('promotedElementType', 'entry');
        $promotion->elementType = TargetElementTypeHelper::elementTypeForKey($targetType);

        // Handle element select field (comes as array)
        $promotedElement = $request->getBodyParam('promotedElement' . ucfirst($targetType));
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

        if (!$promotion->validate() || !SearchManager::$plugin->promotions->save($promotion)) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save promotion')
            );

            // Return with errors
            Craft::$app->getUrlManager()->setRouteParams([
                'promotion' => $promotion,
            ]);

            return null;
        }

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

        if (SearchManager::$plugin->promotions->delete($promotion)) {
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

        if (!SearchManager::$plugin->promotions->save($promotion)) {
            $error = Craft::t('search-manager', 'Could not duplicate promotion');
            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/promotions');
        }

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

        return $this->bulkSetEnabled(true);
    }

    /**
     * Bulk disable promotions
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePermission('searchManager:editPromotions');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->bulkSetEnabled(false);
    }

    /**
     * Bulk delete promotions
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePermission('searchManager:deletePromotions');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $result = BulkMutationResult::fromIdentifiers(
            Craft::$app->getRequest()->getBodyParam('promotionIds', []),
        );
        if (!$result->canMutate()) {
            return $this->asJson($result->toArray());
        }

        foreach ($result->identifiers() as $id) {
            $promotion = Promotion::findById($id);
            if ($promotion === null) {
                $result->addSkip();
                continue;
            }
            $promotionName = $promotion->title ?: (string) $id;

            try {
                if (SearchManager::$plugin->promotions->delete($promotion)) {
                    $result->addSuccess();
                } else {
                    $result->addModelErrors(
                        $promotionName,
                        $promotion,
                        Craft::t('search-manager', 'Could not delete promotion'),
                    );
                }
            } catch (\Throwable $e) {
                $result->addNamedError($promotionName, Craft::t('search-manager', 'Could not delete promotion'));
                $this->logError('Bulk promotion deletion failed', [
                    'promotionId' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->asJson($result->toArray());
    }

    private function bulkSetEnabled(bool $enabled): Response
    {
        $result = BulkMutationResult::fromIdentifiers(
            Craft::$app->getRequest()->getBodyParam('promotionIds', []),
        );
        if (!$result->canMutate()) {
            return $this->asJson($result->toArray());
        }

        foreach ($result->identifiers() as $id) {
            $promotion = Promotion::findById($id);
            if ($promotion === null) {
                $result->addSkip();
                continue;
            }
            if ($promotion->enabled === $enabled) {
                $result->addSkip();
                continue;
            }
            $promotionName = $promotion->title ?: (string) $id;

            try {
                $promotion->enabled = $enabled;
                if (SearchManager::$plugin->promotions->save($promotion)) {
                    $result->addSuccess();
                } else {
                    $result->addModelErrors(
                        $promotionName,
                        $promotion,
                        Craft::t('search-manager', 'Could not save promotion'),
                    );
                }
            } catch (\Throwable $e) {
                $result->addNamedError($promotionName, Craft::t('search-manager', 'Could not save promotion'));
                $this->logError('Bulk promotion status update failed', [
                    'promotionId' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->asJson($result->toArray());
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

    /**
     * @return array<string, array<int, ElementInterface>>
     */
    private function selectedTargetElements(?int $elementId, ?string $elementType, ?int $siteId): array
    {
        $elements = [];
        if ($elementId === null) {
            return $elements;
        }

        $queryElementType = TargetElementTypeHelper::isSupportedElementType($elementType) ? $elementType : null;
        $element = Craft::$app->getElements()->getElementById($elementId, $queryElementType, $siteId);
        if ($element instanceof ElementInterface) {
            $elements[TargetElementTypeHelper::keyForElementType(get_class($element))] = [$element];
        }

        return $elements;
    }

    private function resolveTargetElementType(?int $elementId, ?string $elementType, ?int $siteId): ?string
    {
        if (TargetElementTypeHelper::isSupportedElementType($elementType)) {
            return $elementType;
        }

        if ($elementId === null) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        return $element instanceof ElementInterface ? get_class($element) : null;
    }
}
