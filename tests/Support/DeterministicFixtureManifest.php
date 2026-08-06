<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Support;

/**
 * Loads the package-owned deterministic Craft fixture identity contract.
 *
 * @since 5.54.0
 */
final class DeterministicFixtureManifest
{
    public const EXPECTED_HASH = '379dcf47d39a01f9ac5ab88f2cdcab9db8696d4c0bc9d9686fca48834689c9ae';

    /** @var list<class-string> */
    public const REQUIRED_INTEGRATION_CLASSES = [
        'craft\\ckeditor\\Field',
        'craft\\commerce\\elements\\Product',
        'craft\\commerce\\elements\\Variant',
        'lindemannrock\\docsmanager\\elements\\SourceDoc',
        'lindemannrock\\docsmanager\\records\\SourceRecord',
    ];

    /** @return array<string, mixed> */
    public static function load(?string $path = null): array
    {
        $path ??= dirname(__DIR__) . '/Fixtures/Project/fixture-manifest.json';
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException("Unable to read fixture manifest: {$path}");
        }
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1) {
            throw new \RuntimeException("Unsupported fixture manifest: {$path}");
        }
        foreach (['sites', 'fields', 'sections', 'entries', 'assets', 'categories', 'users', 'commerce', 'docsManager', 'searchManagerConfig', 'backend', 'indices', 'templates'] as $key) {
            if (!array_key_exists($key, $manifest)) {
                throw new \RuntimeException("Fixture manifest is missing {$key}: {$path}");
            }
        }

        return $manifest;
    }

    public static function hash(?string $path = null): string
    {
        $canonical = json_encode(self::canonicalise(self::load($path)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /** @return array<string, string> */
    public static function identities(?string $path = null): array
    {
        $identities = [];
        self::collectIdentities(self::load($path), '', $identities);
        ksort($identities, SORT_STRING);

        return $identities;
    }

    public static function assertRealDependenciesInstalled(): void
    {
        foreach (self::REQUIRED_INTEGRATION_CLASSES as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException("Required real fixture dependency is not installed: {$class}");
            }
        }
    }

    public static function assertExpectedHash(): void
    {
        $actual = self::hash();
        if ($actual !== self::EXPECTED_HASH) {
            throw new \RuntimeException(
                "Fixture manifest hash mismatch: expected " . self::EXPECTED_HASH . ", got {$actual}.",
            );
        }
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalise($item);
        }

        return $value;
    }

    /** @param array<string, string> $identities */
    private static function collectIdentities(mixed $value, string $path, array &$identities): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $itemPath = $path === '' ? (string)$key : $path . '.' . $key;
            if (in_array($key, ['handle', 'uid', 'slug', 'username', 'filename', 'productTypeHandle', 'sourceHandle', 'docSlug'], true) && is_string($item)) {
                $identities[$itemPath] = $item;
            }
            self::collectIdentities($item, $itemPath, $identities);
        }
    }
}
