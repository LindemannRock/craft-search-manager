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
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit #362.
 *
 * @since 5.53.0
 */
final class AuditItem362RegressionTest extends TestCase
{
    private const USER_INDEX_HANDLE = 'test_audit_362_users';

    /**
     * @var list<int>
     */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        try {
            $this->deleteTestIndexByHandle(self::USER_INDEX_HANDLE);
            $this->deleteCreatedUsers();
        } finally {
            parent::tearDown();
        }
    }

    public function testRebuildAndExpectedCountRouteAvailabilityThroughCanonicalHelper(): void
    {
        $rebuildBody = $this->methodBody(
            $this->readPluginSource('src/jobs/RebuildIndexJob.php'),
            'rebuildSingleIndex',
        );
        $expectedCountBody = $this->methodBody(
            $this->readPluginSource('src/models/SearchIndex.php'),
            'getExpectedCount',
            'public',
        );
        $indexingBody = $this->methodBody(
            $this->readPluginSource('src/services/IndexingService.php'),
            'shouldIndexElementForSite',
            'public',
        );

        self::assertStringContainsString('SearchElementAvailabilityHelper::isSearchable($element)', $rebuildBody);
        self::assertStringContainsString('SearchElementAvailabilityHelper::applyToQuery($query, $elementType);', $expectedCountBody);
        self::assertStringContainsString('SearchElementAvailabilityHelper::isSearchable($element)', $indexingBody);
        self::assertStringNotContainsString('$element->enabled && $element->getEnabledForSite()', $rebuildBody);
        self::assertStringNotContainsString('$element instanceof \craft\elements\Entry', $rebuildBody);
        self::assertStringNotContainsString('$query->status(Entry::STATUS_LIVE);', $expectedCountBody);
    }

    public function testRebuildExcludesInactiveUsers(): void
    {
        $inactiveUser = $this->createInactiveUser();
        $this->insertTestIndex(self::USER_INDEX_HANDLE, User::class, null);

        $backend = new AuditItem362RecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        SearchManager::$plugin->getSettings()->enableCacheWarming = false;

        (new RebuildIndexJob([
            'indexHandle' => self::USER_INDEX_HANDLE,
        ]))->execute(Craft::$app->queue);

        $indexedElementIds = [];
        foreach ($backend->callsFor('batchIndex') as $call) {
            foreach ($call['items'] ?? [] as $item) {
                $indexedElementIds[] = (int)($item['elementId'] ?? $item['id'] ?? 0);
            }
        }

        self::assertNotContains((int)$inactiveUser->id, $indexedElementIds);
    }

    public function testExpectedCountExcludesInactiveUsers(): void
    {
        $this->createInactiveUser();

        $siteIds = Craft::$app->getSites()->getAllSiteIds();
        $index = new SearchIndex([
            'handle' => self::USER_INDEX_HANDLE,
            'elementType' => User::class,
            'siteId' => $siteIds,
        ]);

        $activeCount = 0;
        foreach ($siteIds as $siteId) {
            $activeCount += (int)User::find()
                ->siteId((int)$siteId)
                ->status(User::STATUS_ACTIVE)
                ->drafts(false)
                ->revisions(false)
                ->count();
        }

        self::assertSame($activeCount, $index->getExpectedCount());
    }

    public function testEntryExpectedCountStillUsesLiveStatus(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $index = new SearchIndex([
            'handle' => 'test_audit_362_entries',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
        ]);

        $liveCount = (int)Entry::find()
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE)
            ->drafts(false)
            ->revisions(false)
            ->count();

        self::assertSame($liveCount, $index->getExpectedCount());
    }

    private function createInactiveUser(): User
    {
        $suffix = bin2hex(random_bytes(6));
        $user = new User();
        $user->username = 'audit-362-' . $suffix;
        $user->email = 'audit-362-' . $suffix . '@example.test';
        $user->active = false;

        self::assertTrue(Craft::$app->getElements()->saveElement($user, false), print_r($user->getErrors(), true));
        self::assertSame(User::STATUS_INACTIVE, $user->getStatus());
        $this->createdUserIds[] = (int)$user->id;

        return $user;
    }

    private function deleteCreatedUsers(): void
    {
        foreach ($this->createdUserIds as $userId) {
            $user = User::find()
                ->id($userId)
                ->status(null)
                ->one();

            if ($user instanceof User) {
                Craft::$app->getElements()->deleteElement($user, true);
            }
        }

        $this->createdUserIds = [];
    }

    private function insertTestIndex(string $handle, string $elementType, int|array|null $siteId): void
    {
        $this->deleteTestIndexByHandle($handle);

        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Audit 362 Users',
            'handle' => $handle,
            'elementType' => $elementType,
            'siteId' => $siteId === null ? null : json_encode($siteId, JSON_THROW_ON_ERROR),
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();
    }

    private function deleteTestIndexByHandle(string $handle): void
    {
        $ids = (new Query())
            ->select('id')
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => $handle])
            ->column();

        if ($ids !== []) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $ids])
                ->execute();
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => $handle])
            ->execute();
        SearchIndex::clearCache();
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'private'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}

final class AuditItem362RecordingBackendService extends BackendService
{
    /**
     * @var list<array{method: string, indexName: string, items?: list<array<string, mixed>>}>
     */
    private array $calls = [];

    public function clearIndex(string $indexName): bool
    {
        $this->calls[] = ['method' => 'clearIndex', 'indexName' => $indexName];

        return true;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchIndex', 'indexName' => $indexName, 'items' => $items];

        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->calls[] = ['method' => 'clearSearchCache', 'indexName' => $indexName];
    }

    /**
     * @return list<array{method: string, indexName: string, items?: list<array<string, mixed>>}>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn(array $call): bool => $call['method'] === $method,
        ));
    }
}
