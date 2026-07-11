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
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\models\SearchIndex;

/**
 * CP-only live element comparison for indexed search hits.
 *
 * Public REST and GraphQL response shaping uses indexed hit data directly.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.39.0
 */
class LiveComparisonService extends Component
{
    /**
     * Attach live element comparison metadata without changing public hit fields.
     *
     * @param array<int, array<string, mixed>> $hits Canonical indexed hits
     * @param array<int, string> $indexHandles Index handles that were searched
     * @param array<string, mixed> $options Live comparison options
     * @return array<int, array<string, mixed>>
     */
    public function compareHits(array $hits, array $indexHandles, array $options = []): array
    {
        $siteId = SearchSiteScopeHelper::scopedSiteId($options['siteId'] ?? null);
        $preloadedElements = $this->preloadElements($hits, $siteId, $indexHandles);
        $currentSiteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $compared = [];

        foreach ($hits as $hit) {
            $elementId = SearchHitIdentityHelper::elementId($hit);
            $hitSiteId = isset($hit['siteId']) ? (int)$hit['siteId'] : ($siteId ?? $currentSiteId);
            $element = $elementId !== null ? ($preloadedElements[$hitSiteId . ':' . $elementId] ?? null) : null;

            if (!$element instanceof ElementInterface) {
                $hit['_liveComparison'] = $this->missingComparison();
                $compared[] = $hit;
                continue;
            }

            $liveUrl = $this->stringValueFromMixed($element->url ?? null);
            $hit['_liveComparison'] = $this->elementComparison($element, $liveUrl);
            $compared[] = $hit;
        }

        return $compared;
    }

    /**
     * @return array{elementFound: false, title: null, url: null, cpEditUrl: null, type: null, site: null, language: null}
     */
    private function missingComparison(): array
    {
        return [
            'elementFound' => false,
            'title' => null,
            'url' => null,
            'cpEditUrl' => null,
            'type' => null,
            'site' => null,
            'language' => null,
        ];
    }

    /**
     * @return array{elementFound: true, title: string, url: string|null, cpEditUrl: string|null, type: string, site: string|null, language: string|null}
     */
    private function elementComparison(ElementInterface $element, string $liveUrl): array
    {
        $site = $element->siteId ? Craft::$app->getSites()->getSiteById((int)$element->siteId) : null;

        return [
            'elementFound' => true,
            'title' => $this->elementTitle($element),
            'url' => $liveUrl !== '' ? $liveUrl : null,
            'cpEditUrl' => $this->stringValueFromMixed($element->cpEditUrl ?? null) ?: null,
            'type' => $this->documentTypeForElement($element),
            'site' => $site?->handle,
            'language' => $site?->language,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     * @param array<int, string> $indexHandles
     * @return array<string, ElementInterface> Map keyed by "siteId:elementId"
     */
    private function preloadElements(array $hits, ?int $siteId, array $indexHandles): array
    {
        $currentSiteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $fallbackHandle = $indexHandles[0] ?? '';
        $elementClassByHandle = [];

        foreach ($hits as $hit) {
            $handle = is_string($hit['index'] ?? null)
                ? $hit['index']
                : (is_string($hit['_index'] ?? null) ? $hit['_index'] : $fallbackHandle);
            if ($handle !== '' && !array_key_exists($handle, $elementClassByHandle)) {
                $elementClassByHandle[$handle] = SearchIndex::findByHandle($handle)?->elementType;
            }
        }

        $groups = [];
        $unresolved = [];

        foreach ($hits as $hit) {
            $elementId = SearchHitIdentityHelper::elementId($hit);
            if ($elementId === null) {
                continue;
            }

            $resolvedSiteId = isset($hit['siteId']) ? (int)$hit['siteId'] : ($siteId ?? $currentSiteId);
            $explicitElementClass = is_string($hit['_elementType'] ?? null) ? $hit['_elementType'] : null;
            $handle = is_string($hit['index'] ?? null)
                ? $hit['index']
                : (is_string($hit['_index'] ?? null) ? $hit['_index'] : $fallbackHandle);
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
                    ->id(array_keys($idSet))
                    ->status(null);

                if (!SearchElementAvailabilityHelper::isSiteIndependent($elementClass)) {
                    $query->siteId($resolvedSiteId);
                }

                foreach (SearchElementAvailabilityHelper::applyToQuery($query, $elementClass)->all() as $element) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                }
            }
        }

        foreach ($unresolved as $resolvedSiteId => $idSet) {
            foreach (array_keys($idSet) as $elementId) {
                $element = Craft::$app->elements->getElementById($elementId, null, $resolvedSiteId);
                if ($element !== null) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                }
            }
        }

        return $map;
    }

    private function elementTitle(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\User) {
            foreach (['fullName', 'username', 'email'] as $property) {
                $value = $this->stringValueFromMixed($element->{$property} ?? null);
                if ($value !== '') {
                    return $value;
                }
            }

            return $element->id !== null ? '#' . $element->id : '';
        }

        $title = $this->stringValueFromMixed($element->title ?? null);

        return $title !== '' ? $title : 'Untitled';
    }

    private function stringValueFromMixed(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function documentTypeForElement(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\Entry) {
            return 'entry';
        }

        if (is_a($element, CommerceElementTypeHelper::productElementType())) {
            return 'product';
        }

        if (is_a($element, CommerceElementTypeHelper::variantElementType())) {
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
