<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

use yii\base\Component;

/**
 * Public v1 cache-only widget telemetry service.
 *
 * @internal
 * @since 5.55.0
 */
class WidgetCacheTelemetryService extends Component
{
    public const ENVELOPE_TTL = WidgetSearchOutcomeTelemetryService::ENVELOPE_TTL;
    public const MAX_MISS_DURATION_MS = WidgetSearchOutcomeTelemetryService::MAX_MISS_DURATION_MS;

    public function __construct(
        private readonly WidgetSearchOutcomeTelemetryService $authority,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @param list<string> $indexHandles
     * @param array<string, array{cached: bool|null, duration: float|null}> $outcomesByIndex
     */
    public function issue(
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
        array $outcomesByIndex,
    ): ?string {
        return $this->authority->issueV1CacheTelemetry(
            $query,
            $siteId,
            $indexHandles,
            $resultCount,
            $outcomesByIndex,
        );
    }

    /**
     * @param list<string> $indexHandles
     * @return array<string, float|null>|null
     */
    public function consume(
        ?string $envelope,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
    ): ?array {
        return $this->authority->consumeV1CacheTelemetry(
            $envelope,
            $query,
            $siteId,
            $indexHandles,
            $resultCount,
        );
    }
}
