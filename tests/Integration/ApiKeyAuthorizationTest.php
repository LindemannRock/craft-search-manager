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
use yii\web\ForbiddenHttpException;
use yii\web\HeaderCollection;
use yii\web\UnauthorizedHttpException;

/**
 * Pins the slice 2b authorization contract: the API key's index permission
 * boundary (scopeIndices), per-page clamp (clampHitsPerPage), referrer matcher
 * (ApiKey::allowsReferrer), and the public-key referrer gate in beforeAction.
 *
 * @since 5.47.0
 */
final class ApiKeyAuthorizationTest extends TestCase
{
    private const TEST_KEY_NAME_PREFIX = '__sm_authz_test__';
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

    // ---- ApiKey::allowsReferrer() -------------------------------------------

    public function testReferrerNoPatternsAllowsAnything(): void
    {
        $key = new ApiKey();
        $key->allowedReferrers = [];
        $this->assertTrue($key->allowsReferrer(null));
        $this->assertTrue($key->allowsReferrer('https://anything.example/'));
    }

    public function testReferrerWithPatternsRejectsMissingReferer(): void
    {
        $key = new ApiKey();
        $key->allowedReferrers = ['example.com'];
        $this->assertFalse($key->allowsReferrer(null));
        $this->assertFalse($key->allowsReferrer('   '));
    }

    public function testReferrerExactHostMatch(): void
    {
        $key = new ApiKey();
        $key->allowedReferrers = ['example.com'];
        $this->assertTrue($key->allowsReferrer('https://example.com/search?q=x'));
        $this->assertFalse($key->allowsReferrer('https://notexample.com/'));
        $this->assertFalse($key->allowsReferrer('https://sub.example.com/'), 'exact pattern must not match a subdomain');
    }

    public function testReferrerWildcardMatchesBaseAndSubdomains(): void
    {
        $key = new ApiKey();
        $key->allowedReferrers = ['*.example.com'];
        $this->assertTrue($key->allowsReferrer('https://example.com/'), 'wildcard covers the base domain');
        $this->assertTrue($key->allowsReferrer('https://www.example.com/'));
        $this->assertTrue($key->allowsReferrer('https://deep.shop.example.com/'));
        $this->assertFalse($key->allowsReferrer('https://example.com.evil.com/'));
    }

    // ---- ApiKeyService::scopeIndices() --------------------------------------

    public function testScopeIndicesAllIndicesKeyIsTransparent(): void
    {
        $key = new ApiKey();
        $key->allowedIndices = [ApiKey::ALL_INDICES];

        $this->assertSame([['docs'], true], $this->svc()->scopeIndices($key, ['docs'], true));
        $this->assertSame([[], false], $this->svc()->scopeIndices($key, [], false));
    }

    public function testScopeIndicesAllowsRequestedInAllowlist(): void
    {
        $key = new ApiKey();
        $key->allowedIndices = ['docs', 'blog'];

        $this->assertSame([['docs'], true], $this->svc()->scopeIndices($key, ['docs'], true));
    }

    public function testScopeIndicesRejectsRequestedOutsideAllowlist(): void
    {
        $key = new ApiKey();
        $key->allowedIndices = ['docs'];

        $this->expectException(ForbiddenHttpException::class);
        $this->svc()->scopeIndices($key, ['secret'], true);
    }

    public function testScopeIndicesUnscopedRequestFallsBackToKeyAllowlist(): void
    {
        // Allowlist references a handle that isn't an enabled index → it
        // validates away to [], and the request is marked provided so the
        // caller returns an empty result rather than "all enabled".
        $key = new ApiKey();
        $key->allowedIndices = ['__sm_no_such_index__'];

        $this->assertSame([[], true], $this->svc()->scopeIndices($key, [], false));
    }

    // ---- ApiKeyService::clampHitsPerPage() ----------------------------------

    public function testClampHitsPerPageNullCapLeavesValue(): void
    {
        $key = new ApiKey();
        $key->maxHitsPerPage = null;
        $this->assertSame(20, $this->svc()->clampHitsPerPage($key, 20));
    }

    public function testClampHitsPerPageCapsAboveLimit(): void
    {
        $key = new ApiKey();
        $key->maxHitsPerPage = 5;
        $this->assertSame(5, $this->svc()->clampHitsPerPage($key, 20));
        $this->assertSame(3, $this->svc()->clampHitsPerPage($key, 3), 'below the cap is untouched');
    }

    // ---- Referrer gate in ApiController::beforeAction() ---------------------

    public function testPublicKeyRejectsDisallowedReferrer(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext, 'https://evil.com/');

        $this->expectException(ForbiddenHttpException::class);
        $this->runApiBeforeAction();
    }

    public function testPublicKeyAllowsMatchingReferrer(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext, 'https://example.com/search');

        $this->assertTrue($this->runApiBeforeAction());
    }

    public function testPublicKeyAllowsMatchingOriginWhenRefererMissing(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['headless.example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext, null, 'https://headless.example.com');

        $this->assertTrue($this->runApiBeforeAction());
    }

    public function testRefererTakesPrecedenceOverOrigin(): void
    {
        [, $plaintext] = $this->seedKey(allowedReferrers: ['headless.example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext, 'https://evil.example.com', 'https://headless.example.com');

        $this->expectException(ForbiddenHttpException::class);
        $this->runApiBeforeAction();
    }

    public function testServerKeyIsRejectedBeforeReferrerCheck(): void
    {
        [, $plaintext] = $this->seedKey(type: ApiKey::TYPE_SERVER, allowedReferrers: ['example.com']);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest($plaintext, 'https://anywhere.example/');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid API key.');
        $this->runApiBeforeAction();
    }

    private function svc(): \lindemannrock\searchmanager\services\ApiKeyService
    {
        return SearchManager::$plugin->apiKeys;
    }

    private function installRequest(string $apiKey, ?string $referer, ?string $origin = null): void
    {
        Craft::$app->set('request', new class($apiKey, $referer, $origin, self::API_KEY_HEADER) extends \craft\console\Request {
            private HeaderCollection $headers;

            public function __construct(string $apiKey, ?string $referer, ?string $origin, string $headerName)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
                $this->headers->set($headerName, $apiKey);
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
     * @param list<string> $allowedReferrers
     * @return array{0: ApiKey, 1: string}
     */
    private function seedKey(string $type = ApiKey::TYPE_PUBLIC, array $allowedReferrers = []): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey($type);
        $key = new ApiKey();
        $key->name = self::TEST_KEY_NAME_PREFIX . '_' . (++$this->seedCounter);
        $key->type = $type;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];
        $key->allowedReferrers = $allowedReferrers;

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
