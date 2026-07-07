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
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use yii\queue\Queue;

/**
 * Regression coverage for audit Batch 5 findings.
 *
 * @since 5.53.0
 */
final class AuditBatch5RegressionTest extends TestCase
{
    private string $handlePrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handlePrefix = 'audit-batch-5-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', 'audit-batch-5-'])
            ->execute();

        parent::tearDown();
    }

    public function testModelFromRowDatesAreParsedAsUtc(): void
    {
        foreach ([
            'src/models/SearchIndex.php',
            'src/models/ConfiguredBackend.php',
            'src/models/QueryRule.php',
            'src/models/Promotion.php',
        ] as $file) {
            $source = $this->readPluginSource($file);

            self::assertStringContainsString("new \\DateTimeZone('UTC')", $source, $file . ' should parse row dates as UTC.');
            self::assertStringNotContainsString("new \\DateTime(\$row['dateCreated'])", $source, $file);
            self::assertStringNotContainsString("new \\DateTime(\$row['dateUpdated'])", $source, $file);
        }
    }

    public function testPromotionElementIdZeroIsInvalid(): void
    {
        $promotion = new Promotion();
        $promotion->elementId = 0;

        $promotion->validateElement('elementId');

        self::assertTrue($promotion->hasErrors('elementId'));
    }

    public function testAnalyticsRefererInstallColumnAllowsLongUrls(): void
    {
        $source = $this->readPluginSource('src/migrations/Install.php');

        self::assertStringContainsString("'referer' => \$this->string(2048)->null(),", $source);
    }

    public function testTransformerTableHasNoRuntimeWritePathYet(): void
    {
        foreach ($this->runtimePhpSources() as $file => $source) {
            self::assertStringNotContainsString('searchmanager_transformers', $source, $file);
        }
    }

    public function testIndexDocumentWithResultUsesPerDocumentMutexAroundReplacement(): void
    {
        $source = $this->readPluginSource('src/search/SearchEngine.php');
        $body = $this->methodBody($source, 'indexDocumentWithResult');

        self::assertStringContainsString('$lockName = $this->indexDocumentLockName($siteId, $elementId);', $body);
        self::assertStringContainsString('getMutex()->acquire($lockName, 30)', $body);
        self::assertStringContainsString('finally', $body);
        self::assertStringContainsString('getMutex()->release($lockName)', $body);
        $lockPosition = strpos($body, 'getMutex()->acquire($lockName, 30)');
        $replacementPosition = strpos($body, '$oldDocLength = $this->storage->getDocumentLength($siteId, $elementId);');
        self::assertIsInt($lockPosition);
        self::assertIsInt($replacementPosition);
        self::assertLessThan(
            $replacementPosition,
            $lockPosition,
            'The per-document mutex must be acquired before reading/replacing old document storage.',
        );

        $lockBody = $this->methodBody($source, 'indexDocumentLockName');
        self::assertStringContainsString('search-manager:index-document:%s:%d:%d', $lockBody);
        self::assertStringContainsString('$this->indexHandle', $lockBody);
    }

    public function testBatchSchedulingSkipsDbQueueDedupeForCustomQueueDrivers(): void
    {
        $repository = new PendingSyncRepository();
        $method = new \ReflectionMethod(PendingSyncRepository::class, 'hasExistingDbQueueBatchJob');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($repository, new AuditBatch5QueueStub()));
    }

    public function testSearchIndexSaveRollsBackInsertedRowWhenSiteSaveFails(): void
    {
        $handle = $this->handlePrefix . '-partial-index';
        $index = new SearchIndex();
        $index->name = 'Audit Batch 5 Partial Index';
        $index->handle = $handle;
        $index->elementType = Entry::class;
        $index->siteId = [$this->bogusSiteId()];
        $index->source = 'database';

        self::assertFalse($index->save());
        self::assertNull($index->id);
        self::assertSame(0, $this->countRows('{{%searchmanager_indices}}', ['handle' => $handle]));
    }

    private function bogusSiteId(): int
    {
        $ids = array_map(static fn($site): int => (int)$site->id, Craft::$app->getSites()->getAllSites());

        return (empty($ids) ? 0 : max($ids)) + 1000;
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    /**
     * @return iterable<string, string>
     */
    private function runtimePhpSources(): iterable
    {
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src');
        $iterator = new \RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/migrations/')) {
                continue;
            }

            $source = file_get_contents($path);
            self::assertIsString($source);

            yield $path => $source;
        }
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/(?:public|private) function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}

final class AuditBatch5QueueStub extends Queue
{
    public function status($id): int
    {
        return self::STATUS_WAITING;
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        return 'audit-batch-5';
    }
}
