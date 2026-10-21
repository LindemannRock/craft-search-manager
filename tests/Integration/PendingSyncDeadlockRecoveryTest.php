<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Runs autocommit and contention coverage in an exclusively owned database.
 *
 * @since 5.57.0
 */
final class PendingSyncDeadlockRecoveryTest extends TestCase
{
    public function testWorkerRecoveryWithPersistentSharedTables(): void
    {
        $boundary = TestProjectBoundary::resolve();
        self::assertTrue($boundary->disposable, 'Worker fixtures require the disposable project runner.');
        $process = proc_open([
            PHP_BINARY,
            $boundary->vendorRoot . '/bin/phpunit',
            '--configuration',
            $boundary->packageRoot . '/phpunit.xml.dist',
            '--colors=never',
            '--no-progress',
            'tests/Fixtures/PendingSyncWorkerRecoveryTest.php',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $boundary->packageRoot);
        self::assertIsResource($process);
        $this->registerOwnedProcess($process, $pipes, 'pending-worker-fixtures');
        $result = $this->finishOwnedProcess($process, $pipes);
        fwrite(STDERR, $result['output']);
        self::assertSame(0, $result['exitCode'], $result['output'] . $result['error']);
        self::assertStringNotContainsString('Skipped:', $result['output']);
    }
}
