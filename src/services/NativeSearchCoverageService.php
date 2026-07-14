<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use lindemannrock\base\helpers\ConfigFileHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Shared coverage rules for native-search enhancement.
 *
 * @since 5.53.0
 */
class NativeSearchCoverageService extends Component
{
    /**
     * @var string[]
     */
    public const LOCAL_BACKENDS = ['mysql', 'pgsql', 'redis', 'file'];

    /**
     * @return array<int, array{type: string, label: string, covered: bool, indexHandle: string|null}>
     */
    public function getReport(): array
    {
        $report = [];
        $allSiteIds = array_map('intval', Craft::$app->getSites()->getAllSiteIds());

        foreach ($this->getElementTypeOptions() as $elementType => $label) {
            $index = $this->findCoverageIndex($elementType, $allSiteIds);
            $report[] = [
                'type' => $elementType,
                'label' => $label,
                'covered' => $index !== null,
                'indexHandle' => $index?->handle,
            ];
        }

        return $report;
    }

    /**
     * Element type options for Search Manager indices.
     *
     * @return array<string, string>
     */
    public function getElementTypeOptions(): array
    {
        $options = [
            Entry::class => Craft::t('search-manager', 'Entries'),
            Asset::class => Craft::t('search-manager', 'Assets'),
            Category::class => Craft::t('search-manager', 'Categories'),
            User::class => Craft::t('search-manager', 'Users'),
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

    public function getIndexForQuery(ElementQuery $query): ?SearchIndex
    {
        return $this->findCoverageIndex((string)$query->elementType, $query->siteId);
    }

    public function isCoverageIndex(SearchIndex $index, string $elementType, mixed $siteId): bool
    {
        return $index->enabled
            && $index->elementType === $elementType
            && $this->isLocalBackendIndex($index)
            && $this->indexCoversSites($index, $siteId)
            && $this->indexHasNoCriteriaRestriction($index);
    }

    public function hasLocalBackend(): bool
    {
        return $this->defaultBackendIsLocal() || $this->getLocalBackendOptions() !== [];
    }

    public function defaultBackendIsLocal(): bool
    {
        return $this->isLocalBackendName(SearchManager::$plugin->backend->getActiveBackend()?->getName());
    }

    public function isLocalBackendName(?string $backendName): bool
    {
        return $backendName !== null && in_array($backendName, self::LOCAL_BACKENDS, true);
    }

    public function isLocalBackendHandle(?string $handle): bool
    {
        if ($handle === null || $handle === '') {
            return $this->defaultBackendIsLocal();
        }

        $configuredBackend = ConfiguredBackend::findByHandle($handle);

        return $configuredBackend !== null
            && $configuredBackend->enabled
            && $this->isLocalBackendName($configuredBackend->backendType);
    }

    /**
     * @return array<string, string>
     */
    public function getLocalBackendOptions(): array
    {
        $options = [];

        foreach (ConfiguredBackend::findAllEnabled() as $backend) {
            if (!$this->isLocalBackendName($backend->backendType)) {
                continue;
            }

            $options[$backend->handle] = $backend->name . ' (' . ($backend->getTypeLabel()) . ')';
        }

        return $options;
    }

    private function findCoverageIndex(string $elementType, mixed $siteId): ?SearchIndex
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($this->isCoverageIndex($index, $elementType, $siteId)) {
                return $index;
            }
        }

        return null;
    }

    private function isLocalBackendIndex(SearchIndex $index): bool
    {
        $backendType = SearchManager::$plugin->backend->getBackendForIndex($index->handle)?->getName();

        return $backendType !== null && in_array($backendType, self::LOCAL_BACKENDS, true);
    }

    private function indexCoversSites(SearchIndex $index, mixed $siteId): bool
    {
        $querySiteIds = SearchSiteScopeHelper::siteIds($siteId);
        $indexSiteIds = $index->getSiteIds();

        if ($querySiteIds === null) {
            return $indexSiteIds === null;
        }

        if ($indexSiteIds === null) {
            return true;
        }

        return empty(array_diff($querySiteIds, array_map('intval', $indexSiteIds)));
    }

    private function indexHasNoCriteriaRestriction(SearchIndex $index): bool
    {
        if ($index->isFromConfig()) {
            $config = ConfigFileHelper::getConfigByHandle('search-manager', 'indices', $index->handle);

            return $config !== null && !array_key_exists('criteria', $config);
        }

        return $this->isEmptyCriteria($index->criteria);
    }

    private function isEmptyCriteria(mixed $criteria): bool
    {
        if ($criteria === [] || $criteria === null || $criteria === '' || $criteria === '{}') {
            return true;
        }

        if (!is_array($criteria)) {
            return false;
        }

        foreach ($criteria as $value) {
            if (!$this->isEmptyCriteria($value)) {
                return false;
            }
        }

        return true;
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
}
