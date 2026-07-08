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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Action;
use yii\web\ForbiddenHttpException;
use yii\web\HeaderCollection;
use yii\web\UnauthorizedHttpException;

/**
 * Pins the slice 4 tracking gate (closes audit #8): when `requireApiKey` is on,
 * `track-search` / `track-click` require a valid key (same authenticate +
 * public-key referrer gate as search/autocomplete) plus an allowed-indices
 * check when the ping names them — but are NOT rate-limited. When the setting
 * is off, the endpoints stay anonymous.
 *
 * @since 5.47.0
 */
final class ApiKeyTrackingGateTest extends TestCase
{
    private const KEY_PREFIX = '__sm_trackgate_test__';
    private const INDEX_HANDLE = '__sm_trackgate_index__';
    private const API_KEY_HEADER = 'X-Search-Manager-Key';

    private int $seedCounter = 0;
    private bool $originalRequireApiKey = false;
    private array|string $originalTrackingAllowedOrigins = [];
    private ?object $originalRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCounter = 0;
        $this->originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        $this->originalTrackingAllowedOrigins = SearchManager::$plugin->getSettings()->trackingAllowedOrigins;
        $this->originalRequest = Craft::$app->getRequest();
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->purge();
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = $this->originalRequireApiKey;
        SearchManager::$plugin->getSettings()->trackingAllowedOrigins = $this->originalTrackingAllowedOrigins;
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        $this->purge();
        parent::tearDown();
    }

    public function testTrackingStaysAnonymousWhenRequireApiKeyOff(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->installRequest(); // no key

        $this->assertTrue($this->runBeforeAction('track-search'));
        $this->assertTrue($this->runBeforeAction('track-click'));
    }

    public function testTrackingIgnoresInvalidKeyWhenRequireApiKeyOff(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->installRequest(apiKey: 'sm_pub_' . str_repeat('0', 32));

        $this->assertTrue($this->runBeforeAction('track-search'));
        $this->assertTrue($this->runBeforeAction('track-click'));
    }

    public function testMissingKeyThrows401(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(); // no key header

        $this->expectException(UnauthorizedHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testInvalidKeyThrows401(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: 'sm_pub_' . str_repeat('0', 32));

        $this->expectException(UnauthorizedHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testDisabledKeyThrows403(): void
    {
        [, $plaintext] = $this->seedKey(enabled: false);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext);

        $this->expectException(ForbiddenHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testExpiredKeyThrows403(): void
    {
        [, $plaintext] = $this->seedKey(validUntil: new \DateTime('-1 day', new \DateTimeZone('UTC')));
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext);

        $this->expectException(ForbiddenHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testValidKeyAllows(): void
    {
        [, $plaintext] = $this->seedKey();
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext);

        $this->assertTrue($this->runBeforeAction('track-search'));
    }

    public function testTrackClickAlsoGated(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(); // no key

        $this->expectException(UnauthorizedHttpException::class);
        $this->runBeforeAction('track-click');
    }

    public function testPublicKeyBadReferrerThrows403(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext, referer: 'https://evil.com/');

        $this->expectException(ForbiddenHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testPublicKeyAllowsMatchingOriginWhenRefererMissing(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['headless.example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        SearchManager::$plugin->getSettings()->trackingAllowedOrigins = ['https://headless.example.com'];
        $this->installRequest(apiKey: $plaintext, origin: 'https://headless.example.com');

        $this->assertTrue($this->runBeforeAction('track-search'));
    }

    public function testAllowedTrackingOriginStillRequiresApiKeyReferrerMatch(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['other.example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        SearchManager::$plugin->getSettings()->trackingAllowedOrigins = ['https://headless.example.com'];
        $this->installRequest(apiKey: $plaintext, origin: 'https://headless.example.com');

        $this->expectException(ForbiddenHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testRefererTakesPrecedenceOverOrigin(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['headless.example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        SearchManager::$plugin->getSettings()->trackingAllowedOrigins = ['https://headless.example.com'];
        $this->installRequest(
            apiKey: $plaintext,
            referer: 'https://evil.example.com/search',
            origin: 'https://headless.example.com',
        );

        $this->expectException(ForbiddenHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testServerKeyIsRejected(): void
    {
        [, $plaintext] = $this->seedKey(type: ApiKey::TYPE_SERVER, allowedReferrers: ['example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext, referer: 'https://anywhere.example/');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid API key.');
        $this->runBeforeAction('track-search');
    }

    public function testAllowsIndexInAllowlist(): void
    {
        $this->seedIndex(self::INDEX_HANDLE);
        [, $plaintext] = $this->seedKey(allowedIndices: [self::INDEX_HANDLE]);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext, params: ['indices' => self::INDEX_HANDLE]);

        $this->assertTrue($this->runBeforeAction('track-search'));
    }

    public function testRejectsIndexNotInAllowlist(): void
    {
        $this->seedIndex(self::INDEX_HANDLE);
        [, $plaintext] = $this->seedKey(allowedIndices: ['__sm_some_other_index__']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext, params: ['indices' => self::INDEX_HANDLE]);

        $this->expectException(ForbiddenHttpException::class);
        $this->runBeforeAction('track-search');
    }

    public function testTrackingIsNotRateLimited(): void
    {
        // rateLimit=1 would 429 on the 2nd search/autocomplete request, but
        // tracking must NOT be rate-limited.
        [, $plaintext] = $this->seedKey(rateLimit: 1);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest(apiKey: $plaintext);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->runBeforeAction('track-search'));
        }
    }

    private function installRequest(string $apiKey = '', ?string $referer = null, ?string $origin = null, array $params = []): void
    {
        Craft::$app->set('request', new class($apiKey, $referer, $origin, $params, self::API_KEY_HEADER) extends \craft\console\Request {
            private HeaderCollection $headers;

            /** @param array<string,string> $params */
            public function __construct(string $apiKey, ?string $referer, ?string $origin, private array $params, string $headerName)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
                if ($apiKey !== '') {
                    $this->headers->set($headerName, $apiKey);
                }
                if ($referer !== null) {
                    $this->headers->set('Referer', $referer);
                }
                if ($origin !== null) {
                    $this->headers->set('Origin', $origin);
                }
            }

            public function getHeaders(): HeaderCollection
            {
                return $this->headers;
            }

            public function getParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
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

    private function runBeforeAction(string $actionId): bool
    {
        $controller = new SearchController('search', Craft::$app);
        $action = new Action($actionId, $controller);

        return $controller->beforeAction($action);
    }

    /**
     * @param list<string> $allowedReferrers
     * @param list<string> $allowedIndices
     * @return array{0: ApiKey, 1: string}
     */
    private function seedKey(
        string $type = ApiKey::TYPE_PUBLIC,
        bool $enabled = true,
        ?\DateTime $validUntil = null,
        array $allowedReferrers = [],
        array $allowedIndices = [ApiKey::ALL_INDICES],
        ?int $rateLimit = null,
    ): array {
        $generated = SearchManager::$plugin->apiKeys->generateKey($type);
        $key = new ApiKey();
        $key->name = self::KEY_PREFIX . '_' . (++$this->seedCounter);
        $key->type = $type;
        $key->enabled = $enabled;
        $key->validUntil = $validUntil;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = $allowedIndices;
        $key->allowedReferrers = $allowedReferrers;
        $key->rateLimit = $rateLimit;

        $this->assertTrue($key->save(), 'Seeded key save() must succeed');

        return [$key, $generated['plaintext']];
    }

    private function seedIndex(string $handle): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'transformerClass' => \lindemannrock\searchmanager\transformers\EntryTransformer::class,
            'enabled' => 1,
            'source' => 'database',
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function purge(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::KEY_PREFIX . '%', false])->execute();
        $db->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => self::INDEX_HANDLE])->execute();
        SearchIndex::clearCache();
    }
}
