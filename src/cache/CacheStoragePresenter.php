<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\cache;

use lindemannrock\base\cache\CacheBackendStatus;

/**
 * Maps a cache storage decision to compact, translatable presentation data.
 *
 * @since 5.55.0
 */
final class CacheStoragePresenter
{
    /**
     * @return array{
     *     heading: string,
     *     explanation: string|null,
     *     statusType: 'success'|'info'|'warning',
     *     filePath: string|null,
     *     usesFile: bool,
     *     utilityValue: string,
     *     utilityDescription: string,
     *     utilityStatusType: 'success'|'info'|'warning'|'inactive'
     * }
     */
    public function present(
        CacheStorageDecision $decision,
        bool $hasEnabledFamilies = true,
        ?string $filePath = null,
    ): array {
        if ($decision->isDisabled()) {
            $presentation = [
                'heading' => 'Caching disabled',
                'explanation' => 'No suitable cross-request cache is available. Values are recomputed as needed.',
                'statusType' => 'warning',
                'filePath' => null,
                'usesFile' => false,
                'utilityValue' => 'Disabled',
                'utilityDescription' => 'Recomputed as needed',
                'utilityStatusType' => 'warning',
            ];
        } elseif ($decision->usesFileCache()) {
            $presentation = [
                'heading' => 'Using file cache',
                'explanation' => null,
                'statusType' => 'success',
                'filePath' => $decision->canResolveFilePath ? $filePath : null,
                'usesFile' => true,
                'utilityValue' => 'Active',
                'utilityDescription' => 'File cache',
                'utilityStatusType' => 'success',
            ];
        } elseif ($decision->backendStatus->backend === CacheBackendStatus::BACKEND_UNKNOWN) {
            $presentation = [
                'heading' => 'Using application cache',
                'explanation' => 'Cross-request persistence could not be confirmed.',
                'statusType' => 'info',
                'filePath' => null,
                'usesFile' => false,
                'utilityValue' => 'Best effort',
                'utilityDescription' => 'Application cache',
                'utilityStatusType' => 'info',
            ];
        } else {
            [$heading, $utilityDescription] = match ($decision->backendStatus->backend) {
                CacheBackendStatus::BACKEND_MANAGED => ['Using managed cache', 'Managed cache'],
                CacheBackendStatus::BACKEND_REDIS => ['Using Redis cache', 'Redis cache'],
                CacheBackendStatus::BACKEND_DATABASE => ['Using database cache', 'Database cache'],
                CacheBackendStatus::BACKEND_FILESYSTEM => ['Using filesystem cache', 'Filesystem cache'],
                default => ['Using application cache', 'Application cache'],
            };

            $presentation = [
                'heading' => $heading,
                'explanation' => $decision->fileStorageBypassed
                    ? 'This host has an ephemeral filesystem, so the application cache is used automatically.'
                    : null,
                'statusType' => 'success',
                'filePath' => null,
                'usesFile' => false,
                'utilityValue' => 'Active',
                'utilityDescription' => $utilityDescription,
                'utilityStatusType' => 'success',
            ];
        }

        if (!$hasEnabledFamilies) {
            $presentation['utilityValue'] = 'Inactive';
            $presentation['utilityDescription'] = 'No cache families enabled';
            $presentation['utilityStatusType'] = 'inactive';
        }

        return $presentation;
    }

    public function applicationOptionToken(string $configuredStorage): string
    {
        return in_array($configuredStorage, ['redis', 'craft'], true) ? $configuredStorage : 'craft';
    }

    /**
     * @param array{search: bool, autocomplete: bool, device: bool} $enabledFamilies
     * @param array<string, int>|null $fileCounts
     * @return list<array{family: string, label: string, value: int|string}>
     */
    public function presentFamilies(
        CacheStorageDecision $decision,
        array $enabledFamilies,
        ?array $fileCounts = null,
    ): array {
        $labels = [
            'search' => 'Search',
            'autocomplete' => 'Autocomplete',
            'device' => 'Devices',
        ];
        $families = [];

        foreach ($enabledFamilies as $family => $enabled) {
            if (!$enabled) {
                continue;
            }

            $families[] = [
                'family' => $family,
                'label' => $labels[$family],
                'value' => $decision->usesFileCache()
                    ? ($fileCounts[$family] ?? 0)
                    : ($decision->isDisabled() ? '—' : '✓'),
            ];
        }

        return $families;
    }
}
