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
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\log\Logger;

/**
 * Focused regression coverage for audit #364.
 *
 * @since 5.53.0
 */
final class AuditItem364RegressionTest extends TestCase
{
    private const PREFIX = 'audit-item-364';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
    }

    protected function tearDown(): void
    {
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        parent::tearDown();
    }

    public function testFindByHandleLoadsConfigIndexWithArrayCriteria(): void
    {
        $handle = self::PREFIX . '-array';
        $criteria = ['section' => ['news']];
        $this->withConfigFileIndices([
            $handle => $this->configIndexDefinition(['criteria' => $criteria]),
        ]);

        $index = SearchIndex::findByHandle($handle);

        self::assertNotNull($index);
        self::assertSame($criteria, $index->criteria);
    }

    public function testFindByHandleLoadsConfigIndexWithClosureCriteria(): void
    {
        $handle = self::PREFIX . '-closure';
        $criteria = static fn($query) => $query;
        $this->withConfigFileIndices([
            $handle => $this->configIndexDefinition(['criteria' => $criteria]),
        ]);

        $index = SearchIndex::findByHandle($handle);

        self::assertNotNull($index);
        self::assertSame($criteria, $index->criteria);
    }

    public function testFindByHandleNormalizesScalarConfigCriteriaAndLogsWarning(): void
    {
        $handle = self::PREFIX . '-scalar';
        $this->withConfigFileIndices([
            $handle => $this->configIndexDefinition(['criteria' => 'not-valid']),
        ]);

        $logger = Craft::getLogger();
        $before = count($logger->messages);

        $index = SearchIndex::findByHandle($handle);

        self::assertNotNull($index);
        self::assertSame([], $index->criteria);

        $messages = array_slice($logger->messages, $before);
        $warnings = array_filter($messages, static function(array $message) use ($handle): bool {
            return ($message[1] ?? null) === Logger::LEVEL_WARNING
                && ($message[2] ?? null) === SearchManager::$plugin->id
                && str_contains((string)($message[0] ?? ''), 'Invalid criteria value in config index')
                && str_contains((string)($message[0] ?? ''), '"handle":"' . $handle . '"')
                && str_contains((string)($message[0] ?? ''), '"type":"string"');
        });

        self::assertNotEmpty($warnings, 'Malformed config criteria should emit a diagnostic warning.');
    }

    public function testConfigIndexBuildPathsShareCriteriaNormalization(): void
    {
        $source = $this->readPluginFile('src/models/SearchIndex.php');

        self::assertStringContainsString('private static function buildConfigIndexModel', $source);
        self::assertStringContainsString('private static function normalizeConfigCriteria', $source);
        self::assertStringContainsString('$model = self::buildConfigIndexModel($handle, $configData);', $source);
        self::assertStringContainsString('$model = self::buildConfigIndexModel($handle, $indexConfig);', $source);
        self::assertStringNotContainsString('$model->criteria = $configData[\'criteria\'] ?? [];', $source);
        self::assertStringNotContainsString('$model->criteria = $indexConfig[\'criteria\'] ?? [];', $source);
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
            'name' => 'Audit Item 364 Config Index',
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

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
