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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\log\Logger;

/**
 * Focused regression coverage for audit #382.
 *
 * @since 5.53.0
 */
final class AuditItem382RegressionTest extends TestCase
{
    private const PREFIX = 'audit-item-382';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        parent::tearDown();
    }

    public function testFindByHandleReturnsNullForMalformedConfigName(): void
    {
        $handle = self::PREFIX . '-bad-name';
        $this->withConfigFileIndices([
            $handle => $this->configIndexDefinition(['name' => ['x']]),
        ]);

        $logger = Craft::getLogger();
        $before = count($logger->messages);

        self::assertNull(SearchIndex::findByHandle($handle));
        $this->assertMalformedConfigWarningWasLogged($handle, array_slice($logger->messages, $before));
    }

    public function testFindByHandleReturnsNullForMalformedConfigHeadingLevels(): void
    {
        $handle = self::PREFIX . '-bad-heading-levels';
        $this->withConfigFileIndices([
            $handle => $this->configIndexDefinition(['headingLevels' => 3]),
        ]);

        self::assertNull(SearchIndex::findByHandle($handle));
    }

    public function testDatabaseRowScalarJsonCriteriaAndHeadingLevelsDegrade(): void
    {
        $handle = self::PREFIX . '-bad-row-json';
        $this->insertIndexRow($handle, [
            'criteria' => '"not-valid"',
            'headingLevels' => '3',
        ]);

        $index = SearchIndex::findByHandle($handle);

        self::assertNotNull($index);
        self::assertSame([], $index->criteria);
        self::assertNull($index->headingLevels);
    }

    /**
     * @param list<array<mixed>> $messages
     */
    private function assertMalformedConfigWarningWasLogged(string $handle, array $messages): void
    {
        $warnings = array_filter($messages, static function(array $message) use ($handle): bool {
            return ($message[1] ?? null) === Logger::LEVEL_WARNING
                && ($message[2] ?? null) === SearchManager::$plugin->id
                && str_contains((string)($message[0] ?? ''), 'Failed to build config index model')
                && str_contains((string)($message[0] ?? ''), '"handle":"' . $handle . '"');
        });

        self::assertNotEmpty($warnings, 'Malformed config index model builds should emit a diagnostic warning.');
    }

    /**
     * @param array<string, mixed> $indices
     */
    private function withConfigFileIndices(array $indices): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = ['indices' => $indices];
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function configIndexDefinition(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Audit Item 382 Config Index',
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => [],
            'transformer' => null,
            'headingLevels' => null,
            'language' => null,
            'backend' => null,
            'enabled' => true,
            'enableAnalytics' => true,
            'disableStopWords' => false,
            'skipEntriesWithoutUrl' => false,
            'splitSections' => false,
            'retrievableFields' => ['*'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertIndexRow(string $handle, array $overrides = []): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', array_merge([
            'name' => 'Audit Item 382 Row Index',
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => null,
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
        ], $overrides))->execute();

        SearchIndex::clearCache();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        SearchIndex::clearCache();
    }

    private function configCache(): mixed
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function setConfigCache(mixed $cache): void
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }
}
