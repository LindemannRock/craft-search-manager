<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use lindemannrock\base\cache\CacheBackendStatus;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Issues and consumes one-time widget cache-telemetry envelopes.
 *
 * @internal
 * @since 5.55.0
 */
class WidgetCacheTelemetryService extends Component
{
    public const ENVELOPE_TTL = 300;
    public const MAX_MISS_DURATION_MS = 60000.0;

    private const VERSION = 1;
    private const PREFIX = 'v1.';
    private const NONCE_PATTERN = '/\A[a-zA-Z0-9_-]{22}\z/D';
    private const REPLAY_MARKER = 'search-manager-widget-cache-telemetry-v1';

    /** @var array<string, true> */
    private static array $loggedFailures = [];

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
        try {
            $now = $this->now();
            $outcomes = [];
            foreach ($indexHandles as $indexHandle) {
                $outcome = $outcomesByIndex[$indexHandle] ?? ['cached' => null, 'duration' => null];
                $cached = is_bool($outcome['cached'] ?? null) ? $outcome['cached'] : null;
                $duration = $cached === false
                    ? $this->normalizeMissDuration($outcome['duration'] ?? null)
                    : null;

                if ($cached === false && $duration === null) {
                    $cached = null;
                }

                $outcomes[] = [
                    'index' => $indexHandle,
                    'cached' => $cached,
                    'duration' => $duration,
                ];
            }

            $payload = Json::encode([
                'version' => self::VERSION,
                'query' => $this->queryIdentity($query),
                'siteId' => $siteId,
                'indexHandles' => array_values($indexHandles),
                'resultCount' => max(0, $resultCount),
                'issuedAt' => $now,
                'expiresAt' => $now + self::ENVELOPE_TTL,
                'nonce' => $this->base64UrlEncode(random_bytes(16)),
                'outcomes' => $outcomes,
            ]);
            $signed = Craft::$app->getSecurity()->hashData($payload);

            return self::PREFIX . $this->base64UrlEncode($signed);
        } catch (\Throwable) {
            $this->logFailureOnce('issue');
            return null;
        }
    }

    /**
     * @param list<string> $indexHandles
     * @return array<string, float|null>|null Execution time by index handle.
     */
    public function consume(
        ?string $envelope,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
    ): ?array {
        if (!is_string($envelope) || !str_starts_with($envelope, self::PREFIX)) {
            return null;
        }

        try {
            $signed = $this->base64UrlDecode(substr($envelope, strlen(self::PREFIX)));
            if ($signed === null) {
                return null;
            }

            $json = Craft::$app->getSecurity()->validateData($signed);
            if (!is_string($json)) {
                return null;
            }

            $payload = Json::decodeIfJson($json, true);
            if (!is_array($payload) || !$this->hasValidBinding($payload, $query, $siteId, $indexHandles, $resultCount)) {
                return null;
            }

            $now = $this->now();
            $issuedAt = $payload['issuedAt'];
            $expiresAt = $payload['expiresAt'];
            if (
                !is_int($issuedAt)
                || !is_int($expiresAt)
                || $issuedAt > $now + 30
                || $expiresAt <= $now
                || $expiresAt <= $issuedAt
                || $expiresAt - $issuedAt > self::ENVELOPE_TTL
            ) {
                return null;
            }

            $nonce = $payload['nonce'];
            if (!is_string($nonce) || preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
                return null;
            }

            $executionTimes = $this->executionTimes($payload['outcomes'], $indexHandles);
            if ($executionTimes === null || !$this->claimReplayMarker($nonce, $expiresAt - $now)) {
                return null;
            }

            return $executionTimes;
        } catch (\Throwable) {
            $this->logFailureOnce('consume');
            return null;
        }
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $indexHandles
     */
    private function hasValidBinding(
        array $payload,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
    ): bool {
        return ($payload['version'] ?? null) === self::VERSION
            && ($payload['query'] ?? null) === $this->queryIdentity($query)
            && ($payload['siteId'] ?? null) === $siteId
            && ($payload['indexHandles'] ?? null) === array_values($indexHandles)
            && ($payload['resultCount'] ?? null) === max(0, $resultCount)
            && is_array($payload['outcomes'] ?? null);
    }

    /**
     * @param mixed $rawOutcomes
     * @param list<string> $indexHandles
     * @return array<string, float|null>|null
     */
    private function executionTimes(mixed $rawOutcomes, array $indexHandles): ?array
    {
        if (!is_array($rawOutcomes) || count($rawOutcomes) !== count($indexHandles)) {
            return null;
        }

        $executionTimes = [];
        foreach ($indexHandles as $position => $indexHandle) {
            $outcome = $rawOutcomes[$position] ?? null;
            if (!is_array($outcome) || ($outcome['index'] ?? null) !== $indexHandle) {
                return null;
            }

            $cached = $outcome['cached'] ?? null;
            if ($cached === true) {
                $executionTimes[$indexHandle] = 0.0;
                continue;
            }
            if ($cached === false) {
                $duration = $this->normalizeMissDuration($outcome['duration'] ?? null);
                if ($duration === null) {
                    return null;
                }
                $executionTimes[$indexHandle] = $duration;
                continue;
            }
            if ($cached !== null || ($outcome['duration'] ?? null) !== null) {
                return null;
            }

            $executionTimes[$indexHandle] = null;
        }

        return $executionTimes;
    }

    private function normalizeMissDuration(mixed $duration): ?float
    {
        if (!is_int($duration) && !is_float($duration)) {
            return null;
        }

        $duration = (float)$duration;
        if (!is_finite($duration) || $duration < 0 || $duration > self::MAX_MISS_DURATION_MS) {
            return null;
        }

        return max(0.01, $duration);
    }

    private function claimReplayMarker(string $nonce, int $ttl): bool
    {
        if ($ttl <= 0) {
            return false;
        }

        $cache = PluginHelper::getApplicationCacheOrLog(SearchManager::$plugin->id . ':widget-cache-telemetry-replay');
        $status = CacheBackendStatus::fromCache($cache);
        if ($cache === null || !$status->supportsCrossRequest(App::isEphemeral())) {
            $this->logFailureOnce('replay-cache');
            return false;
        }

        $key = sprintf(
            'lr-cache:v1:app:%s:plugin:%s:family:widget-cache-telemetry-replay:item:%s',
            hash('sha256', (string)Craft::$app->id),
            SearchManager::$plugin->id,
            hash('sha256', $nonce),
        );

        try {
            return $cache->add($key, self::REPLAY_MARKER, min(self::ENVELOPE_TTL, $ttl)) === true;
        } catch (\Throwable) {
            $this->logFailureOnce('replay-cache');
            return false;
        }
    }

    private function queryIdentity(string $query): string
    {
        return hash('sha256', QueryNormalizer::forCacheIdentity($query));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function logFailureOnce(string $operation): void
    {
        if (isset(self::$loggedFailures[$operation])) {
            return;
        }

        self::$loggedFailures[$operation] = true;
        Craft::warning(
            'Widget cache telemetry is unavailable; analytics cache classification will remain unknown.',
            SearchManager::$plugin->id,
        );
    }
}
