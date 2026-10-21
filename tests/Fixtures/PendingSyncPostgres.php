<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use craft\db\Command;
use craft\db\Connection;
use craft\db\Query;
use craft\elements\Entry;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use PHPUnit\Framework\Assert;
use yii\db\Exception as DbException;

// Direct-only dialect smoke. The caller owns an ephemeral PostgreSQL container;
// no existing project or database is accepted.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
$host = $argv[1] ?? '';
if (!preg_match('/^sm-pending-pg-[a-f0-9]{12}$/', $host)) {
    throw new RuntimeException('Expected a run-owned PostgreSQL container.');
}
require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__, 4) . '/vendor/craftcms/cms/src/Craft.php';
/**
 * PostgreSQL execution error control for this dialect fixture only.
 *
 * @since 5.57.0
 */
final class PendingSyncPostgresCommand extends Command
{
    public static bool $failClaim = false;
    public static int $attempts = 0;

    /** @inheritdoc */
    protected function internalExecute($rawSql): void
    {
        if (self::$failClaim && str_starts_with($this->getSql(), 'UPDATE') && str_contains($this->getRawSql(), "'processing'")) {
            self::$attempts++;
            throw new DbException('Controlled error', ['40001', 7, 'controlled PostgreSQL serialization failure']);
        }
        parent::internalExecute($rawSql);
    }
}

$root = sys_get_temp_dir() . '/' . $host;
if (!mkdir($root, 0700)) {
    throw new RuntimeException('PostgreSQL fixture root must be fresh.');
}
$db = new Connection([
    'dsn' => 'pgsql:host=' . $host . ';port=5432;dbname=worker_fixture',
    'username' => 'worker_fixture',
    'password' => 'worker_fixture',
    'commandClass' => PendingSyncPostgresCommand::class,
]);
try {
    new yii\console\Application(['id' => $host, 'basePath' => $root, 'components' => ['db' => $db]]);
    $db->createCommand('CREATE TABLE searchmanager_pending_syncs (
        id SERIAL PRIMARY KEY, "indexHandle" VARCHAR(255) NOT NULL,
        "elementType" VARCHAR(255) NOT NULL, "elementId" INTEGER NOT NULL,
        "siteId" INTEGER NOT NULL, op VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL,
        "attemptCount" INTEGER NOT NULL DEFAULT 0, "queuedAt" TIMESTAMP NOT NULL,
        "nextAttemptAt" TIMESTAMP NOT NULL, "claimedAt" TIMESTAMP NULL,
        "claimToken" VARCHAR(36) NULL, "dirtyAt" TIMESTAMP NULL, "lastError" TEXT NULL,
        "lastProcessedAt" TIMESTAMP NULL, "dateCreated" TIMESTAMP NOT NULL,
        "dateUpdated" TIMESTAMP NOT NULL, uid VARCHAR(36) NOT NULL,
        UNIQUE ("indexHandle", "elementId", "siteId")
    )')->execute();
    $repository = new PendingSyncRepository();
    $target = ['indexHandle' => 'owned-dialect-target', 'elementType' => Entry::class, 'elementId' => 1, 'siteId' => 1, 'op' => 'upsert'];
    Assert::assertSame(1, $repository->upsertRows([$target]));
    PendingSyncPostgresCommand::$failClaim = true;
    try {
        $repository->claim(10, 300);
        Assert::fail('PostgreSQL errors must propagate immediately.');
    } catch (DbException $error) {
        Assert::assertSame(['40001', 7, 'controlled PostgreSQL serialization failure'], $error->errorInfo);
    }
    Assert::assertSame(1, PendingSyncPostgresCommand::$attempts);
    PendingSyncPostgresCommand::$failClaim = false;
    $rows = $repository->claim(10, 300);
    Assert::assertCount(1, $rows);
    Assert::assertSame(1, (int)$rows[0]['attemptCount']);
    $repository->upsertRows([array_replace($target, ['op' => 'delete'])]);
    $repository->markSucceeded([$rows[0]['id']], 'wrong-token');
    Assert::assertSame($rows[0]['claimToken'], (new Query())->from('searchmanager_pending_syncs')->one()['claimToken']);
    $repository->markSucceeded([$rows[0]['id']], $rows[0]['claimToken']);
    $dirty = (new Query())->from('searchmanager_pending_syncs')->one();
    Assert::assertSame('delete', $dirty['op']);
    Assert::assertSame('pending', $dirty['status']);
    Assert::assertSame(0, (int)$dirty['attemptCount']);
    $rows = $repository->claim(10, 300);
    $repository->markRetry([$rows[0]['id']], 'controlled backend failure', 2, 1, $rows[0]['claimToken']);
    Assert::assertSame('failed', (new Query())->from('searchmanager_pending_syncs')->one()['status']);
    sleep(2);
    $rows = $repository->claim(10, 300);
    $repository->markRetry([$rows[0]['id']], 'controlled backend failure', 2, 1, $rows[0]['claimToken']);
    Assert::assertSame('abandoned', (new Query())->from('searchmanager_pending_syncs')->one()['status']);
    Assert::assertSame(0, $repository->purgeOld(60));
    $repository->upsertRows([$target]);
    $rows = $repository->claim(10, 300);
    $repository->markSucceeded([$rows[0]['id']], $rows[0]['claimToken']);
    Assert::assertSame(0, (int)(new Query())->from('searchmanager_pending_syncs')->count());
    fwrite(STDOUT, json_encode(['php' => PHP_VERSION, 'postgres' => $db->getServerVersion(), 'assertions' => Assert::getCount(), 'result' => 'passed'], JSON_THROW_ON_ERROR) . "\n");
} finally {
    try {
        if ($db->getIsActive()) {
            $db->createCommand('DROP TABLE IF EXISTS searchmanager_pending_syncs')->execute();
        }
    } finally {
        $db->close();
        if (is_dir($root . '/runtime')) {
            craft\helpers\FileHelper::removeDirectory($root . '/runtime');
        }
        rmdir($root);
    }
}
