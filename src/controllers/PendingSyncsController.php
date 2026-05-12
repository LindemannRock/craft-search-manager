<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Pending Syncs Controller
 *
 * Operator surface for the L3 pending-sync buffer. View-only by default —
 * destructive actions require explicit nested permissions.
 *
 * @since 5.45.0
 */
class PendingSyncsController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Failure-triage view. By default only surfaces rows that need an
     * operator's attention — `failed` and `abandoned`. Pending and processing
     * rows are normal mid-flight state for `BatchSyncJob` and don't belong on
     * a triage page; the operator can opt into them with `?statusGroup=all`
     * or an explicit `?status=...`.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageSyncFailures');

        $request = Craft::$app->getRequest();
        $statusGroup = (string) $request->getParam('statusGroup', 'failures');
        $explicitStatus = $request->getParam('status');

        $statusFilter = null;
        if ($explicitStatus !== null && $explicitStatus !== '' && $explicitStatus !== 'all') {
            $statusFilter = (string) $explicitStatus;
        } elseif ($statusGroup === 'failures') {
            $statusFilter = [PendingSyncRepository::STATUS_FAILED, PendingSyncRepository::STATUS_ABANDONED];
        }

        $filters = array_filter([
            'status' => $statusFilter,
            'indexHandle' => $request->getParam('indexHandle'),
            'op' => $request->getParam('op'),
            'siteId' => $request->getParam('siteId') !== null ? (int) $request->getParam('siteId') : null,
            'search' => $request->getParam('search'),
            'stuck' => $request->getParam('stuck') === '1' ? true : null,
        ], static fn($v): bool => $v !== null && $v !== '' && $v !== 'all' && $v !== []);

        $sort = (string) $request->getParam('sort', 'queuedAt');
        $dir = (string) $request->getParam('dir', 'asc');
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $repository = SearchManager::$plugin->pendingSyncs;
        $result = $repository->search($filters, $sort, $dir, $limit, $offset);
        $stats = $repository->getStats();
        ['elements' => $elements, 'existsAnywhere' => $existsAnywhere] = $this->preloadElements($result['rows']);

        return $this->renderTemplate('search-manager/pending-syncs/index', [
            'rows' => $result['rows'],
            'elements' => $elements,
            'existsAnywhere' => $existsAnywhere,
            'totalCount' => $result['total'],
            'stats' => $stats,
            'filters' => $filters,
            'statusGroup' => $statusGroup,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'staleCutoffSeconds' => $repository->getStaleCutoffSeconds(),
            'indices' => SearchIndex::findAll(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Resolve elements for the current page in two passes (one query per type
     * per site, plus one cross-site existence probe per type). Returns:
     *
     *   - `elements` — map keyed `"{elementId}:{siteId}"` for rows where the
     *     element is propagated to the row's site. Template uses this to link
     *     to the CP edit page.
     *   - `existsAnywhere` — set of element IDs that exist on *some* site.
     *     Template uses this to distinguish "not propagated to this site"
     *     (routine — the processor will flip the row to delete) from "truly
     *     deleted" (slightly more notable but still routine).
     *
     * The L3 design queues a row per (index, site) regardless of where the
     * element is actually propagated, so `existsAnywhere=true` for a missing
     * `(elementId, siteId)` pair is the most common case.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{
     *     elements: array<string, \craft\base\ElementInterface>,
     *     existsAnywhere: array<int, true>,
     * }
     */
    private function preloadElements(array $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $type = (string) $row['elementType'];
            $byType[$type][(int) $row['siteId']][] = (int) $row['elementId'];
        }

        $resolved = [];
        $existsAnywhere = [];

        foreach ($byType as $type => $bySite) {
            if (!class_exists($type)) {
                continue;
            }

            /** @var class-string<\craft\base\ElementInterface> $type */
            $allIdsForType = [];
            foreach ($bySite as $siteId => $ids) {
                $allIdsForType = array_merge($allIdsForType, $ids);

                $query = $type::find()
                    ->id(array_values(array_unique($ids)))
                    ->siteId($siteId)
                    ->status(null)
                    ->drafts(null)
                    ->revisions(false);

                foreach ($query->all() as $element) {
                    $resolved[$element->id . ':' . $siteId] = $element;
                }
            }

            // Cross-site existence probe for rows that did not resolve on
            // their queued site — answers "does this element exist on SOME
            // site?" in one query, regardless of which site.
            $uniqueIds = array_values(array_unique($allIdsForType));
            $existingIds = $type::find()
                ->id($uniqueIds)
                ->siteId('*')
                ->status(null)
                ->drafts(null)
                ->revisions(false)
                ->ids();

            foreach ($existingIds as $id) {
                $existsAnywhere[(int) $id] = true;
            }
        }

        return ['elements' => $resolved, 'existsAnywhere' => $existsAnywhere];
    }

    /**
     * Retry one or more rows: reset to pending and force re-claim on the
     * next BatchSyncJob run. Skips rows currently being processed by a
     * non-stale worker.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:retrySyncFailures');

        $ids = $this->idsParam();
        $updated = SearchManager::$plugin->pendingSyncs->retry($ids);

        $this->logInfo('Pending syncs retried from CP', [
            'requested' => count($ids),
            'updated' => $updated,
            'user' => Craft::$app->getUser()->getId(),
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', '{count} pending sync(s) queued for retry.', [
            'count' => $updated,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Hard-delete rows from the buffer by id.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:purgeSyncFailures');

        $ids = $this->idsParam();
        $deleted = SearchManager::$plugin->pendingSyncs->deleteByIds($ids);

        $this->logInfo('Pending syncs deleted from CP', [
            'requested' => count($ids),
            'deleted' => $deleted,
            'user' => Craft::$app->getUser()->getId(),
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', '{count} pending sync(s) deleted.', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Delete every row at `status = abandoned`.
     */
    public function actionPurgeAbandoned(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:purgeSyncFailures');

        $deleted = SearchManager::$plugin->pendingSyncs->purgeByStatus(PendingSyncRepository::STATUS_ABANDONED);

        $this->logInfo('Abandoned pending syncs purged from CP', [
            'deleted' => $deleted,
            'user' => Craft::$app->getUser()->getId(),
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', '{count} abandoned pending sync(s) purged.', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return int[]
     */
    private function idsParam(): array
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getBodyParam('ids', []);

        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), static fn($v): bool => $v !== '');
        }

        if (!is_array($raw)) {
            throw new BadRequestHttpException('ids must be an array or comma-delimited string');
        }

        $ids = array_values(array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0));

        if (empty($ids)) {
            throw new BadRequestHttpException('At least one valid id is required.');
        }

        return $ids;
    }
}
