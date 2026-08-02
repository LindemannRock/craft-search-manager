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
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
final class A10Fs2PromotionApplicationTruthTest extends TestCase
{
    private const PREFIX = '__sm_a10_fs2_';

    private ?object $originalRequest = null;
    private ?string $runtimeIndexHandle = null;
    private ?bool $originalIndexAnalytics = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->runtimeIndexHandle !== null) {
                SearchManager::$plugin->backend->clearSearchCache($this->runtimeIndexHandle);
            }

            $this->purgeFixtures();
            if ($this->runtimeIndexHandle !== null && $this->originalIndexAnalytics !== null) {
                Craft::$app->getDb()->createCommand()->update(
                    '{{%searchmanager_indices}}',
                    ['enableAnalytics' => (int)$this->originalIndexAnalytics],
                    ['handle' => $this->runtimeIndexHandle],
                )->execute();
                SearchIndex::clearCache();
            }

            if ($this->originalRequest !== null) {
                Craft::$app->set('request', $this->originalRequest);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testOutcomeDeduplicatesBySearchedSiteIdentityAndKeepsDeterministicPositionOrder(): void
    {
        $stub = $this->installStubBackend();
        foreach ([101, 202] as $elementId) {
            $stub->documentsByElementId['test-index:' . $elementId . ':7'] = [
                'id' => $elementId,
                'elementId' => $elementId,
                'siteId' => 7,
                'title' => 'Promotion ' . $elementId,
                'url' => '/promotion-' . $elementId,
                'type' => 'entry',
            ];
        }

        $winner = $this->promotion(10, 101, 1);
        $samePositionDistinctTarget = $this->promotion(20, 202, 1);
        $duplicateLaterPosition = $this->promotion(30, 101, 3);
        $missingTarget = $this->promotion(40, 303, 2);

        $outcome = (new PromotionService())->applyPromotionsWithOutcome(
            [['id' => 999, 'elementId' => 999, 'siteId' => 7, 'type' => 'entry']],
            self::PREFIX . 'order',
            'test-index',
            7,
            [$duplicateLaterPosition, $samePositionDistinctTarget, $missingTarget, $winner],
        );

        self::assertSame([101, 202, 999], array_column($outcome['hits'], 'elementId'));
        self::assertSame([10, 20], array_map(static fn(Promotion $promotion): ?int => $promotion->id, $outcome['presentedPromotions']));
        self::assertSame([1, 1], array_column($outcome['hits'], 'position'));
        self::assertSame([101, 202, 303], $stub->callsFor('getDocumentsByElementIds')[0]['items'][0]['elementIds']);
    }

    public function testOutcomeReturnsOnlyPromotionsThatSurviveTheFinalTypeFilter(): void
    {
        $stub = $this->installStubBackend();
        $stub->documentsByElementId['test-index:101:7'] = [
            'id' => 101,
            'elementId' => 101,
            'siteId' => 7,
            'type' => 'entry',
        ];
        $stub->documentsByElementId['test-index:202:7'] = [
            'id' => 202,
            'elementId' => 202,
            'siteId' => 7,
            'type' => 'asset',
        ];

        $outcome = (new PromotionService())->applyPromotionsWithOutcome(
            [],
            self::PREFIX . 'types',
            'test-index',
            7,
            [$this->promotion(10, 101, 1), $this->promotion(20, 202, 2), $this->promotion(30, 303, 3)],
            static fn(array $hits): array => array_values(array_filter(
                $hits,
                static fn(array $hit): bool => ($hit['type'] ?? null) === 'entry',
            )),
        );

        self::assertSame([101], array_column($outcome['hits'], 'elementId'));
        self::assertSame([10], array_map(static fn(Promotion $promotion): ?int => $promotion->id, $outcome['presentedPromotions']));
    }

    public function testPublicHitsOnlyContractAndSplitPageShapingRemainCompatible(): void
    {
        $stub = $this->installStubBackend();
        $stub->documentsByElementId['test-index:101:7'] = [
            'id' => 101,
            'elementId' => 101,
            'siteId' => 7,
            'title' => 'Split page',
            'url' => '/split-page',
            'type' => 'entry',
            'sectionId' => 'intro',
            'sectionType' => 'intro',
            'sectionBody' => 'Private section body',
        ];

        $hits = (new PromotionService())->applyPromotions(
            [],
            self::PREFIX . 'split',
            'test-index',
            7,
            [$this->promotion(10, 101, 1)],
        );

        self::assertIsArray($hits);
        self::assertCount(1, $hits);
        self::assertSame('promoted-page', $hits[0]['sectionId']);
        self::assertSame('promoted-page', $hits[0]['sectionType']);
        self::assertSame('101_7_promoted-page', $hits[0]['backendId']);
        self::assertArrayNotHasKey('sectionBody', $hits[0]);
    }

    public function testEmptyOrganicFreshAndCachedSearchesUseOnlyThePresentedOverlapWinnerEverywhere(): void
    {
        [$index, $element] = $this->workingIndexAndElement();
        $query = self::PREFIX . 'overlap_' . StringHelper::randomString(12);
        $winnerId = $this->seedPromotion($index, $element, $query, 'exact', 1, $element->siteId);
        $this->seedPromotion($index, $element, self::PREFIX . 'overlap_', 'prefix', 2, null);
        $this->seedPromotion(null, $element, 'overlap_', 'contains', 3, null);

        $counter = (object)['searches' => 0];
        $this->installPipelineBackend(
            $index,
            [
                'hits' => [],
                'total' => 0,
            ],
            [
                (int)$element->id => $this->indexedDocument($element, 'entry'),
            ],
            $counter,
        );

        $options = [
            'siteId' => (int)$element->siteId,
            'type' => 'entry',
            'a10Fs2Nonce' => StringHelper::UUID(),
        ];
        $fresh = SearchManager::$plugin->backend->search($index->handle, $query, $options);
        $cached = SearchManager::$plugin->backend->search($index->handle, $query, $options);

        self::assertFalse($fresh['meta']['cached']);
        self::assertTrue($cached['meta']['cached']);
        self::assertSame(1, $counter->searches, 'The second response must come from the raw-result cache.');
        self::assertSame(0, $fresh['total']);
        self::assertSame(0, $cached['total']);
        self::assertSame([(int)$element->id], array_column($fresh['hits'], 'elementId'));
        self::assertSame($fresh['hits'], $cached['hits']);
        self::assertSame([$winnerId], array_column($fresh['meta']['promotionsMatched'], 'id'));
        self::assertSame($fresh['meta']['promotionsMatched'], $cached['meta']['promotionsMatched']);

        $analytics = $this->analyticsRows($query);
        self::assertCount(2, $analytics);
        self::assertSame([0, 0], array_map('intval', array_column($analytics, 'resultsCount')));
        self::assertSame([0, 0], array_map('intval', array_column($analytics, 'isHit')));
        self::assertSame([1, 1], array_map('intval', array_column($analytics, 'promotionsShown')));
        self::assertSame([$winnerId, $winnerId], array_map('intval', array_column($this->promotionAnalyticsRows($query), 'promotionId')));
        self::assertNotContains($query, $this->contentGapQueries((int)$element->siteId));
    }

    public function testNonemptyOrganicSearchPreservesOrganicTotalAndPromotionPosition(): void
    {
        [$index, $element] = $this->workingIndexAndElement();
        $query = self::PREFIX . 'organic_' . StringHelper::randomString(12);
        $promotionId = $this->seedPromotion($index, $element, $query, 'exact', 2, null);
        $counter = (object)['searches' => 0];
        $this->installPipelineBackend(
            $index,
            [
                'hits' => [
                    ['id' => 9001, 'elementId' => 9001, 'siteId' => (int)$element->siteId, 'type' => 'entry'],
                    ['id' => 9002, 'elementId' => 9002, 'siteId' => (int)$element->siteId, 'type' => 'entry'],
                ],
                'total' => 7,
            ],
            [(int)$element->id => $this->indexedDocument($element, 'entry')],
            $counter,
        );

        $result = SearchManager::$plugin->backend->search($index->handle, $query, [
            'siteId' => (int)$element->siteId,
            'a10Fs2Nonce' => StringHelper::UUID(),
        ]);

        self::assertSame(7, $result['total']);
        self::assertSame([9001, (int)$element->id, 9002], array_column($result['hits'], 'elementId'));
        self::assertSame([$promotionId], array_column($result['meta']['promotionsMatched'], 'id'));
    }

    #[DataProvider('nonPresentedPromotionProvider')]
    public function testMissingAndTypeFilteredTargetsRemainContentGaps(string $documentType, bool $provideDocument, ?string $typeFilter): void
    {
        [$index, $element] = $this->workingIndexAndElement();
        $query = self::PREFIX . 'gap_' . StringHelper::randomString(12);
        $promotionId = $this->seedPromotion($index, $element, $query, 'exact', 1, null);
        $counter = (object)['searches' => 0];
        $documents = $provideDocument
            ? [(int)$element->id => $this->indexedDocument($element, $documentType)]
            : [];
        $this->installPipelineBackend($index, ['hits' => [], 'total' => 0], $documents, $counter);

        $options = [
            'siteId' => (int)$element->siteId,
            'a10Fs2Nonce' => StringHelper::UUID(),
        ];
        if ($typeFilter !== null) {
            $options['type'] = $typeFilter;
        }

        $result = SearchManager::$plugin->backend->search($index->handle, $query, $options);

        self::assertSame([], $result['hits']);
        self::assertSame([], $result['meta']['promotionsMatched']);
        self::assertSame(0, (int)$this->analyticsRows($query)[0]['promotionsShown']);
        self::assertSame([], array_map('intval', array_column($this->promotionAnalyticsRows($query), 'promotionId')));
        self::assertContains($query, $this->contentGapQueries((int)$element->siteId));
        self::assertNotSame(0, $promotionId, 'Fixture sanity: the matching promotion was persisted.');
    }

    public static function nonPresentedPromotionProvider(): array
    {
        return [
            'missing indexed target' => ['entry', false, null],
            'target removed by supported type filter' => ['asset', true, 'entry'],
        ];
    }

    public function testStandardSearchRemainsPromotionFree(): void
    {
        [$index, $element] = $this->workingIndexAndElement();
        $query = self::PREFIX . 'standard_' . StringHelper::randomString(12);
        $this->seedPromotion($index, $element, $query, 'exact', 1, null);
        $counter = (object)['searches' => 0];
        $this->installPipelineBackend(
            $index,
            ['hits' => [], 'total' => 0],
            [(int)$element->id => $this->indexedDocument($element, 'entry')],
            $counter,
        );
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        $result = SearchManager::$plugin->backend->search($index->handle, $query, [
            'siteId' => (int)$element->siteId,
            'skipAnalytics' => true,
            'a10Fs2Nonce' => StringHelper::UUID(),
        ]);

        self::assertSame([], $result['hits']);
        self::assertSame([], $result['meta']['promotionsMatched']);
    }

    private function workingIndexAndElement(): array
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            self::markTestSkipped('Requires one available Entry index with a matching element.');
        }

        return $pair;
    }

    /**
     * @param array<string, mixed> $searchResponse
     * @param array<int, array<string, mixed>> $documents
     */
    private function installPipelineBackend(SearchIndex $index, array $searchResponse, array $documents, object $counter): void
    {
        $this->runtimeIndexHandle = $index->handle;
        $this->originalIndexAnalytics ??= $index->enableAnalytics;
        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_indices}}',
            ['enableAnalytics' => 1],
            ['handle' => $index->handle],
        )->execute();
        SearchIndex::clearCache();

        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAnalytics = true;
        $settings->enableCache = true;
        $settings->enableGeoDetection = false;

        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
        }
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));

        $backend = $this->createMock(BackendInterface::class);
        $backend->method('getName')->willReturn('a10-fs2');
        $backend->method('search')->willReturnCallback(static function() use ($searchResponse, $counter): array {
            $counter->searches++;

            return $searchResponse;
        });
        $backend->method('getDocumentsByElementIds')->willReturnCallback(
            static function(string $indexName, array $elementIds, ?int $siteId = null) use ($documents): array {
                return array_intersect_key($documents, array_flip(array_map('intval', $elementIds)));
            },
        );

        $this->swapPluginComponent('search-manager', 'backend', new A10Fs2PipelineBackendService($backend));
        SearchManager::$plugin->backend->clearSearchCache($index->handle);
    }

    private function seedPromotion(
        ?SearchIndex $index,
        ElementInterface $element,
        string $query,
        string $matchType,
        int $position,
        ?int $siteId,
    ): int {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => $index?->handle,
            'title' => self::PREFIX . StringHelper::randomString(12),
            'query' => $query,
            'matchType' => $matchType,
            'elementId' => (int)$element->id,
            'elementType' => get_class($element),
            'position' => $position,
            'siteId' => $siteId,
            'enabled' => 1,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function indexedDocument(ElementInterface $element, string $type): array
    {
        return [
            'id' => (int)$element->id,
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'title' => 'Indexed promotion target',
            'url' => '/indexed-promotion-target',
            'type' => $type,
        ];
    }

    private function promotion(int $id, int $elementId, int $position): Promotion
    {
        $promotion = new Promotion();
        $promotion->id = $id;
        $promotion->query = self::PREFIX . 'direct';
        $promotion->elementId = $elementId;
        $promotion->position = $position;

        return $promotion;
    }

    private function analyticsRows(string $query): array
    {
        return (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['query' => $query])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function promotionAnalyticsRows(string $query): array
    {
        return (new Query())
            ->from('{{%searchmanager_promotion_analytics}}')
            ->where(['query' => $query])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function contentGapQueries(int $siteId): array
    {
        return array_column(SearchManager::$plugin->analytics->getZeroResultClusters($siteId, 'last30days', 100), 'representative');
    }

    private function purgeFixtures(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotion_analytics}}', ['like', 'query', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['like', 'query', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'title', self::PREFIX . '%', false])
            ->execute();
    }
}

/**
 * @since 5.54.0
 */
final class A10Fs2PipelineBackendService extends BackendService
{
    public function __construct(private readonly BackendInterface $backend)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->backend;
    }
}
