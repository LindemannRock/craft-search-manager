<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Composer\InstalledVersions;
use Craft;
use craft\cachecascade\CascadeCache;
use lindemannrock\base\cache\CacheBackendStatus;
use lindemannrock\base\cache\DisposableCacheStorageDecision;
use lindemannrock\base\cache\DisposableCacheStoragePresentation;
use lindemannrock\base\cache\DisposableCacheStoragePresenter;
use lindemannrock\base\cache\DisposableCacheStorageResolver;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\base\cache\ScopedCacheResult;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\CacheStorageService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\utilities\ClearSearchCache;
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

    public function testApprovedBaseCacheApisLoadFromTheDeclaredDependency(): void
    {
        $baseRoot = InstalledVersions::getInstallPath('lindemannrock/craft-plugin-base');
        self::assertIsString($baseRoot);
        $expectedBasePath = realpath($baseRoot . '/src');
        self::assertIsString($expectedBasePath);

        foreach ([
            CacheBackendStatus::class,
            DisposableCacheStorageDecision::class,
            DisposableCacheStoragePresentation::class,
            DisposableCacheStoragePresenter::class,
            DisposableCacheStorageResolver::class,
            ScopedCache::class,
            ScopedCacheResult::class,
            PluginHelper::class,
        ] as $class) {
            $filename = (new ReflectionClass($class))->getFileName();
            self::assertIsString($filename);
            self::assertStringStartsWith($expectedBasePath . DIRECTORY_SEPARATOR, (string)realpath($filename));
        }

        self::assertTrue(method_exists(PluginHelper::class, 'getApplicationCacheOrLog'));
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/cache/CacheStorageDecision.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/cache/CacheStoragePresenter.php');
    }

    public function testKnownApplicationBackendsUseCompactAccuratePresentation(): void
    {
        $redis = (new ReflectionClass(RedisCache::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(RedisCache::class, $redis);

        $cases = [
            [$redis, CacheBackendStatus::BACKEND_REDIS, 'Using Redis cache', 'Redis cache', DisposableCacheStorageDecision::PERSISTENCE_CONFIRMED],
            [new CascadeCache(), CacheBackendStatus::BACKEND_MANAGED, 'Using managed cache', 'Managed cache', DisposableCacheStorageDecision::PERSISTENCE_UNKNOWN],
            [new DbCache(), CacheBackendStatus::BACKEND_DATABASE, 'Using database cache', 'Database cache', DisposableCacheStorageDecision::PERSISTENCE_CONFIRMED],
            [new FileCache(), CacheBackendStatus::BACKEND_FILESYSTEM, 'Using filesystem cache', 'Filesystem cache', DisposableCacheStorageDecision::PERSISTENCE_CONFIRMED],
        ];

        $managedHeadingCount = 0;
        foreach ($cases as [$cache, $backend, $heading, $description, $persistence]) {
            self::assertInstanceOf(CacheInterface::class, $cache);
            [$decision, $presentation] = $this->presentation($cache, 'craft', false);

            self::assertTrue($decision->usesApplicationCache());
            self::assertSame($cache, $decision->applicationCache);
            self::assertSame($backend, $decision->backendStatus->backend);
            self::assertSame($persistence, $decision->persistenceConfidence);
            self::assertSame(DisposableCacheStorageDecision::REASON_EXPLICIT_APPLICATION_CACHE, $decision->reasonCode);
            self::assertSame($heading, $presentation->headingKey);
            self::assertSame([], $presentation->explanationKeys);
            self::assertSame('Active', $presentation->utilityValueKey);
            self::assertSame($description, $presentation->utilityDescriptionKey);
            self::assertFalse($presentation->filePathEligible);
            $managedHeadingCount += str_contains($presentation->headingKey, 'managed') ? 1 : 0;
        }

        self::assertSame(1, $managedHeadingCount);
    }

    public function testUnknownUnsuitableUnavailableAndUnknownTokenAvoidPersistenceClaims(): void
    {
        [$unknownDecision, $unknown] = $this->presentation(new PresentationUnknownCache(), 'craft', true);
        self::assertTrue($unknownDecision->usesApplicationCache());
        self::assertSame(DisposableCacheStorageDecision::PERSISTENCE_UNKNOWN, $unknownDecision->persistenceConfidence);
        self::assertSame('Using application cache', $unknown->headingKey);
        self::assertSame(['Cross-request persistence could not be confirmed.'], $unknown->explanationKeys);
        self::assertSame('Best effort', $unknown->utilityValueKey);
        self::assertSame('Application cache', $unknown->utilityDescriptionKey);
        self::assertSame('info', $unknown->statusSeverity);

        foreach ([new ArrayCache(), new FileCache()] as $cache) {
            [, $disabled] = $this->presentation($cache, 'craft', true);
            self::assertSame('Caching disabled', $disabled->headingKey);
            self::assertSame(['No suitable cross-request cache is available. Cache data is recomputed as needed.'], $disabled->explanationKeys);
            self::assertSame('Disabled', $disabled->utilityValueKey);
            self::assertSame('Recomputed as needed', $disabled->utilityDescriptionKey);
            self::assertSame('warning', $disabled->statusSeverity);
        }

        Craft::$app->set('cache', static function(): never {
            throw new \RuntimeException('Injected application-cache resolution failure.');
        });
        $unavailableDecision = (new CacheStorageService())->getStorageDecision('craft');
        self::assertTrue($unavailableDecision->isDisabled());
        self::assertSame(CacheBackendStatus::BACKEND_UNAVAILABLE, $unavailableDecision->backendStatus->backend);
        self::assertSame(DisposableCacheStorageDecision::REASON_APPLICATION_CACHE_UNSUITABLE, $unavailableDecision->reasonCode);

        $unknownToken = (new CacheStorageService())->getStorageDecision('unsupported');
        self::assertTrue($unknownToken->isDisabled());
        self::assertSame(DisposableCacheStorageDecision::REASON_UNKNOWN_CONFIGURED_TOKEN, $unknownToken->reasonCode);
    }

    public function testFileDecisionOnlyExposesPathForDurablePluginOwnedStorage(): void
    {
        [, $durable] = $this->presentation(new ArrayCache(), 'file', false);
        self::assertSame('Using file cache', $durable->headingKey);
        self::assertTrue($durable->filePathEligible);
        $durableDecision = $this->presentation(new ArrayCache(), 'file', false)[0];
        self::assertStringContainsString('/search-manager/cache/', (string)(new CacheStorageService())->getDisplayFilePath($durableDecision));

        [$ephemeralDecision, $ephemeral] = $this->presentation(new CascadeCache(), 'file', true);
        self::assertTrue($ephemeralDecision->usesApplicationCache());
        self::assertTrue($ephemeralDecision->fileStorageBypassed);
        self::assertFalse($ephemeralDecision->filePathEligible);
        self::assertSame(DisposableCacheStorageDecision::REASON_EPHEMERAL_FILE_APPLICATION_CACHE, $ephemeralDecision->reasonCode);
        self::assertSame('Using managed cache', $ephemeral->headingKey);
        self::assertSame(
            ['This host has an ephemeral filesystem, so the application cache is used automatically.'],
            $ephemeral->explanationKeys,
        );
        self::assertNull((new CacheStorageService())->getDisplayFilePath($ephemeralDecision));

        [, $unknownFallback] = $this->presentation(new PresentationUnknownCache(), 'file', true);
        self::assertSame([
            'This host has an ephemeral filesystem, so the application cache is used automatically.',
            'Cross-request persistence could not be confirmed.',
        ], $unknownFallback->explanationKeys);
    }

    public function testUtilityPresentationHandlesMultipleEnabledAndInactiveFamilies(): void
    {
        [$managedDecision] = $this->presentation(new CascadeCache(), 'craft', true);
        $presenter = new DisposableCacheStoragePresenter();
        $enabled = ['search' => true, 'autocomplete' => false, 'device' => true];

        $managed = $presenter->present($managedDecision, true);
        self::assertSame('Active', $managed->utilityValueKey);
        self::assertSame('Managed cache', $managed->utilityDescriptionKey);
        $presentFamilies = new ReflectionMethod(ClearSearchCache::class, 'presentCacheFamilies');
        self::assertSame([
            ['family' => 'search', 'label' => 'Search', 'value' => '✓'],
            ['family' => 'device', 'label' => 'Devices', 'value' => '✓'],
        ], $presentFamilies->invoke(null, $managedDecision, $enabled));

        $inactive = $presenter->present($managedDecision, false);
        self::assertSame('Inactive', $inactive->utilityValueKey);
        self::assertSame('No cache families enabled', $inactive->utilityDescriptionKey);
        self::assertSame('inactive', $inactive->utilitySeverity);
        self::assertSame([], $presentFamilies->invoke(null, $managedDecision, [
            'search' => false,
            'autocomplete' => false,
            'device' => false,
        ]));

        [$fileDecision] = $this->presentation(new ArrayCache(), 'file', false);
        self::assertSame([
            ['family' => 'search', 'label' => 'Search', 'value' => 4],
            ['family' => 'device', 'label' => 'Devices', 'value' => 2],
        ], $presentFamilies->invoke(null, $fileDecision, $enabled, ['search' => 4, 'device' => 2]));

        [$disabledDecision] = $this->presentation(new ArrayCache(), 'craft', true);
        self::assertSame([
            ['family' => 'search', 'label' => 'Search', 'value' => '—'],
            ['family' => 'device', 'label' => 'Devices', 'value' => '—'],
        ], $presentFamilies->invoke(null, $disabledDecision, $enabled));
    }

    public function testBothApplicationTokensArePreservedAcrossPresentationAndUnrelatedSaves(): void
    {
        Craft::$app->set('cache', new CascadeCache());
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new ReflectionMethod($controller, 'cacheStorageTemplateVariables');

        foreach (['redis', 'craft'] as $token) {
            $variables = $method->invoke($controller, new Settings(['cacheStorageMethod' => $token]));
            self::assertIsArray($variables);
            self::assertSame($token, $variables['applicationToken']);
            self::assertSame('Using managed cache', $variables['applicationPresentation']->headingKey);

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
        self::assertStringContainsString("'lindemannrock-base/_partials/field-cache-storage'", $settingsTemplate);
        self::assertStringContainsString('cacheStorage.applicationToken', $settingsTemplate);
        self::assertStringContainsString('cacheStorage.filePresentation', $settingsTemplate);
        self::assertStringContainsString('cacheStorage.applicationPresentation', $settingsTemplate);
        self::assertStringNotContainsString('yii\\redis\\Cache', $settingsTemplate);
        self::assertStringNotContainsString('className', $settingsTemplate);
        self::assertStringNotContainsString('Redis Not Configured', $settingsTemplate);
        self::assertStringNotContainsString('Configured choice', $settingsTemplate);
        self::assertStringNotContainsString('Effective storage', $settingsTemplate);
        self::assertStringNotContainsString('Application cache backend', $settingsTemplate);

        $utilityTemplate = $this->readPluginFile('src/templates/utilities/index.twig');
        self::assertStringContainsString("title: 'Cache Status'|t('search-manager')", $utilityTemplate);
        self::assertStringContainsString("cacheStorage.utilityValueKey|t('lindemannrock-base')", $utilityTemplate);
        self::assertStringContainsString("cacheStorage.utilityDescriptionKey|t('lindemannrock-base')", $utilityTemplate);
        self::assertStringNotContainsString('storageMethod', $utilityTemplate);
        self::assertStringNotContainsString("' (Redis)'", $utilityTemplate);
        self::assertStringNotContainsString("' (File)'", $utilityTemplate);

        $utility = $this->readPluginFile('src/utilities/ClearSearchCache.php');
        self::assertStringContainsString('$cacheStorage->getStorageDecision()', $utility);
        self::assertStringContainsString('self::presentCacheFamilies(', $utility);
        self::assertStringNotContainsString("? 'file' : 'redis'", $utility);

        $service = $this->readPluginFile('src/services/CacheStorageService.php');
        self::assertStringContainsString('new DisposableCacheStorageResolver()', $service);
        self::assertStringNotContainsString('PluginHelper::getApplicationCacheOrLog(', $service);
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
        $presentations[] = (new DisposableCacheStoragePresenter())->present(
            $this->presentation(new CascadeCache(), 'craft', false)[0],
            false,
        );

        $baseRoot = InstalledVersions::getInstallPath('lindemannrock/craft-plugin-base');
        self::assertIsString($baseRoot);
        $baseEnglish = require $baseRoot . '/src/translations/en/lindemannrock-base.php';
        self::assertIsArray($baseEnglish);
        foreach ($presentations as $presentation) {
            self::assertArrayHasKey($presentation->headingKey, $baseEnglish);
            self::assertArrayHasKey($presentation->utilityValueKey, $baseEnglish);
            self::assertArrayHasKey($presentation->utilityDescriptionKey, $baseEnglish);
            foreach ($presentation->explanationKeys as $explanationKey) {
                self::assertArrayHasKey($explanationKey, $baseEnglish);
            }
        }

        $searchEnglish = require dirname(__DIR__, 2) . '/src/translations/en/search-manager.php';
        self::assertIsArray($searchEnglish);
        foreach ([
            'Cache Storage Method',
            'Choose where disposable cache data is stored. File caching automatically uses the application cache on ephemeral hosts.',
            'File cache',
            'Application cache',
            'Using managed cache',
            'This host has an ephemeral filesystem, so the application cache is used automatically.',
            'Using Redis cache',
            'Using database cache',
            'Using file cache',
            'Using filesystem cache',
            'Using application cache',
            'Cross-request persistence could not be confirmed.',
            'Caching disabled',
            'No suitable cross-request cache is available. Values are recomputed as needed.',
            'Managed cache',
            'Redis cache',
            'Database cache',
            'Filesystem cache',
            'Best effort',
            'Recomputed as needed',
            'Inactive',
            'No cache families enabled',
            'This is being overridden by the <code>cacheStorageMethod</code> setting in <code>config/search-manager.php</code>.',
            'How to store cache data. Use Redis/Database for load-balanced or multi-server environments.',
            'Redis/Database (load-balanced, multi-server, cloud hosting)',
            'Total cached entries',
            '<strong>Cache Location:</strong> <code>{path}</code>',
            '<strong>Cache Location:</strong> Using Craft\'s configured Redis cache from <code>config/app.php</code>',
            '<strong>Redis Not Configured:</strong> To use Redis caching, install <code>yiisoft/yii2-redis</code> and configure it in <code>config/app.php</code>. <a href="https://craftcms.com/docs/5.x/reference/config/app.html#cache" target="_blank" rel="noopener">Learn more</a>',
            'Monitor search indices, clear file cache, and manage your search infrastructure.',
            'File System (default, single server)',
        ] as $retiredKey) {
            self::assertArrayNotHasKey($retiredKey, $searchEnglish);
        }

        self::assertArrayHasKey('Active', $searchEnglish);
        self::assertArrayHasKey('Disabled', $searchEnglish);
    }

    /**
     * @return array{DisposableCacheStorageDecision, DisposableCacheStoragePresentation}
     */
    private function presentation(CacheInterface $cache, string $configuredStorage, bool $ephemeral): array
    {
        Craft::$app->set('cache', $cache);
        $_SERVER['CRAFT_EPHEMERAL'] = $ephemeral;
        $storage = new CacheStorageService();
        $decision = $storage->getStorageDecision($configuredStorage);
        $presentation = (new DisposableCacheStoragePresenter())->present($decision);

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
