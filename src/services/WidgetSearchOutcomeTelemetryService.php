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
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * Issues and consumes one-time widget search-outcome telemetry.
 *
 * The current envelope contains only signed request bindings and a random
 * nonce. Complete per-index outcomes remain in a short-lived, server-side
 * cache record until the matching intent request consumes them.
 *
 * @internal
 * @since 5.56.0
 */
class WidgetSearchOutcomeTelemetryService extends Component
{
    public const ENVELOPE_TTL = 300;
    public const MAX_MISS_DURATION_MS = 60000.0;

    private const VERSION = 2;
    private const PREFIX = 'v2.';
    private const V1_VERSION = 1;
    private const V1_PREFIX = 'v1.';
    private const MAX_RULES_PER_INDEX = 100;
    private const MAX_PROMOTIONS_PER_INDEX = 100;
    private const MAX_RULE_NAME_LENGTH = 255;
    private const MAX_BACKEND_LENGTH = 64;
    private const NONCE_PATTERN = '/\A[a-zA-Z0-9_-]{22}\z/D';
    private const REPLAY_MARKER = 'search-manager-widget-search-outcome-v2';
    private const V1_REPLAY_MARKER = 'search-manager-widget-cache-telemetry-v1';

    /** @var array<string, true> */
    private static array $loggedFailures = [];

    /**
     * @param list<string> $indexHandles
     * @param array<string, array<string, mixed>> $outcomesByIndex
     */
    public function issueSearchOutcome(
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
        string $source,
        array $outcomesByIndex,
    ): ?string {
        try {
            $cache = $this->suitableCache('record-cache');
            if ($cache === null) {
                return null;
            }

            $now = $this->now();
            $expiresAt = $now + self::ENVELOPE_TTL;
            $nonce = $this->base64UrlEncode(random_bytes(16));
            $outcomes = $this->normalizeSearchOutcomes($indexHandles, $outcomesByIndex);
            if ($outcomes === null) {
                $this->logFailureOnce('outcome-normalization');
                return null;
            }
            if (!$cache->set($this->recordKey($nonce), [
                'version' => self::VERSION,
                'issuedAt' => $now,
                'expiresAt' => $expiresAt,
                'outcomes' => $outcomes,
            ], self::ENVELOPE_TTL)) {
                $this->logFailureOnce('record-write');
                return null;
            }

            $payload = Json::encode([
                'version' => self::VERSION,
                'query' => $this->queryIdentity($query),
                'siteId' => $siteId,
                'indexHandles' => array_values($indexHandles),
                'resultCount' => max(0, $resultCount),
                'source' => $source,
                'issuedAt' => $now,
                'expiresAt' => $expiresAt,
                'nonce' => $nonce,
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
     * @return array<string, array{executionTime: float|null, backend: string, synonymsExpanded: bool, rulesMatched: int, matchedRules: list<array{id: int, name: string, actionType: string}>, wasRedirected: bool, promotions: list<array{id: int, elementId: int, position: int}>}>|null
     */
    public function consumeSearchOutcome(
        ?string $envelope,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
        string $source,
    ): ?array {
        if (!is_string($envelope) || !str_starts_with($envelope, self::PREFIX)) {
            return null;
        }

        try {
            $payload = $this->decodeEnvelope($envelope, self::PREFIX);
            if (
                $payload === null
                || !$this->hasValidSearchBinding($payload, $query, $siteId, $indexHandles, $resultCount, $source)
                || !$this->hasValidLifetime($payload)
            ) {
                return null;
            }

            $nonce = $payload['nonce'];
            if (!is_string($nonce) || preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
                return null;
            }

            $now = $this->now();
            $expiresAt = $payload['expiresAt'];
            $cache = $this->suitableCache('record-cache');
            if ($cache === null || !$this->claimReplayMarker($cache, $nonce, $expiresAt - $now, false)) {
                return null;
            }

            $recordKey = $this->recordKey($nonce);
            try {
                $record = $cache->get($recordKey);
            } finally {
                $cache->delete($recordKey);
            }

            if (
                !is_array($record)
                || ($record['version'] ?? null) !== self::VERSION
                || ($record['issuedAt'] ?? null) !== $payload['issuedAt']
                || ($record['expiresAt'] ?? null) !== $expiresAt
            ) {
                return null;
            }

            return $this->consumedSearchOutcomes($record['outcomes'] ?? null, $indexHandles);
        } catch (\Throwable) {
            $this->logFailureOnce('consume');
            return null;
        }
    }

    /**
     * Issue the v1 cache-only envelope.
     *
     * @param list<string> $indexHandles
     * @param array<string, array{cached: bool|null, duration: float|null}> $outcomesByIndex
     */
    public function issueV1CacheTelemetry(
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
        array $outcomesByIndex,
    ): ?string {
        try {
            $now = $this->now();
            $outcomes = $this->normalizeCacheOutcomes($indexHandles, $outcomesByIndex);
            $payload = Json::encode([
                'version' => self::V1_VERSION,
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

            return self::V1_PREFIX . $this->base64UrlEncode($signed);
        } catch (\Throwable) {
            $this->logFailureOnce('v1-issue');
            return null;
        }
    }

    /**
     * Consume the v1 cache-only envelope.
     *
     * @param list<string> $indexHandles
     * @return array<string, float|null>|null Execution time by index handle.
     */
    public function consumeV1CacheTelemetry(
        ?string $envelope,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
    ): ?array {
        if (!is_string($envelope) || !str_starts_with($envelope, self::V1_PREFIX)) {
            return null;
        }

        try {
            $payload = $this->decodeEnvelope($envelope, self::V1_PREFIX);
            if (
                $payload === null
                || !$this->hasValidV1Binding($payload, $query, $siteId, $indexHandles, $resultCount)
                || !$this->hasValidLifetime($payload)
            ) {
                return null;
            }

            $nonce = $payload['nonce'];
            $now = $this->now();
            $expiresAt = $payload['expiresAt'];
            if (
                !is_string($nonce)
                || preg_match(self::NONCE_PATTERN, $nonce) !== 1
                || !$this->claimReplayMarker(null, $nonce, $expiresAt - $now, true)
            ) {
                return null;
            }

            return $this->executionTimes($payload['outcomes'], $indexHandles);
        } catch (\Throwable) {
            $this->logFailureOnce('v1-consume');
            return null;
        }
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * @param list<string> $indexHandles
     * @param array<string, array<string, mixed>> $outcomesByIndex
     * @return list<array{index: string, cached: bool|null, duration: float|null, backend: string, synonymsExpanded: bool, matchedRules: list<array{id: int, name: string, actionType: string}>, wasRedirected: bool, promotions: list<array{id: int, elementId: int, position: int}>}>|null
     */
    private function normalizeSearchOutcomes(array $indexHandles, array $outcomesByIndex): ?array
    {
        $cacheOutcomes = $this->normalizeCacheOutcomes($indexHandles, $outcomesByIndex);

        $outcomes = [];
        foreach ($cacheOutcomes as $position => $cacheOutcome) {
            $indexHandle = $indexHandles[$position];
            $rawOutcome = $outcomesByIndex[$indexHandle] ?? [];
            $backend = is_string($rawOutcome['backend'] ?? null)
                ? trim($rawOutcome['backend'])
                : 'unknown';
            if ($backend === '') {
                $backend = 'unknown';
            }
            if (mb_strlen($backend) > self::MAX_BACKEND_LENGTH) {
                return null;
            }
            $matchedRules = $this->normalizeRuleSnapshots($rawOutcome['matchedRules'] ?? []);
            if ($matchedRules === null) {
                return null;
            }
            $rawPromotions = $rawOutcome['promotions'] ?? [];
            if (!is_array($rawPromotions) || count($rawPromotions) > self::MAX_PROMOTIONS_PER_INDEX) {
                return null;
            }

            $promotions = [];
            foreach ($rawPromotions as $promotion) {
                if (
                    !is_array($promotion)
                    || !is_int($promotion['id'] ?? null)
                    || !is_int($promotion['elementId'] ?? null)
                    || !is_int($promotion['position'] ?? null)
                    || $promotion['id'] <= 0
                    || $promotion['elementId'] <= 0
                    || $promotion['position'] < 0
                ) {
                    return null;
                }
                $promotions[$promotion['id']] = [
                    'id' => $promotion['id'],
                    'elementId' => $promotion['elementId'],
                    'position' => $promotion['position'],
                ];
            }
            $outcomes[] = array_merge($cacheOutcome, [
                'backend' => $backend,
                'synonymsExpanded' => ($rawOutcome['synonymsExpanded'] ?? false) === true,
                'matchedRules' => $matchedRules,
                'wasRedirected' => ($rawOutcome['wasRedirected'] ?? false) === true,
                'promotions' => array_values($promotions),
            ]);
        }

        return $outcomes;
    }

    /**
     * @param list<string> $indexHandles
     * @param array<string, array{cached: bool|null, duration: float|null}> $outcomesByIndex
     * @return list<array{index: string, cached: bool|null, duration: float|null}>
     */
    private function normalizeCacheOutcomes(array $indexHandles, array $outcomesByIndex): array
    {
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

        return $outcomes;
    }

    /** @return array<string, mixed>|null */
    private function decodeEnvelope(string $envelope, string $prefix): ?array
    {
        $signed = $this->base64UrlDecode(substr($envelope, strlen($prefix)));
        if ($signed === null) {
            return null;
        }

        $json = Craft::$app->getSecurity()->validateData($signed);
        if (!is_string($json)) {
            return null;
        }

        $payload = Json::decodeIfJson($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $indexHandles
     */
    private function hasValidSearchBinding(
        array $payload,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
        string $source,
    ): bool {
        return ($payload['version'] ?? null) === self::VERSION
            && ($payload['query'] ?? null) === $this->queryIdentity($query)
            && ($payload['siteId'] ?? null) === $siteId
            && ($payload['indexHandles'] ?? null) === array_values($indexHandles)
            && ($payload['resultCount'] ?? null) === max(0, $resultCount)
            && ($payload['source'] ?? null) === $source;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $indexHandles
     */
    private function hasValidV1Binding(
        array $payload,
        string $query,
        ?int $siteId,
        array $indexHandles,
        int $resultCount,
    ): bool {
        return ($payload['version'] ?? null) === self::V1_VERSION
            && ($payload['query'] ?? null) === $this->queryIdentity($query)
            && ($payload['siteId'] ?? null) === $siteId
            && ($payload['indexHandles'] ?? null) === array_values($indexHandles)
            && ($payload['resultCount'] ?? null) === max(0, $resultCount)
            && is_array($payload['outcomes'] ?? null);
    }

    /** @param array<string, mixed> $payload */
    private function hasValidLifetime(array $payload): bool
    {
        $now = $this->now();
        $issuedAt = $payload['issuedAt'] ?? null;
        $expiresAt = $payload['expiresAt'] ?? null;

        return is_int($issuedAt)
            && is_int($expiresAt)
            && $issuedAt <= $now + 30
            && $expiresAt > $now
            && $expiresAt > $issuedAt
            && $expiresAt - $issuedAt <= self::ENVELOPE_TTL;
    }

    /**
     * @param mixed $rawOutcomes
     * @param list<string> $indexHandles
     * @return array<string, array{executionTime: float|null, backend: string, synonymsExpanded: bool, rulesMatched: int, matchedRules: list<array{id: int, name: string, actionType: string}>, wasRedirected: bool, promotions: list<array{id: int, elementId: int, position: int}>}>|null
     */
    private function consumedSearchOutcomes(mixed $rawOutcomes, array $indexHandles): ?array
    {
        $executionTimes = $this->executionTimes($rawOutcomes, $indexHandles);
        if ($executionTimes === null || !is_array($rawOutcomes)) {
            return null;
        }

        $outcomes = [];
        foreach ($indexHandles as $position => $indexHandle) {
            $raw = $rawOutcomes[$position] ?? null;
            if (
                !is_array($raw)
                || !is_string($raw['backend'] ?? null)
                || $raw['backend'] === ''
                || mb_strlen($raw['backend']) > self::MAX_BACKEND_LENGTH
                || !is_bool($raw['synonymsExpanded'] ?? null)
                || !is_array($raw['matchedRules'] ?? null)
                || !is_bool($raw['wasRedirected'] ?? null)
                || !is_array($raw['promotions'] ?? null)
                || count($raw['promotions']) > self::MAX_PROMOTIONS_PER_INDEX
            ) {
                return null;
            }

            $matchedRules = $this->normalizeRuleSnapshots($raw['matchedRules']);
            if ($matchedRules === null || count($matchedRules) !== count($raw['matchedRules'])) {
                return null;
            }

            $promotions = [];
            $promotionIds = [];
            foreach ($raw['promotions'] as $promotion) {
                if (
                    !is_array($promotion)
                    || !is_int($promotion['id'] ?? null)
                    || !is_int($promotion['elementId'] ?? null)
                    || !is_int($promotion['position'] ?? null)
                    || $promotion['id'] <= 0
                    || $promotion['elementId'] <= 0
                    || $promotion['position'] < 0
                    || isset($promotionIds[$promotion['id']])
                ) {
                    return null;
                }
                $promotionIds[$promotion['id']] = true;
                $promotions[] = $promotion;
            }

            $outcomes[$indexHandle] = [
                'executionTime' => $executionTimes[$indexHandle],
                'backend' => $raw['backend'],
                'synonymsExpanded' => $raw['synonymsExpanded'],
                'rulesMatched' => count($matchedRules),
                'matchedRules' => $matchedRules,
                'wasRedirected' => $raw['wasRedirected'],
                'promotions' => $promotions,
            ];
        }

        return $outcomes;
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

    /**
     * @return list<array{id: int, name: string, actionType: string}>|null
     */
    private function normalizeRuleSnapshots(mixed $rawRules): ?array
    {
        if (!is_array($rawRules) || count($rawRules) > self::MAX_RULES_PER_INDEX) {
            return null;
        }

        $actionTypes = [
            QueryRule::ACTION_SYNONYM,
            QueryRule::ACTION_BOOST_SECTION,
            QueryRule::ACTION_BOOST_CATEGORY,
            QueryRule::ACTION_BOOST_ELEMENT,
            QueryRule::ACTION_REDIRECT,
        ];
        $rules = [];
        foreach ($rawRules as $rule) {
            if (
                !is_array($rule)
                || !is_int($rule['id'] ?? null)
                || $rule['id'] <= 0
                || !is_string($rule['name'] ?? null)
                || mb_strlen($rule['name']) > self::MAX_RULE_NAME_LENGTH
                || !is_string($rule['actionType'] ?? null)
                || !in_array($rule['actionType'], $actionTypes, true)
            ) {
                return null;
            }
            $rules[$rule['id']] ??= [
                'id' => $rule['id'],
                'name' => $rule['name'],
                'actionType' => $rule['actionType'],
            ];
        }

        return array_values($rules);
    }

    private function claimReplayMarker(?CacheInterface $cache, string $nonce, int $ttl, bool $v1): bool
    {
        if ($ttl <= 0) {
            return false;
        }

        $cache ??= $this->suitableCache('replay-cache');
        if ($cache === null) {
            return false;
        }

        $family = $v1 ? 'widget-cache-telemetry-replay' : 'widget-search-outcome-replay';
        $marker = $v1 ? self::V1_REPLAY_MARKER : self::REPLAY_MARKER;

        try {
            return $cache->add($this->cacheKey($family, $nonce), $marker, min(self::ENVELOPE_TTL, $ttl)) === true;
        } catch (\Throwable) {
            $this->logFailureOnce('replay-cache');
            return false;
        }
    }

    private function suitableCache(string $operation): ?CacheInterface
    {
        $cache = PluginHelper::getApplicationCacheOrLog(SearchManager::$plugin->id . ':widget-search-outcome');
        $status = CacheBackendStatus::fromCache($cache);
        if ($cache === null || !$status->supportsCrossRequest(App::isEphemeral())) {
            $this->logFailureOnce($operation);
            return null;
        }

        return $cache;
    }

    private function recordKey(string $nonce): string
    {
        return $this->cacheKey('widget-search-outcome', $nonce);
    }

    private function cacheKey(string $family, string $nonce): string
    {
        return sprintf(
            'lr-cache:v1:app:%s:plugin:%s:family:%s:item:%s',
            hash('sha256', (string)Craft::$app->id),
            SearchManager::$plugin->id,
            $family,
            hash('sha256', $nonce),
        );
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
            'Widget search-outcome telemetry is unavailable; analytics search outcomes will remain unknown.',
            SearchManager::$plugin->id,
        );
    }
}
