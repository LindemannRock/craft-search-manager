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
use craft\cachecascade\CascadeCache;
use lindemannrock\base\cache\CacheBackendStatus;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\base\cache\ScopedCacheResult;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\cache\CacheStorageDecision;
use lindemannrock\searchmanager\cache\CacheStoragePresenter;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\CacheStorageService;
use lindemannrock\searchmanager\tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use yii\caching\ArrayCache;
use yii\caching\Cache;
use yii\caching\CacheInterface;
use yii\caching\DbCache;
use yii\caching\FileCache;
use yii\redis\Cache as RedisCache;

require_once dirname(__DIR__) . '/Fixtures/CascadeCache.php';

/**
 * Covers truthful configured and effective cache-storage presentation.
 *
 * @since 5.55.0
 */
final class CacheStoragePresentationTest extends TestCase
{
    private bool $hadEphemeralSetting;
    private mixed $originalEphemeralSetting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $this->originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = false;
    }

    protected function tearDown(): void
    {
        if ($this->hadEphemeralSetting) {
            $_SERVER['CRAFT_EPHEMERAL'] = $this->originalEphemeralSetting;
        } else {
            unset($_SERVER['CRAFT_EPHEMERAL']);
        }

        parent::tearDown();
    }

    public function testApprovedBaseCacheApisLoadFromTheLocalCandidate(): void
    {
        $expectedBasePath = realpath(dirname(__DIR__, 3) . '/base/src');
        self::assertIsString($expectedBasePath);

        foreach ([CacheBackendStatus::class, ScopedCache::class, ScopedCacheResult::class, PluginHelper::class] as $class) {
            $filename = (new ReflectionClass($class))->getFileName();
            self::assertIsString($filename);
            self::assertStringStartsWith($expectedBasePath . DIRECTORY_SEPARATOR, (string)realpath($filename));
        }

        self::assertTrue(method_exists(PluginHelper::class, 'getApplicationCacheOrLog'));
    }

    public function testKnownApplicationBackendsUseCompactAccuratePresentation(): void
    {
        $redis = (new ReflectionClass(RedisCache::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(RedisCache::class, $redis);

        $cases = [
            [$redis, CacheBackendStatus::BACKEND_REDIS, 'Using Redis cache', 'Redis cache', CacheStorageDecision::PERSISTENCE_CONFIRMED],
            [new CascadeCache(), CacheBackendStatus::BACKEND_MANAGED, 'Using managed cache', 'Managed cache', CacheStorageDecision::PERSISTENCE_UNKNOWN],
            [new DbCache(), CacheBackendStatus::BACKEND_DATABASE, 'Using database cache', 'Database cache', CacheStorageDecision::PERSISTENCE_CONFIRMED],
            [new FileCache(), CacheBackendStatus::BACKEND_FILESYSTEM, 'Using filesystem cache', 'Filesystem cache', CacheStorageDecision::PERSISTENCE_CONFIRMED],
        ];

        $managedHeadingCount = 0;
        foreach ($cases as [$cache, $backend, $heading, $description, $persistence]) {
            self::assertInstanceOf(CacheInterface::class, $cache);
            [$decision, $presentation] = $this->presentation($cache, 'craft', false);

            self::assertTrue($decision->usesApplicationCache());
            self::assertSame($cache, $decision->applicationCache);
            self::assertSame($backend, $decision->backendStatus->backend);
            self::assertSame($persistence, $decision->persistence);
            self::assertSame(CacheStorageDecision::REASON_APPLICATION, $decision->reasonCode);
            self::assertSame($heading, $presentation['heading']);
            self::assertNull($presentation['explanation']);
            self::assertSame('Active', $presentation['utilityValue']);
            self::assertSame($description, $presentation['utilityDescription']);
            self::assertNull($presentation['filePath']);
            $managedHeadingCount += str_contains($presentation['heading'], 'managed') ? 1 : 0;
        }

        self::assertSame(1, $managedHeadingCount);
    }

    public function testUnknownUnsuitableUnavailableAndUnknownTokenAvoidPersistenceClaims(): void
    {
        [$unknownDecision, $unknown] = $this->presentation(new PresentationUnknownCache(), 'craft', true);
        self::assertTrue($unknownDecision->usesApplicationCache());
        self::assertSame(CacheStorageDecision::PERSISTENCE_UNKNOWN, $unknownDecision->persistence);
        self::assertSame('Using application cache', $unknown['heading']);
        self::assertSame('Cross-request persistence could not be confirmed.', $unknown['explanation']);
        self::assertSame('Best effort', $unknown['utilityValue']);
        self::assertSame('Application cache', $unknown['utilityDescription']);
        self::assertSame('info', $unknown['statusType']);

        foreach ([new ArrayCache(), new FileCache()] as $cache) {
            [, $disabled] = $this->presentation($cache, 'craft', true);
            self::assertSame('Caching disabled', $disabled['heading']);
            self::assertSame('No suitable cross-request cache is available. Values are recomputed as needed.', $disabled['explanation']);
            self::assertSame('Disabled', $disabled['utilityValue']);
            self::assertSame('Recomputed as needed', $disabled['utilityDescription']);
            self::assertSame('warning', $disabled['statusType']);
        }

        Craft::$app->set('cache', static function(): never {
            throw new \RuntimeException('Injected application-cache resolution failure.');
        });
        $unavailableDecision = (new CacheStorageService())->getStorageDecision('craft');
        self::assertTrue($unavailableDecision->isDisabled());
        self::assertSame(CacheBackendStatus::BACKEND_UNAVAILABLE, $unavailableDecision->backendStatus->backend);
        self::assertSame(CacheStorageDecision::REASON_APPLICATION_UNSUITABLE, $unavailableDecision->reasonCode);

        $unknownToken = (new CacheStorageService())->getStorageDecision('unsupported');
        self::assertTrue($unknownToken->isDisabled());
        self::assertSame(CacheStorageDecision::REASON_UNKNOWN_TOKEN, $unknownToken->reasonCode);
    }

    public function testFileDecisionOnlyExposesPathForDurablePluginOwnedStorage(): void
    {
        [, $durable] = $this->presentation(new ArrayCache(), 'file', false);
        self::assertSame('Using file cache', $durable['heading']);
        self::assertStringContainsString('/search-manager/cache/', (string)$durable['filePath']);
        self::assertTrue($durable['usesFile']);

        [$ephemeralDecision, $ephemeral] = $this->presentation(new CascadeCache(), 'file', true);
        self::assertTrue($ephemeralDecision->usesApplicationCache());
        self::assertTrue($ephemeralDecision->fileStorageBypassed);
        self::assertFalse($ephemeralDecision->canResolveFilePath);
        self::assertSame(CacheStorageDecision::REASON_EPHEMERAL_FILE_APPLICATION, $ephemeralDecision->reasonCode);
        self::assertSame('Using managed cache', $ephemeral['heading']);
        self::assertSame(
            'This host has an ephemeral filesystem, so the application cache is used automatically.',
            $ephemeral['explanation'],
        );
        self::assertNull($ephemeral['filePath']);
        self::assertNull((new CacheStorageService())->getDisplayFilePath($ephemeralDecision));
    }

    public function testUtilityPresentationHandlesMultipleEnabledAndInactiveFamilies(): void
    {
        [$managedDecision] = $this->presentation(new CascadeCache(), 'craft', true);
        $presenter = new CacheStoragePresenter();
        $enabled = ['search' => true, 'autocomplete' => false, 'device' => true];

        $managed = $presenter->present($managedDecision, true);
        self::assertSame('Active', $managed['utilityValue']);
        self::assertSame('Managed cache', $managed['utilityDescription']);
        self::assertSame([
            ['family' => 'search', 'label' => 'Search', 'value' => '✓'],
            ['family' => 'device', 'label' => 'Devices', 'value' => '✓'],
        ], $presenter->presentFamilies($managedDecision, $enabled));

        $inactive = $presenter->present($managedDecision, false);
        self::assertSame('Inactive', $inactive['utilityValue']);
        self::assertSame('No cache families enabled', $inactive['utilityDescription']);
        self::assertSame('inactive', $inactive['utilityStatusType']);
        self::assertSame([], $presenter->presentFamilies($managedDecision, [
            'search' => false,
            'autocomplete' => false,
            'device' => false,
        ]));

        [$fileDecision] = $this->presentation(new ArrayCache(), 'file', false);
        self::assertSame([
            ['family' => 'search', 'label' => 'Search', 'value' => 4],
            ['family' => 'device', 'label' => 'Devices', 'value' => 2],
        ], $presenter->presentFamilies($fileDecision, $enabled, ['search' => 4, 'device' => 2]));

        [$disabledDecision] = $this->presentation(new ArrayCache(), 'craft', true);
        self::assertSame([
            ['family' => 'search', 'label' => 'Search', 'value' => '—'],
            ['family' => 'device', 'label' => 'Devices', 'value' => '—'],
        ], $presenter->presentFamilies($disabledDecision, $enabled));
    }

    public function testBothApplicationTokensArePreservedAcrossPresentationAndUnrelatedSaves(): void
    {
        Craft::$app->set('cache', new CascadeCache());
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new ReflectionMethod($controller, 'cacheStorageTemplateVariables');

        foreach (['redis', 'craft'] as $token) {
            $variables = $method->invoke($controller, new Settings(['cacheStorageMethod' => $token]));
            self::assertIsArray($variables);
            self::assertSame('application', $variables['selectedChoice']);
            self::assertSame($token, $variables['applicationToken']);
            self::assertSame('Using managed cache', $variables['presentations']['application']['heading']);

            $settings = Settings::loadFromDatabase();
            $settings->cacheStorageMethod = $token;
            self::assertTrue($settings->saveToDatabase(['cacheStorageMethod']));
            $settings->pluginName .= ' presentation';
            self::assertTrue($settings->saveToDatabase(['pluginName']));
            self::assertSame($token, $this->fetchSettingsRow()['cacheStorageMethod'] ?? null);
        }
    }

    public function testSettingsAndUtilityTemplatesUseStructuredBackendNeutralPresentation(): void
    {
        $settingsTemplate = $this->readPluginFile('src/templates/settings/cache.twig');
        self::assertStringContainsString("label: 'File cache'|t('search-manager')", $settingsTemplate);
        self::assertStringContainsString("label: 'Application cache'|t('search-manager')", $settingsTemplate);
        self::assertStringContainsString('cacheStorage.applicationToken', $settingsTemplate);
        self::assertStringContainsString("value === 'file' ? 'file' : 'application'", $settingsTemplate);
        self::assertStringContainsString('presentation.heading', $settingsTemplate);
        self::assertStringContainsString('presentation.explanation', $settingsTemplate);
        self::assertStringNotContainsString('yii\\redis\\Cache', $settingsTemplate);
        self::assertStringNotContainsString('className', $settingsTemplate);
        self::assertStringNotContainsString('Redis Not Configured', $settingsTemplate);
        self::assertStringNotContainsString('Configured choice', $settingsTemplate);
        self::assertStringNotContainsString('Effective storage', $settingsTemplate);
        self::assertStringNotContainsString('Application cache backend', $settingsTemplate);

        $utilityTemplate = $this->readPluginFile('src/templates/utilities/index.twig');
        self::assertStringContainsString("title: 'Cache Status'|t('search-manager')", $utilityTemplate);
        self::assertStringContainsString('cacheStorage.utilityValue', $utilityTemplate);
        self::assertStringContainsString('cacheStorage.utilityDescription', $utilityTemplate);
        self::assertStringContainsString('cacheFileCounts ? cacheFileCounts.search', $utilityTemplate);
        self::assertStringNotContainsString('storageMethod', $utilityTemplate);
        self::assertStringNotContainsString("' (Redis)'", $utilityTemplate);
        self::assertStringNotContainsString("' (File)'", $utilityTemplate);

        $utility = $this->readPluginFile('src/utilities/ClearSearchCache.php');
        self::assertStringContainsString('$cacheStorage->getStorageDecision()', $utility);
        self::assertStringContainsString('$cachePresenter->presentFamilies(', $utility);
        self::assertStringNotContainsString("? 'file' : 'redis'", $utility);

        $service = $this->readPluginFile('src/services/CacheStorageService.php');
        self::assertStringContainsString('CacheStorageDecision $decision', $service);
        self::assertStringContainsString('$cache = $decision->applicationCache;', $service);
    }

    public function testEveryDynamicPresentationStringExistsInEnglishCatalogue(): void
    {
        $presentations = [];
        foreach ([
            [new CascadeCache(), 'file', true],
            [new CascadeCache(), 'craft', false],
            [new DbCache(), 'craft', false],
            [new FileCache(), 'craft', false],
            [new PresentationUnknownCache(), 'craft', true],
            [new ArrayCache(), 'craft', true],
            [new ArrayCache(), 'file', false],
        ] as [$cache, $configured, $ephemeral]) {
            [, $presentation] = $this->presentation($cache, $configured, $ephemeral);
            $presentations[] = $presentation;
        }
        $presentations[] = (new CacheStoragePresenter())->present(
            $this->presentation(new CascadeCache(), 'craft', false)[0],
            false,
        );

        $english = require dirname(__DIR__, 2) . '/src/translations/en/search-manager.php';
        self::assertIsArray($english);
        foreach ($presentations as $presentation) {
            foreach (['heading', 'explanation', 'utilityValue', 'utilityDescription'] as $field) {
                if ($presentation[$field] !== null) {
                    self::assertArrayHasKey($presentation[$field], $english, "Missing presentation key: {$presentation[$field]}");
                }
            }
        }

        foreach ([
            'How to store cache data. Use Redis/Database for load-balanced or multi-server environments.',
            'Redis/Database (load-balanced, multi-server, cloud hosting)',
            'Total cached entries',
            '<strong>Cache Location:</strong> <code>{path}</code>',
            '<strong>Cache Location:</strong> Using Craft\'s configured Redis cache from <code>config/app.php</code>',
            '<strong>Redis Not Configured:</strong> To use Redis caching, install <code>yiisoft/yii2-redis</code> and configure it in <code>config/app.php</code>. <a href="https://craftcms.com/docs/5.x/reference/config/app.html#cache" target="_blank" rel="noopener">Learn more</a>',
            'Monitor search indices, clear file cache, and manage your search infrastructure.',
            'File System (default, single server)',
        ] as $retiredKey) {
            self::assertArrayNotHasKey($retiredKey, $english);
        }
    }

    /**
     * @return array{CacheStorageDecision, array<string, bool|string|null>}
     */
    private function presentation(CacheInterface $cache, string $configuredStorage, bool $ephemeral): array
    {
        Craft::$app->set('cache', $cache);
        $_SERVER['CRAFT_EPHEMERAL'] = $ephemeral;
        $storage = new CacheStorageService();
        $decision = $storage->getStorageDecision($configuredStorage);
        $presentation = (new CacheStoragePresenter())->present(
            $decision,
            true,
            $storage->getDisplayFilePath($decision),
        );

        return [$decision, $presentation];
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}

/**
 * Unknown application-cache implementation with no persistence claim.
 *
 * @since 5.55.0
 */
final class PresentationUnknownCache extends Cache
{
    /** @var array<string, mixed> */
    private array $values = [];

    protected function getValue($key)
    {
        return $this->values[$key] ?? false;
    }

    protected function getValues($keys)
    {
        return array_map(fn(string $key): mixed => $this->getValue($key), $keys);
    }

    protected function setValue($key, $value, $duration)
    {
        $this->values[$key] = $value;

        return true;
    }

    protected function setValues($data, $duration)
    {
        foreach ($data as $key => $value) {
            $this->values[$key] = $value;
        }

        return [];
    }

    protected function addValue($key, $value, $duration)
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    protected function deleteValue($key)
    {
        unset($this->values[$key]);

        return true;
    }

    protected function flushValues()
    {
        $this->values = [];

        return true;
    }
}
