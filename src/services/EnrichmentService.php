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
use craft\base\ElementInterface;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Internal/debug live element augmentation for raw search backend hits.
 *
 * Public REST and GraphQL response shaping uses indexed hit data directly.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.39.0
 */
class EnrichmentService extends Component
{
    /**
     * Enrich raw search results with live element metadata for CP/debug callers.
     *
     * @param array<int, array<string, mixed>> $rawHits Raw hits from the search backend
     * @param array<int, string> $indexHandles Index handles that were searched
     * @param array<string, mixed> $options Enrichment options
     * @return array<int, array<string, mixed>> Enriched results array
     */
    public function enrichResults(array $rawHits, string $query, array $indexHandles, array $options = []): array
    {
        $showCodeSnippets = (bool)($options['showCodeSnippets'] ?? false);
        $parseMarkdownSnippets = (bool)($options['parseMarkdownSnippets'] ?? false);
        $hideResultsWithoutUrl = (bool)($options['hideResultsWithoutUrl'] ?? false);
        $includeDebugMeta = (bool)($options['includeDebugMeta'] ?? false);
        $includeQueryRuleDebug = (bool)($options['includeQueryRuleDebug'] ?? false);
        $siteId = SearchSiteScopeHelper::scopedSiteId($options['siteId'] ?? null);

        $currentSiteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $preloadedElements = $this->preloadElements($rawHits, $siteId, $indexHandles);

        $results = [];

        foreach ($rawHits as $hit) {
            $elementId = SearchHitIdentityHelper::elementId($hit);
            if ($elementId === null) {
                continue;
            }

            $hitSiteId = isset($hit['siteId']) ? (int)$hit['siteId'] : ($siteId ?? $currentSiteId);
            $element = $preloadedElements[$hitSiteId . ':' . $elementId] ?? null;

            if ($element === null) {
                continue;
            }

            $urlValue = array_key_exists('url', $hit) ? $hit['url'] : ($element->url ?? null);
            $url = is_scalar($urlValue) ? (string)$urlValue : null;
            if ($url === null && Craft::$app->getRequest()->getIsCpRequest()) {
                $url = $element->cpEditUrl;
            }

            if ($hideResultsWithoutUrl && $url === null) {
                continue;
            }

            $documentType = strtolower((string)($hit['type'] ?? $hit['elementType'] ?? $this->documentTypeForElement($element)));
            $title = $this->resultTitle($hit, $element);
            $snippetDebug = $includeDebugMeta ? [] : null;
            $snippetData = $this->prepareHitSnippets(
                $hit,
                $query,
                is_string($hit['_index'] ?? null) ? $hit['_index'] : ($indexHandles[0] ?? ''),
                [
                    'snippetMode' => (string)($options['snippetMode'] ?? 'balanced'),
                    'snippetLength' => (int)($options['snippetLength'] ?? 150),
                    'showCodeSnippets' => $showCodeSnippets,
                    'parseMarkdownSnippets' => $parseMarkdownSnippets,
                    'title' => $title,
                    'url' => $url,
                    'documentType' => $documentType,
                ],
                $snippetDebug,
            );
            $result = [
                'id' => $elementId,
                'title' => $title,
                'url' => $url,
                'snippet' => $snippetData['snippet'],
                'headings' => $snippetData['headings'],
                'type' => $documentType,
                'elementType' => $documentType,
                'fields' => SearchFieldValueHelper::fieldsFromHit($hit),
                'score' => $hit['score'] ?? null,
            ];

            $result = array_merge($result, $this->elementKindMetadata($hit, $element, $documentType));

            if ($includeDebugMeta && !empty($hit['_index'])) {
                $result['_index'] = $hit['_index'];
                $backend = SearchManager::$plugin->backend->getBackendForIndex((string)$hit['_index']);
                if ($backend !== null) {
                    $result['backend'] = $backend->getName();
                }
            }

            if ($element->siteId) {
                $result['siteId'] = $element->siteId;
                $site = Craft::$app->getSites()->getSiteById((int)$element->siteId);
                if ($site !== null) {
                    $result['site'] = $site->handle;
                    $result['language'] = $site->language;
                }
            }

            foreach (['productType', 'productTypeHandle'] as $commerceKey) {
                if (isset($hit[$commerceKey]) && $hit[$commerceKey] !== '') {
                    $result[$commerceKey] = $hit[$commerceKey];
                }
            }

            if (!empty($hit['matchedIn'])) {
                $result['matchedIn'] = $hit['matchedIn'];
            }

            if (!empty($hit['matchedTerms'])) {
                $result['matchedTerms'] = $hit['matchedTerms'];
            }

            if (!empty($hit['matchedPhrases'])) {
                $result['matchedPhrases'] = $hit['matchedPhrases'];
            }

            if ($includeDebugMeta && !empty($snippetDebug)) {
                $result['_snippet'] = $snippetDebug;
            }

            if (!empty($hit['promoted'])) {
                $result['promoted'] = true;
            }

            if (!empty($hit['boosted'])) {
                $result['boosted'] = true;
            }

            if ($includeQueryRuleDebug && isset($hit['_queryRuleDebug']) && is_array($hit['_queryRuleDebug'])) {
                $result['_queryRuleDebug'] = $hit['_queryRuleDebug'];
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Build plain-text snippets from saved indexed hit data.
     *
     * @param array<string, mixed> $hit
     * @param array<string, mixed> $options
     * @since 5.53.0
     * @return array{snippet: string|null, headings: list<array{title: string, id: string, level: int, url: string|null, snippet: string|null}>}
     */
    public function prepareHitSnippets(
        array $hit,
        string $query,
        string $indexHandle = '',
        array $options = [],
        ?array &$debugMeta = null,
    ): array {
        return SearchManager::$plugin->indexedSnippets->prepareHitSnippets(
            $hit,
            $query,
            $indexHandle,
            $options,
            $debugMeta,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rawHits Raw hits from the search backend
     * @param array<int, string> $indexHandles Index handles for the search
     * @return array<string, ElementInterface> Map keyed by "siteId:elementId"
     */
    private function preloadElements(array $rawHits, ?int $siteId, array $indexHandles): array
    {
        $isCpRequest = Craft::$app->getRequest()->getIsCpRequest();
        $currentSiteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $fallbackHandle = $indexHandles[0] ?? '';
        $elementClassByHandle = [];

        foreach ($rawHits as $hit) {
            $handle = is_string($hit['_index'] ?? null) ? $hit['_index'] : $fallbackHandle;
            if ($handle !== '' && !array_key_exists($handle, $elementClassByHandle)) {
                $elementClassByHandle[$handle] = SearchIndex::findByHandle($handle)?->elementType;
            }
        }

        $groups = [];
        $unresolved = [];

        foreach ($rawHits as $hit) {
            $elementId = SearchHitIdentityHelper::elementId($hit);
            if ($elementId === null) {
                continue;
            }

            $resolvedSiteId = isset($hit['siteId']) ? (int)$hit['siteId'] : ($siteId ?? $currentSiteId);
            $explicitElementClass = is_string($hit['_elementType'] ?? null) ? $hit['_elementType'] : null;
            $handle = is_string($hit['_index'] ?? null) ? $hit['_index'] : $fallbackHandle;
            $elementClass = $explicitElementClass ?: ($handle !== '' ? ($elementClassByHandle[$handle] ?? null) : null);

            if ($elementClass !== null && is_subclass_of($elementClass, ElementInterface::class)) {
                $groups[$elementClass][$resolvedSiteId][$elementId] = true;
            } else {
                $unresolved[$resolvedSiteId][$elementId] = true;
            }
        }

        $map = [];

        /** @var class-string<ElementInterface> $elementClass */
        foreach ($groups as $elementClass => $bySite) {
            foreach ($bySite as $resolvedSiteId => $idSet) {
                /** @var \craft\elements\db\ElementQuery $query */
                $query = $elementClass::find()
                    ->id(array_keys($idSet));
                if ($isCpRequest) {
                    $query->status(null);
                } else {
                    SearchElementAvailabilityHelper::applyToQuery($query, $elementClass);
                }

                if (!SearchElementAvailabilityHelper::isSiteIndependent($elementClass)) {
                    $query->siteId($resolvedSiteId);
                }

                foreach ($query->all() as $element) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                }
            }
        }

        foreach ($unresolved as $resolvedSiteId => $idSet) {
            foreach (array_keys($idSet) as $elementId) {
                $element = Craft::$app->elements->getElementById($elementId, null, $resolvedSiteId);
                if ($element !== null && ($isCpRequest || SearchElementAvailabilityHelper::isSearchable($element))) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function resultTitle(array $hit, ElementInterface $element): string
    {
        $hitTitle = $this->stringValueFromMixed($hit['title'] ?? null);
        if ($hitTitle !== '') {
            return $hitTitle;
        }

        if ($element instanceof \craft\elements\User) {
            foreach (['fullName', 'username', 'email'] as $property) {
                $value = $this->stringValueFromMixed($element->{$property} ?? null);
                if ($value !== '') {
                    return $value;
                }
            }

            return $element->id !== null ? '#' . $element->id : '';
        }

        $elementTitle = $this->stringValueFromMixed($element->title ?? null);

        return $elementTitle !== '' ? $elementTitle : 'Untitled';
    }

    private function stringValueFromMixed(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function elementKindMetadata(array $hit, ElementInterface $element, string $documentType): array
    {
        return match ($documentType) {
            'entry' => $this->entryMetadata($hit, $element),
            'asset' => $this->assetMetadata($hit, $element),
            'category' => $this->categoryMetadata($hit, $element),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function entryMetadata(array $hit, ElementInterface $element): array
    {
        $section = $element instanceof \craft\elements\Entry ? $element->getSection() : null;

        return $this->filterElementKindMetadata([
            'section' => $this->stringValueFromMixed($hit['section'] ?? null) ?: $section?->name,
            'sectionHandle' => $this->stringValueFromMixed($hit['sectionHandle'] ?? null) ?: $section?->handle,
            'sectionType' => $this->stringValueFromMixed($hit['sectionType'] ?? null) ?: $section?->type,
            'ancestors' => $this->ancestorsFromHit($hit['ancestors'] ?? null),
            'level' => $this->integerValueFromMixed($hit['level'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function assetMetadata(array $hit, ElementInterface $element): array
    {
        $volume = $element instanceof \craft\elements\Asset ? $element->getVolume() : null;

        return $this->filterElementKindMetadata([
            'volume' => $this->stringValueFromMixed($hit['volume'] ?? null) ?: $volume?->name,
            'volumeHandle' => $this->stringValueFromMixed($hit['volumeHandle'] ?? null) ?: $volume?->handle,
            'ancestors' => $this->ancestorsFromHit($hit['ancestors'] ?? null),
            'folderPath' => $this->stringValueFromMixed($hit['folderPath'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function categoryMetadata(array $hit, ElementInterface $element): array
    {
        $group = $element instanceof \craft\elements\Category ? $element->getGroup() : null;

        return $this->filterElementKindMetadata([
            'group' => $this->stringValueFromMixed($hit['group'] ?? null) ?: $group?->name,
            'groupHandle' => $this->stringValueFromMixed($hit['groupHandle'] ?? null) ?: $group?->handle,
            'ancestors' => $this->ancestorsFromHit($hit['ancestors'] ?? null),
            'level' => $this->integerValueFromMixed($hit['level'] ?? null),
        ]);
    }

    private function integerValueFromMixed(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function ancestorsFromHit(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ancestors = [];
        foreach ($value as $ancestor) {
            if (!is_array($ancestor)) {
                continue;
            }

            $id = $ancestor['id'] ?? null;
            $title = $this->stringValueFromMixed($ancestor['title'] ?? null);
            if (!is_numeric($id) || $title === '') {
                continue;
            }

            $ancestors[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $ancestors;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function filterElementKindMetadata(array $metadata): array
    {
        return array_filter($metadata, static function(mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            return !is_array($value) || $value !== [];
        });
    }

    private function documentTypeForElement(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\Entry) {
            return 'entry';
        }

        if (is_a($element, \lindemannrock\searchmanager\helpers\CommerceElementTypeHelper::productElementType())) {
            return 'product';
        }

        if (is_a($element, \lindemannrock\searchmanager\helpers\CommerceElementTypeHelper::variantElementType())) {
            return 'variant';
        }

        if ($element instanceof \craft\elements\Category) {
            return 'category';
        }

        if ($element instanceof \craft\elements\Asset) {
            return 'asset';
        }

        if ($element instanceof \craft\elements\User) {
            return 'user';
        }

        return strtolower($element::displayName());
    }
}
