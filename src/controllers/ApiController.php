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
use lindemannrock\searchmanager\helpers\CanonicalHitPipeline;
use lindemannrock\searchmanager\helpers\SearchDebugAccessHelper;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;
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
    /**
     * Maximum number of indices allowed per request
     */
    private const MAX_INDICES_COUNT = 5;

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * The API key authenticated for this request, or null when enforcement is
     * off. Set in {@see beforeAction()}; consumed by the action methods to scope
     * indices and clamp hitsPerPage.
     */
    private ?ApiKey $authenticatedKey = null;

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
     * @since 5.47.0
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

        return true;
    }

    /**
     * Get autocomplete suggestions and/or element results
     *
     * GET /actions/search-manager/api/autocomplete?q=test&indices=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional)
     * - hitsPerPage: Max results (default: 10)
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
        $query = Craft::$app->getRequest()->getParam('q', '');

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'suggestions' => [],
                'results' => [],
                'error' => 'Query too long',
            ]);
        }

        $limit = (int) Craft::$app->getRequest()->getParam('hitsPerPage', 10);
        // Clamp limit to prevent expensive queries (max 100, 0 or negative = use default)
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min(100, $limit);
        // Clamp to the key's per-page cap (2b).
        if ($this->authenticatedKey !== null) {
            $limit = SearchManager::$plugin->apiKeys->clampHitsPerPage($this->authenticatedKey, $limit);
        }
        $only = Craft::$app->getRequest()->getParam('only', null);
        $typeFilter = Craft::$app->getRequest()->getParam('type', null);
        $siteId = Craft::$app->getRequest()->getParam('siteId');
        $siteId = $siteId ? (int)$siteId : null;
        // Support both 'language' and 'lang' parameters.
        $language = self::normalizePublicLanguage(
            Craft::$app->getRequest()->getParam('language') ?? Craft::$app->getRequest()->getParam('lang'),
        );

        if (empty($query)) {
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
        [$indexHandles, $indicesProvided] = SearchIndex::resolveRequestedIndices(
            Craft::$app->getRequest()->getParam('indices', ''),
            self::MAX_INDICES_COUNT,
        );

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

        // Only suggestions: return plain strings
        if ($only === 'suggestions') {
            if (count($indexHandles) === 1) {
                return $this->asJson($autocomplete->suggest($query, $indexHandles[0], $options));
            }
            if (count($indexHandles) > 1) {
                $allSuggestions = [];
                foreach ($indexHandles as $handle) {
                    $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
                }
                return $this->asJson(array_values(array_unique($allSuggestions)));
            }
            $allIndices = SearchIndex::findAll();
            $allIndexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );
            $allSuggestions = [];
            foreach ($allIndexHandles as $handle) {
                $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
            }
            return $this->asJson(array_values(array_unique($allSuggestions)));
        }

        // Only results: return element objects with type info
        if ($only === 'results') {
            $options['type'] = $typeFilter;
            if (count($indexHandles) === 1) {
                return $this->asJson($autocomplete->suggestElements($query, $indexHandles[0], $options));
            }
            if (count($indexHandles) > 1) {
                $allResults = [];
                foreach ($indexHandles as $handle) {
                    $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, $options));
                }
                return $this->asJson($this->dedupeAutocompleteResults($allResults));
            }
            $allIndices = SearchIndex::findAll();
            $allIndexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );
            $allResults = [];
            foreach ($allIndexHandles as $handle) {
                $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, $options));
            }
            return $this->asJson($this->dedupeAutocompleteResults($allResults));
        }

        // Default: return both
        if (count($indexHandles) === 1) {
            return $this->asJson([
                'suggestions' => $autocomplete->suggest($query, $indexHandles[0], $options),
                'results' => $autocomplete->suggestElements($query, $indexHandles[0], array_merge($options, ['type' => $typeFilter])),
            ]);
        }
        if (count($indexHandles) > 1) {
            $allSuggestions = [];
            $allResults = [];
            foreach ($indexHandles as $handle) {
                $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
                $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, array_merge($options, ['type' => $typeFilter])));
            }
            return $this->asJson([
                'suggestions' => array_values(array_unique($allSuggestions)),
                'results' => $this->dedupeAutocompleteResults($allResults),
            ]);
        }

        $allIndices = SearchIndex::findAll();
        $allIndexHandles = array_map(
            fn($idx) => $idx->handle,
            array_filter($allIndices, fn($idx) => $idx->enabled)
        );
        $allSuggestions = [];
        $allResults = [];
        foreach ($allIndexHandles as $handle) {
            $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
            $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, array_merge($options, ['type' => $typeFilter])));
        }
        return $this->asJson([
            'suggestions' => array_values(array_unique($allSuggestions)),
            'results' => $this->dedupeAutocompleteResults($allResults),
        ]);
    }

    /**
     * Dedupe element autocomplete results after merging multiple indices.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAutocompleteResults(array $results): array
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
     * Perform search
     *
     * GET /actions/search-manager/api/search?q=test&indices=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional)
     * - hitsPerPage: Max results per page (default: 20, min: 1, max: 200)
     * - page: Page number (0-based, default: 0)
     * - type: Filter by element type (optional, e.g., 'product', 'category', 'product,category')
     * - language: Language code for localized operators (optional, e.g., 'de', 'fr', 'es', 'ar')
     *             Supports: AND/OR/NOT in English, UND/ODER/NICHT (German), ET/OU/SAUF (French),
     *             Y/O/NO (Spanish), و/أو/ليس (Arabic). Defaults to site language.
     * - source: Analytics source identifier (optional, e.g., 'ios-app', 'android-app')
     * - platform: Platform info (optional, e.g., 'iOS 17.2', 'Android 14')
     * - appVersion: App version (optional, e.g., '2.1.0')
     * - skipAnalytics: Skip analytics tracking for this search (default: 0)
     *
     * Snippet parameters:
     * - snippetMode: Snippet positioning mode: 'early'|'balanced'|'deep' (default: 'balanced')
     * - snippetLength: Max snippet length in chars (default: 150, min: 50, max: 1000)
     * - showCodeSnippets: Include code block snippets (default: 0)
     * - parseMarkdownSnippets: Parse markdown before generating snippets (default: 0)
     * - hideResultsWithoutUrl: Exclude results that have no URL (default: 0)
     *
     * Response format:
     * - {hits: [{id, elementId, backendId, objectID, title, url, snippet, headings, fields, score, ...}, ...],
     *   total, page, hitsPerPage, totalPages}
     *
     * @return Response
     */
    public function actionSearch(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => 'Query too long (max ' . self::MAX_QUERY_LENGTH . ' characters)',
            ]);
        }

        // hitsPerPage: min 1, default 20, max 200
        $limit = (int) $request->getParam('hitsPerPage', 20);
        if ($limit < 1) {
            $limit = 20;
        }
        $limit = min(200, $limit);
        // Clamp to the key's per-page cap (2b) before deriving the offset.
        if ($this->authenticatedKey !== null) {
            $limit = SearchManager::$plugin->apiKeys->clampHitsPerPage($this->authenticatedKey, $limit);
        }
        $page = (int) $request->getParam('page', 0);
        if ($page < 0) {
            $page = 0;
        }
        $offset = $page * $limit;
        $typeFilter = $request->getParam('type', null);
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int) $siteId : null;
        $language = self::normalizePublicLanguage($request->getParam('language', null) ?? $request->getParam('lang', null));

        // Skip analytics if explicitly requested (e.g., widget passes skipAnalytics=1 to prevent keystroke spam)
        $skipAnalytics = (bool) $request->getParam('skipAnalytics', false);

        // Analytics options (for mobile apps and custom integrations).
        // This is an anonymous endpoint, so cap each value to its analytics column
        // width and strip unexpected characters — otherwise an oversized/garbage
        // value silently truncates (non-strict MySQL) or trips a caught insert error
        // (strict MySQL/PostgreSQL), losing the analytics row and adding log noise.
        $source = TrackingMetadataHelper::source($request->getParam('source', null));
        $platform = TrackingMetadataHelper::platform($request->getParam('platform', null));
        $appVersion = TrackingMetadataHelper::appVersion($request->getParam('appVersion', null));

        if (empty($query)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        // Parse and validate requested indices
        [$indexHandles, $indicesProvided] = SearchIndex::resolveRequestedIndices(
            $request->getParam('indices', ''),
            self::MAX_INDICES_COUNT,
        );

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
        ];

        // Add siteId if provided (scope search to a specific site)
        if ($siteId !== null) {
            $options['siteId'] = $siteId;
        }

        // Add language if provided (for localized boolean operators)
        if ($language !== null) {
            $options['language'] = $language;
        }

        // Add analytics options if provided
        if ($source !== null) {
            $options['source'] = $source;
        }
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

        // Run search (single, multi, or all enabled indices)
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
                    'error' => 'No search indices configured',
                ]);
            }

            $results = SearchManager::$plugin->backend->searchMultiple($allIndexHandles, $query, $options);
        }

        // Canonical REST mode: enrich is ignored and every request uses the same
        // indexed-hit response path. Keep backend meta only for the existing
        // widget/debug toolbar contract.
        if (!(bool) $request->getParam('debug', false) || !SearchDebugAccessHelper::canExposeDebugMeta()) {
            unset($results['meta']);
        }

        if (!empty($results['hits'])) {
            $results['hits'] = CanonicalHitPipeline::presentHits($results['hits'], $query, $indexHandles, [
                'snippetMode' => (string) $request->getParam('snippetMode', 'balanced'),
                'snippetLength' => (int) $request->getParam('snippetLength', 150),
                'showCodeSnippets' => (bool) $request->getParam('showCodeSnippets', false),
                'parseMarkdownSnippets' => (bool) $request->getParam('parseMarkdownSnippets', false),
                'hideResultsWithoutUrl' => (bool) $request->getParam('hideResultsWithoutUrl', false),
            ]);
        }

        $total = (int) ($results['total'] ?? 0);
        $results['page'] = $page;
        $results['hitsPerPage'] = $limit;
        $results['totalPages'] = (int) ceil($total / $limit);

        $results = SearchHitPresenter::presentResults($results);

        return $this->asJson($results);
    }
}
