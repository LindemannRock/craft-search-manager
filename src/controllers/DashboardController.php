<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Dashboard Controller
 */
class DashboardController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewIndices');

        $settings = SearchManager::$plugin->getSettings();
        $indices = SearchIndex::findAll();

        // Count totals
        $totalDocuments = 0;
        $enabledIndices = 0;
        foreach ($indices as $index) {
            $totalDocuments += $index->documentCount;
            if ($index->enabled) {
                $enabledIndices++;
            }
        }

        // Get promotions count
        $promotionsCount = SearchManager::$plugin->promotions->getPromotionCount();
        $enabledPromotions = SearchManager::$plugin->promotions->getPromotionCount(true);

        // Get query rules count
        $queryRulesCount = SearchManager::$plugin->queryRules->getQueryRuleCount();
        $enabledQueryRules = SearchManager::$plugin->queryRules->getQueryRuleCount(true);

        // Get analytics stats if enabled
        $searchesToday = 0;
        $searchesYesterday = 0;
        $topSearches = [];
        $recentZeroResults = [];
        if ($settings->enableAnalytics) {
            $searchesToday = SearchManager::$plugin->analytics->getAnalyticsCount(null, null, 'today');
            $searchesYesterday = SearchManager::$plugin->analytics->getAnalyticsCount(null, null, 'yesterday');
            $topSearches = SearchManager::$plugin->analytics->getMostCommonSearches(null, 5, 'last7days');
            $recentZeroResults = SearchManager::$plugin->analytics->getRecentSearches(null, 5, false, 'last7days');
        }

        // Get all sites for reference
        $sites = Craft::$app->getSites()->getAllSites();

        return $this->renderTemplate('search-manager/dashboard/index', [
            'settings' => $settings,
            'indices' => $indices,
            'totalDocuments' => $totalDocuments,
            'enabledIndices' => $enabledIndices,
            'promotionsCount' => $promotionsCount,
            'enabledPromotions' => $enabledPromotions,
            'queryRulesCount' => $queryRulesCount,
            'enabledQueryRules' => $enabledQueryRules,
            'searchesToday' => $searchesToday,
            'searchesYesterday' => $searchesYesterday,
            'topSearches' => $topSearches,
            'recentZeroResults' => $recentZeroResults,
            'sites' => $sites,
        ]);
    }
}
