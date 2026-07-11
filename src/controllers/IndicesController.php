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
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use lindemannrock\searchmanager\transformers\DocsManagerTransformer;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Indices Controller
 *
 * @since 5.0.0
 */
class IndicesController extends Controller
{
    use LoggingTrait;

    private const PLUGIN_HANDLE = 'search-manager';
    private const DOCS_MANAGER_SOURCE_DOC_ELEMENT_TYPE = 'lindemannrock\\docsmanager\\elements\\SourceDoc';

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all indices.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageIndices');

        $request = Craft::$app->getRequest();
        $settings = SearchManager::$plugin->getSettings();

        $indices = SearchIndex::findAll();
        $configHandles = BaseConfigFileHelper::getHandles(self::PLUGIN_HANDLE, 'indices');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_indices}}')
            ->where(['source' => 'database'])
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $sourceFilter = (string) $request->getQueryParam('source', 'all');
        $validSources = ['all', 'config', 'database'];
        if (!in_array($sourceFilter, $validSources, true)) {
            $sourceFilter = 'all';
        }

        $backendFilter = (string) $request->getQueryParam('backend', 'all');
        $validBackends = ['all', 'mysql', 'pgsql', 'file', 'redis', 'typesense', 'algolia', 'meilisearch'];
        if (!in_array($backendFilter, $validBackends, true)) {
            $backendFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'elementType', 'source', 'enabled'];
        $sort = (string) $request->getParam('sort', 'source');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'source';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $indices = array_values(array_filter($indices, fn(SearchIndex $i): bool => $i->enabled));
        } elseif ($statusFilter === 'disabled') {
            $indices = array_values(array_filter($indices, fn(SearchIndex $i): bool => !$i->enabled));
        }

        if ($sourceFilter === 'config') {
            $indices = array_values(array_filter($indices, fn(SearchIndex $i): bool => $i->source === 'config'));
        } elseif ($sourceFilter === 'database') {
            $indices = array_values(array_filter($indices, fn(SearchIndex $i): bool => $i->source !== 'config'));
        }

        if ($backendFilter !== 'all') {
            $indices = array_values(array_filter($indices, fn(SearchIndex $i): bool => $i->getEffectiveBackendType() === $backendFilter));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $indices = array_values(array_filter($indices, function(SearchIndex $i) use ($needle): bool {
                return str_contains(mb_strtolower((string) $i->name), $needle)
                    || str_contains(mb_strtolower((string) $i->handle), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $indices = $this->sortIndices($indices, $sort, $dir);

        $totalCount = count($indices);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $indices = array_slice($indices, $offset, $limit);

        return $this->renderTemplate('search-manager/indices/index', [
            'indices' => $indices,
            'collisionHandles' => $collisionHandles,
            'statusFilter' => $statusFilter,
            'sourceFilter' => $sourceFilter,
            'backendFilter' => $backendFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createIndices'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editIndices'),
            'canDelete' => Craft::$app->getUser()->checkPermission('searchManager:deleteIndices'),
            'canRebuild' => Craft::$app->getUser()->checkPermission('searchManager:rebuildIndices'),
            'canClear' => Craft::$app->getUser()->checkPermission('searchManager:clearIndices'),
            'canClearCache' => Craft::$app->getUser()->checkPermission('searchManager:clearCache'),
            'elementTypeLabels' => $this->getElementTypeLabels(),
        ]);
    }

    /**
     * @param SearchIndex[] $indices
     * @return SearchIndex[]
     */
    private function sortIndices(array $indices, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($indices, function(SearchIndex $a, SearchIndex $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'elementType' => strcmp((string) $a->elementType, (string) $b->elementType),
                'source' => strcmp((string) ($a->source ?? ''), (string) ($b->source ?? '')),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $indices;
    }

    /**
     * View an index (read-only, for config indices)
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('searchManager:manageIndices');

        if (!$handle) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Index handle required'));
        }

        $index = SearchIndex::findByHandle($handle);

        if (!$index) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Index not found'));
        }

        // If the index is editable (database), redirect to edit page
        if ($index->canEdit()) {
            return $this->redirect('search-manager/indices/edit/' . $index->id);
        }

        return $this->renderTemplate('search-manager/indices/view', [
            'index' => $index,
            'elementTypeLabels' => $this->getElementTypeLabels(),
        ]);
    }

    /**
     * Edit or create an index
     */
    public function actionEdit(?int $indexId = null, ?SearchIndex $index = null): Response
    {
        // Require create permission for new, edit permission for existing
        if ($indexId) {
            $this->requirePermission('searchManager:editIndices');
        } else {
            $this->requirePermission('searchManager:createIndices');
        }

        if (!$index) {
            if ($indexId) {
                $index = SearchIndex::findById($indexId);
                if (!$index) {
                    throw new NotFoundHttpException(Craft::t('search-manager', 'Index not found'));
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
        }

        return $this->renderTemplate('search-manager/indices/edit', [
            'index' => $index,
            'isNew' => !$indexId,
            'elementTypeOptions' => $this->getElementTypeOptions(),
            'docsManagerTransformerAvailable' => $this->isDocsManagerTransformerAvailable(),
            'defaultTransformerPlaceholder' => $this->getDefaultTransformerPlaceholder(),
            'transformerPlaceholders' => $this->getTransformerPlaceholders(),
        ]);
    }

    /**
     * Element type options for editable database-backed indices.
     *
     * @return array<string, string>
     */
    private function getElementTypeOptions(): array
    {
        $options = [
            \craft\elements\Entry::class => Craft::t('search-manager', 'Entries'),
            \craft\elements\Asset::class => Craft::t('search-manager', 'Assets'),
            \craft\elements\Category::class => Craft::t('search-manager', 'Categories'),
            \craft\elements\User::class => Craft::t('search-manager', 'Users'),
        ];

        $options = array_merge($options, $this->getTranslatedCommerceElementTypeLabels());

        if (PluginHelper::isPluginEnabled('smartlink-manager')) {
            $options['lindemannrock\\smartlinkmanager\\elements\\SmartLink'] = PluginHelper::getPluginName('smartlink-manager', 'SmartLink Manager');
        }

        if (PluginHelper::isPluginEnabled('shortlink-manager')) {
            $options['lindemannrock\\shortlinkmanager\\elements\\ShortLink'] = PluginHelper::getPluginName('shortlink-manager', 'ShortLink Manager');
        }

        if (PluginHelper::isPluginEnabled('docs-manager')) {
            $options['lindemannrock\\docsmanager\\elements\\SourceDoc'] = PluginHelper::getPluginName('docs-manager', 'Docs Manager');
        }

        return $options;
    }

    /**
     * Human labels for index element type displays.
     *
     * @return array<string, string>
     */
    private function getElementTypeLabels(): array
    {
        $labels = [
            \craft\elements\Entry::class => Craft::t('search-manager', 'Entry'),
            \craft\elements\Asset::class => Craft::t('search-manager', 'Asset'),
            \craft\elements\Category::class => Craft::t('search-manager', 'Category'),
            \craft\elements\User::class => Craft::t('search-manager', 'User'),
        ];

        $labels = array_merge($labels, $this->getTranslatedCommerceElementTypeLabels());

        return $labels;
    }

    private function isDocsManagerTransformerAvailable(): bool
    {
        return PluginHelper::isPluginEnabled('docs-manager');
    }

    private function getDefaultTransformerPlaceholder(): string
    {
        return AutoTransformer::class;
    }

    /**
     * @return array<string, string>
     */
    private function getTransformerPlaceholders(): array
    {
        $placeholders = [];

        if ($this->isDocsManagerTransformerAvailable()) {
            $placeholders[self::DOCS_MANAGER_SOURCE_DOC_ELEMENT_TYPE] = DocsManagerTransformer::class;
        }

        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $placeholders[$elementType] = CommerceTransformer::class;
        }

        return $placeholders;
    }

    /**
     * @return array<string, string>
     */
    private function getTranslatedCommerceElementTypeLabels(): array
    {
        $labelKeys = [
            'Product' => 'Commerce Product',
            'Variant' => 'Commerce Variant',
        ];

        $labels = [];
        foreach (CommerceElementTypeHelper::availableElementTypeLabels() as $elementType => $label) {
            $labels[$elementType] = Craft::t('search-manager', $labelKeys[$label] ?? $label);
        }

        return $labels;
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
                throw new NotFoundHttpException(Craft::t('search-manager', 'Index not found'));
            }
        } else {
            $index = new SearchIndex();
        }

        // Set attributes
        $index->name = $request->getBodyParam('name');
        $index->handle = SlugHandleHelper::normalizeSlug(
            (string)$request->getBodyParam('handle'),
            (string)$index->name,
        );
        if (!$indexId && $index->handle !== '') {
            $index->handle = SlugHandleHelper::makeUnique('{{%searchmanager_indices}}', 'handle', $index->handle);
        }
        $index->elementType = $request->getBodyParam('elementType');
        $siteIdParam = $request->getBodyParam('siteId');
        if (is_array($siteIdParam)) {
            $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIdParam), fn($id) => $id > 0)));
            $index->siteId = $siteIds ?: null;
        } else {
            $index->siteId = $siteIdParam ?: null;
        }
        $index->transformerClass = $request->getBodyParam('transformerClass');
        $headingLevelsParam = $request->getBodyParam('headingLevels');
        if (is_array($headingLevelsParam)) {
            $levels = array_values(array_unique(array_filter(array_map('intval', $headingLevelsParam), fn($level) => $level >= 1 && $level <= 6)));
            $index->headingLevels = $levels ?: null;
        } else {
            $index->headingLevels = null;
        }
        $index->language = $request->getBodyParam('language') ?: null;
        $index->backend = $request->getBodyParam('backend') ?: null;
        $index->enabled = (bool)$request->getBodyParam('enabled');
        $index->enableAnalytics = (bool)$request->getBodyParam('enableAnalytics', true);
        $index->disableStopWords = (bool)$request->getBodyParam('disableStopWords', false);
        $index->skipEntriesWithoutUrl = (bool)$request->getBodyParam('skipEntriesWithoutUrl', false);
        $index->splitSections = (bool)$request->getBodyParam('splitSections', false);
        $index->retrievableFields = SearchIndex::normalizeRetrievableFields($request->getBodyParam('retrievableFields', '*'));
        $criteria = $request->getBodyParam('criteria', []);
        $validCriteriaKeys = ['sections', 'volumes', 'groups', 'sourceHandles'];
        $index->criteria = array_intersect_key(
            is_array($criteria) ? $criteria : [],
            array_flip($validCriteriaKeys),
        );

        if (!$index->validate() || !$index->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save index')
            );

            Craft::$app->getUrlManager()->setRouteParams([
                'index' => $index,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Index saved')
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

        $request = Craft::$app->getRequest();
        $acceptsJson = $request->getAcceptsJson();
        $indexId = $request->getRequiredBodyParam('indexId');

        $index = SearchIndex::findByIdOrHandle($indexId);

        if (!$index) {
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Index not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Index not found'));
        }

        if (!$index->canEdit()) {
            $error = Craft::t('search-manager', 'This index is defined in config and cannot be deleted.');
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/indices');
        }

        if ($index->delete()) {
            $message = Craft::t('search-manager', 'Index deleted');
            if ($acceptsJson) {
                return $this->asJson(['success' => true, 'message' => $message]);
            }
            Craft::$app->getSession()->setNotice($message);
        } else {
            $error = Craft::t('search-manager', 'Could not delete index');
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
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

        $request = Craft::$app->getRequest();
        $acceptsJson = $request->getAcceptsJson();
        $indexId = $request->getRequiredBodyParam('indexId');

        $index = SearchIndex::findByIdOrHandle($indexId);

        if (!$index) {
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Index not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Index not found'));
        }

        // Clear backend storage
        SearchManager::$plugin->backend->clearIndex($index->handle);

        // Update stats to 0
        $index->updateStats(0);

        // Clear caches
        SearchManager::$plugin->backend->clearSearchCache($index->handle);
        SearchManager::$plugin->autocomplete->clearCache($index->handle);

        $message = Craft::t('search-manager', 'Index data cleared');
        if ($acceptsJson) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }
        Craft::$app->getSession()->setNotice($message);

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

        $index = SearchIndex::findByIdOrHandle($indexId);

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Index not found'),
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
                'message' => Craft::t('search-manager', 'Cache cleared for "{name}"', [
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
                'error' => Craft::t('search-manager', 'Failed to clear cache'),
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

        $index = SearchIndex::findByIdOrHandle($indexId);

        if (!$index) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Index not found'),
            ]);
        }

        try {
            // Get the backend for this index
            $backend = SearchManager::$plugin->backend->getBackendForIndex($index->handle);

            if (!$backend) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'No backend configured for this index'),
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
                    'error' => Craft::t('search-manager', 'Index "{name}" not found on backend', [
                        'name' => $fullIndexName,
                    ]),
                ]);
            }

            // Check if count is available (stats may fail due to permissions)
            if ($entriesAvailable === false) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'Could not retrieve document count from backend (permission issue)'),
                ]);
            }

            // Update the local document count
            if (!$index->updateStats($backendCount)) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'Failed to update index stats'),
                ]);
            }

            $this->logInfo('Synced document count from backend', [
                'index' => $index->handle,
                'count' => $backendCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'Count synced for "{name}": {count} documents', [
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

        $request = Craft::$app->getRequest();
        $acceptsJson = $request->getAcceptsJson();
        $indexId = $request->getRequiredBodyParam('indexId');

        $this->logDebug('Rebuilding index', [
            'indexId' => $indexId,
            'type' => gettype($indexId),
            'isNumeric' => is_numeric($indexId),
        ]);

        $index = SearchIndex::findByIdOrHandle($indexId);

        if (!$index) {
            $this->logError('Index not found', [
                'indexId' => $indexId,
                'type' => gettype($indexId),
            ]);
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Index not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Index not found'));
        }

        SearchManager::$plugin->indexing->rebuildIndex($index->handle);

        $message = Craft::t('search-manager', 'Index rebuild queued');
        if ($acceptsJson) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }
        Craft::$app->getSession()->setNotice($message);

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
            $index = SearchIndex::findByIdOrHandle($id);

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
            $index = SearchIndex::findByIdOrHandle($id);

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
            $index = SearchIndex::findByIdOrHandle($id);

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
