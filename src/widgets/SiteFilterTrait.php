<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\widgets;

use Craft;

/**
 * Shared site filter behavior for Search Manager dashboard widgets.
 *
 * @since 5.52.0
 */
trait SiteFilterTrait
{
    /**
     * @var string Selected site ID, or "all" for all editable sites
     */
    public string $siteId = 'all';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function siteOptions(): array
    {
        $options = [
            ['value' => 'all', 'label' => Craft::t('search-manager', 'All Sites')],
        ];

        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $options[] = [
                'value' => (string) $site->id,
                'label' => $site->name,
            ];
        }

        return $options;
    }

    /**
     * @return int|array<int>
     */
    protected function effectiveSiteId(): int|array
    {
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();

        if ($this->siteId !== 'all') {
            $siteId = (int) $this->siteId;

            if (in_array($siteId, $editableSiteIds, true)) {
                return $siteId;
            }
        }

        return $editableSiteIds;
    }
}
