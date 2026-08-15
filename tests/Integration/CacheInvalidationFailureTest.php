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
use craft\web\Request;
use craft\web\Response;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\CacheStorageService;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\tests\Fixtures\FailingApplicationCache;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\caching\CacheInterface;

/**
 * @since 5.55.0
 */
final class CacheInvalidationFailureTest extends TestCase
{
    private CacheInterface $originalCache;
    private object $originalRequest;
    private object $originalResponse;
    private string $originalRequestMethod;
    private string $originalStorageMethod;
    private bool $originalDeviceCacheEnabled;
    private bool $hadEphemeralSetting;
    private mixed $originalEphemeralSetting;
    private mixed $originalDeviceDetector;
    /** @var array<string, true> */
    private array $originalLoggedFailures;
    private FailingApplicationCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $cache = Craft::$app->getCache();
        self::assertInstanceOf(CacheInterface::class, $cache);
        $this->originalCache = $cache;
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $this->originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = false;

        $settings = SearchManager::$plugin->getSettings();
        $this->originalStorageMethod = $settings->cacheStorageMethod;
        $this->originalDeviceCacheEnabled = (bool)$settings->cacheDeviceDetection;
        $settings->cacheStorageMethod = 'redis';
        $settings->cacheDeviceDetection = true;

        $this->cache = new FailingApplicationCache();
        Craft::$app->set('cache', $this->cache);
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');

        $this->originalDeviceDetector = $this->deviceDetectorProperty()->getValue(SearchManager::$plugin->deviceDetection);
        $loggedFailures = new \ReflectionProperty(CacheStorageService::class, 'loggedFailures');
        $value = $loggedFailures->getValue();
        self::assertIsArray($value);
        $this->originalLoggedFailures = $value;
    }

    protected function tearDown(): void
    {
        $this->deviceDetectorProperty()->setValue(
            SearchManager::$plugin->deviceDetection,
            $this->originalDeviceDetector,
        );
        (new \ReflectionProperty(CacheStorageService::class, 'loggedFailures'))
            ->setValue(null, $this->originalLoggedFailures);
        Craft::$app->set('cache', $this->originalCache);
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        if ($this->hadEphemeralSetting) {
            $_SERVER['CRAFT_EPHEMERAL'] = $this->originalEphemeralSetting;
        } else {
            unset($_SERVER['CRAFT_EPHEMERAL']);
        }

        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = $this->originalStorageMethod;
        $settings->cacheDeviceDetection = $this->originalDeviceCacheEnabled;

        parent::tearDown();
    }

    public function testSearchScopeAndFamilyInvalidationFailuresAreObservableWithoutSuccessLogs(): void
    {
        $sentinel = $this->seedSentinel();
        $offset = count(Craft::getLogger()->messages);
        $this->failFamily('search', true);

        self::assertRuntimeFailure(
            static fn() => SearchManager::$plugin->backend->clearSearchCache('content'),
            'Search cache invalidation failed.',
        );
        self::assertSame('safe', $sentinel->get('item', 'scope')->value);
        $this->assertNoLogMessageContaining($offset, 'Cleared search cache for index');

        $this->cache->failingSetKeyFragments = [];
        $offset = count(Craft::getLogger()->messages);
        $this->failFamily('search');
        self::assertRuntimeFailure(
            static fn() => SearchManager::$plugin->backend->clearAllSearchCache(),
            'Search cache invalidation failed.',
        );
        self::assertSame('safe', $sentinel->get('item', 'scope')->value);
        $this->assertNoLogMessageContaining($offset, 'Cleared all search cache');
        self::assertSame([], $this->cache->deleteKeys);
        self::assertSame(0, $this->cache->flushCalls);
    }

    public function testAutocompleteInvalidationFailureIsObservableWithoutSuccessLogs(): void
    {
        $sentinel = $this->seedSentinel();
        $offset = count(Craft::getLogger()->messages);
        $this->failFamily('autocomplete');

        self::assertRuntimeFailure(
            static fn() => SearchManager::$plugin->autocomplete->clearCache(),
            'Autocomplete cache invalidation failed.',
        );
        self::assertSame('safe', $sentinel->get('item', 'scope')->value);
        $this->assertNoLogMessageContaining($offset, 'Cleared autocomplete cache');
        self::assertSame([], $this->cache->deleteKeys);
        self::assertSame(0, $this->cache->flushCalls);
    }

    public function testDeviceInvalidationFailureResetsTheRequestLocalDetectorAndRemainsObservable(): void
    {
        $sentinel = $this->seedSentinel();
        $service = SearchManager::$plugin->deviceDetection;
        $service->detectDevice('CacheInvalidationFailure/1.0');
        self::assertNotNull($this->deviceDetectorProperty()->getValue($service));
        $this->failFamily('device');

        self::assertRuntimeFailure(
            static fn() => $service->clearCache(),
            'Device cache invalidation failed.',
        );
        self::assertNull($this->deviceDetectorProperty()->getValue($service));
        self::assertSame('safe', $sentinel->get('item', 'scope')->value);
        self::assertSame([], $this->cache->deleteKeys);
        self::assertSame(0, $this->cache->flushCalls);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function utilityFailureProvider(): iterable
    {
        yield 'search' => ['actionClearSearchCache', 'search', 'Failed to clear search cache'];
        yield 'autocomplete' => ['actionClearAutocompleteCache', 'autocomplete', 'Failed to clear autocomplete cache'];
        yield 'device' => ['actionClearDeviceCache', 'device', 'Failed to clear device cache'];
    }

    #[DataProvider('utilityFailureProvider')]
    public function testIndividualUtilityActionsReturnFailureWithoutAFalseSuccessLog(
        string $action,
        string $family,
        string $expectedError,
    ): void {
        $sentinel = $this->seedSentinel();
        $this->failFamily($family);
        $offset = count(Craft::getLogger()->messages);

        $response = (new UtilitiesController('utilities', SearchManager::$plugin))->{$action}();

        self::assertFalse($response->data['success']);
        self::assertSame($expectedError, $response->data['error']);
        self::assertSame('safe', $sentinel->get('item', 'scope')->value);
        $this->assertNoLogMessageContaining($offset, 'cache cleared via utility');
        self::assertSame([], $this->cache->deleteKeys);
        self::assertSame(0, $this->cache->flushCalls);
    }

    public function testClearAllAttemptsEveryFamilyAndReturnsFailureWhenOneInvalidationFails(): void
    {
        $families = $this->seedFamilies();
        $this->failFamily('autocomplete');
        $offset = count(Craft::getLogger()->messages);

        $response = (new UtilitiesController('utilities', SearchManager::$plugin))->actionClearAllCaches();

        self::assertFalse($response->data['success']);
        self::assertSame('Failed to clear all caches', $response->data['error']);
        self::assertTrue($families['search']->get('item', 'scope')->isMiss());
        self::assertTrue($families['autocomplete']->get('item', 'scope')->isHit());
        self::assertTrue($families['device']->get('item', 'scope')->isMiss());
        self::assertSame('safe', $families['sentinel']->get('item', 'scope')->value);
        $this->assertNoLogMessageContaining($offset, 'All caches cleared via utility');
        self::assertSame([], $this->cache->deleteKeys);
        self::assertSame(0, $this->cache->flushCalls);
    }

    /**
     * @return array<string, ScopedCache>
     */
    private function seedFamilies(): array
    {
        $families = [
            'search' => new ScopedCache($this->cache, SearchManager::$plugin->id, 'search'),
            'autocomplete' => new ScopedCache($this->cache, SearchManager::$plugin->id, 'autocomplete'),
            'device' => new ScopedCache($this->cache, SearchManager::$plugin->id, 'device'),
            'sentinel' => new ScopedCache($this->cache, 'unrelated-plugin', 'sentinel'),
        ];
        foreach ($families as $family => $cache) {
            self::assertTrue($cache->set('item', $family === 'sentinel' ? 'safe' : $family, 300, 'scope'));
        }

        return $families;
    }

    private function seedSentinel(): ScopedCache
    {
        $sentinel = new ScopedCache($this->cache, 'unrelated-plugin', 'sentinel');
        self::assertTrue($sentinel->set('item', 'safe', 300, 'scope'));

        return $sentinel;
    }

    private function failFamily(string $family, bool $scope = false): void
    {
        $this->cache->failingSetKeyFragments = [sprintf(
            ':plugin:%s:family:%s:generation:%s',
            SearchManager::$plugin->id,
            $family,
            $scope ? 'scope:' : 'global',
        )];
    }

    private static function assertRuntimeFailure(callable $callback, string $expectedMessage): void
    {
        try {
            $callback();
            self::fail('Expected cache invalidation to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame($expectedMessage, $e->getMessage());
        }
    }

    private function assertNoLogMessageContaining(int $offset, string $needle): void
    {
        foreach (array_slice(Craft::getLogger()->messages, $offset) as $message) {
            self::assertStringNotContainsString($needle, (string)($message[0] ?? ''));
        }
    }

    private function deviceDetectorProperty(): \ReflectionProperty
    {
        return new \ReflectionProperty(DeviceDetectionService::class, 'deviceDetection');
    }
}
