<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\searchmanager\helpers\AutocompleteResponseHelper;
use lindemannrock\searchmanager\helpers\CanonicalHitPipeline;
use lindemannrock\searchmanager\helpers\PublicRequestScalarHelper;
use lindemannrock\searchmanager\helpers\SearchDebugAccessHelper;
use lindemannrock\searchmanager\helpers\SnippetOptionsHelper;
use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ApiKeyService;
use yii\web\Response;

/**
 * API Controller
 *
 * Provides AJAX endpoints for frontend search features
 *
 * @since 5.0.0
 */
class ApiController extends Controller
{
    /**
     * Maximum query length to prevent resource exhaustion
     */
    private const MAX_QUERY_LENGTH = 256;

    /** @var array<string, scalar|null> */
    private const AUTOCOMPLETE_PARAMETERS = [
        'q' => '',
        'indexHandles' => '',
        'resultsLimit' => 10,
        'only' => null,
        'type' => null,
        'siteId' => null,
        'language' => null,
        'lang' => null,
    ];

    /** @var array<string, scalar|null> */
    private const SEARCH_PARAMETERS = [
        'q' => '',
        'indexHandles' => '',
        'resultsLimit' => 20,
        'page' => 0,
        'type' => null,
        'siteId' => null,
        'language' => null,
        'lang' => null,
        'retrievableFields' => null,
        'skipAnalytics' => false,
        'analyticsSource' => null,
        'platform' => null,
        'appVersion' => null,
        'debugEnabled' => false,
        'snippetMode' => SnippetOptionsHelper::DEFAULT_MODE,
        'snippetMaxLength' => SnippetOptionsHelper::DEFAULT_LENGTH,
        'snippetIncludeCodeBlocks' => SnippetOptionsHelper::DEFAULT_SHOW_CODE,
        'snippetCleanMarkdown' => SnippetOptionsHelper::DEFAULT_PARSE_MARKDOWN,
        'resultsRequireUrl' => false,
    ];

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * The API key authenticated for this request, or null when enforcement is
     * off. Set in {@see beforeAction()}; consumed by the action methods to scope
     * indices and clamp resultsLimit.
     */
    private ?ApiKey $authenticatedKey = null;

    /** @var array<string, array<string, string|null>> */
    private array $normalizedParameters = [];

    /**
     * @inheritdoc
     *
     * Slice 2 enforcement gate: when the operator enables `requireApiKey`, every
     * action on this controller requires a valid, active public key in the
     * `X-Search-Manager-Key` header (401 missing/invalid, 403 disabled/expired).
     * Public keys are referrer-restricted; server keys remain for trusted
     * server-specific gates, not this public browser/headless gate.
     * When disabled (default), the endpoints stay anonymous — backward
     * compatible. `$allowAnonymous` stays true so the action is reachable; this
     * gate is the real access control (audit #16).
     *
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (SearchManager::$plugin->getSettings()->requireApiKey) {
            $headers = Craft::$app->getRequest()->getHeaders();
            $header = $headers->get(ApiKeyService::REQUEST_HEADER);
            $referer = $headers->get('Referer');
            $origin = $headers->get('Origin');
            $referrerCandidate = SearchManager::$plugin->apiKeys->referrerCandidate($referer, $origin);

            // Authenticate + public-key referrer check (shared with the tracking
            // gate). Prefer Referer, fall back to Origin for browser requests
            // where Referrer-Policy suppresses Referer.
            $key = SearchManager::$plugin->apiKeys->authenticateRequest(
                is_string($header) ? $header : null,
                $referrerCandidate,
            );

            // Per-key request cap (slice 3): 429 when the per-minute limit is hit.
            SearchManager::$plugin->apiKeys->enforceRateLimit($key);

            $this->authenticatedKey = $key;
        }

        $this->parametersForAction($action->id);

        return true;
    }

    /**
     * Get autocomplete suggestions and/or element results
     *
     * GET /actions/search-manager/api/autocomplete?q=test&indexHandles=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indexHandles: Comma-separated index handles (optional)
     * - resultsLimit: Max results (default: 10)
     * - only: Return only 'suggestions' or 'results' (optional, default returns both)
     * - type: Filter results by element type (optional, e.g., 'product', 'category')
     *
     * Response formats:
     * - Default: {suggestions: ["term1", ...], results: [{text, type, id}, ...]}
     * - only=suggestions: ["term1", "term2", ...]
     * - only=results: [{text: "Product Name", type: "product", id: 123}, ...]
     *
     * @return Response
     */
    public function actionAutocomplete(): Response
    {
        $parameters = $this->parametersForAction('autocomplete');
        $query = (string)$parameters['q'];

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'suggestions' => [],
                'results' => [],
                'error' => Craft::t('search-manager', 'Query too long'),
            ]);
        }

        $limit = (int)$parameters['resultsLimit'];
        // Clamp limit to prevent expensive queries (max 100, 0 or negative = use default)
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min(100, $limit);
        // Clamp to the key's per-page cap (2b).
        if ($this->authenticatedKey !== null) {
            $limit = SearchManager::$plugin->apiKeys->clampHitsPerPage($this->authenticatedKey, $limit);
        }
        $only = $parameters['only'];
        $typeFilter = $parameters['type'];
        $siteId = $parameters['siteId'];
        $siteId = $siteId ? (int)$siteId : null;
        // Support both 'language' and 'lang' parameters.
        $language = self::normalizePublicLanguage(
            $parameters['language'] ?? $parameters['lang'],
        );

        if (trim($query) === '') {
            if ($only === 'suggestions') {
                return $this->asJson([]);
            }
            if ($only === 'results') {
                return $this->asJson([]);
            }
            return $this->asJson([
                'suggestions' => [],
                'results' => [],
            ]);
        }

        $autocomplete = SearchManager::$plugin->autocomplete;

        // Parse and validate requested indices
        [$indexHandles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices(
            (string)$parameters['indexHandles'],
        );
        if ($exceededMax) {
            return $this->asJson([
                'suggestions' => [],
                'results' => [],
                'error' => Craft::t('search-manager', 'The indexHandles argument accepts at most {max} indices.', ['max' => SearchIndex::MAX_REQUESTED_INDICES]),
            ]);
        }

        // Apply the API key's index permission boundary (2b).
        if ($this->authenticatedKey !== null) {
            [$indexHandles, $indicesProvided] = SearchManager::$plugin->apiKeys->scopeIndices(
                $this->authenticatedKey,
                $indexHandles,
                $indicesProvided,
            );

            // Validate a requested siteId against the selected indices' scope (2c).
            if ($siteId !== null) {
                $selectedIndices = [];
                foreach ($indexHandles as $handle) {
                    $index = SearchIndex::findByHandle($handle);
                    if ($index !== null) {
                        $selectedIndices[] = $index;
                    }
                }
                SearchManager::$plugin->apiKeys->assertSiteInScope($siteId, ...$selectedIndices);
            }
        }

        // If indices were explicitly provided but none are valid/enabled, return empty
        // Don't fall back to "all enabled" - that would expose unintended results
        if ($indicesProvided && empty($indexHandles)) {
            if ($only === 'suggestions') {
                return $this->asJson([]);
            }
            if ($only === 'results') {
                return $this->asJson([]);
            }
            return $this->asJson(['suggestions' => [], 'results' => []]);
        }

        // Build options array
        $options = ['limit' => $limit];
        if ($siteId !== null) {
            $options['siteId'] = $siteId;
        }
        if ($language !== null) {
            $options['language'] = $language;
        }

        if (empty($indexHandles)) {
            $allIndices = SearchIndex::findAll();
            $indexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );
        }

        $suggestionSources = [];
        $resultSources = [];
        foreach ($indexHandles as $handle) {
            if ($only !== 'results') {
                $suggestionSources[] = $autocomplete->suggest($query, $handle, $options);
            }
            if ($only !== 'suggestions') {
                $resultSources[] = $autocomplete->suggestElements(
                    $query,
                    $handle,
                    array_merge($options, ['type' => $typeFilter]),
                );
            }
        }

        if ($only === 'suggestions') {
            return $this->asJson(AutocompleteResponseHelper::suggestions($suggestionSources, $limit));
        }
        if ($only === 'results') {
            return $this->asJson(AutocompleteResponseHelper::results($resultSources, $limit));
        }

        return $this->asJson([
            'suggestions' => AutocompleteResponseHelper::suggestions($suggestionSources, $limit),
            'results' => AutocompleteResponseHelper::results($resultSources, $limit),
        ]);
    }

    public static function normalizePublicLanguage(mixed $language): ?string
    {
        return is_string($language) ? LanguageNormalizer::normalizeOrNull($language) : null;
    }

    /**
     * Perform search
     *
     * GET /actions/search-manager/api/search?q=test&indexHandles=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indexHandles: Comma-separated index handles (optional)
     * - resultsLimit: Max results per page (default: 20, min: 1, max: 200)
     * - page: Page number (0-based, default: 0)
     * - type: Filter by element type (optional, e.g., 'product', 'category', 'product,category')
     * - language: Language code for localized operators (optional, e.g., 'de', 'fr', 'es', 'ar')
     *             Supports: AND/OR/NOT in English, UND/ODER/NICHT (German), ET/OU/SAUF (French),
     *             Y/O/NO (Spanish), و/أو/ليس (Arabic). Defaults to site language.
     * - analyticsSource: Analytics source identifier (optional, e.g., 'ios-app', 'android-app')
     * - platform: Platform info (optional, e.g., 'iOS 17.2', 'Android 14')
     * - appVersion: App version (optional, e.g., '2.1.0')
     * - skipAnalytics: Skip analytics tracking for this search (default: 0)
     * - retrievableFields: Comma-separated field handles to narrow the public hit fields payload
     *
     * Snippet parameters:
     * - snippetMode: Snippet positioning mode: 'early'|'balanced'|'deep' (default: 'balanced')
     * - snippetMaxLength: Max snippet length in chars (default: 150, min: 50, max: 1000)
     * - snippetIncludeCodeBlocks: Include code block snippets (default: 0)
     * - snippetCleanMarkdown: Clean Markdown markers from snippet display text (default: 0)
     * - resultsRequireUrl: Exclude results that have no URL (default: 0)
     *
     * Response format:
     * - {hits: [{elementId, backendId, siteId, title, url, snippet, headings, fields, score, ...}, ...],
     *   total, page, resultsLimit, totalPages}
     *
     * @return Response
     */
    public function actionSearch(): Response
    {
        $parameters = $this->parametersForAction('search');
        $query = (string)$parameters['q'];

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => Craft::t('search-manager', 'Query too long (max {max} characters)', ['max' => self::MAX_QUERY_LENGTH]),
            ]);
        }

        // resultsLimit: min 1, default 20, max 200
        $limit = (int)$parameters['resultsLimit'];
        if ($limit < 1) {
            $limit = 20;
        }
        $limit = min(200, $limit);
        // Clamp to the key's per-page cap (2b) before deriving the offset.
        if ($this->authenticatedKey !== null) {
            $limit = SearchManager::$plugin->apiKeys->clampHitsPerPage($this->authenticatedKey, $limit);
        }
        $page = (int)$parameters['page'];
        if ($page < 0) {
            $page = 0;
        }
        $offset = $page * $limit;
        $typeFilter = $parameters['type'];
        $siteId = $parameters['siteId'];
        $siteId = $siteId ? (int) $siteId : null;
        $language = self::normalizePublicLanguage($parameters['language'] ?? $parameters['lang']);
        $requestedRetrievableFields = SearchIndex::requestedRetrievableFields($parameters['retrievableFields']);

        // Skip analytics if explicitly requested (e.g., widget passes skipAnalytics=1 to prevent keystroke spam)
        $skipAnalytics = (bool)$parameters['skipAnalytics'];

        // Analytics options (for mobile apps and custom integrations).
        // Source normalization is resolved once at the final analytics writer.
        $source = $parameters['analyticsSource'];
        $platform = TrackingMetadataHelper::platform($parameters['platform']);
        $appVersion = TrackingMetadataHelper::appVersion($parameters['appVersion']);

        if (trim($query) === '') {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        // Parse and validate requested indices
        [$indexHandles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices(
            (string)$parameters['indexHandles'],
        );
        if ($exceededMax) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => Craft::t('search-manager', 'The indexHandles argument accepts at most {max} indices.', ['max' => SearchIndex::MAX_REQUESTED_INDICES]),
            ]);
        }

        // Apply the API key's index permission boundary (2b): rejects an
        // out-of-scope explicit index (403), or scopes an unscoped request to
        // the key's own allowed indices.
        if ($this->authenticatedKey !== null) {
            [$indexHandles, $indicesProvided] = SearchManager::$plugin->apiKeys->scopeIndices(
                $this->authenticatedKey,
                $indexHandles,
                $indicesProvided,
            );

            // Validate a requested siteId against the selected indices' scope (2c).
            // Keyed requests only; siteId stays a filter, never a permission widener.
            if ($siteId !== null) {
                $selectedIndices = [];
                foreach ($indexHandles as $handle) {
                    $index = SearchIndex::findByHandle($handle);
                    if ($index !== null) {
                        $selectedIndices[] = $index;
                    }
                }
                SearchManager::$plugin->apiKeys->assertSiteInScope($siteId, ...$selectedIndices);
            }
        }

        // If indices were explicitly provided but none are valid/enabled, return empty
        // Don't fall back to "all enabled" - that would expose unintended results
        if ($indicesProvided && empty($indexHandles)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        $options = [
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'type' => $typeFilter,
            'skipAnalytics' => $skipAnalytics,
            'source' => $source,
            'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,
        ];

        // Add siteId if provided (scope search to a specific site)
        if ($siteId !== null) {
            $options['siteId'] = $siteId;
        }

        // Add language if provided (for localized boolean operators)
        if ($language !== null) {
            $options['language'] = $language;
        }

        // Add optional analytics metadata.
        if ($platform !== null) {
            $options['platform'] = $platform;
        }
        if ($appVersion !== null) {
            $options['appVersion'] = $appVersion;
        }

        // Attribute the analytics row to the authenticated key (slice 5).
        // No-op for anonymous requests (returns an empty array).
        $options = array_merge(
            $options,
            SearchManager::$plugin->apiKeys->attributionOptions($this->authenticatedKey),
        );
        $options['_captureWidgetCacheTelemetry'] = true;
        $options['retrievableFieldsByIndex'] = SearchIndex::retrievableFieldsByIndex($indexHandles, $requestedRetrievableFields);

        // Run search (single, multi, or all enabled indices)
        $searchedIndexHandles = $indexHandles;
        if (count($indexHandles) === 1) {
            $results = SearchManager::$plugin->backend->search($indexHandles[0], $query, $options);
        } elseif (count($indexHandles) > 1) {
            $results = SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $options);
        } else {
            // No indices specified - search all enabled indices
            $allIndices = SearchIndex::findAll();
            $allIndexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );

            if (empty($allIndexHandles)) {
                return $this->asJson([
                    'hits' => [],
                    'total' => 0,
                    'error' => Craft::t('search-manager', 'No search indices configured'),
                ]);
            }

            $searchedIndexHandles = $allIndexHandles;
            $options['retrievableFieldsByIndex'] = SearchIndex::retrievableFieldsByIndex($allIndexHandles, $requestedRetrievableFields);
            $results = SearchManager::$plugin->backend->searchMultiple($allIndexHandles, $query, $options);
        }

        $cacheOutcomes = $this->widgetCacheOutcomes($results, $searchedIndexHandles);
        unset($results['_widgetCacheOutcomes']);

        // Present every result through the canonical indexed-hit response path.
        // Keep backend meta only for the existing authorized debug-toolbar contract.
        $results = SearchDebugAccessHelper::filterDebugMeta(
            $results,
            (bool)$parameters['debugEnabled'],
        );

        if (!empty($results['hits'])) {
            $results['hits'] = CanonicalHitPipeline::presentHits($results['hits'], $query, $searchedIndexHandles, [
                'snippetMode' => (string)$parameters['snippetMode'],
                'snippetMaxLength' => (int)$parameters['snippetMaxLength'],
                'snippetIncludeCodeBlocks' => (bool)$parameters['snippetIncludeCodeBlocks'],
                'snippetCleanMarkdown' => (bool)$parameters['snippetCleanMarkdown'],
                'resultsRequireUrl' => (bool)$parameters['resultsRequireUrl'],
                'retrievableFieldsByIndex' => SearchIndex::retrievableFieldsByIndex($searchedIndexHandles, $requestedRetrievableFields),
            ]);
        }

        $total = (int) ($results['total'] ?? 0);
        $results['page'] = $page;
        $results['resultsLimit'] = $limit;
        $results['totalPages'] = (int) ceil($total / $limit);

        $cacheTelemetry = SearchManager::$plugin->widgetCacheTelemetry->issue(
            $query,
            $siteId,
            $searchedIndexHandles,
            count(is_array($results['hits'] ?? null) ? $results['hits'] : []),
            $cacheOutcomes,
        );
        if ($cacheTelemetry !== null) {
            $results['cacheTelemetry'] = $cacheTelemetry;
        }

        return $this->asJson($results);
    }

    /**
     * @param array<string, mixed> $results
     * @param list<string> $indexHandles
     * @return array<string, array{cached: bool|null, duration: float|null}>
     */
    private function widgetCacheOutcomes(array $results, array $indexHandles): array
    {
        $outcomes = [];
        $multiOutcomes = is_array($results['_widgetCacheOutcomes'] ?? null)
            ? $results['_widgetCacheOutcomes']
            : [];
        $singleMeta = count($indexHandles) === 1 && is_array($results['meta'] ?? null)
            ? $results['meta']
            : [];

        foreach ($indexHandles as $indexHandle) {
            $raw = is_array($multiOutcomes[$indexHandle] ?? null)
                ? $multiOutcomes[$indexHandle]
                : $singleMeta;
            $cached = is_bool($raw['cached'] ?? null) ? $raw['cached'] : null;
            $outcomes[$indexHandle] = [
                'cached' => $cached,
                'duration' => $cached === false && is_numeric($raw['duration'] ?? $raw['took'] ?? null)
                    ? (float)($raw['duration'] ?? $raw['took'])
                    : null,
            ];
        }

        return $outcomes;
    }

    /**
     * @return array<string, string|null>
     */
    private function parametersForAction(string $actionId): array
    {
        if (isset($this->normalizedParameters[$actionId])) {
            return $this->normalizedParameters[$actionId];
        }

        $defaults = match ($actionId) {
            'autocomplete' => self::AUTOCOMPLETE_PARAMETERS,
            'search' => self::SEARCH_PARAMETERS,
            default => [],
        };

        return $this->normalizedParameters[$actionId] = PublicRequestScalarHelper::normalize(
            Craft::$app->getRequest(),
            $defaults,
        );
    }
}
