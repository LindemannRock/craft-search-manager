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
 * Search Manager Content Gaps Widget
 *
 * Shows searches that returned zero results - helps identify content gaps.
 *
 * @since 5.1.0
 */
class ContentGapsWidget extends Widget
{
    /**
     * @var int Number of content gaps to show
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
        return Craft::t('search-manager', '{pluginName} - Content Gaps', ['pluginName' => $pluginName]);
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return '@app/icons/solid/triangle-exclamation.svg';
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
        return Craft::t('search-manager', '{pluginName} - Content Gaps', ['pluginName' => $pluginName]);
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
        $contentGaps = SearchManager::$plugin->analytics->getZeroResultClusters(null, $this->dateRange, $this->limit);

        return Craft::$app->getView()->renderTemplate(
            'search-manager/widgets/content-gaps/body',
            [
                'widget' => $this,
                'contentGaps' => $contentGaps,
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
            'search-manager/widgets/content-gaps/settings',
            [
                'widget' => $this,
            ]
        );
    }
}
