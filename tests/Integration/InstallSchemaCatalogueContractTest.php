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
final class InstallSchemaCatalogueContractTest extends TestCase
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

    public function testAnalyticsRefererInstallColumnAllowsLongUrls(): void
    {
        $source = $this->readPluginSource('src/migrations/Install.php');

        self::assertStringContainsString("'referer' => \$this->string(2048)->null(),", $source);
    }

    public function testInstallContainsNoRuntimeDeadLegacyTables(): void
    {
        $source = $this->readPluginSource('src/migrations/Install.php');
        $legacyTables = [
            'searchmanager_transformers',
            'searchmanager_index_queue',
            'searchmanager_index_stats',
        ];

        foreach ([
            ...$legacyTables,
            'createTransformersTable',
            'createIndexQueueTable',
            'createIndexStatsTable',
        ] as $legacySchemaToken) {
            self::assertStringNotContainsString($legacySchemaToken, $source);
        }

        foreach ([
            'searchmanager_pending_syncs',
            'searchmanager_search_documents',
            'searchmanager_analytics',
        ] as $liveTable) {
            self::assertStringContainsString($liveTable, $source);
        }

        foreach ($this->runtimePhpSources() as $file => $runtimeSource) {
            foreach ($legacyTables as $legacyTable) {
                self::assertStringNotContainsString($legacyTable, $runtimeSource, $file);
            }
        }
    }

    public function testBackendMetadataListsEverySupportedImplementation(): void
    {
        $composer = $this->readPluginSource('composer.json');
        $plugin = $this->readPluginSource('src/SearchManager.php');

        foreach (['Algolia', 'File', 'Meilisearch', 'MySQL', 'PostgreSQL', 'Redis', 'Typesense'] as $backend) {
            self::assertStringContainsString($backend, $composer);
            self::assertStringContainsString($backend, $plugin);
        }
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
}
