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
use yii\web\HeaderCollection;
use yii\web\ForbiddenHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * Pins the slice 2 enforcement contract for ApiKeyService::authenticate():
 * 401 for missing / unknown / tampered keys, 403 for a known key that is
 * disabled or expired, and a usage-timestamp write on success.
 *
 * @since 5.47.0
 */
final class ApiKeyAuthenticationTest extends TestCase
{
    private const TEST_KEY_NAME_PREFIX = '__sm_authn_test__';
    private const API_KEY_HEADER = 'X-Search-Manager-Key';

    private int $seedCounter = 0;
    private bool $originalRequireApiKey = false;
    private ?object $originalRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCounter = 0;
        $this->originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        $this->originalRequest = Craft::$app->getRequest();
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->purgeTestKeys();
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = $this->originalRequireApiKey;
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        $this->purgeTestKeys();
        parent::tearDown();
    }

    public function testNullKeyThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);
        SearchManager::$plugin->apiKeys->authenticate(null);
    }

    public function testBlankKeyThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);
        SearchManager::$plugin->apiKeys->authenticate('   ');
    }

    public function testUnknownPrefixThrows401(): void
    {
        // Well-formed shape, but no matching prefix exists in the table.
        $this->expectException(UnauthorizedHttpException::class);
        SearchManager::$plugin->apiKeys->authenticate('sm_pub_' . str_repeat('0', 32));
    }

    public function testTamperedBodyThrows401(): void
    {
        [, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        // Same 15-char prefix (so the row is found), wrong body → hash mismatch.
        $tampered = substr($plaintext, 0, 15) . str_repeat('f', strlen($plaintext) - 15);

        $this->expectException(UnauthorizedHttpException::class);
        SearchManager::$plugin->apiKeys->authenticate($tampered);
    }

    public function testDisabledKeyThrows403(): void
    {
        [, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC, enabled: false);

        $this->expectException(ForbiddenHttpException::class);
        SearchManager::$plugin->apiKeys->authenticate($plaintext);
    }

    public function testExpiredKeyThrows403(): void
    {
        [, $plaintext] = $this->seedKey(
            ApiKey::TYPE_PUBLIC,
            validUntil: new \DateTime('-1 day', new \DateTimeZone('UTC')),
        );

        $this->expectException(ForbiddenHttpException::class);
        SearchManager::$plugin->apiKeys->authenticate($plaintext);
    }

    public function testActiveKeyReturnsKeyAndRecordsUsage(): void
    {
        [$key, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        $this->assertNull($key->lastUsedAt, 'Freshly seeded key has no lastUsedAt');

        $authed = SearchManager::$plugin->apiKeys->authenticate($plaintext);

        $this->assertInstanceOf(ApiKey::class, $authed);
        $this->assertSame($key->id, $authed->id);

        // recordUsage() persisted lastUsedAt — reload to confirm it stuck.
        $reloaded = ApiKey::findById($key->id);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->lastUsedAt, 'authenticate() must stamp lastUsedAt on success');
    }

    public function testApiControllerAllowsAnonymousWhenRequireApiKeyIsOff(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = false;
        $this->installHeaderRequest();

        $this->assertTrue($this->runApiBeforeAction());
    }

    public function testApiControllerRequiresHeaderWhenRequireApiKeyIsOn(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installHeaderRequest();

        $this->expectException(UnauthorizedHttpException::class);
        $this->runApiBeforeAction();
    }

    public function testApiControllerAllowsValidHeaderWhenRequireApiKeyIsOn(): void
    {
        [, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installHeaderRequest($plaintext);

        $this->assertTrue($this->runApiBeforeAction());
    }

    public function testApiControllerRejectsDisabledHeaderWhenRequireApiKeyIsOn(): void
    {
        [, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC, enabled: false);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installHeaderRequest($plaintext);

        $this->expectException(ForbiddenHttpException::class);
        $this->runApiBeforeAction();
    }

    private function installHeaderRequest(?string $apiKey = null): void
    {
        Craft::$app->set('request', new class($apiKey, self::API_KEY_HEADER) extends \craft\console\Request {
            private HeaderCollection $headers;

            public function __construct(?string $apiKey, private readonly string $headerName)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
                if ($apiKey !== null) {
                    $this->headers->set($this->headerName, $apiKey);
                }
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

    private function runApiBeforeAction(): bool
    {
        $controller = new ApiController('api', Craft::$app);
        $action = new Action('search', $controller);

        return $controller->beforeAction($action);
    }

    /**
     * @return array{0: ApiKey, 1: string} the saved key + its one-shot plaintext
     */
    private function seedKey(string $type, bool $enabled = true, ?\DateTime $validUntil = null): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey($type);
        $key = new ApiKey();
        $key->name = self::TEST_KEY_NAME_PREFIX . '_' . (++$this->seedCounter);
        $key->type = $type;
        $key->enabled = $enabled;
        $key->validUntil = $validUntil;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];

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
