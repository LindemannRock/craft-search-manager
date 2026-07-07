<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use yii\base\Component;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * API Key Service
 *
 * Generates, hashes, and verifies API keys. Persistence lives on the
 * `ApiKey` model itself (matches the plugin's Promotion/QueryRule shape);
 * this service owns the crypto + lookup contract.
 *
 * Key format: `sm_<type>_<32 hex chars>` (40 chars total).
 * Stored prefix: first 15 chars (`sm_<type>_<8 hex chars>`) — enough for
 * unambiguous CP identification, indexed UNIQUE for O(1) lookup on the
 * enforcement hot path (slice 2).
 *
 * Hash: HMAC-SHA256 of the full plaintext key, keyed by Craft's
 * `securityKey`. Chosen over bcrypt because API keys are already 128-bit
 * CSPRNG random — there's no low-entropy attack surface bcrypt's cost
 * factor exists to slow down, and HMAC is the right primitive for
 * fast constant-time-comparable opaque-token verification.
 *
 * @since 5.46.0
 */
class ApiKeyService extends Component
{
    use LoggingTrait;

    /** @var int Number of hex characters in the random body of a key (= 128 bits entropy). */
    private const RANDOM_HEX_CHARS = 32;

    /** @var int Length of the stored/displayed prefix: `sm_xxx_` + 8 hex chars. */
    private const PREFIX_LENGTH = 15;

    /** @var string HTTP header that carries the API key on enforced endpoints. */
    public const REQUEST_HEADER = 'X-Search-Manager-Key';

    /** @var string Cache-key prefix for per-key, per-minute rate-limit counters. */
    private const RATE_LIMIT_CACHE_PREFIX = 'searchmanager:apikey:ratelimit:';

    /** @var int Rate-limit window length in seconds (fixed one-minute window). */
    private const RATE_LIMIT_WINDOW = 60;

    private const TYPE_PREFIXES = [
        ApiKey::TYPE_PUBLIC => 'sm_pub_',
        ApiKey::TYPE_SERVER => 'sm_srv_',
    ];

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // KEY GENERATION + VERIFICATION
    // =========================================================================

    /**
     * Generate a fresh plaintext key for the given type, plus its derived
     * prefix and hash. The plaintext is the caller's only chance to capture
     * the full value — only the prefix + hash are persisted.
     *
     * @return array{plaintext: string, prefix: string, hash: string}
     * @throws \InvalidArgumentException if $type isn't a recognised ApiKey type
     * @throws \Exception if the CSPRNG fails (extremely rare)
     */
    public function generateKey(string $type): array
    {
        if (!isset(self::TYPE_PREFIXES[$type])) {
            throw new \InvalidArgumentException("Invalid API key type: '$type'.");
        }

        $randomHex = bin2hex(random_bytes((int)(self::RANDOM_HEX_CHARS / 2)));
        $plaintext = self::TYPE_PREFIXES[$type] . $randomHex;

        return [
            'plaintext' => $plaintext,
            'prefix' => substr($plaintext, 0, self::PREFIX_LENGTH),
            'hash' => $this->hashKey($plaintext),
        ];
    }

    /**
     * Compute the HMAC-SHA256 hash for a plaintext key.
     * Keyed by Craft's `securityKey` so hashes are not portable across installs
     * (defence in depth against a leaked DB dump replayed on another install).
     */
    public function hashKey(string $plaintext): string
    {
        $securityKey = Craft::$app->getConfig()->getGeneral()->securityKey;
        return hash_hmac('sha256', $plaintext, $securityKey);
    }

    /**
     * Constant-time check that $plaintext is the original of $key's stored hash.
     */
    public function verifyKey(string $plaintext, ApiKey $key): bool
    {
        // Prefix mismatch is a cheap pre-check — also catches typos before crypto.
        if (!str_starts_with($plaintext, $key->keyPrefix)) {
            return false;
        }
        return hash_equals($key->keyHash, $this->hashKey($plaintext));
    }

    /**
     * Enforcement hot-path entry point (will be used in slice 2 by the
     * endpoint guard). Parses a presented plaintext key, looks up the
     * matching ApiKey row by prefix, and verifies the hash.
     *
     * Returns the matching ApiKey on success, or null on any failure
     * (unknown prefix, hash mismatch, malformed input). Failure is
     * deliberately undifferentiated to the caller — slice 2 wraps this
     * in a generic 401 with no leaked detail about why.
     */
    public function findByPlaintextKey(string $plaintext): ?ApiKey
    {
        if (strlen($plaintext) < self::PREFIX_LENGTH) {
            return null;
        }

        $prefix = substr($plaintext, 0, self::PREFIX_LENGTH);
        $key = ApiKey::findByPrefix($prefix);
        if ($key === null) {
            return null;
        }

        return $this->verifyKey($plaintext, $key) ? $key : null;
    }

    /**
     * Authenticate a presented plaintext key for an enforced endpoint.
     *
     * Slice 2 enforcement entry point: resolves the key, confirms it is
     * currently usable (enabled + not expired), records usage, and returns it.
     * Per-request restriction checks (allowed indices, referrer, maxHitsPerPage,
     * siteId) are layered on in later checkpoints — this only proves the key
     * exists and is active.
     *
     * Status codes follow the locked design: 401 when no key is presented or it
     * fails resolution/verification (undifferentiated, so we don't leak which
     * prefixes exist); 403 when a *known* key is disabled or expired.
     *
     * @throws UnauthorizedHttpException 401 — missing, unknown, or invalid key.
     * @throws ForbiddenHttpException 403 — known key that is disabled or expired.
     * @since 5.47.0
     */
    public function authenticate(?string $plaintext): ApiKey
    {
        // Enforcement messages are returned as JSON to API clients, so they
        // stay raw English (not Craft::t) per the suite's exception-message
        // convention for REST endpoints. See exception-messages.md.
        $plaintext = is_string($plaintext) ? trim($plaintext) : '';
        if ($plaintext === '') {
            throw new UnauthorizedHttpException('API key required.');
        }

        $key = $this->findByPlaintextKey($plaintext);
        if ($key === null) {
            throw new UnauthorizedHttpException('Invalid API key.');
        }

        // Known key but not active → 403 with the specific reason.
        $status = $key->getStatus();
        if ($status === ApiKey::STATUS_DISABLED) {
            throw new ForbiddenHttpException('This API key is disabled.');
        }
        if ($status === ApiKey::STATUS_EXPIRED) {
            throw new ForbiddenHttpException('This API key has expired.');
        }

        $this->recordUsage($key);

        return $key;
    }

    /**
     * Authenticate a presented key and apply the public-key referrer check —
     * the gate shared by the search/autocomplete (ApiController) and tracking
     * (SearchController) endpoints. Index / siteId / rate-limit checks are NOT
     * here; they differ per endpoint group and are applied by the caller.
     *
     * @throws UnauthorizedHttpException 401 — missing, unknown, or invalid key.
     * @throws ForbiddenHttpException 403 — disabled/expired key, or a public
     *   key whose referrer is outside its allowed referrers.
     * @since 5.47.0
     */
    public function authenticateRequest(?string $plaintext, ?string $referer): ApiKey
    {
        $key = $this->authenticate($plaintext);

        // Public keys are referrer-restricted; server keys are trusted
        // backend-to-backend and skip the check.
        if ($key->type === ApiKey::TYPE_PUBLIC && !$key->allowsReferrer($referer)) {
            // Raw English — JSON API response (see exception-messages.md).
            throw new ForbiddenHttpException('Referrer not allowed for this API key.');
        }

        return $key;
    }

    public function referrerCandidate(mixed $referer, mixed $origin): ?string
    {
        if (is_string($referer) && trim($referer) !== '') {
            return $referer;
        }

        return is_string($origin) && trim($origin) !== '' ? $origin : null;
    }

    /**
     * Apply a key's index permission boundary to the resolved request indices.
     *
     * - A `*` (all-indices) key is transparent — the request's own index scope
     *   is returned unchanged.
     * - When the request explicitly named indices, every one must be within the
     *   key's allowlist or the whole request is rejected (403).
     * - When the request named no indices, the key's own allowed indices become
     *   the explicit scope (validated against currently-enabled indices), so a
     *   restricted key never falls back to "all enabled".
     *
     * Returns `[handles, indicesProvided]`. The caller treats an empty
     * `handles` with `indicesProvided === true` as "search nothing" (empty
     * response) rather than a fall-back to all.
     *
     * @param list<string> $requestedHandles enabled-validated handles from the request
     * @return array{0: list<string>, 1: bool}
     * @throws ForbiddenHttpException 403 — a requested index is outside the allowlist.
     * @since 5.47.0
     */
    public function scopeIndices(ApiKey $key, array $requestedHandles, bool $indicesProvided): array
    {
        if ($key->allowsAllIndices()) {
            return [$requestedHandles, $indicesProvided];
        }

        if ($indicesProvided) {
            foreach ($requestedHandles as $handle) {
                if (!$key->allowsIndex($handle)) {
                    // Raw English — JSON API response (see exception-messages.md).
                    throw new ForbiddenHttpException('API key not permitted for the requested index: ' . $handle);
                }
            }

            return [$requestedHandles, true];
        }

        // No indices requested → default scope is the key's own allowlist,
        // validated against enabled indices (drops stale/disabled handles).
        [$scoped] = SearchIndex::resolveRequestedIndices(
            implode(',', $key->allowedIndices),
            '',
            max(1, count($key->allowedIndices)),
        );

        return [$scoped, true];
    }

    /**
     * Clamp a requested `hitsPerPage` to the key's `maxHitsPerPage` cap.
     * A null cap leaves the request value untouched.
     *
     * @since 5.47.0
     */
    public function clampHitsPerPage(ApiKey $key, int $requested): int
    {
        if ($key->maxHitsPerPage === null) {
            return $requested;
        }

        return min($requested, $key->maxHitsPerPage);
    }

    /**
     * Assert a requested siteId is usable for the selected indices (2c).
     *
     * The site must be a real Craft site, and every concretely-selected index
     * must apply to it (an all-sites index applies to any valid site; a
     * site-limited index must include it). An out-of-scope site is rejected
     * rather than silently returning empty, so keyed callers get a clear error.
     *
     * Called only when a siteId is provided. An empty `$indices` list (the
     * all-enabled fan-out, where there is no concrete selection) still validates
     * that the site exists. siteId stays a filter — this never widens or
     * replaces the `allowedIndices` permission boundary (2b).
     *
     * @throws BadRequestHttpException 400 — the siteId is not a real Craft site.
     * @throws ForbiddenHttpException 403 — a selected index does not cover the site.
     * @since 5.47.0
     */
    public function assertSiteInScope(int $siteId, SearchIndex ...$indices): void
    {
        // Raw English — JSON API responses (see exception-messages.md).
        if (Craft::$app->getSites()->getSiteById($siteId) === null) {
            throw new BadRequestHttpException('Unknown site requested.');
        }

        foreach ($indices as $index) {
            if (!$index->appliesToSiteId($siteId)) {
                throw new ForbiddenHttpException('The requested site is outside the scope of index "' . $index->handle . '".');
            }
        }
    }

    /**
     * Enforce the key's per-minute request cap (slice 3 — closes audit #23).
     *
     * Counts requests in a fixed one-minute window via a cache-backed counter
     * keyed per API key (`{id}:{minute}`). When the count reaches `rateLimit`,
     * the next request is rejected with `429`. A null `rateLimit` means no cap.
     * Only authenticated requests reach here (the controller calls this after
     * {@see authenticate()}), so this never applies to anonymous traffic.
     *
     * Uses Craft's cache (the same primitive the search-result cache uses). The
     * read-then-write is not atomic, so a burst at the exact window boundary may
     * allow a couple extra requests — acceptable for a coarse per-minute cap.
     *
     * @throws TooManyRequestsHttpException 429 — the per-minute cap is exceeded.
     * @since 5.47.0
     */
    public function enforceRateLimit(ApiKey $key): void
    {
        if ($key->rateLimit === null || $key->id === null) {
            return;
        }

        $cache = Craft::$app->getCache();
        $window = (int) floor(time() / self::RATE_LIMIT_WINDOW);
        $cacheKey = self::RATE_LIMIT_CACHE_PREFIX . $key->id . ':' . $window;

        $count = (int) $cache->get($cacheKey);
        if ($count >= $key->rateLimit) {
            // Raw English — JSON API response (see exception-messages.md).
            throw new TooManyRequestsHttpException('API rate limit exceeded. Try again in a moment.');
        }

        $cache->set($cacheKey, $count + 1, self::RATE_LIMIT_WINDOW);
    }

    /**
     * Build the analytics attribution options for a request's authenticated key
     * (slice 5). Returns an empty array for anonymous / unkeyed requests so the
     * analytics row records null attribution columns and stays backward
     * compatible.
     *
     * `apiKeyId` is stored as a plain int with no foreign key and is retained
     * after the key is revoked, so historical analytics rows keep their
     * correlation id. `apiKeyPrefix` / `apiKeyType` are snapshots that stay
     * readable once the key row is gone.
     *
     * @return array{apiKeyId?: int|null, apiKeyPrefix?: string, apiKeyType?: string}
     * @since 5.47.0
     */
    public function attributionOptions(?ApiKey $key): array
    {
        if ($key === null) {
            return [];
        }

        return [
            'apiKeyId' => $key->id,
            'apiKeyPrefix' => $key->keyPrefix,
            'apiKeyType' => $key->type,
        ];
    }

    /**
     * Cheap "are there any keys at all" check — a single `COUNT(*)` query.
     * Used by the API Keys CP index to decide whether to show the
     * "no keys configured yet" banner, without loading every row's data.
     */
    public function hasAnyKeys(): bool
    {
        return ApiKey::count() > 0;
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /**
     * Flip `enabled` on a set of keys in one query. Used by the CP bulk
     * Enable / Disable actions. Recoverable — operators can flip the bit
     * back at any time. Touches `dateUpdated` so audit views show the change.
     *
     * @param int[] $ids
     * @return int Number of rows actually updated (excludes rows that already had the target state)
     */
    public function bulkSetEnabled(array $ids, bool $enabled): int
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }

        $affected = (int) Craft::$app->getDb()->createCommand()
            ->update(
                '{{%searchmanager_api_keys}}',
                [
                    'enabled' => (int) $enabled,
                    'dateUpdated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
                ],
                ['id' => $ids],
            )
            ->execute();

        $this->logInfo('Bulk set API key enabled state', [
            'enabled' => $enabled,
            'requestedIds' => $ids,
            'affected' => $affected,
        ]);

        return $affected;
    }

    /**
     * Hard-delete a set of keys by id. Destructive — there is no recovery,
     * because the plaintext is unrecoverable (only the hash is persisted).
     *
     * @param int[] $ids
     * @return int Number of rows deleted
     */
    public function bulkDelete(array $ids): int
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }

        $deleted = (int) Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['id' => $ids])
            ->execute();

        $this->logInfo('Bulk revoke API keys', [
            'requestedIds' => $ids,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Filter, coerce, and dedupe an incoming list of ids. Drops anything
     * non-integer or non-positive so a malformed POST payload can't widen
     * the affected set or coerce a SQL surprise.
     *
     * @param array<mixed> $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $raw) {
            if (!is_numeric($raw)) {
                continue;
            }
            $i = (int) $raw;
            if ($i > 0) {
                $clean[$i] = true; // keys for dedupe
            }
        }
        return array_keys($clean);
    }

    /**
     * Touch `lastUsedAt` to the current UTC datetime. Called after a
     * successful enforcement check (slice 2). Wrapped in try/catch so
     * the bookkeeping write can't fail the request — the worst case is
     * a stale `lastUsedAt` value in the CP, not a denied legitimate call.
     */
    public function recordUsage(ApiKey $key): void
    {
        try {
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            Craft::$app->getDb()->createCommand()
                ->update(
                    '{{%searchmanager_api_keys}}',
                    ['lastUsedAt' => \craft\helpers\Db::prepareDateForDb($now)],
                    ['id' => $key->id],
                )
                ->execute();
            $key->lastUsedAt = $now;
        } catch (\Throwable $e) {
            $this->logWarning('Failed to record API key usage timestamp', [
                'keyId' => $key->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
