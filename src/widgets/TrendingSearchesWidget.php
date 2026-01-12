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
 * Search Manager Trending Searches Widget
 *
 * Shows searches that are trending up or down compared to the previous period.
 *
 * @since 5.1.0
 */
class TrendingSearchesWidget extends Widget
{
    /**
     * @var int Number of trending searches to show
     */
    public int $limit = 5;

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
        $rules[] = [['limit'], 'integer', 'min' => 3, 'max' => 20];
        $rules[] = [['limit'], 'default', 'value' => 5];
        $rules[] = [['dateRange'], 'in', 'range' => ['today', 'yesterday', 'last7days', 'last30days', 'last90days', 'all']];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        $pluginName = SearchManager::$plugin->getSettings()->getFullName();
        return Craft::t('search-manager', '{pluginName} - Trending', ['pluginName' => $pluginName]);
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return '@app/icons/solid/arrow-trend-up.svg';
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
        return Craft::t('search-manager', '{pluginName} - Trending', ['pluginName' => $pluginName]);
    }

    /**
     * @inheritdoc
     */
    public function getSubtitle(): ?string
    {
        return match ($this->dateRange) {
            'today' => Craft::t('search-manager', 'Today vs Yesterday'),
            'yesterday' => Craft::t('search-manager', 'Yesterday vs Day Before'),
            'last7days' => Craft::t('search-manager', 'Last 7 days vs Previous'),
            'last30days' => Craft::t('search-manager', 'Last 30 days vs Previous'),
            'last90days' => Craft::t('search-manager', 'Last 90 days vs Previous'),
            'all' => Craft::t('search-manager', 'Last 30 days vs Previous'),
            default => Craft::t('search-manager', 'Last 7 days vs Previous'),
        };
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        $trending = SearchManager::$plugin->analytics->getTrendingQueries(null, $this->dateRange, $this->limit);

        return Craft::$app->getView()->renderTemplate(
            'search-manager/widgets/trending-searches/body',
            [
                'widget' => $this,
                'trending' => $trending,
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
            'search-manager/widgets/trending-searches/settings',
            [
                'widget' => $this,
            ]
        );
    }
}
