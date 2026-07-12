<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\DocsManagerTransformer;

/**
 * Resolves split-section documents for split-capable transformer families.
 *
 * @since 5.53.0
 */
class SplitSectionDocumentHelper
{
    private const SOURCE_DOC_ELEMENT_TYPE = 'lindemannrock\\docsmanager\\elements\\SourceDoc';

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function documentsForIndex(?SearchIndex $index, ElementInterface $element, array $data): array
    {
        if (!$index?->usesSplitSections()) {
            return [$data];
        }

        $resolvedTransformer = SearchManager::$plugin->transformers->resolveTransformerClass($element, $index->transformerClass);
        $headingLevels = SearchHeadingHelper::normalizeLevels($index->headingLevels ?? null);

        if (
            is_a($element, self::SOURCE_DOC_ELEMENT_TYPE)
            && is_string($resolvedTransformer)
            && is_a($resolvedTransformer, DocsManagerTransformer::class, true)
        ) {
            /** @var \lindemannrock\docsmanager\elements\SourceDoc $element */
            $documents = SourceDocSectionSplitter::split($element, $data, $headingLevels);

            return $documents !== [] ? $documents : [$data];
        }

        if (is_string($resolvedTransformer) && is_a($resolvedTransformer, AutoTransformer::class, true)) {
            $documents = AutoTransformerSectionSplitter::split($element, $data, $headingLevels);

            return $documents !== [] ? $documents : [$data];
        }

        return [$data];
    }
}
