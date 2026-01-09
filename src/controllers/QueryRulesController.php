<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Query Rules Controller
 *
 * Manages query rules (synonyms, boosts, filters, redirects) in the CP
 */
class QueryRulesController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all query rules
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewQueryRules');

        $rules = QueryRule::findAll();
        $indices = SearchIndex::findAll();

        // Build index lookup for display
        $indexLookup = [];
        foreach ($indices as $index) {
            $indexLookup[$index->handle] = $index->name;
        }

        return $this->renderTemplate('search-manager/query-rules/index', [
            'rules' => $rules,
            'indexLookup' => $indexLookup,
        ]);
    }

    /**
     * Edit or create a query rule
     */
    public function actionEdit(?int $ruleId = null): Response
    {
        // Require create permission for new, edit permission for existing
        if ($ruleId) {
            $this->requirePermission('searchManager:editQueryRules');
        } else {
            $this->requirePermission('searchManager:createQueryRules');
        }

        if ($ruleId) {
            $rule = QueryRule::findById($ruleId);
            if (!$rule) {
                throw new NotFoundHttpException('Query rule not found');
            }
        } else {
            $rule = new QueryRule();
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
                'label' => Craft::t('search-manager', $label),
                'value' => $value,
            ];
        }

        // Action type options
        $actionTypeOptions = [];
        foreach (QueryRule::getActionTypes() as $value => $label) {
            $actionTypeOptions[] = [
                'label' => Craft::t('search-manager', $label),
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
                throw new NotFoundHttpException('Query rule not found');
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
                    'multiplier' => (float)$request->getBodyParam('boostMultiplier', 2.0),
                ];
                break;

            case QueryRule::ACTION_BOOST_CATEGORY:
                // Element selector returns an array of IDs
                $boostCategory = $request->getBodyParam('boostCategory');
                $categoryId = is_array($boostCategory) && !empty($boostCategory) ? (int)$boostCategory[0] : 0;
                $actionValue = [
                    'categoryId' => $categoryId,
                    'multiplier' => (float)$request->getBodyParam('boostMultiplier', 2.0),
                ];
                break;

            case QueryRule::ACTION_BOOST_ELEMENT:
                // Element selector returns an array of IDs
                $boostElement = $request->getBodyParam('boostElement');
                $elementId = is_array($boostElement) && !empty($boostElement) ? (int)$boostElement[0] : 0;
                $actionValue = [
                    'elementId' => $elementId,
                    'multiplier' => (float)$request->getBodyParam('boostMultiplier', 2.0),
                ];
                break;

            case QueryRule::ACTION_FILTER:
                $actionValue = [
                    'field' => $request->getBodyParam('filterField'),
                    'value' => $request->getBodyParam('filterValue'),
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

                    $elementTypeMap = [
                        'entry' => \craft\elements\Entry::class,
                        'category' => \craft\elements\Category::class,
                        'asset' => \craft\elements\Asset::class,
                    ];

                    $actionValue = [
                        'elementId' => $elementId ? (int)$elementId : null,
                        'elementType' => $elementTypeMap[$redirectType] ?? null,
                    ];
                }
                break;
        }

        $rule->actionValue = $actionValue;

        if (!$rule->validate() || !$rule->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save query rule.')
            );

            // Return with errors
            Craft::$app->getUrlManager()->setRouteParams([
                'rule' => $rule,
            ]);

            return null;
        }

        // Clear all search cache (rules can affect any query)
        SearchManager::$plugin->backend->clearAllSearchCache();

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Query rule saved.')
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
                return $this->asJson(['success' => false, 'error' => 'Query rule not found']);
            }
            throw new NotFoundHttpException('Query rule not found');
        }

        if ($rule->delete()) {
            // Clear all search cache
            SearchManager::$plugin->backend->clearAllSearchCache();

            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }

            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Query rule deleted.')
            );
        } else {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => 'Could not delete query rule']);
            }

            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not delete query rule.')
            );
        }

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
                if ($rule->save()) {
                    $count++;
                }
            }
        }

        // Clear all search cache
        if ($count > 0) {
            SearchManager::$plugin->backend->clearAllSearchCache();
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
                if ($rule->save()) {
                    $count++;
                }
            }
        }

        // Clear all search cache
        if ($count > 0) {
            SearchManager::$plugin->backend->clearAllSearchCache();
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
            if ($rule && $rule->delete()) {
                $count++;
            }
        }

        // Clear all search cache
        if ($count > 0) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }
}
