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
final class TimestampHydrationContractTest extends TestCase
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

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }
}
