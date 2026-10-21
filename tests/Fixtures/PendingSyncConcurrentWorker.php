<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use craft\elements\Entry;
use craft\queue\Queue;
use lindemannrock\base\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\PendingSyncFaultCommand;
use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;

// Direct-only worker. The parent registers/reaps this process and owns every
// barrier path; the disposable project runner owns the database on interruption.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
require dirname(__DIR__) . '/Support/TestProjectBoundary.php';
$boundary = TestProjectBoundary::resolve();
if (!$boundary->disposable) {
    throw new RuntimeException('Concurrent workers refuse the owner project.');
}
$input = json_decode((string)fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_string($input['token'] ?? null)
    || !hash_equals($input['token'], (string)file_get_contents($input['directory'] . '/token'))
) {
    throw new RuntimeException('Missing authenticated worker boundary.');
}
require $boundary->vendorAutoload();
require $boundary->projectRoot . '/bootstrap.php';
require $boundary->vendorRoot . '/craftcms/cms/bootstrap/console.php';
$db = Craft::$app->getDb();
$database = $db->createCommand('SELECT DATABASE()')->queryScalar();
if ($database !== $input['database'] || !preg_match('/^sm_a12_3p_[a-f0-9]{16}$/', $database)) {
    throw new RuntimeException('Worker database does not match its owner.');
}
foreach (['pendingTable' => 'pending', 'queueTable' => 'queue'] as $key => $kind) {
    if (!preg_match('/^sm_worker_' . $kind . '_[a-f0-9]{16}$/', $input[$key])
        || $db->getTableSchema($input[$key]) === null
    ) {
        throw new RuntimeException('Missing owned persistent worker table.');
    }
}
$db->commandClass = PendingSyncFaultCommand::class;
PendingSyncFaultCommand::$pendingTable = $input['pendingTable'];
Craft::$app->set('queue', new Queue(['tableName' => $input['queueTable']]));
$cache = (new ReflectionProperty(ConfigFileHelper::class, '_configCache'))->getValue();
$cache['search-manager'] = ['indices' => [$input['handle'] => [
    'name' => 'Owned worker index',
    'elementType' => Entry::class,
    'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
    'backend' => 'fixtureMysql',
    'enabled' => true,
]]];
(new ReflectionProperty(ConfigFileHelper::class, '_configCache'))->setValue(null, $cache);
SearchIndex::clearCache();
$index = SearchIndex::findByHandle($input['handle']);
(new ReflectionProperty(SearchIndex::class, 'allCache'))->setValue(null, [$index]);
(new ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt'))->setValue(null, microtime(true) + 3600);
SearchManager::$plugin->getSettings()->autoIndex = true;
SearchManager::$plugin->getSettings()->batchFlushInterval = 1;
$repository = SearchManager::$plugin->pendingSyncs;
$connectionId = (int)$db->createCommand('SELECT CONNECTION_ID()')->queryScalar();
$signal = static function(string $name) use ($input, $connectionId, $db): void {
    file_put_contents($input['directory'] . '/' . $input['label'] . '-' . $name, json_encode([
        'pid' => getmypid(), 'connectionId' => $connectionId,
        'pdoTransaction' => $db->getMasterPdo()->inTransaction(),
        'yiiTransaction' => $db->getTransaction() !== null,
    ], JSON_THROW_ON_ERROR));
};
$wait = static function(string $name) use ($input): void {
    $deadline = microtime(true) + 15;
    while (!is_file($input['directory'] . '/' . $name)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker barrier timed out: ' . $name);
        }
        usleep(10000);
    }
};
$statements = [];
$transaction = null;
try {
    if ($input['mode'] === 'transaction') {
        if (!preg_match('/^sm_worker_locks_[a-f0-9]{16}$/', $input['lockTable'])) {
            throw new RuntimeException('Invalid controlled rollback table.');
        }
        $transaction = $db->beginTransaction();
        // A separate controlled engine-contract test, not a reconstruction of
        // the production lock graph. Both rows and this table are run-owned.
        $db->createCommand('UPDATE `' . $input['lockTable'] . '` SET value = value + 1 WHERE id >= 2')->execute();
        $signal('ready');
        $wait('go');
        $signal('enter');
        $db->createCommand('UPDATE `' . $input['lockTable'] . '` SET value = value + 1 WHERE id = 1')->execute();
        $transaction->commit();
        $result = [];
    } else {
        PendingSyncFaultCommand::$beforeExecute = static function(PendingSyncFaultCommand $command) use ($input, $signal, $wait, &$statements): void {
            $sql = $command->getRawSql();
            if (!str_contains($sql, $input['pendingTable'])) {
                return;
            }
            $statements[] = $sql;
            $match = $input['mode'] === 'claim'
                ? str_starts_with($sql, 'UPDATE') && str_contains($sql, "'processing'")
                : str_starts_with($sql, 'UPDATE') && str_contains($sql, "`status`='pending'");
            if ($match) {
                $signal('ready');
                if ($input['mode'] === 'claim') {
                    $wait('go');
                }
            }
        };
        if ($input['mode'] === 'claim') {
            $result = $repository->claim(100, 300);
        } elseif ($input['mode'] === 'save') {
            $transaction = $db->beginTransaction();
            $entry = Entry::find()->id($input['firstId'])->siteId($index->siteId)->one();
            $entry->title = 'Concurrent newest title';
            if (!Craft::$app->getElements()->saveElement($entry, false, true, false)) {
                throw new RuntimeException('Concurrent supported save failed.');
            }
            $signal('ready');
            $wait('go');
            $transaction->commit();
            $result = [];
        } else {
            $repository->markSucceeded($input['ids'], $input['claimToken']);
            $result = [];
        }
    }
    fwrite(STDOUT, json_encode([
        'database' => $database, 'connectionId' => $connectionId,
        'mode' => $input['mode'], 'rows' => $result, 'statements' => $statements,
        'pdoTransactionAtExit' => $db->getMasterPdo()->inTransaction(),
        'yiiTransactionAtExit' => $db->getTransaction() !== null,
    ], JSON_THROW_ON_ERROR));
} finally {
    PendingSyncFaultCommand::$beforeExecute = null;
    if ($transaction?->getIsActive()) {
        $transaction->rollBack();
    }
    $db->close();
}
