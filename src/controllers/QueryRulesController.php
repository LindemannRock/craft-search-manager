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
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Query Rules Controller
 *
 * Manages query rules (synonyms, boosts, redirects) in the CP
 *
 * @since 5.10.0
 */
class QueryRulesController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all query rules.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageQueryRules');

        $request = Craft::$app->getRequest();
        $settings = SearchManager::$plugin->getSettings();

        $rules = QueryRule::findAll();
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
        $validMatchTypes = ['all', 'exact', 'contains', 'prefix', 'regex'];
        if (!in_array($matchTypeFilter, $validMatchTypes, true)) {
            $matchTypeFilter = 'all';
        }

        $actionTypeFilter = (string) $request->getQueryParam('actionType', 'all');
        $validActionTypes = ['all', 'synonym', 'boost_section', 'boost_category', 'boost_element', 'redirect'];
        if (!in_array($actionTypeFilter, $validActionTypes, true)) {
            $actionTypeFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'matchValue', 'matchType', 'actionType', 'priority', 'siteId', 'enabled'];
        $sort = (string) $request->getParam('sort', 'priority');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'priority';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $rules = array_values(array_filter($rules, fn(QueryRule $r): bool => $r->enabled));
        } elseif ($statusFilter === 'disabled') {
            $rules = array_values(array_filter($rules, fn(QueryRule $r): bool => !$r->enabled));
        }

        if ($matchTypeFilter !== 'all') {
            $rules = array_values(array_filter($rules, fn(QueryRule $r): bool => $r->matchType === $matchTypeFilter));
        }

        if ($actionTypeFilter !== 'all') {
            $rules = array_values(array_filter($rules, fn(QueryRule $r): bool => $r->actionType === $actionTypeFilter));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rules = array_values(array_filter($rules, function(QueryRule $r) use ($needle): bool {
                return str_contains(mb_strtolower((string) $r->name), $needle)
                    || str_contains(mb_strtolower((string) $r->matchValue), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $rules = $this->sortRules($rules, $sort, $dir);

        $totalCount = count($rules);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $rules = array_slice($rules, $offset, $limit);

        return $this->renderTemplate('search-manager/query-rules/index', [
            'rules' => $rules,
            'indexLookup' => $indexLookup,
            'statusFilter' => $statusFilter,
            'matchTypeFilter' => $matchTypeFilter,
            'actionTypeFilter' => $actionTypeFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createQueryRules'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editQueryRules'),
            'canDelete' => Craft::$app->getUser()->checkPermission('searchManager:deleteQueryRules'),
        ]);
    }

    /**
     * @param QueryRule[] $rules
     * @return QueryRule[]
     */
    private function sortRules(array $rules, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($rules, function(QueryRule $a, QueryRule $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'matchValue' => strcasecmp((string) $a->matchValue, (string) $b->matchValue),
                'matchType' => strcmp((string) $a->matchType, (string) $b->matchType),
                'actionType' => strcmp((string) $a->actionType, (string) $b->actionType),
                'priority' => ((int) $a->priority) <=> ((int) $b->priority),
                'siteId' => ((int) ($a->siteId ?? 0)) <=> ((int) ($b->siteId ?? 0)),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $rules;
    }

    /**
     * Edit or create a query rule
     */
    public function actionEdit(?int $ruleId = null, ?QueryRule $rule = null): Response
    {
        // Require create permission for new, edit permission for existing
        if ($ruleId) {
            $this->requirePermission('searchManager:editQueryRules');
        } else {
            $this->requirePermission('searchManager:createQueryRules');
        }

        if (!$rule) {
            if ($ruleId) {
                $rule = QueryRule::findById($ruleId);
                if (!$rule) {
                    throw new NotFoundHttpException(Craft::t('search-manager', 'Query rule not found'));
                }
            } else {
                $rule = new QueryRule();
            }
        }

        // Get indices for dropdown
        $indices = SearchIndex::findAll();
        $indexOptions = [
            ['label' => Craft::t('search-manager', 'All Indices'), 'value' => ''],
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
        $matchTypeOptions = [];
        foreach (QueryRule::getMatchTypes() as $value => $label) {
            $matchTypeOptions[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        // Action type options
        $actionTypeOptions = [];
        foreach (QueryRule::getActionTypes() as $value => $label) {
            $actionTypeOptions[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        // Section options (for boost_section)
        $sectionOptions = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sectionOptions[] = [
                'label' => $section->name,
                'value' => $section->handle,
            ];
        }

        // Category group options (for boost_category)
        $categoryGroupOptions = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $categoryGroupOptions[] = [
                'label' => $group->name,
                'value' => $group->handle,
            ];
        }

        return $this->renderTemplate('search-manager/query-rules/edit', [
            'rule' => $rule,
            'isNew' => !$ruleId,
            'indexOptions' => $indexOptions,
            'siteOptions' => $siteOptions,
            'matchTypeOptions' => $matchTypeOptions,
            'actionTypeOptions' => $actionTypeOptions,
            'sectionOptions' => $sectionOptions,
            'categoryGroupOptions' => $categoryGroupOptions,
            'targetTypeOptions' => TargetElementTypeHelper::options(),
            'boostElementTargetType' => TargetElementTypeHelper::keyForElementType($this->resolveTargetElementType($rule->actionValue['elementId'] ?? null, $rule->actionValue['elementType'] ?? null, $rule->siteId)),
            'redirectTargetType' => TargetElementTypeHelper::keyForElementType($this->resolveTargetElementType($rule->actionValue['elementId'] ?? null, $rule->actionValue['elementType'] ?? null, $rule->siteId)),
            'boostTargetElements' => $this->selectedTargetElements($rule->actionValue['elementId'] ?? null, $rule->actionValue['elementType'] ?? null, $rule->siteId),
            'redirectTargetElements' => $this->selectedTargetElements($rule->actionValue['elementId'] ?? null, $rule->actionValue['elementType'] ?? null, $rule->siteId),
        ]);
    }

    /**
     * Save a query rule
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $ruleId = $request->getBodyParam('ruleId');

        // Require create permission for new, edit permission for existing
        if ($ruleId) {
            $this->requirePermission('searchManager:editQueryRules');
        } else {
            $this->requirePermission('searchManager:createQueryRules');
        }

        if ($ruleId) {
            $rule = QueryRule::findById($ruleId);
            if (!$rule) {
                throw new NotFoundHttpException(Craft::t('search-manager', 'Query rule not found'));
            }
        } else {
            $rule = new QueryRule();
        }

        // Set attributes
        $rule->name = $request->getBodyParam('name');
        $rule->indexHandle = $request->getBodyParam('indexHandle') ?: null;
        $rule->matchType = $request->getBodyParam('matchType', 'exact');
        $rule->matchValue = $request->getBodyParam('matchValue');
        $rule->actionType = $request->getBodyParam('actionType');
        $rule->priority = (int)$request->getBodyParam('priority', 0);
        $rule->siteId = $request->getBodyParam('siteId') ?: null;
        $rule->enabled = (bool)$request->getBodyParam('enabled', true);

        // Build actionValue based on actionType
        $actionType = $rule->actionType;
        $actionValue = [];

        switch ($actionType) {
            case QueryRule::ACTION_SYNONYM:
                $synonyms = $request->getBodyParam('synonyms', '');
                // Parse comma-separated synonyms
                $terms = array_map('trim', explode(',', $synonyms));
                $terms = array_filter($terms);
                $actionValue = ['terms' => array_values($terms)];
                break;

            case QueryRule::ACTION_BOOST_SECTION:
                $actionValue = [
                    'sectionHandle' => $request->getBodyParam('boostSectionHandle'),
                    'multiplier' => (float)$request->getBodyParam('boostSectionMultiplier', 2.0),
                ];
                break;

            case QueryRule::ACTION_BOOST_CATEGORY:
                // Element selector returns an array of IDs
                $boostCategory = $request->getBodyParam('boostCategory');
                $categoryId = is_array($boostCategory) && !empty($boostCategory) ? (int)$boostCategory[0] : 0;
                $actionValue = [
                    'categoryId' => $categoryId,
                    'multiplier' => (float)$request->getBodyParam('boostCategoryMultiplier', 2.0),
                ];
                break;

            case QueryRule::ACTION_BOOST_ELEMENT:
                // Element selector returns an array of IDs
                $boostElementType = (string)$request->getBodyParam('boostElementType', 'entry');
                $boostElement = $request->getBodyParam('boostElement' . ucfirst($boostElementType));
                $elementId = is_array($boostElement) && !empty($boostElement) ? (int)$boostElement[0] : 0;
                $actionValue = [
                    'elementId' => $elementId,
                    'elementType' => TargetElementTypeHelper::elementTypeForKey($boostElementType),
                    'multiplier' => (float)$request->getBodyParam('boostElementMultiplier', 2.0),
                ];
                break;

            case QueryRule::ACTION_REDIRECT:
                $redirectType = $request->getBodyParam('redirectType', 'url');

                if ($redirectType === 'url') {
                    $actionValue = [
                        'url' => $request->getBodyParam('redirectUrl'),
                    ];
                } else {
                    // Element-based redirect
                    $elementIds = $request->getBodyParam('redirectElement' . ucfirst($redirectType), []);
                    $elementId = is_array($elementIds) ? ($elementIds[0] ?? null) : $elementIds;

                    $actionValue = [
                        'elementId' => $elementId ? (int)$elementId : null,
                        'elementType' => TargetElementTypeHelper::elementTypeForKey($redirectType),
                    ];
                }
                break;
        }

        $rule->actionValue = $actionValue;

        if (!$rule->validate() || !SearchManager::$plugin->queryRules->save($rule)) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save query rule')
            );

            // Return with errors
            Craft::$app->getUrlManager()->setRouteParams([
                'rule' => $rule,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Query rule saved')
        );

        return $this->redirectToPostedUrl($rule);
    }

    /**
     * Delete a query rule
     */
    public function actionDelete(): Response
    {
        $this->requirePermission('searchManager:deleteQueryRules');
        $this->requirePostRequest();

        $ruleId = Craft::$app->getRequest()->getRequiredBodyParam('ruleId');
        $rule = QueryRule::findById((int)$ruleId);

        if (!$rule) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Query rule not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Query rule not found'));
        }

        if (SearchManager::$plugin->queryRules->delete($rule)) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }

            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Query rule deleted')
            );
        } else {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Could not delete query rule')]);
            }

            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not delete query rule')
            );
        }

        return $this->redirect('search-manager/query-rules');
    }

    /**
     * Duplicate a query rule.
     *
     * @since 5.53.0
     */
    public function actionDuplicate(): Response
    {
        $this->requirePermission('searchManager:createQueryRules');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $ruleId = $request->getRequiredBodyParam('ruleId');
        $source = QueryRule::findById((int)$ruleId);

        if (!$source) {
            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Query rule not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Query rule not found'));
        }

        $rule = new QueryRule();
        $rule->name = $this->uniqueCopyLabel('{{%searchmanager_query_rules}}', 'name', $source->name);
        $rule->indexHandle = $source->indexHandle;
        $rule->matchType = $source->matchType;
        $rule->matchValue = $source->matchValue;
        $rule->actionType = $source->actionType;
        $rule->actionValue = $source->actionValue;
        $rule->priority = $source->priority;
        $rule->siteId = $source->siteId;
        $rule->enabled = false;

        if (!SearchManager::$plugin->queryRules->save($rule)) {
            $error = Craft::t('search-manager', 'Could not duplicate query rule');
            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/query-rules');
        }

        $message = Craft::t('search-manager', 'Query rule duplicated');

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);
        return $this->redirect('search-manager/query-rules');
    }

    /**
     * Bulk enable query rules
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePermission('searchManager:editQueryRules');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ruleIds = Craft::$app->getRequest()->getRequiredBodyParam('ruleIds');
        $count = 0;

        foreach ($ruleIds as $id) {
            $rule = QueryRule::findById((int)$id);
            if ($rule) {
                $rule->enabled = true;
                if (SearchManager::$plugin->queryRules->save($rule)) {
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
     * Bulk disable query rules
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePermission('searchManager:editQueryRules');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ruleIds = Craft::$app->getRequest()->getRequiredBodyParam('ruleIds');
        $count = 0;

        foreach ($ruleIds as $id) {
            $rule = QueryRule::findById((int)$id);
            if ($rule) {
                $rule->enabled = false;
                if (SearchManager::$plugin->queryRules->save($rule)) {
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
     * Bulk delete query rules
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePermission('searchManager:deleteQueryRules');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ruleIds = Craft::$app->getRequest()->getRequiredBodyParam('ruleIds');
        $count = 0;

        foreach ($ruleIds as $id) {
            $rule = QueryRule::findById((int)$id);
            if ($rule && SearchManager::$plugin->queryRules->delete($rule)) {
                $count++;
            }
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

    /**
     * @return array<string, array<int, ElementInterface>>
     */
    private function selectedTargetElements(mixed $elementId, mixed $elementType, ?int $siteId): array
    {
        $elements = [];
        if (!is_numeric($elementId)) {
            return $elements;
        }

        $queryElementType = is_string($elementType) && TargetElementTypeHelper::isSupportedElementType($elementType) ? $elementType : null;
        $element = Craft::$app->getElements()->getElementById((int)$elementId, $queryElementType, $siteId);
        if ($element instanceof ElementInterface) {
            $elements[TargetElementTypeHelper::keyForElementType(get_class($element))] = [$element];
        }

        return $elements;
    }

    private function resolveTargetElementType(mixed $elementId, mixed $elementType, ?int $siteId): ?string
    {
        if (is_string($elementType) && TargetElementTypeHelper::isSupportedElementType($elementType)) {
            return $elementType;
        }

        if (!is_numeric($elementId)) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById((int)$elementId, null, $siteId);

        return $element instanceof ElementInterface ? get_class($element) : null;
    }
}
