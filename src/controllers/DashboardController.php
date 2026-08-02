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
use craft\web\User;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Dashboard Controller
 *
 * @since 5.0.0
 */
class DashboardController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function actionIndex(): Response
    {
        $user = $this->getCpUser();
        $settings = SearchManager::$plugin->getSettings();
        $dashboardCards = $this->getDashboardCardAccess();

        if (!in_array(true, $dashboardCards, true)) {
            $sections = SearchManager::$plugin->getCpSections($settings, false, true);
            $route = CpNavHelper::firstAccessibleRoute($user, $settings, $sections);
            if ($route) {
                return $this->redirect($route);
            }

            throw new ForbiddenHttpException();
        }

        $variables = [
            'settings' => $settings,
            'dashboardCards' => $dashboardCards,
            'indices' => [],
            'totalDocuments' => 0,
            'enabledIndices' => 0,
            'promotionsCount' => 0,
            'enabledPromotions' => 0,
            'queryRulesCount' => 0,
            'enabledQueryRules' => 0,
            'searchesToday' => 0,
            'searchesYesterday' => 0,
            'topSearches' => [],
            'recentZeroResults' => [],
        ];

        if ($dashboardCards['indices']) {
            $variables = array_replace($variables, $this->loadIndexCardData());
        }
        if ($dashboardCards['promotions']) {
            $variables = array_replace($variables, $this->loadPromotionCardData());
        }
        if ($dashboardCards['queryRules']) {
            $variables = array_replace($variables, $this->loadQueryRuleCardData());
        }
        if ($dashboardCards['analytics']) {
            $variables = array_replace($variables, $this->loadAnalyticsCardData());
        }

        return $this->renderTemplate('search-manager/dashboard/index', $variables);
    }

    /**
     * @return array{indices: bool, promotions: bool, queryRules: bool, analytics: bool}
     */
    protected function getDashboardCardAccess(): array
    {
        return SearchManager::$plugin->getDashboardCardAccess(
            SearchManager::$plugin->getSettings(),
            $this->getCpUser(),
        );
    }

    protected function getCpUser(): User
    {
        return Craft::$app->getUser();
    }

    /** @return array{indices: array, totalDocuments: int, enabledIndices: int} */
    protected function loadIndexCardData(): array
    {
        $indices = SearchIndex::findAll();
        $totalDocuments = 0;
        $enabledIndices = 0;
        foreach ($indices as $index) {
            $totalDocuments += $index->documentCount;
            if ($index->enabled) {
                $enabledIndices++;
            }
        }

        return [
            'indices' => $indices,
            'totalDocuments' => $totalDocuments,
            'enabledIndices' => $enabledIndices,
        ];
    }

    /** @return array{promotionsCount: int, enabledPromotions: int} */
    protected function loadPromotionCardData(): array
    {
        return [
            'promotionsCount' => SearchManager::$plugin->promotions->getPromotionCount(),
            'enabledPromotions' => SearchManager::$plugin->promotions->getPromotionCount(true),
        ];
    }

    /** @return array{queryRulesCount: int, enabledQueryRules: int} */
    protected function loadQueryRuleCardData(): array
    {
        return [
            'queryRulesCount' => SearchManager::$plugin->queryRules->getQueryRuleCount(),
            'enabledQueryRules' => SearchManager::$plugin->queryRules->getQueryRuleCount(true),
        ];
    }

    /** @return array{searchesToday: int, searchesYesterday: int, topSearches: array, recentZeroResults: array} */
    protected function loadAnalyticsCardData(): array
    {
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        $todaySummary = SearchManager::$plugin->analytics->getAnalyticsSummary($editableSiteIds, 'today');
        $yesterdaySummary = SearchManager::$plugin->analytics->getAnalyticsSummary($editableSiteIds, 'yesterday');

        return [
            'searchesToday' => $todaySummary['totalSearches'],
            'searchesYesterday' => $yesterdaySummary['totalSearches'],
            'topSearches' => SearchManager::$plugin->analytics
                ->getMostCommonSearches($editableSiteIds, 5, 'last7days'),
            'recentZeroResults' => SearchManager::$plugin->analytics
                ->getRecentSearches($editableSiteIds, 5, false, 'last7days'),
        ];
    }
}
