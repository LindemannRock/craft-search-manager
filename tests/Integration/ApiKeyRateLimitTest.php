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
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Action;
use yii\caching\ArrayCache;
use yii\web\HeaderCollection;
use yii\web\TooManyRequestsHttpException;

/**
 * Pins the slice 3 per-key rate-limit contract (audit #23): a `null` cap never
 * blocks, requests under the cap pass, the request that exceeds it gets `429`,
 * counters are isolated per key, and enforcement only runs for authenticated
 * requests (never anonymous). The cache is swapped for an isolated in-memory
 * ArrayCache so counting is deterministic regardless of the dev cache driver.
 *
 * @since 5.47.0
 */
final class ApiKeyRateLimitTest extends TestCase
{
    private const TEST_KEY_NAME_PREFIX = '__sm_ratelimit_test__';
    private const API_KEY_HEADER = 'X-Search-Manager-Key';

    private int $seedCounter = 0;
    private bool $originalRequireApiKey = false;
    private ?object $originalRequest = null;
    private mixed $originalCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCounter = 0;
        $this->originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalCache = Craft::$app->getCache();
        // Isolated, deterministic counter store for this test.
        Craft::$app->set('cache', new ArrayCache());
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->purgeTestKeys();
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = $this->originalRequireApiKey;
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalCache !== null) {
            Craft::$app->set('cache', $this->originalCache);
        }
        $this->purgeTestKeys();
        parent::tearDown();
    }

    // ---- Service-level: ApiKeyService::enforceRateLimit() -------------------

    public function testNullRateLimitNeverBlocks(): void
    {
        [$key] = $this->seedKey(rateLimit: null);
        for ($i = 0; $i < 50; $i++) {
            $this->svc()->enforceRateLimit($key);
        }
        $this->addToAssertionCount(1);
    }

    public function testUnderLimitAllows(): void
    {
        [$key] = $this->seedKey(rateLimit: 5);
        for ($i = 0; $i < 5; $i++) {
            $this->svc()->enforceRateLimit($key);
        }
        $this->addToAssertionCount(1);
    }

    public function testExceedingLimitThrows429(): void
    {
        [$key] = $this->seedKey(rateLimit: 3);
        $this->svc()->enforceRateLimit($key);
        $this->svc()->enforceRateLimit($key);
        $this->svc()->enforceRateLimit($key); // 3 allowed

        $this->expectException(TooManyRequestsHttpException::class);
        $this->svc()->enforceRateLimit($key); // 4th → 429
    }

    public function testCountersAreIsolatedPerKey(): void
    {
        [$keyA] = $this->seedKey(rateLimit: 2);
        [$keyB] = $this->seedKey(rateLimit: 2);

        // Exhaust keyA's window; keyB's calls must not affect keyA and vice versa.
        $this->svc()->enforceRateLimit($keyA);
        $this->svc()->enforceRateLimit($keyB);
        $this->svc()->enforceRateLimit($keyA); // keyA now at 2
        $this->svc()->enforceRateLimit($keyB); // keyB still only at 2 — independent
        $this->addToAssertionCount(1);

        // keyA is exhausted (2/2) → next throws, proving its counter is its own.
        $this->expectException(TooManyRequestsHttpException::class);
        $this->svc()->enforceRateLimit($keyA);
    }

    // ---- Controller-level: ApiController::beforeAction() --------------------

    public function testAnonymousRequestsAreNotRateLimited(): void
    {
        // requireApiKey OFF → keys never authenticated, so the cap never applies.
        [, $plaintext] = $this->seedKey(rateLimit: 1);
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->installRequest($plaintext);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->runApiBeforeAction('search'));
        }
    }

    public function testSearchActionRateLimitedThroughBeforeAction(): void
    {
        [, $plaintext] = $this->seedKey(rateLimit: 2);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext);

        $this->assertTrue($this->runApiBeforeAction('search'));
        $this->assertTrue($this->runApiBeforeAction('search')); // 2 allowed

        $this->expectException(TooManyRequestsHttpException::class);
        $this->runApiBeforeAction('search'); // 3rd → 429
    }

    public function testAutocompleteActionAlsoRateLimited(): void
    {
        [, $plaintext] = $this->seedKey(rateLimit: 1);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext);

        $this->assertTrue($this->runApiBeforeAction('autocomplete')); // 1 allowed

        $this->expectException(TooManyRequestsHttpException::class);
        $this->runApiBeforeAction('autocomplete'); // 2nd → 429
    }

    private function svc(): \lindemannrock\searchmanager\services\ApiKeyService
    {
        return SearchManager::$plugin->apiKeys;
    }

    private function installRequest(string $apiKey): void
    {
        Craft::$app->set('request', new class($apiKey, self::API_KEY_HEADER) extends \craft\console\Request {
            private HeaderCollection $headers;

            public function __construct(string $apiKey, string $headerName)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
                $this->headers->set($headerName, $apiKey);
            }

            public function getHeaders(): HeaderCollection
            {
                return $this->headers;
            }

            public function validateCsrfToken($clientSuppliedToken = null): bool
            {
                return true;
            }

            public function hasValidSiteToken(): bool
            {
                return false;
            }
        });
    }

    private function runApiBeforeAction(string $actionId): bool
    {
        $controller = new ApiController('api', Craft::$app);
        $action = new Action($actionId, $controller);

        return $controller->beforeAction($action);
    }

    /**
     * @return array{0: ApiKey, 1: string}
     */
    private function seedKey(?int $rateLimit): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $key = new ApiKey();
        $key->name = self::TEST_KEY_NAME_PREFIX . '_' . (++$this->seedCounter);
        $key->type = ApiKey::TYPE_PUBLIC;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];
        $key->rateLimit = $rateLimit;

        $this->assertTrue($key->save(), 'Seeded key save() must succeed');

        return [$key, $generated['plaintext']];
    }

    private function purgeTestKeys(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::TEST_KEY_NAME_PREFIX . '%', false])
            ->execute();
    }
}
