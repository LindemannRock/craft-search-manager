<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\widgets;

use Craft;
use craft\base\Widget;
use lindemannrock\searchmanager\SearchManager;

/**
 * Search Manager Analytics Summary Widget
 *
 * @since 5.1.0
 */
class AnalyticsSummaryWidget extends Widget
{
    /**
     * @var string Date range for analytics
     */
    public string $dateRange = 'last7days';

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['dateRange'], 'in', 'range' => ['today', 'yesterday', 'last7days', 'last30days', 'last90days', 'all']];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        $pluginName = SearchManager::$plugin->getSettings()->getFullName();
        return Craft::t('search-manager', '{pluginName} - Analytics', ['pluginName' => $pluginName]);
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return '@app/icons/solid/chart-line.svg';
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 2;
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string
    {
        $pluginName = SearchManager::$plugin->getSettings()->getFullName();
        return Craft::t('search-manager', '{pluginName} - Analytics', ['pluginName' => $pluginName]);
    }

    /**
     * @inheritdoc
     */
    public function getSubtitle(): ?string
    {
        return match ($this->dateRange) {
            'today' => Craft::t('search-manager', 'Today'),
            'yesterday' => Craft::t('search-manager', 'Yesterday'),
            'last7days' => Craft::t('search-manager', 'Last 7 days'),
            'last30days' => Craft::t('search-manager', 'Last 30 days'),
            'last90days' => Craft::t('search-manager', 'Last 90 days'),
            'all' => Craft::t('search-manager', 'All time'),
            default => Craft::t('search-manager', 'Last 7 days'),
        };
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        $analyticsData = SearchManager::$plugin->analytics->getAnalyticsSummary($this->dateRange);
        $topSearches = SearchManager::$plugin->analytics->getMostCommonSearches(null, 1, $this->dateRange);
        $topSearch = $topSearches[0] ?? null;

        return Craft::$app->getView()->renderTemplate(
            'search-manager/dashboard-widgets/analytics-summary/body',
            [
                'widget' => $this,
                'analyticsData' => $analyticsData,
                'topSearch' => $topSearch,
                'searchHelper' => SearchManager::$plugin->getSettings(),
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'search-manager/dashboard-widgets/analytics-summary/settings',
            [
                'widget' => $this,
            ]
        );
    }
}
