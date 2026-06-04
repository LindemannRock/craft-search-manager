<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\ForbiddenHttpException;
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
     * Request header that carries the API key on enforced endpoints.
     */
    private const API_KEY_HEADER = 'X-Search-Manager-Key';

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
     * action on this controller requires a valid, active key in the
     * `X-Search-Manager-Key` header (401 missing/invalid, 403 disabled/expired).
     * Public keys are additionally referrer-restricted; server keys are not.
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
            $request = Craft::$app->getRequest();
            $header = $request->getHeaders()->get(self::API_KEY_HEADER);
            $key = SearchManager::$plugin->apiKeys->authenticate(is_string($header) ? $header : null);

            // Public keys are referrer-restricted (read the Referer header
            // directly rather than getReferrer() so console/test requests work);
            // server keys are trusted backend-to-backend and skip the check.
            if ($key->type === ApiKey::TYPE_PUBLIC) {
                $referer = $request->getHeaders()->get('Referer');
                if (!$key->allowsReferrer(is_string($referer) ? $referer : null)) {
                    // Raw English — JSON API response (see exception-messages.md).
                    throw new ForbiddenHttpException('Referrer not allowed for this API key.');
                }
            }

            $this->authenticatedKey = $key;
        }

        return true;
    }

    /**
     * Get autocomplete suggestions and/or element results
     *
     * GET /actions/search-manager/api/autocomplete?q=test&index=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional)
     * - index: Single index handle (legacy)
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
        // Support both 'language' and 'lang' parameters
        $language = Craft::$app->getRequest()->getParam('language') ?? Craft::$app->getRequest()->getParam('lang');

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
            Craft::$app->getRequest()->getParam('index', ''),
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
                return $this->asJson($allResults);
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
            return $this->asJson($allResults);
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
                'results' => $allResults,
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
            'results' => $allResults,
        ]);
    }

    /**
     * Perform search
     *
     * GET /actions/search-manager/api/search?q=test&index=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional)
     * - index: Single index handle (legacy)
     * - hitsPerPage: Max results per page (default: 20, min: 1, max: 200)
     * - page: Page number (0-based, default: 0)
     * - type: Filter by element type (optional, e.g., 'product', 'category', 'product,category')
     * - language: Language code for localized operators (optional, e.g., 'de', 'fr', 'es', 'ar')
     *             Supports: AND/OR/NOT in English, UND/ODER/NICHT (German), ET/OU/SAUF (French),
     *             Y/O/NO (Spanish), و/أو/ليس (Arabic). Defaults to site language.
     * - source: Analytics source identifier (optional, e.g., 'ios-app', 'android-app')
     * - platform: Platform info (optional, e.g., 'iOS 17.2', 'Android 14')
     * - appVersion: App version (optional, e.g., '2.1.0')
     * - enrich: Enable result enrichment (default: 0). When enabled, results include
     *           snippets, heading expansion, thumbnails, debug meta, and promoted/boosted flags.
     *           Response uses 'hits' key with enriched result objects.
     * - skipAnalytics: Skip analytics tracking for this search (default: 0)
     *
     * Enrichment parameters (only used when enrich=1):
     * - snippetMode: Snippet positioning mode: 'early'|'balanced'|'deep' (default: 'balanced')
     * - snippetLength: Max snippet length in chars (default: 150, min: 50, max: 1000)
     * - showCodeSnippets: Include code block snippets (default: 0)
     * - parseMarkdownSnippets: Parse markdown before generating snippets (default: 0)
     * - hideResultsWithoutUrl: Exclude results that have no URL (default: 0)
     * - debug: Include debug metadata in response (default: devMode setting)
     *
     * Response format:
     * - Raw (default): {hits: [{objectID, id, score, type}, ...], total, page, hitsPerPage, totalPages}
     * - Enriched (enrich=1): {hits: [{id, title, url, description, section, type, score, ...}, ...],
     *   total, page, hitsPerPage, totalPages, query}
     *
     * @return Response
     */
    public function actionSearch(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');
        $enrich = (bool) $request->getParam('enrich', false);

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
        $language = $request->getParam('language', null);

        // Skip analytics if explicitly requested (e.g., widget passes skipAnalytics=1 to prevent keystroke spam)
        $skipAnalytics = (bool) $request->getParam('skipAnalytics', false);

        // Analytics options (for mobile apps and custom integrations)
        $source = $request->getParam('source', null);
        $platform = $request->getParam('platform', null);
        $appVersion = $request->getParam('appVersion', null);

        if (empty($query)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        // Parse and validate requested indices
        [$indexHandles, $indicesProvided] = SearchIndex::resolveRequestedIndices(
            $request->getParam('indices', ''),
            $request->getParam('index', ''),
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

        if ($enrich) {
            // Enriched mode: resolve elements, generate snippets, expand headings
            // Debug mode: requires devMode OR searchManager:viewDebug permission
            $debugParam = $request->getParam('debug');
            $canViewDebug = Craft::$app->config->general->devMode
                || Craft::$app->getUser()->checkPermission('searchManager:viewDebug');
            $includeDebugMeta = $canViewDebug && ($debugParam !== null ? (bool) $debugParam : Craft::$app->config->general->devMode);

            $enrichOptions = [
                'snippetMode' => (string) $request->getParam('snippetMode', 'balanced'),
                'snippetLength' => (int) $request->getParam('snippetLength', 150),
                'showCodeSnippets' => (bool) $request->getParam('showCodeSnippets', false),
                'parseMarkdownSnippets' => (bool) $request->getParam('parseMarkdownSnippets', false),
                'hideResultsWithoutUrl' => (bool) $request->getParam('hideResultsWithoutUrl', false),
                'includeDebugMeta' => $includeDebugMeta,
            ];

            if ($siteId !== null) {
                $enrichOptions['siteId'] = $siteId;
            }

            try {
                $enrichedHits = SearchManager::$plugin->enrichment->enrichResults(
                    $results['hits'] ?? [],
                    $query,
                    $indexHandles,
                    $enrichOptions,
                );
            } catch (\Throwable $e) {
                Craft::error('Enrichment failed: ' . $e->getMessage(), 'search-manager');

                return $this->asJson([
                    'hits' => [],
                    'total' => 0,
                    'query' => $query,
                    'error' => Craft::$app->config->general->devMode ? $e->getMessage() : 'Search enrichment failed',
                ]);
            }

            $total = (int) ($results['total'] ?? count($enrichedHits));
            $totalPages = (int) ceil($total / $limit);

            $response = [
                'hits' => $enrichedHits,
                'total' => $total,
                'query' => $query,
                'page' => $page,
                'hitsPerPage' => $limit,
                'totalPages' => $totalPages,
            ];

            // Include debug meta only when devMode is on OR debug param explicitly set
            if ($includeDebugMeta && !empty($results['meta'])) {
                $response['meta'] = $results['meta'];
                $response['meta']['indices'] = $indexHandles ?: ['all'];
            }

            return $this->asJson($response);
        }

        // Raw mode: strip internal meta and content fields
        unset($results['meta']);

        if (!empty($results['hits'])) {
            foreach ($results['hits'] as &$hit) {
                unset($hit['content'], $hit['body'], $hit['excerpt']);
            }
            unset($hit);
        }

        $total = (int) ($results['total'] ?? 0);
        $results['page'] = $page;
        $results['hitsPerPage'] = $limit;
        $results['totalPages'] = (int) ceil($total / $limit);

        return $this->asJson($results);
    }
}
