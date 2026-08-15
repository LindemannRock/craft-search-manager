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
use craft\web\Request;
use craft\web\Response;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\caching\CacheInterface;

require_once dirname(__DIR__) . '/Fixtures/CascadeCache.php';

/**
 * @since 5.55.0
 */
#[CoversClass(UtilitiesController::class)]
final class UtilitiesCacheClearTest extends TestCase
{
    private CacheInterface $originalCache;
    private object $originalRequest;
    private object $originalResponse;
    private string $originalRequestMethod;
    private string $originalStorageMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $cache = Craft::$app->getCache();
        self::assertInstanceOf(CacheInterface::class, $cache);
        $this->originalCache = $cache;
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $settings = SearchManager::$plugin->getSettings();
        $this->originalStorageMethod = $settings->cacheStorageMethod;
        $settings->cacheStorageMethod = 'redis';
        Craft::$app->set('cache', new CascadeCache());
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    protected function tearDown(): void
    {
        Craft::$app->set('cache', $this->originalCache);
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        SearchManager::$plugin->getSettings()->cacheStorageMethod = $this->originalStorageMethod;

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function individualClearProvider(): iterable
    {
        yield 'search' => ['actionClearSearchCache', 'search'];
        yield 'autocomplete' => ['actionClearAutocompleteCache', 'autocomplete'];
        yield 'device' => ['actionClearDeviceCache', 'device'];
    }

    #[DataProvider('individualClearProvider')]
    public function testIndividualUtilityActionInvalidatesOnlyItsOwnedFamily(string $action, string $family): void
    {
        $scoped = $this->seedFamilies();
        $response = (new UtilitiesController('utilities', SearchManager::$plugin))->{$action}();

        self::assertTrue($response->data['success']);
        foreach (['search', 'autocomplete', 'device'] as $candidate) {
            $result = $scoped[$candidate]->get('item', 'scope');
            if ($candidate === $family) {
                self::assertTrue($result->isMiss(), $candidate);
            } else {
                self::assertTrue($result->isHit(), $candidate);
            }
        }
        self::assertSame('safe', $scoped['sentinel']->get('item', 'scope')->value);
    }

    public function testClearAllInvalidatesDisposableFamiliesWithoutFlushingApplicationCache(): void
    {
        $scoped = $this->seedFamilies();
        $response = (new UtilitiesController('utilities', SearchManager::$plugin))->actionClearAllCaches();

        self::assertTrue($response->data['success']);
        self::assertTrue($scoped['search']->get('item', 'scope')->isMiss());
        self::assertTrue($scoped['autocomplete']->get('item', 'scope')->isMiss());
        self::assertTrue($scoped['device']->get('item', 'scope')->isMiss());
        self::assertSame('safe', $scoped['sentinel']->get('item', 'scope')->value);
    }

    /**
     * @return array<string, ScopedCache>
     */
    private function seedFamilies(): array
    {
        $cache = Craft::$app->getCache();
        self::assertInstanceOf(CacheInterface::class, $cache);
        $scoped = [
            'search' => new ScopedCache($cache, SearchManager::$plugin->id, 'search'),
            'autocomplete' => new ScopedCache($cache, SearchManager::$plugin->id, 'autocomplete'),
            'device' => new ScopedCache($cache, SearchManager::$plugin->id, 'device'),
            'sentinel' => new ScopedCache($cache, 'unrelated-plugin', 'sentinel'),
        ];
        foreach ($scoped as $family => $familyCache) {
            self::assertTrue($familyCache->set('item', $family === 'sentinel' ? 'safe' : $family, 300, 'scope'));
        }

        return $scoped;
    }
}
