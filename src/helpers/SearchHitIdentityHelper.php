<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes search hit identity fields across local and external backends.
 *
 * @since 5.53.0
 */
class SearchHitIdentityHelper
{
    /**
     * Return Search Manager's backend document ID for an element/site pair.
     */
    public static function backendId(int|string $elementId, int|string|null $siteId = null): string
    {
        $elementId = (string)$elementId;

        if ($siteId === null || $siteId === '') {
            return $elementId;
        }

        return $elementId . '_' . (string)$siteId;
    }

    /**
     * Prepare a document for a backend that uses `objectID` as the primary key.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prepareObjectIdDocument(array $data): array
    {
        $elementId = self::elementId($data);
        if ($elementId === null) {
            throw new \InvalidArgumentException('Document must have either "elementId", "id", or "objectID" field');
        }

        $siteId = self::siteId($data);
        $backendId = self::backendId($elementId, $siteId);

        $data['elementId'] = $elementId;
        $data['backendId'] = $backendId;
        $data['id'] = $elementId;
        $data['objectID'] = $backendId;

        return $data;
    }

    /**
     * Prepare a document for a backend that uses `id` as the primary key.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prepareIdDocument(array $data): array
    {
        $elementId = self::elementId($data);
        if ($elementId === null) {
            throw new \InvalidArgumentException('Document must have either "elementId", "id", or "objectID" field');
        }

        $siteId = self::siteId($data);
        $backendId = self::backendId($elementId, $siteId);

        $data['elementId'] = $elementId;
        $data['backendId'] = $backendId;
        $data['objectID'] = $elementId;
        $data['id'] = $backendId;

        return $data;
    }

    /**
     * Normalize a search hit for public/internal consumption.
     *
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    public static function normalizeHit(array $hit): array
    {
        $elementId = self::elementId($hit);
        if ($elementId !== null) {
            $hit['elementId'] = $elementId;
            $hit['id'] = $elementId;
        }

        $backendId = self::rawBackendId($hit);
        if ($backendId === null && $elementId !== null) {
            $backendId = self::backendId($elementId, self::siteId($hit));
        }
        if ($backendId !== null) {
            $hit['backendId'] = $backendId;
        }

        return $hit;
    }

    /**
     * @param array<string, mixed> $hit
     */
    public static function elementId(array $hit): ?int
    {
        foreach (['elementId', 'id', 'objectID'] as $key) {
            if (isset($hit[$key]) && is_numeric($hit[$key])) {
                return (int)$hit[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $hit
     */
    public static function rawBackendId(array $hit): ?string
    {
        if (isset($hit['backendId']) && (string)$hit['backendId'] !== '') {
            return (string)$hit['backendId'];
        }

        foreach (['id', 'objectID'] as $key) {
            if (!isset($hit[$key])) {
                continue;
            }

            $value = (string)$hit[$key];
            if ($value !== '' && !is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private static function siteId(array $hit): int|string|null
    {
        $siteId = $hit['siteId'] ?? null;

        return $siteId === '' ? null : $siteId;
    }
}
