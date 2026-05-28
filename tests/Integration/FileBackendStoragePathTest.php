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
use lindemannrock\searchmanager\helpers\FileBackendStoragePathHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins file-backend storage path validation and resolution.
 *
 * @since 5.47.0
 */
final class FileBackendStoragePathTest extends TestCase
{
    private const ENV_NAME = 'SEARCH_MANAGER_FILE_STORAGE_PATH_TEST';

    protected function tearDown(): void
    {
        putenv(self::ENV_NAME);
        unset($_ENV[self::ENV_NAME], $_SERVER[self::ENV_NAME]);

        parent::tearDown();
    }

    public function testStoragePathAllowsAliasInsideStorage(): void
    {
        $backend = $this->backend('@storage/search-manager/indices');

        self::assertTrue($backend->validate());
        self::assertFalse($backend->hasErrors('settings.storagePath'));
    }

    public function testStoragePathAllowsAbsolutePathInsideStorage(): void
    {
        $backend = $this->backend(Craft::getAlias('@storage/search-manager/indices'));

        self::assertTrue($backend->validate());
        self::assertFalse($backend->hasErrors('settings.storagePath'));
    }

    public function testStoragePathAllowsEnvVarResolvingInsideStorage(): void
    {
        $this->setEnvValue(Craft::getAlias('@storage/search-manager/indices'));

        $backend = $this->backend('$' . self::ENV_NAME);

        self::assertTrue($backend->validate());
        self::assertFalse($backend->hasErrors('settings.storagePath'));
        self::assertSame(Craft::getAlias('@storage/search-manager/indices'), FileBackendStoragePathHelper::resolve('$' . self::ENV_NAME));
    }

    public function testStoragePathRejectsEnvVarResolvingToWebroot(): void
    {
        $this->setEnvValue(Craft::getAlias('@webroot/search-manager/indices'));

        $backend = $this->backend('$' . self::ENV_NAME);

        self::assertFalse($backend->validate());
        self::assertTrue($backend->hasErrors('settings.storagePath'));
        self::assertStringContainsString('web-accessible', implode(' ', $backend->getErrors('settings.storagePath')));
    }

    public function testStoragePathRejectsPathOutsideAllowedRoots(): void
    {
        $backend = $this->backend('/tmp/search-manager-indices');

        self::assertFalse($backend->validate());
        self::assertTrue($backend->hasErrors('settings.storagePath'));
        self::assertStringContainsString('@storage', implode(' ', $backend->getErrors('settings.storagePath')));
    }

    public function testStoragePathRejectsParentTraversal(): void
    {
        $backend = $this->backend('@storage/search-manager/../indices');

        self::assertFalse($backend->validate());
        self::assertTrue($backend->hasErrors('settings.storagePath'));
        self::assertStringContainsString('parent directory traversal', implode(' ', $backend->getErrors('settings.storagePath')));
    }

    private function backend(string $storagePath): ConfiguredBackend
    {
        $backend = new ConfiguredBackend();
        $backend->name = 'Test File Backend';
        $backend->handle = 'testFileBackend';
        $backend->backendType = 'file';
        $backend->settings = ['storagePath' => $storagePath];

        return $backend;
    }

    private function setEnvValue(string $value): void
    {
        putenv(self::ENV_NAME . '=' . $value);
        $_ENV[self::ENV_NAME] = $value;
        $_SERVER[self::ENV_NAME] = $value;
    }
}
