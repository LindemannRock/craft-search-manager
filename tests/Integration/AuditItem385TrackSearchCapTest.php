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
use craft\web\Response;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\tests\TestCase;
use yii\web\HeaderCollection;

/**
 * Regression coverage for audit #385.
 *
 * `track-search` accepted an unbounded, duplicate-preserving `indexHandles`
 * list on the anonymous path (the #381 cap only ran inside the `requireApiKey`
 * gate, which defaults off), driving one analytics write per repeated handle.
 * The fix dedupes inside {@see SearchIndex::resolveRequestedIndices()} — so
 * every caller including `searchMultiple()` gets set semantics — and routes
 * `actionTrackSearch()` through it, fail-closed on overflow.
 *
 * @since 5.53.0
 */
final class AuditItem385TrackSearchCapTest extends TestCase
{
    private const ERROR_MESSAGE = 'The indexHandles argument accepts at most 5 indices.';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private bool $originalRequireApiKey = false;
    private bool $originalEnableAnalytics = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        $this->originalEnableAnalytics = SearchManager::$plugin->getSettings()->enableAnalytics;
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        SearchManager::$plugin->getSettings()->enableAnalytics = true;
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = $this->originalRequireApiKey;
        SearchManager::$plugin->getSettings()->enableAnalytics = $this->originalEnableAnalytics;
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        parent::tearDown();
    }

    // ---- resolveRequestedIndices() set semantics ----------------------------

    public function testResolveRequestedIndicesDedupesDuplicateHandles(): void
    {
        $indices = $this->fakeIndices(2);

        $this->withOnlySearchIndices($indices, function (): void {
            [$handles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices(
                'audit-385-index-1,audit-385-index-1,audit-385-index-2',
            );

            self::assertTrue($indicesProvided);
            self::assertFalse($exceededMax);
            self::assertSame(['audit-385-index-1', 'audit-385-index-2'], $handles);
        });
    }

    public function testResolveRequestedIndicesDedupesBeforeApplyingCap(): void
    {
        $indices = $this->fakeIndices(1);

        $this->withOnlySearchIndices($indices, function (): void {
            [$handles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices(
                implode(',', array_fill(0, SearchIndex::MAX_REQUESTED_INDICES + 1, 'audit-385-index-1')),
            );

            self::assertTrue($indicesProvided);
            self::assertFalse($exceededMax, 'Repeats of one handle are a set of one — must not trip the cap.');
            self::assertSame(['audit-385-index-1'], $handles);
        });
    }

    // ---- Anonymous track-search: cap + dedup --------------------------------

    public function testTrackSearchRejectsTooManyDistinctIndexHandles(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES + 1);
        $spy = $this->installAnalyticsSpy();
        $this->installTrackingRequest([
            'q' => '__sm_audit385_overflow',
            'indexHandles' => implode(',', array_map(static fn(SearchIndex $index): string => $index->handle, $indices)),
        ]);

        $response = $this->withOnlySearchIndices($indices, fn(): Response => $this->runTrackSearch());

        self::assertSame(['success' => false, 'error' => self::ERROR_MESSAGE], $response->data);
        self::assertSame([], $spy->trackSearchCalls, 'Overflow must fail closed before any analytics write.');
    }

    public function testTrackSearchTracksDuplicateHandlesOnce(): void
    {
        $indices = $this->fakeIndices(1);
        $spy = $this->installAnalyticsSpy();
        $this->installTrackingRequest([
            'q' => '__sm_audit385_dupes',
            'indexHandles' => 'audit-385-index-1,audit-385-index-1,audit-385-index-1',
        ]);

        $response = $this->withOnlySearchIndices($indices, fn(): Response => $this->runTrackSearch());

        self::assertSame(['success' => true, 'tracked' => true], $response->data);
        self::assertCount(1, $spy->trackSearchCalls, 'Duplicate handles must produce exactly one analytics write.');
        self::assertSame('audit-385-index-1', $spy->trackSearchCalls[0]['handle']);
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * @return list<SearchIndex>
     */
    private function fakeIndices(int $count): array
    {
        $indices = [];
        for ($i = 1; $i <= $count; $i++) {
            $indices[] = new SearchIndex([
                'name' => 'Audit 385 Index ' . $i,
                'handle' => 'audit-385-index-' . $i,
                'elementType' => Entry::class,
                'enabled' => true,
            ]);
        }

        return $indices;
    }

    /**
     * Swap in an analytics spy that records trackSearch() calls instead of
     * writing rows. Auto-restored in tearDown by the base class.
     */
    private function installAnalyticsSpy(): AnalyticsService
    {
        $spy = new class extends AnalyticsService {
            /** @var list<array{handle: string, query: string, sessionId: ?string}> */
            public array $trackSearchCalls = [];

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
                $this->trackSearchCalls[] = [
                    'handle' => $indexHandle,
                    'query' => $query,
                    'sessionId' => $sessionId,
                ];
            }
        };
        $this->swapPluginComponent('search-manager', 'analytics', $spy);

        return $spy;
    }

    private function runTrackSearch(): Response
    {
        return (new SearchController('search', SearchManager::$plugin))->actionTrackSearch();
    }

    /**
     * @param array<string,string> $params
     */
    private function installTrackingRequest(array $params): void
    {
        Craft::$app->set('response', new Response());
        Craft::$app->set('request', new class($params) extends \craft\console\Request {
            private HeaderCollection $headers;

            /** @param array<string,string> $params */
            public function __construct(private array $params)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
            }

            public function getHeaders(): HeaderCollection
            {
                return $this->headers;
            }

            public function getParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getIsPost(): bool
            {
                return true;
            }

            public function getAcceptsJson(): bool
            {
                return true;
            }

            public function validateCsrfToken($clientSuppliedToken = null): bool
            {
                return true;
            }

            public function hasValidSiteToken(): bool
            {
                return false;
            }
        });
    }
}
