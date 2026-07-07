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
use craft\helpers\Db;
use craft\helpers\StringHelper;
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
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_backends}}', ['handle' => 'invalidFilePathHelperTest'])
            ->execute();

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

    public function testConfiguredBasePathsSkipsInvalidStoredFileBackendPath(): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Invalid File Path Helper Test',
            'handle' => 'invalidFilePathHelperTest',
            'backendType' => 'file',
            'settings' => json_encode(['storagePath' => '@missing-alias/search-manager']),
            'enabled' => 1,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        $paths = FileBackendStoragePathHelper::configuredBasePaths();

        self::assertContains(FileBackendStoragePathHelper::defaultBasePath(), $paths);
        self::assertSame($paths, array_values(array_filter(
            $paths,
            static fn(string $path): bool => !str_contains($path, '@missing-alias')
        )));
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
