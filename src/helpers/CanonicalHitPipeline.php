<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\searchmanager\SearchManager;

/**
 * Prepares public search hits from indexed backend data only.
 *
 * @since 5.53.0
 */
class CanonicalHitPipeline
{
    /**
     * @param array<int, mixed> $hits
     * @param array<int, string> $indexHandles
     * @param array{snippetMode: string, snippetLength: int, showCodeSnippets: bool, parseMarkdownSnippets: bool, hideResultsWithoutUrl: bool, includeSnippetDebug?: bool} $options
     * @return array<int, array<string, mixed>>
     */
    public static function presentHits(
        array $hits,
        string $query,
        array $indexHandles,
        array $options,
        bool $includeQueryRuleDebug = false,
    ): array {
        $prepared = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $snippetDebug = !empty($options['includeSnippetDebug']) ? [] : null;
            $snippetData = SearchManager::$plugin->indexedSnippets->prepareHitSnippets(
                $hit,
                $query,
                is_string($hit['_index'] ?? null) ? $hit['_index'] : ($indexHandles[0] ?? ''),
                [
                    'snippetMode' => $options['snippetMode'],
                    'snippetLength' => $options['snippetLength'],
                    'showCodeSnippets' => $options['showCodeSnippets'],
                    'parseMarkdownSnippets' => $options['parseMarkdownSnippets'],
                    'title' => is_string($hit['title'] ?? null) ? $hit['title'] : '',
                    'url' => is_string($hit['url'] ?? null) ? $hit['url'] : '',
                    'documentType' => is_string($hit['type'] ?? null)
                        ? $hit['type']
                        : (is_string($hit['elementType'] ?? null) ? $hit['elementType'] : ''),
                ],
                $snippetDebug,
            );

            $hit['snippet'] = $snippetData['snippet'];
            $hit['headings'] = $snippetData['headings'];

            if ($options['hideResultsWithoutUrl'] && !self::hasIndexedUrl($hit)) {
                continue;
            }

            if ($snippetDebug !== null && $snippetDebug !== []) {
                $hit['_snippet'] = $snippetDebug;
            }

            $prepared[] = SearchHitPresenter::present($hit, $includeQueryRuleDebug);
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private static function hasIndexedUrl(array $hit): bool
    {
        return isset($hit['url']) && is_string($hit['url']) && trim($hit['url']) !== '';
    }
}
