<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use Craft;
use lindemannrock\base\helpers\StoragePathHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;

/**
 * Resolves and validates file-backend index storage paths.
 *
 * @since 5.47.0
 */
class FileBackendStoragePathHelper
{
    /**
     * Return the default file-backend base path.
     */
    public static function defaultBasePath(): string
    {
        return Craft::$app->getRuntimePath() . '/search-manager/indices';
    }

    /**
     * Resolve a configured file-backend storage path, falling back to runtime storage.
     */
    public static function resolve(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return self::defaultBasePath();
        }

        $errors = self::validate($path);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return rtrim(StoragePathHelper::resolve($path), '/\\');
    }

    /**
     * Validate a configured file-backend storage path.
     *
     * @return array<int, string>
     */
    public static function validate(?string $path): array
    {
        $path = trim((string)$path);
        if ($path === '') {
            return [];
        }

        return StoragePathHelper::validatePath($path, [
            'translationCategory' => 'search-manager',
            'allowedAliases' => ['@storage', '@root'],
            'preventWebroot' => true,
            'requireAlias' => true,
            'allowEnvVars' => true,
        ]);
    }

    /**
     * Return all known file-backend base paths, including the default runtime path.
     *
     * @return array<int, string>
     */
    public static function configuredBasePaths(): array
    {
        $paths = [self::defaultBasePath()];

        foreach (ConfiguredBackend::findAll() as $backend) {
            if ($backend->backendType !== 'file') {
                continue;
            }

            try {
                $paths[] = self::resolve($backend->settings['storagePath'] ?? null);
            } catch (\InvalidArgumentException $e) {
                Craft::warning(
                    'Skipping invalid file backend storage path for "' . $backend->handle . '": ' . $e->getMessage(),
                    'search-manager'
                );
            }
        }

        return array_values(array_unique($paths));
    }
}
