<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * API Key Model
 *
 * Represents a hashed API key gating access to public search endpoints.
 *
 * Plaintext keys are generated once via `ApiKeyService::generateKey()` and
 * shown to the operator on creation. Only the hash (HMAC-SHA256 of the
 * plaintext keyed by Craft's `securityKey`) and a 15-char display prefix
 * persist. Lookup on the enforcement hot path is by `keyPrefix` (unique
 * indexed), then `hash_equals` against `keyHash`.
 *
 * Restrictions are per-key (multi-dimensional model):
 *  - `allowedIndices` — whitelist of index handles, or `['*']` for all
 *  - `allowedReferrers` — domain patterns (`example.com`, `*.example.com`)
 *  - `maxHitsPerPage` — clamp on per-request hitsPerPage parameter
 *  - `validUntil` — expiry datetime; null = never expires
 *  - `rateLimit` — requests per minute (enforced in slice 3)
 *
 * Type (`public` | `server`) describes intended exposure / trust level, not
 * a restriction-bypass. Server keys can carry the same restrictions as
 * public keys; the type only documents whether the key is safe to embed in
 * browser code (public) or strictly server-side (server).
 *
 * @since 5.46.0
 */
class ApiKey extends Model
{
    use LoggingTrait;

    public const TYPE_PUBLIC = 'public';
    public const TYPE_SERVER = 'server';

    public const TYPES = [self::TYPE_PUBLIC, self::TYPE_SERVER];

    /**
     * Wildcard value stored in `allowedIndicesJson` to mean
     * "all currently-enabled indices, plus any added later."
     */
    public const ALL_INDICES = '*';

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;

    public string $name = '';

    public string $type = self::TYPE_PUBLIC;

    /**
     * @var bool When false the key is "paused" — enforcement (slice 2) must
     *   reject it. Lets operators temporarily disable a key without losing
     *   the plaintext / config. Distinct from `validUntil` (automatic expiry)
     *   and from revoke (delete). Defaults to true so freshly-generated keys
     *   are immediately usable.
     */
    public bool $enabled = true;

    /**
     * @var string HMAC-SHA256 hash of the plaintext key. Not exposed to UI.
     */
    public string $keyHash = '';

    /**
     * @var string Unhashed prefix of the plaintext key, e.g. `sm_pub_a1b2c3d4` (15 chars).
     *   Stored for CP display + as the lookup index for enforcement.
     */
    public string $keyPrefix = '';

    /**
     * @var string[] Index handle whitelist, or `[self::ALL_INDICES]` for all.
     *   Empty array means "no indices allowed" — keys are non-functional until populated.
     */
    public array $allowedIndices = [];

    /**
     * @var string[] Allowed referrer domain patterns.
     *   Exact host (`example.com`) or wildcard subdomain (`*.example.com`, any depth).
     *   Empty array = all referrers allowed (no restriction).
     */
    public array $allowedReferrers = [];

    public ?int $maxHitsPerPage = null;

    public ?\DateTime $validUntil = null;

    public ?int $rateLimit = null;

    public ?\DateTime $lastUsedAt = null;

    public ?\DateTime $dateCreated = null;

    public ?\DateTime $dateUpdated = null;

    public ?string $uid = null;

    // =========================================================================
    // INIT + VALIDATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['name', 'keyHash', 'keyPrefix', 'type'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['keyHash'], 'string', 'max' => 128],
            [['keyPrefix'], 'string', 'max' => 32],
            [['type'], 'in', 'range' => self::TYPES],
            [['enabled'], 'boolean'],
            [['maxHitsPerPage', 'rateLimit'], 'integer', 'min' => 1, 'max' => 100000],
            [['allowedIndices', 'allowedReferrers'], 'each', 'rule' => ['string', 'max' => 255]],
            [['allowedIndices'], 'validateAllowedIndices', 'skipOnEmpty' => false],
            [['allowedReferrers'], 'validateReferrerPatterns'],
        ];
    }

    /**
     * Enabled keys must have an explicit index permission boundary. An empty
     * allowlist is valid only for disabled draft keys.
     */
    public function validateAllowedIndices(string $attribute): void
    {
        if ($this->enabled && empty($this->allowedIndices)) {
            $this->addError($attribute, Craft::t('search-manager', 'Enabled keys must allow all indices or at least one specific index.'));
        }
    }

    /**
     * Reject regex-looking referrer values + obvious malformed patterns.
     * Wildcard support is intentionally simple: `*.host` matches any subdomain
     * depth; full regex would expose the runtime to ReDoS at request time.
     */
    public function validateReferrerPatterns(string $attribute): void
    {
        foreach ($this->$attribute as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // Allow only: optional leading `*.`, then host chars (alnum, hyphen, dot)
            if (!preg_match('/^(\*\.)?[a-zA-Z0-9][a-zA-Z0-9\-.]*[a-zA-Z0-9]$/', $pattern)) {
                $this->addError($attribute, "Invalid referrer pattern: '$pattern'. Use 'example.com' or '*.example.com'.");
            }
        }
    }

    // =========================================================================
    // SEMANTIC HELPERS
    // =========================================================================

    /**
     * True if this key is allowed against any index (the `*` wildcard).
     */
    public function allowsAllIndices(): bool
    {
        return in_array(self::ALL_INDICES, $this->allowedIndices, true);
    }

    /**
     * True if this key currently allows the given index handle.
     * Used on the enforcement hot path in slice 2.
     */
    public function allowsIndex(string $indexHandle): bool
    {
        if ($this->allowsAllIndices()) {
            return true;
        }
        return in_array($indexHandle, $this->allowedIndices, true);
    }

    /**
     * True if the request's `Referer` is allowed by this key's referrer
     * patterns. An empty pattern list means "no referrer restriction" → always
     * allowed. With patterns set, a missing/unparseable referrer is rejected.
     *
     * Patterns match against the referrer's host: `example.com` (exact) or
     * `*.example.com` (the base domain or any subdomain). Case-insensitive.
     * Referrer enforcement applies to public keys only (server keys skip it).
     *
     * @since 5.47.0
     */
    public function allowsReferrer(?string $referer): bool
    {
        if (empty($this->allowedReferrers)) {
            return true;
        }
        if ($referer === null || trim($referer) === '') {
            return false;
        }

        $host = parse_url(trim($referer), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        foreach ($this->allowedReferrers as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '*.')) {
                $base = substr($pattern, 2);
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_EXPIRED = 'expired';

    /**
     * The display status string. Priority order: disabled > expired > active.
     * Disabled comes first because it reflects an intentional operator action;
     * expiry is automatic/background.
     */
    public function getStatus(): string
    {
        if (!$this->enabled) {
            return self::STATUS_DISABLED;
        }
        if ($this->validUntil !== null && !$this->isStillValid()) {
            return self::STATUS_EXPIRED;
        }
        return self::STATUS_ACTIVE;
    }

    /**
     * True if no `validUntil` set, or `validUntil` is still in the future.
     */
    public function isStillValid(): bool
    {
        if ($this->validUntil === null) {
            return true;
        }
        return $this->validUntil > new \DateTime('now', new \DateTimeZone('UTC'));
    }

    // =========================================================================
    // FINDERS
    // =========================================================================

    public static function findById(int $id): ?self
    {
        $row = (new Query())
            ->from('{{%searchmanager_api_keys}}')
            ->where(['id' => $id])
            ->one();

        return $row ? self::populateFromRow($row) : null;
    }

    /**
     * Find a key by its unhashed prefix. Used on the enforcement hot path:
     * client supplies plaintext, we slice off the prefix, look it up here,
     * then verify the rest with `hash_equals` against `keyHash`.
     */
    public static function findByPrefix(string $keyPrefix): ?self
    {
        $row = (new Query())
            ->from('{{%searchmanager_api_keys}}')
            ->where(['keyPrefix' => $keyPrefix])
            ->one();

        return $row ? self::populateFromRow($row) : null;
    }

    /**
     * @return self[]
     */
    public static function findAll(?string $type = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_api_keys}}')
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($type !== null) {
            $query->andWhere(['type' => $type]);
        }

        $keys = [];
        foreach ($query->all() as $row) {
            $keys[] = self::populateFromRow($row);
        }

        return $keys;
    }

    public static function count(): int
    {
        return (int)(new Query())
            ->from('{{%searchmanager_api_keys}}')
            ->count();
    }

    private static function populateFromRow(array $row): self
    {
        $key = new self();
        $key->id = (int)$row['id'];
        $key->name = (string)$row['name'];
        $key->type = (string)$row['type'];
        $key->enabled = (bool)($row['enabled'] ?? true);
        $key->keyHash = (string)$row['keyHash'];
        $key->keyPrefix = (string)$row['keyPrefix'];
        $key->allowedIndices = self::decodeJsonArray($row['allowedIndicesJson'] ?? null);
        $key->allowedReferrers = self::decodeJsonArray($row['allowedReferrersJson'] ?? null);
        $key->maxHitsPerPage = isset($row['maxHitsPerPage']) ? (int)$row['maxHitsPerPage'] : null;
        $key->rateLimit = isset($row['rateLimit']) ? (int)$row['rateLimit'] : null;
        $key->validUntil = self::parseDate($row['validUntil'] ?? null);
        $key->lastUsedAt = self::parseDate($row['lastUsedAt'] ?? null);
        $key->dateCreated = self::parseDate($row['dateCreated'] ?? null);
        $key->dateUpdated = self::parseDate($row['dateUpdated'] ?? null);
        $key->uid = $row['uid'] ?? null;

        return $key;
    }

    /**
     * @return string[]
     */
    private static function decodeJsonArray(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private static function parseDate(mixed $value): ?\DateTime
    {
        if (empty($value)) {
            return null;
        }
        try {
            return new \DateTime((string)$value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    // =========================================================================
    // PERSISTENCE
    // =========================================================================

    public function save(): bool
    {
        if (!$this->validate()) {
            $this->logError('API key validation failed', [
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $attributes = [
                'name' => $this->name,
                'type' => $this->type,
                'enabled' => (int)$this->enabled,
                'keyHash' => $this->keyHash,
                'keyPrefix' => $this->keyPrefix,
                'allowedIndicesJson' => json_encode(array_values($this->allowedIndices), JSON_THROW_ON_ERROR),
                'allowedReferrersJson' => json_encode(array_values($this->allowedReferrers), JSON_THROW_ON_ERROR),
                'maxHitsPerPage' => $this->maxHitsPerPage,
                'validUntil' => $this->validUntil ? Db::prepareDateForDb($this->validUntil) : null,
                'rateLimit' => $this->rateLimit,
                'lastUsedAt' => $this->lastUsedAt ? Db::prepareDateForDb($this->lastUsedAt) : null,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_api_keys}}', $attributes, ['id' => $this->id])
                    ->execute();
            } else {
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_api_keys}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();
                $this->uid = $attributes['uid'];
            }

            $this->logInfo('API key saved', [
                'id' => $this->id,
                'name' => $this->name,
                'type' => $this->type,
                'keyPrefix' => $this->keyPrefix,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to save API key', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_api_keys}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->logInfo('API key deleted', [
                    'id' => $this->id,
                    'name' => $this->name,
                    'keyPrefix' => $this->keyPrefix,
                ]);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete API key', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
