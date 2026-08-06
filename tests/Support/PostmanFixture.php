<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

require dirname(__DIR__) . '/bootstrap.php';

const POSTMAN_FIXTURE_BACKEND = '__sm_postman_fixture_backend__';
const POSTMAN_FIXTURE_INDEX = '__sm_postman_fixture_index__';
const POSTMAN_FIXTURE_BLOCKED_INDEX = '__sm_postman_fixture_blocked_index__';
const POSTMAN_FIXTURE_KEY = 'sm-postman-fixture-key';
const POSTMAN_FIXTURE_RATE_KEY = 'sm-postman-fixture-rate-key';
const POSTMAN_FIXTURE_QUERY = '__sm_postman_fixture_query__';
const POSTMAN_FIXTURE_RATE_LIMIT = 3;
const POSTMAN_FIXTURE_ITERATIONS = 5;
const POSTMAN_FIXTURE_BASE_URL = 'https://craftcms.ddev.site';

$storageRuntime = Craft::getAlias('@storage/runtime');
if (!is_string($storageRuntime)) {
    throw new RuntimeException('Craft storage runtime alias is unavailable.');
}
FileHelper::createDirectory($storageRuntime);

$stateFile = $storageRuntime . '/search-manager-postman-fixture-state.json';
$environmentFile = $storageRuntime . '/search-manager-postman-fixture-environment.json';
$command = $argv[1] ?? 'status';

try {
    $result = match ($command) {
        'setup' => setupFixture($stateFile, $environmentFile),
        'mode' => setFixtureMode($stateFile, $environmentFile, $argv[2] ?? '', $argv[3] ?? ''),
        'status' => fixtureStatus($stateFile, $environmentFile),
        'cleanup' => cleanupFixture($stateFile, $environmentFile),
        default => throw new InvalidArgumentException('Use setup, mode <anonymous|keyed|rate-limit> <standard|pro>, status, or cleanup.'),
    };

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function setupFixture(string $stateFile, string $environmentFile): array
{
    if (file_exists($stateFile)) {
        throw new RuntimeException('Postman fixture state already exists; clean it before setup.');
    }

    $before = markerCounts();
    foreach ($before as $surface => $count) {
        if ($count !== 0) {
            throw new RuntimeException("Postman fixture marker is not clean before setup: {$surface}={$count}.");
        }
    }

    $db = Craft::$app->getDb();
    $settingsRow = (new Query())
        ->from('{{%searchmanager_settings}}')
        ->where(['id' => 1])
        ->one();
    if ($settingsRow === false) {
        throw new RuntimeException('Search Manager settings row id=1 was not found.');
    }

    $siteId = (int)(new Query())
        ->select(['id'])
        ->from('{{%sites}}')
        ->where(['primary' => 1])
        ->scalar();
    if ($siteId <= 0) {
        throw new RuntimeException('A primary Craft site is required for the Postman fixture.');
    }

    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
    $transaction = $db->beginTransaction();

    try {
        $db->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => POSTMAN_FIXTURE_BACKEND,
            'handle' => POSTMAN_FIXTURE_BACKEND,
            'backendType' => 'mysql',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $indexIds = [];
        foreach ([POSTMAN_FIXTURE_INDEX, POSTMAN_FIXTURE_BLOCKED_INDEX] as $handle) {
            $db->createCommand()->insert('{{%searchmanager_indices}}', [
                'name' => $handle,
                'handle' => $handle,
                'elementType' => \craft\elements\Entry::class,
                'siteId' => $siteId,
                'criteria' => '[]',
                'transformerClass' => '',
                'headingLevels' => null,
                'language' => null,
                'enabled' => 1,
                'enableAnalytics' => 1,
                'disableStopWords' => 0,
                'skipEntriesWithoutUrl' => 0,
                'splitSections' => 0,
                'retrievableFields' => '["*"]',
                'source' => 'database',
                'backend' => POSTMAN_FIXTURE_BACKEND,
                'lastIndexed' => null,
                'documentCount' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
            $indexId = (int)$db->getLastInsertID();
            $indexIds[] = $indexId;
            $db->createCommand()->insert('{{%searchmanager_index_sites}}', [
                'indexId' => $indexId,
                'siteId' => $siteId,
            ])->execute();
        }

        SearchIndex::clearCache();

        [$publicKey, $publicPlaintext] = createFixtureKey(
            POSTMAN_FIXTURE_KEY,
            [POSTMAN_FIXTURE_INDEX],
            null,
        );
        [$rateKey, $ratePlaintext] = createFixtureKey(
            POSTMAN_FIXTURE_RATE_KEY,
            [POSTMAN_FIXTURE_INDEX],
            POSTMAN_FIXTURE_RATE_LIMIT,
        );

        $state = [
            'settingsRow' => $settingsRow,
            'settingsHash' => stableHash($settingsRow),
            'settingsOriginal' => managedSettings($settingsRow),
            'settingsApplied' => managedSettings($settingsRow),
            'settingsPreviousApplied' => managedSettings($settingsRow),
            'settingsApplyPending' => false,
            'siteId' => $siteId,
            'indexIds' => $indexIds,
            'publicKeyId' => $publicKey->id,
            'rateKeyId' => $rateKey->id,
            'publicPlaintext' => $publicPlaintext,
            'ratePlaintext' => $ratePlaintext,
        ];

        writeState($stateFile, $state);
        $editionMode = SearchManager::$plugin->getEditionHandle();
        if (!in_array($editionMode, [SearchManager::EDITION_STANDARD, SearchManager::EDITION_PRO], true)) {
            throw new RuntimeException("Unsupported Search Manager edition: {$editionMode}.");
        }
        writeEnvironment($environmentFile, $state, 'anonymous', $editionMode);
        applyApiModeSettings('anonymous', $stateFile, $state);
        clearRateLimitCounters((int)$rateKey->id);

        $transaction->commit();
    } catch (Throwable $exception) {
        $transaction->rollBack();
        @unlink($stateFile);
        @unlink($environmentFile);
        SearchIndex::clearCache();
        throw $exception;
    }

    return [
        'status' => 'setup',
        'apiMode' => 'anonymous',
        'editionMode' => $editionMode,
        'siteId' => $siteId,
        'environmentFile' => $environmentFile,
        'counts' => markerCounts(),
        'settingsSnapshotHash' => stableHash($settingsRow),
        'credentials' => 'written only to the chmod 0600 disposable environment/state files',
    ];
}

/**
 * @return array<string, mixed>
 */
function setFixtureMode(string $stateFile, string $environmentFile, string $apiMode, string $editionMode): array
{
    $state = readState($stateFile);
    if (!in_array($apiMode, ['anonymous', 'keyed', 'rate-limit'], true)) {
        throw new InvalidArgumentException('API mode must be anonymous, keyed, or rate-limit.');
    }
    if (!in_array($editionMode, [SearchManager::EDITION_STANDARD, SearchManager::EDITION_PRO], true)) {
        throw new InvalidArgumentException('Edition mode must be standard or pro.');
    }

    $activeEdition = SearchManager::$plugin->getEditionHandle();
    if ($editionMode !== $activeEdition) {
        throw new RuntimeException(
            "Requested edition_mode={$editionMode}, but the running plugin edition is {$activeEdition}; the fixture never changes edition configuration.",
        );
    }

    applyApiModeSettings($apiMode, $stateFile, $state);
    if ($apiMode === 'rate-limit') {
        clearRateLimitCounters((int)$state['rateKeyId']);
    }
    writeEnvironment($environmentFile, $state, $apiMode, $editionMode);

    return [
        'status' => 'mode-set',
        'apiMode' => $apiMode,
        'editionMode' => $editionMode,
        'requireApiKey' => $apiMode === 'anonymous' ? 0 : 1,
        'environmentFile' => $environmentFile,
        'counts' => markerCounts(),
    ];
}

/**
 * @return array<string, mixed>
 */
function fixtureStatus(string $stateFile, string $environmentFile): array
{
    return [
        'status' => file_exists($stateFile) ? 'active' : 'absent',
        'stateFile' => file_exists($stateFile),
        'environmentFile' => file_exists($environmentFile),
        'counts' => markerCounts(),
        'currentSettingsHash' => currentSettingsHash(),
    ];
}

/**
 * @return array<string, mixed>
 */
function cleanupFixture(string $stateFile, string $environmentFile): array
{
    $state = readState($stateFile);
    $db = Craft::$app->getDb();
    $handles = [POSTMAN_FIXTURE_INDEX, POSTMAN_FIXTURE_BLOCKED_INDEX];
    $transaction = $db->beginTransaction();

    try {
        foreach ($db->getSchema()->getTableNames() as $tableName) {
            if (!str_contains($tableName, 'searchmanager_')) {
                continue;
            }
            $schema = $db->getSchema()->getTableSchema($tableName, true);
            if ($schema !== null && isset($schema->columns['indexHandle'])) {
                $db->createCommand()->delete(
                    $db->quoteTableName($tableName),
                    ['indexHandle' => $handles],
                )->execute();
            }
        }

        $db->createCommand()->delete('{{%searchmanager_analytics}}', [
            'or',
            ['indexHandle' => $handles],
            ['like', 'query', POSTMAN_FIXTURE_QUERY . '%', false],
            ['like', 'source', 'postman-fixture%', false],
        ])->execute();

        $db->createCommand()->delete('{{%searchmanager_index_sites}}', [
            'indexId' => array_map('intval', $state['indexIds']),
        ])->execute();
        $db->createCommand()->delete('{{%searchmanager_api_keys}}', [
            'handle' => [POSTMAN_FIXTURE_KEY, POSTMAN_FIXTURE_RATE_KEY],
        ])->execute();
        $db->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $handles])->execute();
        $db->createCommand()->delete('{{%searchmanager_backends}}', ['handle' => POSTMAN_FIXTURE_BACKEND])->execute();

        if ($db->getSchema()->getTableSchema('{{%queue}}') !== null) {
            $db->createCommand()->delete('{{%queue}}', ['like', 'job', '__sm_postman_fixture_%', false])->execute();
        }

        restoreManagedSettings($state);

        clearRateLimitCounters((int)$state['publicKeyId']);
        clearRateLimitCounters((int)$state['rateKeyId']);
        SearchIndex::clearCache();
        $transaction->commit();
    } catch (Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
    }

    $counts = markerCounts();
    $settingsRestored = managedSettings(currentSettingsRow()) === managedSettings($state['settingsOriginal']);
    if (array_sum($counts) !== 0 || !$settingsRestored) {
        throw new RuntimeException('Fixture cleanup or settings restoration verification failed.');
    }

    $reportFilesRemoved = 0;
    foreach ([
        dirname($stateFile) . '/search-manager-postman-api-anonymous__edition-pro.json',
        dirname($stateFile) . '/search-manager-postman-api-keyed__edition-pro.json',
        dirname($stateFile) . '/search-manager-postman-api-rate-limit__edition-pro.json',
    ] as $reportFile) {
        if (file_exists($reportFile) && unlink($reportFile)) {
            $reportFilesRemoved++;
        }
    }
    @unlink($environmentFile);
    @unlink($stateFile);

    return [
        'status' => 'clean',
        'counts' => $counts,
        'settingsRestored' => true,
        'settingsSnapshotHash' => $state['settingsHash'],
        'settingsCurrentHash' => currentSettingsHash(),
        'stateFileRemoved' => !file_exists($stateFile),
        'environmentFileRemoved' => !file_exists($environmentFile),
        'reportFilesRemoved' => $reportFilesRemoved,
        'usersCreated' => 0,
    ];
}

/**
 * @param list<string> $allowedIndices
 * @return array{0: ApiKey, 1: string}
 */
function createFixtureKey(string $handle, array $allowedIndices, ?int $rateLimit): array
{
    $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
    $key = new ApiKey();
    $key->name = $handle;
    $key->handle = $handle;
    $key->type = ApiKey::TYPE_PUBLIC;
    $key->enabled = true;
    $key->keyHash = $generated['hash'];
    $key->keyPrefix = $generated['prefix'];
    $key->allowedIndices = $allowedIndices;
    $key->allowedReferrers = ['craftcms.ddev.site'];
    $key->maxHitsPerPage = 20;
    $key->rateLimit = $rateLimit;

    if (!$key->save()) {
        throw new RuntimeException('Unable to create fixture API key: ' . json_encode($key->getErrors()));
    }

    return [$key, $generated['plaintext']];
}

/** @param array<string, mixed> $state */
function applyApiModeSettings(string $apiMode, string $stateFile, array &$state): void
{
    $applied = [
        'requireApiKey' => $apiMode === 'anonymous' ? 0 : 1,
        'enableAnalytics' => 1,
        'enableGeoDetection' => 0,
        'enableCache' => 0,
        'enableAutocompleteCache' => 0,
        'dateUpdated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
    ];
    $applied = managedSettings($applied);
    $previous = managedSettings($state['settingsApplied']);
    $state['settingsPreviousApplied'] = $previous;
    $state['settingsApplied'] = $applied;
    $state['settingsApplyPending'] = true;
    writeState($stateFile, $state);

    $updated = Craft::$app->getDb()->createCommand()->update(
        '{{%searchmanager_settings}}',
        $applied,
        ['and', ['id' => 1], $previous],
    )->execute();
    if ($updated !== 1 && managedSettings(currentSettingsRow()) !== $applied) {
        throw new RuntimeException('Postman fixture refused to overwrite concurrently changed managed settings.');
    }

    $state['settingsApplyPending'] = false;
    writeState($stateFile, $state);
}

/**
 * @param array<string, mixed> $state
 */
function writeEnvironment(
    string $environmentFile,
    array $state,
    string $apiMode,
    string $editionMode,
): void {
    $templateFile = dirname(__DIR__, 2) . '/resources/postman/Search-Manager.postman_environment.json';
    $environment = json_decode((string)file_get_contents($templateFile), true, 512, JSON_THROW_ON_ERROR);
    $values = [
        'api_mode' => $apiMode,
        'edition_mode' => $editionMode,
        'base_url' => POSTMAN_FIXTURE_BASE_URL,
        'api_key' => (string)$state['publicPlaintext'],
        'rate_limit_api_key' => (string)$state['ratePlaintext'],
        'invalid_api_key' => 'sm_pub_invalidplaceholder',
        'referrer' => POSTMAN_FIXTURE_BASE_URL . '/',
        'blocked_referrer' => 'https://blocked.example.test/',
        'origin' => POSTMAN_FIXTURE_BASE_URL,
        'blocked_origin' => 'https://blocked.example.test',
        'query' => POSTMAN_FIXTURE_QUERY,
        'index_handles' => POSTMAN_FIXTURE_INDEX,
        'index_handle' => POSTMAN_FIXTURE_INDEX,
        'blocked_index_handle' => POSTMAN_FIXTURE_BLOCKED_INDEX,
        'results_limit' => '10',
        'site_id' => (string)$state['siteId'],
        'unknown_site_id' => '999999',
        'element_id' => '999998',
        'results_count' => '0',
        'trigger' => 'enter',
        'analytics_source' => 'postman-fixture',
        'rate_limit_allowed_requests' => (string)POSTMAN_FIXTURE_RATE_LIMIT,
        'rate_limit_runner_iterations' => (string)POSTMAN_FIXTURE_ITERATIONS,
    ];

    foreach ($environment['values'] as &$variable) {
        $key = (string)$variable['key'];
        if (!array_key_exists($key, $values)) {
            throw new RuntimeException("Fixture has no value for environment variable {$key}.");
        }
        $variable['value'] = $values[$key];
    }
    unset($variable);

    file_put_contents($environmentFile, json_encode($environment, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($environmentFile, 0600);
}

/**
 * @return array<string, int>
 */
function markerCounts(): array
{
    $db = Craft::$app->getDb();
    $handles = [POSTMAN_FIXTURE_INDEX, POSTMAN_FIXTURE_BLOCKED_INDEX];
    $counts = [
        'apiKeys' => (int)(new Query())->from('{{%searchmanager_api_keys}}')->where([
            'handle' => [POSTMAN_FIXTURE_KEY, POSTMAN_FIXTURE_RATE_KEY],
        ])->count(),
        'indices' => (int)(new Query())->from('{{%searchmanager_indices}}')->where(['handle' => $handles])->count(),
        'backends' => (int)(new Query())->from('{{%searchmanager_backends}}')->where(['handle' => POSTMAN_FIXTURE_BACKEND])->count(),
        'analytics' => (int)(new Query())->from('{{%searchmanager_analytics}}')->where([
            'or',
            ['indexHandle' => $handles],
            ['like', 'query', POSTMAN_FIXTURE_QUERY . '%', false],
            ['like', 'source', 'postman-fixture%', false],
        ])->count(),
        'pendingSyncs' => (int)(new Query())->from('{{%searchmanager_pending_syncs}}')->where(['indexHandle' => $handles])->count(),
        'queueRows' => 0,
        'storageRows' => 0,
        'users' => 0,
    ];

    if ($db->getSchema()->getTableSchema('{{%queue}}') !== null) {
        $counts['queueRows'] = (int)(new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', '__sm_postman_fixture_%', false])
            ->count();
    }

    foreach ($db->getSchema()->getTableNames() as $tableName) {
        if (!str_contains($tableName, 'searchmanager_')) {
            continue;
        }
        $schema = $db->getSchema()->getTableSchema($tableName, true);
        if ($schema === null || !isset($schema->columns['indexHandle'])) {
            continue;
        }
        if (str_ends_with($tableName, 'searchmanager_analytics') || str_ends_with($tableName, 'searchmanager_pending_syncs')) {
            continue;
        }
        $counts['storageRows'] += (int)(new Query())
            ->from($db->quoteTableName($tableName))
            ->where(['indexHandle' => $handles])
            ->count();
    }

    return $counts;
}

/**
 * @return array<string, mixed>
 */
function readState(string $stateFile): array
{
    if (!file_exists($stateFile)) {
        throw new RuntimeException('Postman fixture state does not exist.');
    }

    $state = json_decode((string)file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
        throw new RuntimeException('Postman fixture state is invalid.');
    }

    return $state;
}

/** @param array<string, mixed> $state */
function writeState(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($stateFile, 0600);
}

/** @return array<string, mixed> */
function currentSettingsRow(): array
{
    $row = (new Query())
        ->from('{{%searchmanager_settings}}')
        ->where(['id' => 1])
        ->one();
    if ($row === false) {
        throw new RuntimeException('Search Manager settings row id=1 was not found.');
    }

    return $row;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function managedSettings(array $row): array
{
    $managed = array_intersect_key($row, array_flip([
        'requireApiKey',
        'enableAnalytics',
        'enableGeoDetection',
        'enableCache',
        'enableAutocompleteCache',
        'dateUpdated',
    ]));
    ksort($managed);

    return $managed;
}

/** @param array<string, mixed> $state */
function restoreManagedSettings(array $state): void
{
    $current = managedSettings(currentSettingsRow());
    $expected = managedSettings($state['settingsApplied']);
    if (($state['settingsApplyPending'] ?? false) && $current === managedSettings($state['settingsPreviousApplied'])) {
        $expected = managedSettings($state['settingsPreviousApplied']);
    }
    if ($current !== $expected) {
        throw new RuntimeException(
            'Postman fixture cleanup refused to overwrite concurrently changed managed settings: '
            . json_encode(managedSettingsMismatchEvidence($current, $expected), JSON_THROW_ON_ERROR),
        );
    }

    $updated = Craft::$app->getDb()->createCommand()->update(
        '{{%searchmanager_settings}}',
        managedSettings($state['settingsOriginal']),
        ['and', ['id' => 1], $expected],
    )->execute();
    if ($updated !== 1 && managedSettings(currentSettingsRow()) !== managedSettings($state['settingsOriginal'])) {
        throw new RuntimeException('Postman fixture managed settings restoration failed.');
    }
}

/**
 * @param array<string, mixed> $current
 * @param array<string, mixed> $expected
 * @return array{currentSha256: string, expectedSha256: string, changedKeys: list<string>}
 */
function managedSettingsMismatchEvidence(array $current, array $expected): array
{
    $currentHashInput = $current;
    $expectedHashInput = $expected;
    ksort($currentHashInput);
    ksort($expectedHashInput);

    return [
        'currentSha256' => hash('sha256', json_encode($currentHashInput, JSON_THROW_ON_ERROR)),
        'expectedSha256' => hash('sha256', json_encode($expectedHashInput, JSON_THROW_ON_ERROR)),
        'changedKeys' => array_values(array_unique(array_merge(
            array_keys(array_diff_assoc($current, $expected)),
            array_keys(array_diff_assoc($expected, $current)),
        ))),
    ];
}

function clearRateLimitCounters(int $keyId): void
{
    if ($keyId <= 0) {
        return;
    }

    $cache = Craft::$app->getCache();
    $window = (int)floor(time() / 60);
    foreach (range($window - 1, $window + 1) as $candidate) {
        $cache->delete("searchmanager:apikey:ratelimit:{$keyId}:{$candidate}");
    }
}

/**
 * @param array<string, mixed> $value
 */
function stableHash(array $value): string
{
    ksort($value);

    return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
}

function currentSettingsHash(): string
{
    return stableHash(currentSettingsRow());
}
