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
use craft\web\Request;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\analytics\AnalyticsTrackingService;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(AnalyticsTrackingService::class)]
final class AnalyticsTrackingIndexResolutionTest extends TestCase
{
    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAnalytics = true;
        $settings->enableGeoDetection = false;
        $settings->ipHashSalt = str_repeat('a', 40);

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setHostInfo('https://site.example.test');
        Craft::$app->set('request', $request);
    }

    protected function tearDown(): void
    {
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        parent::tearDown();
    }

    public function testAggregateAnalyticsScopesExcludeUnavailableIndices(): void
    {
        $healthy = 'analytics-resolution-healthy';
        $error = 'analytics-resolution-error';
        $this->withConfigFileIndices([
            $healthy => [
                'name' => 'Analytics Resolution Healthy',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
            $error => [
                'name' => 'Analytics Resolution Error',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
        ]);
        $validation = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $validation->addFinding(
            $error,
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'criteria',
            'Synthetic supported configuration error.',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new AnalyticsResolutionConfigIndexValidator($validation),
        );
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $availableIndices = SearchManager::$plugin->dependencies->getAvailableIndices();
        $availableHandles = array_map(
            static fn(SearchIndex $index): string => $index->handle,
            $availableIndices,
        );
        self::assertContains($healthy, $availableHandles);
        self::assertNotContains($error, $availableHandles);
        $healthyIndex = array_values(array_filter(
            $availableIndices,
            static fn(SearchIndex $index): bool => $index->handle === $healthy,
        ))[0];
        $this->swapPluginComponent(
            'search-manager',
            'dependencies',
            new AnalyticsResolutionDependencyService([$healthyIndex]),
        );

        $service = SearchManager::$plugin->analytics;
        $allQuery = 'analytics canonical all ' . bin2hex(random_bytes(4));
        $commaQuery = 'analytics canonical comma ' . bin2hex(random_bytes(4));
        $service->trackSearch('all', $allQuery, 1, null, 'mysql');
        $service->trackSearch("{$healthy},{$error}", $commaQuery, 1, null, 'mysql');

        $rows = (new Query())
            ->select(['query', 'indexHandle'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['query' => [$allQuery, $commaQuery]])
            ->indexBy('query')
            ->all();
        self::assertArrayHasKey($allQuery, $rows);
        self::assertArrayHasKey($commaQuery, $rows);

        $allHandles = explode(',', (string)$rows[$allQuery]['indexHandle']);
        self::assertContains($healthy, $allHandles);
        self::assertNotContains($error, $allHandles);
        self::assertSame($healthy, $rows[$commaQuery]['indexHandle']);
    }
}

/** @since 5.56.0 */
final class AnalyticsResolutionConfigIndexValidator extends ConfigIndexValidator
{
    public function __construct(
        private readonly ConfigIndexValidationResult $result,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function validate(): ConfigIndexValidationResult
    {
        return $this->result;
    }
}

/** @since 5.56.0 */
final class AnalyticsResolutionDependencyService extends DependencyService
{
    /** @param list<SearchIndex> $availableIndices */
    public function __construct(
        private readonly array $availableIndices,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /** @return list<SearchIndex> */
    public function getAvailableIndices(): array
    {
        return $this->availableIndices;
    }
}
