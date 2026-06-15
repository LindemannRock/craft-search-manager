<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\console\controllers\ApiKeysController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\console\ExitCode;

/**
 * Covers the scripted API-key provisioning command.
 *
 * @since 5.47.0
 */
final class ApiKeysConsoleControllerTest extends TestCase
{
    private const TEST_KEY_NAME_PREFIX = '__sm_console_key_test__';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeTestKeys();
    }

    protected function tearDown(): void
    {
        $this->purgeTestKeys();
        parent::tearDown();
    }

    public function testCreatePublicWildcardKeyPersistsHashOnlyAndPrintsPlaintextOnce(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_public';
        $controller->indices = ApiKey::ALL_INDICES;
        $controller->referrers = 'Example.com, *.Example.com';
        $controller->maxHits = 50;
        $controller->rateLimit = 120;

        [$exitCode, $output] = $this->runCreate($controller);

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString('Plaintext key', $output);
        $this->assertSame(1, preg_match_all('/sm_pub_[0-9a-f]{32}/', $output, $matches));

        $key = $this->latestTestKey();
        $this->assertNotNull($key);
        $this->assertSame(ApiKey::TYPE_PUBLIC, $key->type);
        $this->assertTrue($key->enabled);
        $this->assertSame([ApiKey::ALL_INDICES], $key->allowedIndices);
        $this->assertSame(['example.com', '*.example.com'], $key->allowedReferrers);
        $this->assertSame(50, $key->maxHitsPerPage);
        $this->assertSame(120, $key->rateLimit);
        $this->assertSame($key->keyPrefix, substr($matches[0][0], 0, 15));
        $this->assertNotSame($matches[0][0], $key->keyHash);
        $this->assertSame(64, strlen($key->keyHash));
    }

    public function testCreateServerWildcardKeyPersistsServerType(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_server';
        $controller->type = ApiKey::TYPE_SERVER;
        $controller->indices = ApiKey::ALL_INDICES;

        [$exitCode, $output] = $this->runCreate($controller);

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertMatchesRegularExpression('/sm_srv_[0-9a-f]{32}/', $output);

        $key = $this->latestTestKey();
        $this->assertNotNull($key);
        $this->assertSame(ApiKey::TYPE_SERVER, $key->type);
        $this->assertTrue($key->enabled);
    }

    public function testDisabledDraftCanBeCreatedWithoutIndices(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_draft';
        $controller->disabled = true;

        [$exitCode] = $this->runCreate($controller);

        $this->assertSame(ExitCode::OK, $exitCode);

        $key = $this->latestTestKey();
        $this->assertNotNull($key);
        $this->assertFalse($key->enabled);
        $this->assertSame([], $key->allowedIndices);
    }

    public function testEnabledKeyWithoutIndicesFailsValidation(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_missing_indices';

        [$exitCode, $output] = $this->runCreate($controller);

        $this->assertSame(ExitCode::DATAERR, $exitCode);
        $this->assertStringContainsString('allowedIndices', $output);
        $this->assertNull($this->latestTestKey());
    }

    public function testUnknownExplicitIndexFailsBeforePersisting(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_unknown_index';
        $controller->indices = '__sm_missing_index_handle__';

        [$exitCode, $output] = $this->runCreate($controller);

        $this->assertSame(ExitCode::DATAERR, $exitCode);
        $this->assertStringContainsString('Unknown index handle(s): __sm_missing_index_handle__', $output);
        $this->assertNull($this->latestTestKey());
    }

    public function testInvalidTypeFailsUsage(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_invalid_type';
        $controller->type = 'browser';
        $controller->indices = ApiKey::ALL_INDICES;

        [$exitCode] = $this->runCreate($controller);

        $this->assertSame(ExitCode::USAGE, $exitCode);
        $this->assertNull($this->latestTestKey());
    }

    public function testInvalidDateFailsUsage(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_invalid_date';
        $controller->indices = ApiKey::ALL_INDICES;
        $controller->validUntil = 'not a date';

        [$exitCode] = $this->runCreate($controller);

        $this->assertSame(ExitCode::USAGE, $exitCode);
        $this->assertNull($this->latestTestKey());
    }

    public function testInvalidNumericLimitsFailModelValidation(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_invalid_limits';
        $controller->indices = ApiKey::ALL_INDICES;
        $controller->maxHits = 0;
        $controller->rateLimit = -1;

        [$exitCode, $output] = $this->runCreate($controller);

        $this->assertSame(ExitCode::DATAERR, $exitCode);
        $this->assertStringContainsString('maxHitsPerPage', $output);
        $this->assertStringContainsString('rateLimit', $output);
        $this->assertNull($this->latestTestKey());
    }

    public function testEnabledPublicKeyWithoutReferrersPrintsWarning(): void
    {
        $controller = $this->controller();
        $controller->name = self::TEST_KEY_NAME_PREFIX . '_unrestricted_public';
        $controller->indices = ApiKey::ALL_INDICES;

        [$exitCode, $output] = $this->runCreate($controller);

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString('Warning: this enabled public key has no referrer restrictions.', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCreate(ApiKeysController $controller): array
    {
        $exitCode = $controller->actionCreate();
        $output = $controller instanceof TestApiKeysController ? $controller->output : '';

        return [$exitCode, $output];
    }

    private function controller(): TestApiKeysController
    {
        return new TestApiKeysController('api-keys', SearchManager::$plugin);
    }

    private function latestTestKey(): ?ApiKey
    {
        $keys = array_values(array_filter(
            ApiKey::findAll(),
            fn(ApiKey $key): bool => str_starts_with($key->name, self::TEST_KEY_NAME_PREFIX),
        ));

        return $keys[0] ?? null;
    }

    private function purgeTestKeys(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::TEST_KEY_NAME_PREFIX . '%', false])
            ->execute();
    }
}

/**
 * Captures console output without writing to the PHPUnit process streams.
 */
final class TestApiKeysController extends ApiKeysController
{
    public string $output = '';

    public function stdout($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    public function stderr($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }
}
