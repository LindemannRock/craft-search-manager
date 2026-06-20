<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\gql\resolvers;

use Craft;
use craft\gql\base\Resolver;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\base\helpers\GqlHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\ForbiddenHttpException;

/**
 * GraphQL resolver for Search Manager search and autocomplete queries.
 *
 * @since 5.53.0
 */
class SearchResolver extends Resolver
{
    private const MAX_QUERY_LENGTH = 256;
    private const MAX_INDICES_COUNT = 5;

    /**
     * Resolve the default search field shape.
     *
     * @inheritdoc
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        return self::resolveSearch($source, $arguments, $context, $resolveInfo);
    }

    /**
     * Resolve a search request through the existing backend service.
     *
     * @inheritdoc
     */
    public static function resolveSearch(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $query = trim((string)($arguments['query'] ?? ''));

        if ($query === '') {
            return ['hits' => [], 'total' => 0, 'query' => $query];
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => 'Query too long (max ' . self::MAX_QUERY_LENGTH . ' characters)',
            ];
        }

        $limit = self::clampInt($arguments['hitsPerPage'] ?? null, 20, 1, 200);
        $page = self::clampInt($arguments['page'] ?? null, 0, 0, PHP_INT_MAX);
        $offset = $page * $limit;

        $siteId = self::resolveRequestedSiteId($arguments);
        if (self::hasSiteArgument($arguments) && $siteId === null) {
            return ['hits' => [], 'total' => 0, 'query' => $query, 'page' => $page, 'hitsPerPage' => $limit, 'totalPages' => 0];
        }
        $siteIds = self::resolveSchemaSiteScope($siteId);
        if ($siteIds === []) {
            return ['hits' => [], 'total' => 0, 'query' => $query, 'page' => $page, 'hitsPerPage' => $limit, 'totalPages' => 0];
        }

        [$indexHandles, $indicesProvided] = self::resolveIndexHandles($arguments);
        if ($indicesProvided && empty($indexHandles)) {
            return ['hits' => [], 'total' => 0, 'query' => $query, 'page' => $page, 'hitsPerPage' => $limit, 'totalPages' => 0];
        }

        $filters = self::trimmedString($arguments['filters'] ?? null);
        if ($filters !== null && count($indexHandles) !== 1) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'page' => $page,
                'hitsPerPage' => $limit,
                'totalPages' => 0,
                'error' => 'The filters argument requires a single index.',
            ];
        }

        $options = [
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'type' => self::trimmedString($arguments['type'] ?? null),
            'skipAnalytics' => (bool)($arguments['skipAnalytics'] ?? false),
            'source' => self::trimmedString($arguments['source'] ?? null) ?? 'graphql',
        ];

        if ($filters !== null && count($indexHandles) === 1) {
            $options = array_merge($options, self::filterOptionsForIndex($indexHandles[0], $filters));
        }

        if ($siteIds === null && $siteId !== null) {
            $options['siteId'] = $siteId;
        }
        foreach (['language', 'platform', 'appVersion'] as $option) {
            $value = self::trimmedString($arguments[$option] ?? null);
            if ($value !== null) {
                $options[$option] = $value;
            }
        }

        if (empty($indexHandles)) {
            $indexHandles = self::enabledIndexHandles();
        }

        if (empty($indexHandles)) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => 'No search indices configured',
            ];
        }

        $results = self::runSearch($indexHandles, $query, $options, $siteIds);

        if ((bool)($arguments['enrich'] ?? false)) {
            $results = self::enrichResults($results, $query, $indexHandles, $siteId, $arguments, $limit);
        } else {
            unset($results['meta']);
            self::stripRawHitFields($results);
        }

        $total = (int)($results['total'] ?? 0);
        $results['query'] = $query;
        $results['page'] = $page;
        $results['hitsPerPage'] = $limit;
        $results['totalPages'] = (int)ceil($total / $limit);

        if (isset($results['indices']) && is_array($results['indices'])) {
            $results['indices'] = self::normalizeIndexCounts($results['indices']);
        }

        return $results;
    }

    /**
     * Resolve an autocomplete request through the existing autocomplete service.
     *
     * @inheritdoc
     */
    public static function resolveAutocomplete(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $query = trim((string)($arguments['query'] ?? ''));
        $only = self::trimmedString($arguments['only'] ?? null);

        if ($query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return ['suggestions' => [], 'results' => []];
        }

        $limit = self::clampInt($arguments['hitsPerPage'] ?? null, 10, 1, 100);
        $siteId = self::resolveRequestedSiteId($arguments);
        if (self::hasSiteArgument($arguments) && $siteId === null) {
            return ['suggestions' => [], 'results' => []];
        }
        $siteIds = self::resolveSchemaSiteScope($siteId);
        if ($siteIds === []) {
            return ['suggestions' => [], 'results' => []];
        }

        [$indexHandles, $indicesProvided] = self::resolveIndexHandles($arguments);
        if ($indicesProvided && empty($indexHandles)) {
            return ['suggestions' => [], 'results' => []];
        }

        if (empty($indexHandles)) {
            $indexHandles = self::enabledIndexHandles();
        }

        $options = ['limit' => $limit];
        if ($siteIds === null && $siteId !== null) {
            $options['siteId'] = $siteId;
        }
        $language = self::trimmedString($arguments['language'] ?? null);
        if ($language !== null) {
            $options['language'] = $language;
        }

        $suggestions = [];
        $results = [];
        foreach ($indexHandles as $handle) {
            foreach ($siteIds ?? [null] as $scopedSiteId) {
                $siteOptions = $options;
                if ($scopedSiteId !== null) {
                    $siteOptions['siteId'] = $scopedSiteId;
                }

                if ($only !== 'results') {
                    $suggestions = array_merge($suggestions, SearchManager::$plugin->autocomplete->suggest($query, $handle, $siteOptions));
                }
                if ($only !== 'suggestions') {
                    $results = array_merge(
                        $results,
                        SearchManager::$plugin->autocomplete->suggestElements(
                            $query,
                            $handle,
                            array_merge($siteOptions, ['type' => self::trimmedString($arguments['type'] ?? null)]),
                        ),
                    );
                }
            }
        }

        return [
            'suggestions' => $only === 'results' ? [] : array_values(array_unique($suggestions)),
            'results' => $only === 'suggestions' ? [] : $results,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: array<int, string>, 1: bool}
     */
    private static function resolveIndexHandles(array $arguments): array
    {
        $indices = $arguments['indices'] ?? [];
        $indicesString = is_array($indices)
            ? implode(',', array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $indices)))
            : trim((string)$indices);

        return SearchIndex::resolveRequestedIndices(
            $indicesString,
            trim((string)($arguments['index'] ?? '')),
            self::MAX_INDICES_COUNT,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function enabledIndexHandles(): array
    {
        return array_values(array_map(
            static fn(SearchIndex $index): string => $index->handle,
            array_filter(SearchIndex::findAll(), static fn(SearchIndex $index): bool => $index->enabled),
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function resolveRequestedSiteId(array $arguments): ?int
    {
        return GqlHelper::resolveSiteId($arguments);
    }

    /**
     * Resolve the site IDs the active GraphQL schema may query.
     *
     * A null return means there is no active schema context, so direct internal
     * callers keep the historical all-sites behavior. An empty array means an
     * active schema has no readable sites and should receive no results.
     *
     * @return array<int, int>|null
     * @throws ForbiddenHttpException when an explicit site is outside schema scope
     */
    private static function resolveSchemaSiteScope(?int $requestedSiteId): ?array
    {
        try {
            Craft::$app->getGql()->getActiveSchema();
        } catch (\Throwable) {
            return null;
        }

        $allowedSiteIds = array_values(array_map(
            static fn($site): int => (int)$site->id,
            GqlHelper::getAllowedSites(),
        ));

        if ($requestedSiteId !== null) {
            if (!in_array($requestedSiteId, $allowedSiteIds, true)) {
                throw new ForbiddenHttpException('The active GraphQL schema is not allowed to query the requested site.');
            }

            return [$requestedSiteId];
        }

        return $allowedSiteIds;
    }

    /**
     * @param array<int, string> $indexHandles
     * @param array<string, mixed> $options
     * @param array<int, int>|null $siteIds
     * @return array<string, mixed>
     */
    private static function runSearch(array $indexHandles, string $query, array $options, ?array $siteIds): array
    {
        if ($siteIds === null) {
            return count($indexHandles) === 1
                ? SearchManager::$plugin->backend->search($indexHandles[0], $query, $options)
                : SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $options);
        }

        if (count($siteIds) === 1) {
            $siteOptions = array_merge($options, ['siteId' => $siteIds[0]]);

            return count($indexHandles) === 1
                ? SearchManager::$plugin->backend->search($indexHandles[0], $query, $siteOptions)
                : SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $siteOptions);
        }

        $merged = ['hits' => [], 'total' => 0, 'indices' => []];
        foreach ($siteIds as $siteId) {
            $siteOptions = array_merge($options, [
                'siteId' => $siteId,
                'limit' => (int)($options['offset'] ?? 0) + (int)($options['limit'] ?? 20),
                'offset' => 0,
            ]);
            $siteResults = count($indexHandles) === 1
                ? SearchManager::$plugin->backend->search($indexHandles[0], $query, $siteOptions)
                : SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $siteOptions);

            $merged['hits'] = array_merge($merged['hits'], is_array($siteResults['hits'] ?? null) ? $siteResults['hits'] : []);
            $merged['total'] += (int)($siteResults['total'] ?? 0);

            if (isset($siteResults['indices']) && is_array($siteResults['indices'])) {
                foreach ($siteResults['indices'] as $index => $total) {
                    if (is_string($index)) {
                        $merged['indices'][$index] = ($merged['indices'][$index] ?? 0) + (int)$total;
                    }
                }
            }
        }

        usort($merged['hits'], static function(mixed $a, mixed $b): int {
            if (!is_array($a) || !is_array($b)) {
                return 0;
            }

            return ((float)($b['score'] ?? 0)) <=> ((float)($a['score'] ?? 0));
        });

        $offset = (int)($options['offset'] ?? 0);
        $limit = (int)($options['limit'] ?? 20);
        $merged['hits'] = array_slice($merged['hits'], $offset, $limit);

        return $merged;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function hasSiteArgument(array $arguments): bool
    {
        return (isset($arguments['site']) && trim((string)$arguments['site']) !== '')
            || (isset($arguments['siteId']) && is_numeric($arguments['siteId']) && (int)$arguments['siteId'] > 0);
    }

    private static function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        $intValue = is_numeric($value) ? (int)$value : $default;
        if ($intValue < $min) {
            $intValue = $default;
        }

        return min($max, $intValue);
    }

    private static function trimmedString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Return the correct backend filter option key for a single index.
     *
     * @return array<string, string>
     */
    private static function filterOptionsForIndex(string $indexHandle, string $filters): array
    {
        $backendName = SearchManager::$plugin->backend->getBackendForIndex($indexHandle)?->getName();

        return match ($backendName) {
            'typesense' => ['filter_by' => $filters],
            'meilisearch' => ['filter' => $filters],
            default => ['filters' => $filters],
        };
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function stripRawHitFields(array &$results): void
    {
        if (empty($results['hits']) || !is_array($results['hits'])) {
            return;
        }

        foreach ($results['hits'] as &$hit) {
            if (is_array($hit)) {
                unset($hit['content'], $hit['body'], $hit['excerpt']);
            }
        }
        unset($hit);
    }

    /**
     * @param array<string, mixed> $results
     * @param array<int, string> $indexHandles
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function enrichResults(array $results, string $query, array $indexHandles, ?int $siteId, array $arguments, int $limit): array
    {
        $rawHitsByElement = self::indexRawHitsByElement($results['hits'] ?? []);
        $enrichOptions = [
            'snippetMode' => self::trimmedString($arguments['snippetMode'] ?? null) ?? 'balanced',
            'snippetLength' => self::clampInt($arguments['snippetLength'] ?? null, 150, 50, 1000),
            'showCodeSnippets' => (bool)($arguments['showCodeSnippets'] ?? false),
            'parseMarkdownSnippets' => (bool)($arguments['parseMarkdownSnippets'] ?? false),
            'hideResultsWithoutUrl' => (bool)($arguments['hideResultsWithoutUrl'] ?? false),
            'includeDebugMeta' => Craft::$app->getConfig()->getGeneral()->devMode || Craft::$app->getUser()->checkPermission('searchManager:viewDebug'),
        ];

        if ($siteId !== null) {
            $enrichOptions['siteId'] = $siteId;
        }

        try {
            $results['hits'] = SearchManager::$plugin->enrichment->enrichResults(
                $results['hits'] ?? [],
                $query,
                $indexHandles,
                $enrichOptions,
            );
        } catch (\Throwable $e) {
            Craft::error('GraphQL search enrichment failed: ' . $e->getMessage(), 'search-manager');

            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'hitsPerPage' => $limit,
                'totalPages' => 0,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode ? $e->getMessage() : 'Search enrichment failed',
            ];
        }

        self::mergeStableRawHitFields($results['hits'], $rawHitsByElement);

        if (!$enrichOptions['includeDebugMeta']) {
            unset($results['meta']);
        }

        return $results;
    }

    /**
     * @param mixed $hits
     * @return array<string, array<string, mixed>>
     */
    private static function indexRawHitsByElement(mixed $hits): array
    {
        if (!is_array($hits)) {
            return [];
        }

        $indexed = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $key = self::hitElementKey($hit);
            if ($key !== null) {
                $indexed[$key] = $hit;
            }
        }

        return $indexed;
    }

    /**
     * @param mixed $hits
     * @param array<string, array<string, mixed>> $rawHitsByElement
     */
    private static function mergeStableRawHitFields(mixed &$hits, array $rawHitsByElement): void
    {
        if (!is_array($hits) || empty($rawHitsByElement)) {
            return;
        }

        $stableKeys = [
            'objectID',
            'elementId',
            'elementType',
            '_slug',
            '_title',
            '_index',
            'siteId',
            'dateCreated',
            'dateUpdated',
            'matchedIn',
            'matchedTerms',
            'boosted',
            'promoted',
        ];

        foreach ($hits as &$hit) {
            if (!is_array($hit)) {
                continue;
            }

            $key = self::hitElementKey($hit);
            if ($key === null) {
                continue;
            }

            $rawHit = $rawHitsByElement[$key] ?? null;
            if ($rawHit === null) {
                $elementId = SearchHitIdentityHelper::elementId($hit);
                $rawHit = $elementId !== null ? ($rawHitsByElement['0:' . $elementId] ?? null) : null;
            }
            if ($rawHit === null) {
                continue;
            }

            $backendId = SearchHitIdentityHelper::rawBackendId($rawHit);
            if ($backendId !== null) {
                $hit['_backendId'] = $backendId;
            }

            foreach ($stableKeys as $stableKey) {
                if (array_key_exists($stableKey, $rawHit) && !array_key_exists($stableKey, $hit)) {
                    $hit[$stableKey] = $rawHit[$stableKey];
                }
            }
        }
        unset($hit);
    }

    /**
     * @param array<string, mixed> $hit
     */
    private static function hitElementKey(array $hit): ?string
    {
        $elementId = SearchHitIdentityHelper::elementId($hit);
        if ($elementId === null) {
            return null;
        }
        $siteId = isset($hit['siteId']) && is_numeric($hit['siteId']) ? (int)$hit['siteId'] : 0;

        return $siteId . ':' . $elementId;
    }

    /**
     * @param array<string, int>|array<int, mixed> $indices
     * @return array<int, array{index: string, total: int}>
     */
    private static function normalizeIndexCounts(array $indices): array
    {
        $normalized = [];
        foreach ($indices as $index => $total) {
            if (is_string($index)) {
                $normalized[] = ['index' => $index, 'total' => (int)$total];
            }
        }

        return $normalized;
    }
}
