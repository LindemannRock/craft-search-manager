<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\widgets;

use Craft;
use craft\base\Widget;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\searchmanager\SearchManager;

/**
 * Search Manager Content Gaps Widget
 *
 * Shows searches that returned zero results - helps identify content gaps.
 *
 * @since 5.27.0
 */
class ContentGapsWidget extends Widget
{
    use SiteFilterTrait;

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
        $rules[] = [['dateRange'], 'in', 'range' => array_keys(DateRangeHelper::getOptions('assoc'))];
        $rules[] = [['siteId'], 'in', 'range' => array_column($this->siteOptions(), 'value')];
        $rules[] = [['dateRange'], 'default', 'value' => 'last7days'];
        $rules[] = [['siteId'], 'default', 'value' => 'all'];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return parent::isSelectable() &&
            Craft::$app->getUser()->checkPermission('searchManager:viewAnalytics');
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
        return '@lindemannrock/searchmanager/icon-mask.svg';
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
        $labels = DateRangeHelper::getOptions('assoc');

        return $labels[$this->dateRange] ?? $labels['last7days'];
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission('searchManager:viewAnalytics')) {
            return '<p class="light">' . Craft::t('search-manager', 'You don\'t have permission to view analytics.') . '</p>';
        }

        if (!SearchManager::$plugin->getSettings()->enableAnalytics) {
            return '<p class="light">' . Craft::t('search-manager', 'Analytics are disabled in plugin settings.') . '</p>';
        }

        $contentGaps = SearchManager::$plugin->analytics->getZeroResultClusters($this->effectiveSiteId(), $this->dateRange, $this->limit);

        return Craft::$app->getView()->renderTemplate(
            'search-manager/dashboard-widgets/content-gaps/body',
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
            'search-manager/dashboard-widgets/content-gaps/settings',
            [
                'widget' => $this,
                'siteOptions' => $this->siteOptions(),
            ]
        );
    }
}
