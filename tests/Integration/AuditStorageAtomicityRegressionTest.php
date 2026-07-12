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
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for storage-engine atomicity audit findings.
 *
 * @since 5.53.0
 */
final class AuditStorageAtomicityRegressionTest extends TestCase
{
    private const PREFIX = 'audit-storage-atomicity-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();
        BaseConfigFileHelper::clearCache('search-manager');
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        BaseConfigFileHelper::clearCache('search-manager');
        SearchIndex::clearCache();
        parent::tearDown();
    }

    public function testDeleteDocumentByKeyUsesSamePerDocumentMutexAsIndexingPath(): void
    {
        $source = $this->readPluginSource('src/search/SearchEngine.php');
        $body = $this->methodBody($source, 'deleteDocumentByKey', 'public');

        self::assertStringContainsString('$lockName = $this->indexDocumentLockName($siteId, $documentKey);', $body);
        self::assertStringContainsString('getMutex()->acquire($lockName, 30)', $body);
        self::assertStringContainsString('finally', $body);
        self::assertStringContainsString('getMutex()->release($lockName)', $body);

        $lockPosition = strpos($body, 'getMutex()->acquire($lockName, 30)');
        $readPosition = strpos($body, '$docLength = $this->documentLength($siteId, $elementId, $documentKey);');

        self::assertIsInt($lockPosition);
        self::assertIsInt($readPosition);
        self::assertLessThan($readPosition, $lockPosition);
    }

    public function testLocalBackendDeleteWithResultUsesLockedSearchEngineExistenceDecision(): void
    {
        $source = $this->readPluginSource('src/backends/AbstractSearchEngineBackend.php');
        $body = $this->methodBody($source, 'deleteWithResult', 'public');

        self::assertStringContainsString('$success = $engine->deleteDocument($siteId, $elementId, $existed);', $body);
        self::assertStringNotContainsString('getDocumentTerms($siteId, $elementId)', $body);
        self::assertStringNotContainsString('$existed = !empty', $body);
    }

    public function testExternalDeleteWithResultLocksExistenceCheckAndDeleteTogether(): void
    {
        foreach ([
            'src/backends/AlgoliaBackend.php' => 'algoliaObjectExists',
            'src/backends/MeilisearchBackend.php' => 'meilisearchDocumentExists',
        ] as $relativePath => $existsMethod) {
            $source = $this->readPluginSource($relativePath);
            $body = $this->methodBody($source, 'deleteWithResult', 'public');

            $lockPosition = strpos($body, 'getMutex()->acquire($lockName, 30)');
            $existsPosition = strpos($body, '$this->' . $existsMethod);
            $releasePosition = strpos($body, 'getMutex()->release($lockName)');

            self::assertIsInt($lockPosition, $relativePath);
            self::assertIsInt($existsPosition, $relativePath);
            self::assertIsInt($releasePosition, $relativePath);
            self::assertLessThan($existsPosition, $lockPosition, $relativePath);
            self::assertLessThan($releasePosition, $existsPosition, $relativePath);
        }
    }

    public function testSearchEngineDeleteReportsExistenceFromInsideLockedDeletePath(): void
    {
        $storage = new RecordingStorage(
            [],
            [],
            [],
            1,
            3.0,
            [],
            ['1:312001' => ['alpha' => 1]],
            ['1:312001' => 3],
        );
        $engine = new SearchEngine($storage, 'audit_312_delete');

        $existed = null;
        self::assertTrue($engine->deleteDocumentByKey(1, 312001, '312001_1', $existed));
        self::assertTrue($existed);
        self::assertSame([[
            'siteId' => 1,
            'docLength' => 3,
            'isAddition' => false,
        ]], $storage->updateMetadataEvents);

        $existed = null;
        self::assertTrue($engine->deleteDocumentByKey(1, 312001, '312001_1', $existed));
        self::assertFalse($existed);
        self::assertCount(1, $storage->updateMetadataEvents);
    }

    public function testConfigIndexUpdateStatsUsesDbUpsertAndCanRunTwiceForFreshHandle(): void
    {
        $handle = self::PREFIX . 'config-stats';
        $this->withConfigFileIndices([
            $handle => [
                'name' => 'Audit Storage Atomicity Config Stats',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
        ]);

        $index = new SearchIndex();
        $index->handle = $handle;
        $index->name = 'Audit Storage Atomicity Config Stats';
        $index->elementType = Entry::class;
        $index->source = 'config';
        $index->enabled = true;

        self::assertTrue($index->updateStats(1));
        self::assertTrue($index->updateStats(2));

        $row = $this->fetchRow('{{%searchmanager_indices}}', ['handle' => $handle]);

        self::assertNotNull($row);
        self::assertSame(2, (int)$row['documentCount']);
        self::assertSame(1, $this->countRows('{{%searchmanager_indices}}', ['handle' => $handle]));
    }

    public function testConfigIndexUpdateStatsSourceUsesUpsertInsteadOfSelectThenInsert(): void
    {
        $source = $this->readPluginSource('src/models/SearchIndex.php');
        $body = $this->methodBody($source, 'updateStats', 'public');
        $configBranch = substr($body, 0, (int)strpos($body, '// Database indices: save stats to database'));

        self::assertStringContainsString('->upsert(', $configBranch);
        self::assertStringNotContainsString("->where(['handle' => \$this->handle])", $configBranch);
        self::assertStringNotContainsString('->insert(', $configBranch);
    }

    public function testMatchesCriteriaDoesNotUseMethodExistsQueryGuards(): void
    {
        $source = $this->readPluginSource('src/models/SearchIndex.php');
        $body = $this->methodBody($source, 'matchesCriteria', 'public');

        self::assertStringNotContainsString('method_exists($query', $body);
        self::assertStringContainsString('$query->drafts(false);', $body);
        self::assertStringContainsString('$query->revisions(false);', $body);
        self::assertStringContainsString('$query->section($this->criteria[\'sections\']);', $body);
        self::assertStringContainsString('$query->volume($this->criteria[\'volumes\']);', $body);
        self::assertStringContainsString('$query->group($this->criteria[\'groups\']);', $body);
        self::assertStringContainsString('$query->sourceHandle($this->criteria[\'sourceHandles\']);', $body);
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX])
            ->execute();
    }

    /**
     * @param array<string, mixed> $indices
     */
    private function withConfigFileIndices(array $indices): void
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);
        $cache = $property->getValue();
        $cache['search-manager'] = ['indices' => $indices];
        $property->setValue(null, $cache);

        SearchIndex::clearCache();
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility): string
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
