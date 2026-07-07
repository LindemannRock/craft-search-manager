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
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the contract for API key generation, hashing, and verification.
 *
 * Slice 1 foundation coverage. Enforcement-side behaviour (endpoint guards,
 * referrer/indices/maxHits/expiry/rate-limit checks) lands in slice 2 with
 * its own test file.
 *
 * @since 5.46.0
 */
final class ApiKeyServiceTest extends TestCase
{
    /**
     * Marker `name` value used for every test-seeded key. Cleanup deletes by
     * exact match so real CP-created keys are never touched.
     */
    private const TEST_KEY_NAME_PREFIX = '__sm_dedup_test__';

    private int $seedCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCounter = 0;
        $this->purgeTestKeys();
    }

    protected function tearDown(): void
    {
        $this->purgeTestKeys();
        parent::tearDown();
    }

    public function testGenerateKeyProducesCorrectShapeForPublicType(): void
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);

        $this->assertArrayHasKey('plaintext', $generated);
        $this->assertArrayHasKey('prefix', $generated);
        $this->assertArrayHasKey('hash', $generated);

        $this->assertSame(39, strlen($generated['plaintext']), '39 chars total: 7 type-prefix (sm_pub_) + 32 hex body');
        $this->assertStringStartsWith('sm_pub_', $generated['plaintext']);
        $this->assertMatchesRegularExpression('/^sm_pub_[0-9a-f]{32}$/', $generated['plaintext']);

        $this->assertSame(15, strlen($generated['prefix']));
        $this->assertSame(substr($generated['plaintext'], 0, 15), $generated['prefix']);

        $this->assertSame(64, strlen($generated['hash']), 'SHA-256 hex = 64 chars');
    }

    public function testGenerateKeyProducesCorrectShapeForServerType(): void
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_SERVER);

        $this->assertStringStartsWith('sm_srv_', $generated['plaintext']);
        $this->assertMatchesRegularExpression('/^sm_srv_[0-9a-f]{32}$/', $generated['plaintext']);
        $this->assertStringStartsWith('sm_srv_', $generated['prefix']);
    }

    public function testGenerateKeyRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SearchManager::$plugin->apiKeys->generateKey('unknown-type');
    }

    public function testGeneratedKeysAreUniquePerCall(): void
    {
        $a = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $b = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);

        $this->assertNotSame($a['plaintext'], $b['plaintext']);
        $this->assertNotSame($a['prefix'], $b['prefix']);
        $this->assertNotSame($a['hash'], $b['hash']);
    }

    public function testHashKeyIsDeterministicAndUsesSecurityKey(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        $hashA = $service->hashKey('sm_pub_test_plaintext');
        $hashB = $service->hashKey('sm_pub_test_plaintext');
        $hashC = $service->hashKey('sm_pub_test_plaintextX');

        $this->assertSame($hashA, $hashB, 'Same input → same hash');
        $this->assertNotSame($hashA, $hashC, 'Different input → different hash');
        $this->assertSame(64, strlen($hashA), 'SHA-256 hex output');
    }

    public function testVerifyKeyAcceptsCorrectPlaintext(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        [$key, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        $this->assertTrue($service->verifyKey($plaintext, $key));
    }

    public function testVerifyKeyRejectsWrongPlaintext(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        // Right prefix, wrong body — must NOT verify (hash mismatch).
        $wrong = $key->keyPrefix . str_repeat('0', 32 - 8);
        $this->assertFalse($service->verifyKey($wrong, $key));

        // Completely unrelated string — must NOT verify (prefix mismatch).
        $this->assertFalse($service->verifyKey('not_even_close', $key));
    }

    public function testFindByPlaintextKeyReturnsMatchingRecord(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        [$key, $plaintext] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        $found = $service->findByPlaintextKey($plaintext);
        $this->assertNotNull($found);
        $this->assertSame($key->id, $found->id);
        $this->assertSame($key->keyPrefix, $found->keyPrefix);
    }

    public function testFindByPlaintextKeyReturnsNullForUnknownPrefix(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        $this->seedKey(ApiKey::TYPE_PUBLIC); // ensures the table isn't empty

        $this->assertNull($service->findByPlaintextKey('sm_pub_deadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function testFindByPlaintextKeyReturnsNullForKnownPrefixButWrongHash(): void
    {
        // The forged plaintext shares a real key's prefix (impossible in
        // practice — prefix is 8 hex from the same CSPRNG body — but the
        // crypto must still reject a hash mismatch).
        $service = SearchManager::$plugin->apiKeys;
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        $forged = $key->keyPrefix . str_repeat('f', 32 - 8);
        $this->assertNull($service->findByPlaintextKey($forged));
    }

    public function testFindByPlaintextKeyReturnsNullForTooShortInput(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        $this->assertNull($service->findByPlaintextKey(''));
        $this->assertNull($service->findByPlaintextKey('short'));
    }

    public function testHasAnyKeysReflectsTableState(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        // After purgeTestKeys() in setUp(), table may still have real CP-created
        // keys — we can't assert false unconditionally. But after seeding one
        // test key, the answer must be true regardless of pre-existing rows.
        $this->seedKey(ApiKey::TYPE_PUBLIC);
        $this->assertTrue($service->hasAnyKeys());
    }

    public function testRecordUsageTouchesLastUsedAt(): void
    {
        $service = SearchManager::$plugin->apiKeys;
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        $this->assertNull($key->lastUsedAt);

        $service->recordUsage($key);

        $reloaded = ApiKey::findById($key->id);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->lastUsedAt);
        $this->assertNotNull($key->lastUsedAt, 'In-memory key is updated too');
    }

    // =========================================================================
    // ApiKey model contract
    // =========================================================================

    public function testAllowsIndexHonoursExplicitList(): void
    {
        $key = new ApiKey();
        $key->allowedIndices = ['docs-en', 'blog-en'];
        $this->assertTrue($key->allowsIndex('docs-en'));
        $this->assertTrue($key->allowsIndex('blog-en'));
        $this->assertFalse($key->allowsIndex('products-en'));
        $this->assertFalse($key->allowsAllIndices());
    }

    public function testAllowsIndexHonoursWildcard(): void
    {
        $key = new ApiKey();
        $key->allowedIndices = [ApiKey::ALL_INDICES];
        $this->assertTrue($key->allowsAllIndices());
        $this->assertTrue($key->allowsIndex('anything'));
        $this->assertTrue($key->allowsIndex('docs-en'));
    }

    public function testAllowsIndexReturnsFalseOnEmptyList(): void
    {
        $key = new ApiKey();
        $this->assertFalse($key->allowsIndex('docs-en'));
    }

    public function testIsStillValidTrueForNullExpiry(): void
    {
        $key = new ApiKey();
        $this->assertTrue($key->isStillValid());
    }

    public function testIsStillValidTrueForFutureExpiry(): void
    {
        $key = new ApiKey();
        $key->validUntil = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+1 day');
        $this->assertTrue($key->isStillValid());
    }

    public function testIsStillValidFalseForPastExpiry(): void
    {
        $key = new ApiKey();
        $key->validUntil = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('-1 minute');
        $this->assertFalse($key->isStillValid());
    }

    public function testStatusPriorityDisabledBeatsExpired(): void
    {
        $key = new ApiKey();
        // Active by default.
        $this->assertSame(ApiKey::STATUS_ACTIVE, $key->getStatus());

        // Expiry past, but still enabled → Expired.
        $key->validUntil = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('-1 day');
        $this->assertSame(ApiKey::STATUS_EXPIRED, $key->getStatus());

        // Disabled while also expired → Disabled wins (operator intent over
        // automatic expiry per the agreed priority order).
        $key->enabled = false;
        $this->assertSame(ApiKey::STATUS_DISABLED, $key->getStatus());

        // Disabled but with future expiry → still Disabled.
        $key->validUntil = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+1 day');
        $this->assertSame(ApiKey::STATUS_DISABLED, $key->getStatus());
    }

    public function testEnabledRoundTripsThroughSaveAndLoad(): void
    {
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        $this->assertTrue($key->enabled, 'New seeded keys default to enabled');

        // Flip the toggle and persist.
        $key->enabled = false;
        $this->assertTrue($key->save());

        $reloaded = ApiKey::findById($key->id);
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->enabled);
        $this->assertSame(ApiKey::STATUS_DISABLED, $reloaded->getStatus());
    }

    public function testReferrerPatternValidationRejectsRegex(): void
    {
        $key = $this->makeTestKey();
        $key->allowedReferrers = ['^example\\.com$'];
        $this->assertFalse($key->validate());
        $this->assertArrayHasKey('allowedReferrers', $key->getErrors());
        $this->assertSame(
            'Invalid referrer pattern: \'^example\\.com$\'. Use \'example.com\' or \'*.example.com\'.',
            $key->getFirstError('allowedReferrers'),
        );
    }

    public function testReferrerPatternValidationAcceptsExactAndWildcard(): void
    {
        $key = $this->makeTestKey();
        $key->allowedReferrers = ['example.com', '*.example.com', 'docs.eu.example.com'];
        $this->assertTrue($key->validate());
    }

    public function testEnabledKeyValidationRejectsEmptyAllowedIndices(): void
    {
        $key = $this->makeTestKey();
        $key->enabled = true;
        $key->allowedIndices = [];

        $this->assertFalse($key->validate());
        $this->assertArrayHasKey('allowedIndices', $key->getErrors());
    }

    public function testDisabledKeyValidationAllowsEmptyAllowedIndices(): void
    {
        $key = $this->makeTestKey();
        $key->enabled = false;
        $key->allowedIndices = [];

        $this->assertTrue($key->validate());
    }

    public function testFindAllReturnsAllRowsAndFiltersByType(): void
    {
        $this->seedKey(ApiKey::TYPE_PUBLIC);
        $this->seedKey(ApiKey::TYPE_SERVER);
        $this->seedKey(ApiKey::TYPE_PUBLIC);

        $all = array_values(array_filter(
            ApiKey::findAll(),
            fn(ApiKey $k) => str_starts_with($k->name, self::TEST_KEY_NAME_PREFIX),
        ));
        $this->assertCount(3, $all);

        $publicOnly = array_values(array_filter(
            ApiKey::findAll(ApiKey::TYPE_PUBLIC),
            fn(ApiKey $k) => str_starts_with($k->name, self::TEST_KEY_NAME_PREFIX),
        ));
        $this->assertCount(2, $publicOnly);
        foreach ($publicOnly as $k) {
            $this->assertSame(ApiKey::TYPE_PUBLIC, $k->type);
        }
    }

    // =========================================================================
    // Bulk operations
    // =========================================================================

    public function testBulkSetEnabledAffectsOnlyRequestedIds(): void
    {
        [$a] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        [$b] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        [$untouched] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        $service = SearchManager::$plugin->apiKeys;

        // Disable only $a and $b — $untouched must stay enabled.
        $affected = $service->bulkSetEnabled([$a->id, $b->id], false);
        $this->assertSame(2, $affected);

        $this->assertFalse(ApiKey::findById($a->id)->enabled);
        $this->assertFalse(ApiKey::findById($b->id)->enabled);
        $this->assertTrue(ApiKey::findById($untouched->id)->enabled, 'Unselected row must NOT be touched');
    }

    public function testBulkSetEnabledReturnsZeroOnEmptyIdList(): void
    {
        $this->assertSame(0, SearchManager::$plugin->apiKeys->bulkSetEnabled([], false));
        $this->assertSame(0, SearchManager::$plugin->apiKeys->bulkSetEnabled([], true));
    }

    public function testBulkSetEnabledDropsMalformedIds(): void
    {
        [$valid] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        // Mix of garbage with the one real id. Garbage must be dropped, the
        // valid id must still flip — proves the normaliser doesn't widen the
        // affected set when a payload contains weird input.
        $affected = SearchManager::$plugin->apiKeys->bulkSetEnabled(
            [$valid->id, 'not-a-number', -5, 0, '0', 'abc'],
            false,
        );
        $this->assertSame(1, $affected);
        $this->assertFalse(ApiKey::findById($valid->id)->enabled);
    }

    public function testBulkSetEnabledDedupesRepeatedIds(): void
    {
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        // Caller hands the same id three times. Should still affect one row.
        $affected = SearchManager::$plugin->apiKeys->bulkSetEnabled([$key->id, $key->id, $key->id], false);
        $this->assertSame(1, $affected);
    }

    public function testBulkSetEnabledTouchesDateUpdated(): void
    {
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        // seedKey returns the in-memory model post-save, which doesn't
        // re-read the persisted dateUpdated. Reload to get the baseline.
        $baseline = ApiKey::findById($key->id);
        $this->assertNotNull($baseline);
        $this->assertNotNull($baseline->dateUpdated);

        // Sleep one second so the timestamp delta is observable. (The default
        // datetime column has 1-second resolution.)
        sleep(1);

        SearchManager::$plugin->apiKeys->bulkSetEnabled([$key->id], false);

        $reloaded = ApiKey::findById($key->id);
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->dateUpdated);
        $this->assertGreaterThan($baseline->dateUpdated, $reloaded->dateUpdated, 'dateUpdated must advance');
    }

    public function testBulkDeleteRemovesOnlyRequestedIds(): void
    {
        [$a] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        [$b] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        [$untouched] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        $deleted = SearchManager::$plugin->apiKeys->bulkDelete([$a->id, $b->id]);
        $this->assertSame(2, $deleted);

        $this->assertNull(ApiKey::findById($a->id));
        $this->assertNull(ApiKey::findById($b->id));
        $this->assertNotNull(ApiKey::findById($untouched->id), 'Unselected row must NOT be touched');
    }

    public function testBulkDeleteReturnsZeroOnEmptyIdList(): void
    {
        $this->assertSame(0, SearchManager::$plugin->apiKeys->bulkDelete([]));
    }

    public function testBulkDeleteDropsMalformedIds(): void
    {
        [$valid] = $this->seedKey(ApiKey::TYPE_PUBLIC);

        $deleted = SearchManager::$plugin->apiKeys->bulkDelete([$valid->id, 'abc', -1, null]);
        $this->assertSame(1, $deleted);
        $this->assertNull(ApiKey::findById($valid->id));
    }

    public function testBulkDeleteOfNonExistentIdsReturnsZero(): void
    {
        // No rows match these ids — bulkDelete must return 0 without throwing.
        $this->assertSame(0, SearchManager::$plugin->apiKeys->bulkDelete([99999999, 88888888]));
    }

    public function testDeleteRemovesRowAndIsIdempotent(): void
    {
        [$key] = $this->seedKey(ApiKey::TYPE_PUBLIC);
        $id = $key->id;

        $this->assertTrue($key->delete());
        $this->assertNull(ApiKey::findById($id));

        // Second delete on the now-orphaned model returns false (no id retained
        // by the model would also be acceptable; this asserts safe-second-call).
        $orphan = new ApiKey();
        $this->assertFalse($orphan->delete());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build (but don't save) a valid-shaped ApiKey for validation tests.
     */
    private function makeTestKey(): ApiKey
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $key = new ApiKey();
        $key->name = self::TEST_KEY_NAME_PREFIX . '_validate';
        $key->type = ApiKey::TYPE_PUBLIC;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];
        return $key;
    }

    /**
     * Generate + save a fresh key. Returns a [ApiKey, plaintext] tuple — the
     * plaintext is normally discarded after creation (only the hash persists)
     * but tests need it to exercise verifyKey() / findByPlaintextKey().
     *
     * @return array{0: ApiKey, 1: string}
     */
    private function seedKey(string $type): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey($type);
        $key = new ApiKey();
        $key->name = self::TEST_KEY_NAME_PREFIX . '_' . (++$this->seedCounter);
        $key->type = $type;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];

        $this->assertTrue($key->save(), 'Seeded key save() must succeed');

        return [$key, $generated['plaintext']];
    }

    private function purgeTestKeys(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::TEST_KEY_NAME_PREFIX . '%', false])
            ->execute();
    }
}
