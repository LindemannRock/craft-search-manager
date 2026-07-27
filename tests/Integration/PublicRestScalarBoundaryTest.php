<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * @since 5.54.0
 */
final class PublicRestScalarBoundaryTest extends TestCase
{
    private const KEY_PREFIX = '__sm_a3_scalar_key__';
    private const INDEX_HANDLE = '__sm_a3_scalar_index__';
    private const API_KEY_HEADER = 'X-Search-Manager-Key';

    private bool $originalRequireApiKey;
    private bool $originalEnableAnalytics;
    private array|string $originalTrackingAllowedOrigins;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private string $originalRequestMethod = 'GET';
    private StubBackend $backend;
    private PublicRestRecordingAutocompleteService $autocomplete;
    private PublicRestRecordingAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $settings = SearchManager::$plugin->getSettings();
        $this->originalRequireApiKey = $settings->requireApiKey;
        $this->originalEnableAnalytics = $settings->enableAnalytics;
        $this->originalTrackingAllowedOrigins = $settings->trackingAllowedOrigins;
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $settings->requireApiKey = false;
        $settings->enableAnalytics = true;
        $settings->trackingAllowedOrigins = [];
        $this->purgeKeys();

        $this->backend = $this->installStubBackend();
        $this->autocomplete = new PublicRestRecordingAutocompleteService();
        $this->analytics = new PublicRestRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $this->autocomplete);
        $this->swapPluginComponent('search-manager', 'analytics', $this->analytics);
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->requireApiKey = $this->originalRequireApiKey;
        $settings->enableAnalytics = $this->originalEnableAnalytics;
        $settings->trackingAllowedOrigins = $this->originalTrackingAllowedOrigins;
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }

        $this->purgeKeys();
        parent::tearDown();
    }

    public function testEveryEndpointRejectsEveryMalformedScalarBeforeDownstreamWork(): void
    {
        $malformedValues = [
            'array' => ['value' => 'x'],
            'nested-array' => [['x']],
            'repeated-key' => ['first', 'second'],
            'object' => (object)['value' => 'x'],
        ];

        foreach ($this->endpointParameters() as $endpoint => $parameterNames) {
            foreach ($parameterNames as $parameterName) {
                foreach ($malformedValues as $shape => $malformedValue) {
                    $params = $this->validParams($endpoint);
                    $params[$parameterName] = $malformedValue;
                    $this->installRequest($endpoint, $params);

                    try {
                        $this->runBeforeAction($endpoint);
                        self::fail("{$endpoint}.{$parameterName} accepted {$shape} input.");
                    } catch (BadRequestHttpException $exception) {
                        self::assertSame(400, $exception->statusCode, "{$endpoint}.{$parameterName}.{$shape}");
                    }
                }
            }
        }

        self::assertSame([], $this->backend->calls);
        self::assertSame([], $this->autocomplete->calls);
        self::assertSame([], $this->analytics->calls);
    }

    public function testAuthenticationPrecedesMalformedScalarValidationOnEveryEndpoint(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = true;

        foreach (array_keys($this->endpointParameters()) as $endpoint) {
            $params = $this->validParams($endpoint);
            $params[$this->primaryQueryParameter($endpoint)] = ['malformed'];
            $this->installRequest($endpoint, $params);

            try {
                $this->runBeforeAction($endpoint);
                self::fail("{$endpoint} disclosed malformed-input handling before authentication.");
            } catch (UnauthorizedHttpException $exception) {
                self::assertSame(401, $exception->statusCode, $endpoint);
            }
        }

        [, $plaintext] = $this->seedKey();
        foreach (array_keys($this->endpointParameters()) as $endpoint) {
            $params = $this->validParams($endpoint);
            $params[$this->primaryQueryParameter($endpoint)] = ['malformed'];
            $this->installRequest($endpoint, $params, $plaintext);

            try {
                $this->runBeforeAction($endpoint);
                self::fail("{$endpoint} accepted malformed input after valid authentication.");
            } catch (BadRequestHttpException $exception) {
                self::assertSame(400, $exception->statusCode, $endpoint);
            }
        }

        self::assertSame([], $this->backend->calls);
        self::assertSame([], $this->autocomplete->calls);
        self::assertSame([], $this->analytics->calls);
    }

    public function testApiRateLimitAndTrackingOriginStillPrecedeMalformedValidation(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        [, $plaintext] = $this->seedKey(rateLimit: 1);
        $params = $this->validParams('search');
        $params['q'] = ['malformed'];

        $this->installRequest('search', $params, $plaintext);
        try {
            $this->runBeforeAction('search');
            self::fail('The first malformed request should consume rate capacity and then return 400.');
        } catch (BadRequestHttpException) {
            self::addToAssertionCount(1);
        }

        $this->installRequest('search', $params, $plaintext);
        try {
            $this->runBeforeAction('search');
            self::fail('The rate gate should reject the second request before scalar validation.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(429, $exception->statusCode);
        }

        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $trackingParams = $this->validParams('track-search');
        $trackingParams['q'] = ['malformed'];
        $this->installRequest('track-search', $trackingParams, origin: 'https://untrusted.example.test');

        try {
            $this->runBeforeAction('track-search');
            self::fail('The trusted-origin gate should reject before scalar validation.');
        } catch (ForbiddenHttpException $exception) {
            self::assertSame(403, $exception->statusCode);
        }
    }

    public function testEveryEndpointAcceptsStringZeroAtTheScalarBoundary(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = false;

        foreach ($this->endpointParameters() as $endpoint => $parameterNames) {
            $params = $this->validParams($endpoint);
            foreach ($parameterNames as $parameterName) {
                $params[$parameterName] = '0';
            }
            $this->installRequest($endpoint, $params);

            self::assertTrue($this->runBeforeAction($endpoint), $endpoint);
        }
    }

    public function testStandardTrackingNoOpsBeforeMalformedScalarParsing(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        foreach ([
            'track-search' => ['q' => ['malformed']],
            'track-click' => ['elementId' => ['malformed'], 'query' => ['malformed']],
        ] as $endpoint => $params) {
            $this->installRequest($endpoint, $params);
            $controller = new SearchController('search', SearchManager::$plugin);

            self::assertTrue($controller->beforeAction(new Action($endpoint, $controller)), $endpoint);
            $response = $endpoint === 'track-search'
                ? $controller->actionTrackSearch()
                : $controller->actionTrackClick();

            self::assertSame(204, $response->getStatusCode(), $endpoint);
        }

        self::assertSame([], $this->backend->calls);
        self::assertSame([], $this->autocomplete->calls);
        self::assertSame([], $this->analytics->calls);
    }

    public function testStringZeroRemainsARealQueryAcrossSearchAutocompleteAndTracking(): void
    {
        $index = new SearchIndex([
            'name' => 'A3 Scalar Index',
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => null,
            'enabled' => true,
            'source' => 'database',
        ]);

        $this->withOnlySearchIndices([$index], function(): void {
            $this->installRequest('search', [
                'q' => '0',
                'indexHandles' => self::INDEX_HANDLE,
            ]);
            $searchController = new ApiController('api', SearchManager::$plugin);
            self::assertTrue($searchController->beforeAction(new Action('search', $searchController)));
            $searchResponse = $searchController->actionSearch();
            self::assertSame(200, $searchResponse->getStatusCode());
            self::assertSame('0', $this->backend->callsFor('search')[0]['items'][0]['query'] ?? null);

            $this->installRequest('autocomplete', [
                'q' => '0',
                'indexHandles' => self::INDEX_HANDLE,
                'only' => 'suggestions',
            ]);
            $autocompleteController = new ApiController('api', SearchManager::$plugin);
            self::assertTrue($autocompleteController->beforeAction(new Action('autocomplete', $autocompleteController)));
            $autocompleteResponse = $autocompleteController->actionAutocomplete();
            self::assertSame(200, $autocompleteResponse->getStatusCode());
            self::assertSame('0', $this->autocomplete->calls[0]['query'] ?? null);
        });

        $this->installRequest('track-search', ['q' => '0']);
        $trackingController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($trackingController->beforeAction(new Action('track-search', $trackingController)));
        $trackingResponse = $trackingController->actionTrackSearch();
        self::assertTrue($trackingResponse->data['success']);
        self::assertTrue($trackingResponse->data['tracked']);
        self::assertSame('0', $this->analytics->calls[0]['query'] ?? null);

        $this->installRequest('track-click', [
            'elementId' => '1',
            'query' => '0',
        ]);
        $clickController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($clickController->beforeAction(new Action('track-click', $clickController)));
        $clickResponse = $clickController->actionTrackClick();
        self::assertTrue($clickResponse->data['success']);
    }

    public function testEmptyAndTooLongContractsRemainStable(): void
    {
        $this->installRequest('search', ['q' => '']);
        $searchController = new ApiController('api', SearchManager::$plugin);
        self::assertTrue($searchController->beforeAction(new Action('search', $searchController)));
        self::assertSame(['hits' => [], 'total' => 0], $searchController->actionSearch()->data);

        $this->installRequest('autocomplete', ['q' => '']);
        $autocompleteController = new ApiController('api', SearchManager::$plugin);
        self::assertTrue($autocompleteController->beforeAction(new Action('autocomplete', $autocompleteController)));
        self::assertSame(['suggestions' => [], 'results' => []], $autocompleteController->actionAutocomplete()->data);

        $this->installRequest('track-search', ['q' => '']);
        $trackingController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($trackingController->beforeAction(new Action('track-search', $trackingController)));
        self::assertFalse($trackingController->actionTrackSearch()->data['success']);

        $this->installRequest('track-click', [
            'elementId' => '1',
            'query' => '',
        ]);
        $clickController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($clickController->beforeAction(new Action('track-click', $clickController)));
        self::assertTrue($clickController->actionTrackClick()->data['success']);

        $tooLong = str_repeat('x', 257);
        $this->installRequest('search', ['q' => $tooLong]);
        $searchController = new ApiController('api', SearchManager::$plugin);
        self::assertTrue($searchController->beforeAction(new Action('search', $searchController)));
        self::assertArrayHasKey('error', $searchController->actionSearch()->data);

        $this->installRequest('autocomplete', ['q' => $tooLong]);
        $autocompleteController = new ApiController('api', SearchManager::$plugin);
        self::assertTrue($autocompleteController->beforeAction(new Action('autocomplete', $autocompleteController)));
        self::assertArrayHasKey('error', $autocompleteController->actionAutocomplete()->data);

        $this->installRequest('track-search', ['q' => $tooLong]);
        $trackingController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($trackingController->beforeAction(new Action('track-search', $trackingController)));
        self::assertTrue($trackingController->actionTrackSearch()->data['tracked']);
        self::assertSame(256, mb_strlen($this->analytics->calls[array_key_last($this->analytics->calls)]['query']));

        $this->installRequest('track-click', [
            'elementId' => '1',
            'query' => $tooLong,
        ]);
        $clickController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($clickController->beforeAction(new Action('track-click', $clickController)));
        self::assertTrue($clickController->actionTrackClick()->data['success']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function endpointParameters(): array
    {
        return [
            'search' => [
                'q',
                'indexHandles',
                'resultsLimit',
                'page',
                'type',
                'siteId',
                'language',
                'lang',
                'retrievableFields',
                'skipAnalytics',
                'analyticsSource',
                'platform',
                'appVersion',
                'debugEnabled',
                'snippetMode',
                'snippetMaxLength',
                'snippetIncludeCodeBlocks',
                'snippetCleanMarkdown',
                'resultsRequireUrl',
            ],
            'autocomplete' => ['q', 'indexHandles', 'resultsLimit', 'only', 'type', 'siteId', 'language', 'lang'],
            'track-search' => ['q', 'indexHandles', 'resultsCount', 'trigger', 'analyticsSource', 'widgetType', 'siteId', 'cached', 'took'],
            'track-click' => ['elementId', 'query', 'index', 'position'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validParams(string $endpoint): array
    {
        return match ($endpoint) {
            'search', 'autocomplete' => ['q' => 'valid'],
            'track-search' => ['q' => 'valid'],
            'track-click' => ['elementId' => '1', 'query' => 'valid'],
            default => throw new \InvalidArgumentException("Unsupported endpoint: {$endpoint}"),
        };
    }

    private function primaryQueryParameter(string $endpoint): string
    {
        return $endpoint === 'track-click' ? 'query' : 'q';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function installRequest(
        string $endpoint,
        array $params,
        ?string $apiKey = null,
        ?string $origin = null,
    ): void {
        $isTracking = str_starts_with($endpoint, 'track-');
        $_SERVER['REQUEST_METHOD'] = $isTracking ? 'POST' : 'GET';
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setHostInfo('https://site.example.test');
        if ($isTracking) {
            $request->setBodyParams($params);
            $request->getHeaders()->set('Accept', 'application/json');
        } else {
            $request->setQueryParams($params);
        }
        if ($apiKey !== null) {
            $request->getHeaders()->set(self::API_KEY_HEADER, $apiKey);
        }
        if ($origin !== null) {
            $request->getHeaders()->set('Origin', $origin);
        }

        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
    }

    private function runBeforeAction(string $endpoint): bool
    {
        if (str_starts_with($endpoint, 'track-')) {
            $controller = new SearchController('search', SearchManager::$plugin);
            return $controller->beforeAction(new Action($endpoint, $controller));
        }

        $controller = new ApiController('api', SearchManager::$plugin);
        return $controller->beforeAction(new Action($endpoint, $controller));
    }

    /**
     * @return array{0: ApiKey, 1: string}
     */
    private function seedKey(?int $rateLimit = null): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $key = new ApiKey();
        $key->name = self::KEY_PREFIX . StringHelper::randomString(8);
        $key->type = ApiKey::TYPE_PUBLIC;
        $key->enabled = true;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];
        $key->rateLimit = $rateLimit;
        self::assertTrue($key->save(), 'A3 scalar-boundary API key fixture must save.');

        return [$key, $generated['plaintext']];
    }

    private function purgeKeys(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::KEY_PREFIX . '%', false])
            ->execute();
    }
}

final class PublicRestRecordingAutocompleteService extends AutocompleteService
{
    /** @var list<array{method: string, query: string, indexHandle: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'suggest',
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];

        return [];
    }

    public function suggestElements(string $query, string $indexHandle, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'suggestElements',
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];

        return [];
    }
}

final class PublicRestRecordingAnalyticsService extends AnalyticsService
{
    /** @var list<array{indexHandle: string, query: string, resultsCount: int}> */
    public array $calls = [];

    public function trackSearch(
        string $indexHandle,
        string $query,
        int $resultsCount,
        ?float $executionTime,
        string $backend,
        ?int $siteId = null,
        array $analyticsOptions = [],
        ?string $sessionId = null,
    ): void {
        $this->calls[] = [
            'indexHandle' => $indexHandle,
            'query' => $query,
            'resultsCount' => $resultsCount,
        ];
    }
}
