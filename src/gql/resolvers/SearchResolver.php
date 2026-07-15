<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\gql\resolvers;

use Craft;
use craft\gql\base\Resolver;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\base\helpers\GqlHelper;
use lindemannrock\searchmanager\helpers\CanonicalHitPipeline;
use lindemannrock\searchmanager\helpers\SearchFilterExpressionHelper;
use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\LanguageNormalizer;
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
                'error' => Craft::t('search-manager', 'Query too long (max {max} characters)', ['max' => self::MAX_QUERY_LENGTH]),
            ];
        }

        $limit = self::clampInt($arguments['resultsLimit'] ?? null, 20, 1, 200);
        $page = self::clampInt($arguments['page'] ?? null, 0, 0, PHP_INT_MAX);
        $offset = $page * $limit;

        $siteId = self::resolveRequestedSiteId($arguments);
        if (self::hasSiteArgument($arguments) && $siteId === null) {
            return ['hits' => [], 'total' => 0, 'query' => $query, 'page' => $page, 'resultsLimit' => $limit, 'totalPages' => 0];
        }
        $siteIds = self::resolveSchemaSiteScope($siteId);
        if ($siteIds === []) {
            return ['hits' => [], 'total' => 0, 'query' => $query, 'page' => $page, 'resultsLimit' => $limit, 'totalPages' => 0];
        }

        [$indexHandles, $indicesProvided, $exceededMax] = self::resolveIndexHandles($arguments);
        if ($exceededMax) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'page' => $page,
                'resultsLimit' => $limit,
                'totalPages' => 0,
                'error' => Craft::t('search-manager', 'The indexHandles argument accepts at most {max} indices.', ['max' => SearchIndex::MAX_REQUESTED_INDICES]),
            ];
        }
        if ($indicesProvided && empty($indexHandles)) {
            return ['hits' => [], 'total' => 0, 'query' => $query, 'page' => $page, 'resultsLimit' => $limit, 'totalPages' => 0];
        }

        $filters = self::trimmedString($arguments['filters'] ?? null);
        if ($filters !== null && count($indexHandles) !== 1) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'page' => $page,
                'resultsLimit' => $limit,
                'totalPages' => 0,
                'error' => Craft::t('search-manager', 'The filters argument requires a single index.'),
            ];
        }
        if ($filters !== null && SearchFilterExpressionHelper::normalizeExpression($filters) === null) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'page' => $page,
                'resultsLimit' => $limit,
                'totalPages' => 0,
                'error' => Craft::t('search-manager', 'The filters argument is not a valid filter expression.'),
            ];
        }

        $options = [
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'type' => self::trimmedString($arguments['type'] ?? null),
            'skipAnalytics' => (bool)($arguments['skipAnalytics'] ?? false),
            'source' => TrackingMetadataHelper::source(self::trimmedString($arguments['analyticsSource'] ?? null)) ?? 'graphql',
        ];

        if ($filters !== null && count($indexHandles) === 1) {
            $options = array_merge($options, self::filterOptionsForIndex($indexHandles[0], $filters));
        }

        if ($siteIds === null && $siteId !== null) {
            $options['siteId'] = $siteId;
        }
        $language = self::normalizePublicLanguage($arguments['language'] ?? $arguments['lang'] ?? null);
        if ($language !== null) {
            $options['language'] = $language;
        }
        // Cap analytics tracking params to their DB column widths (audit #189, mirrors #180):
        // source/platform VARCHAR(50), appVersion VARCHAR(20). Prevents silent truncation
        // (non-strict MySQL) or a caught-and-logged lost-analytics insert (strict MySQL/PostgreSQL).
        $platform = TrackingMetadataHelper::platform(self::trimmedString($arguments['platform'] ?? null));
        if ($platform !== null) {
            $options['platform'] = $platform;
        }
        $appVersion = TrackingMetadataHelper::appVersion(self::trimmedString($arguments['appVersion'] ?? null));
        if ($appVersion !== null) {
            $options['appVersion'] = $appVersion;
        }

        if (empty($indexHandles)) {
            $indexHandles = self::enabledIndexHandles();
        }
        $requestedRetrievableFields = SearchIndex::requestedRetrievableFields($arguments['retrievableFields'] ?? null);

        if (empty($indexHandles)) {
            return [
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => Craft::t('search-manager', 'No search indices configured'),
            ];
        }
        $options['retrievableFieldsByIndex'] = SearchIndex::retrievableFieldsByIndex($indexHandles, $requestedRetrievableFields);

        $results = self::runSearch($indexHandles, $query, $options, $siteIds);
        unset($results['meta']);

        if (!empty($results['hits']) && is_array($results['hits'])) {
            $results['hits'] = CanonicalHitPipeline::presentHits($results['hits'], $query, $indexHandles, [
                'snippetMode' => self::trimmedString($arguments['snippetMode'] ?? null) ?? 'balanced',
                'snippetMaxLength' => self::clampInt($arguments['snippetMaxLength'] ?? null, 150, 50, 1000),
                'snippetIncludeCodeBlocks' => (bool)($arguments['snippetIncludeCodeBlocks'] ?? false),
                'snippetCleanMarkdown' => (bool)($arguments['snippetCleanMarkdown'] ?? false),
                'resultsRequireUrl' => (bool)($arguments['resultsRequireUrl'] ?? false),
                'retrievableFieldsByIndex' => SearchIndex::retrievableFieldsByIndex($indexHandles, $requestedRetrievableFields),
            ]);
        }

        $total = (int)($results['total'] ?? 0);
        $results['query'] = $query;
        $results['page'] = $page;
        $results['resultsLimit'] = $limit;
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

        $limit = self::clampInt($arguments['resultsLimit'] ?? null, 10, 1, 100);
        $siteId = self::resolveRequestedSiteId($arguments);
        if (self::hasSiteArgument($arguments) && $siteId === null) {
            return ['suggestions' => [], 'results' => []];
        }
        $siteIds = self::resolveSchemaSiteScope($siteId);
        if ($siteIds === []) {
            return ['suggestions' => [], 'results' => []];
        }

        [$indexHandles, $indicesProvided, $exceededMax] = self::resolveIndexHandles($arguments);
        if ($exceededMax) {
            return [
                'suggestions' => [],
                'results' => [],
                'error' => Craft::t('search-manager', 'The indexHandles argument accepts at most {max} indices.', ['max' => SearchIndex::MAX_REQUESTED_INDICES]),
            ];
        }
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
        $language = self::normalizePublicLanguage($arguments['language'] ?? $arguments['lang'] ?? null);
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
            'results' => $only === 'suggestions' ? [] : self::dedupeAutocompleteResults($results),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function dedupeAutocompleteResults(array $results): array
    {
        $seen = [];
        $deduped = [];

        foreach ($results as $result) {
            $key = implode(':', [
                (string)($result['siteId'] ?? ''),
                (string)($result['id'] ?? ''),
                (string)($result['type'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $result;
        }

        return $deduped;
    }

    public static function normalizePublicLanguage(mixed $language): ?string
    {
        return is_string($language) ? LanguageNormalizer::normalizeOrNull($language) : null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: array<int, string>, 1: bool, 2: bool}
     */
    private static function resolveIndexHandles(array $arguments): array
    {
        $indices = $arguments['indexHandles'] ?? [];
        $indicesString = is_array($indices)
            ? implode(',', array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $indices)))
            : trim((string)$indices);

        return SearchIndex::resolveRequestedIndices($indicesString);
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
                throw new ForbiddenHttpException(Craft::t('search-manager', 'The active GraphQL schema is not allowed to query the requested site.'));
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
