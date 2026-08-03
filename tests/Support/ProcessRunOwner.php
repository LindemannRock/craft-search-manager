<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Support;

/**
 * Owns PHPUnit-run resources outside the PHPUnit process lifecycle.
 *
 * @since 5.54.0
 */
final class ProcessRunOwner
{
    private const CLEANUP_FAILURE_EXIT_CODE = 70;
    private const RUSAGE_CHILDREN_MODE = 1;
    private const JOURNAL_DIRECTORY = '.phpunit.cache/a12-process-owners';
    private const RUN_PREFIX = 'sm-a12-run-';
    private const STORAGE_PREFIX = 'search-manager-a12-run-';

    private static bool $active = false;
    private static bool $phpunitChild = false;
    private static ?string $runId = null;
    private static ?string $runRoot = null;
    private static ?string $storageRoot = null;
    private static ?string $journalDirectory = null;
    private static ?string $secret = null;
    private static ?array $watchdogIdentity = null;
    private static ?int $startupChildPid = null;
    private static ?array $startupChildIdentity = null;
    private static array $startupGates = [];
    private static ?array $lastStartupFailureEvidence = null;
    private static bool $startupJournalRootPreexisted = false;

    /** @var resource|null */
    private static $watchdogProcess = null;

    public static function bootstrap(): void
    {
        if (
            self::$active
            || !defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('SEARCH_MANAGER_PROCESS_OWNER_HELPER')
        ) {
            return;
        }

        self::assertRuntimeSupport();
        self::$lastStartupFailureEvidence = null;
        self::$startupJournalRootPreexisted = is_dir(self::journalRootPath());
        $watchdogEffective = false;
        try {
            self::recoverStaleRuns();
            self::createRunBoundary();
            self::startWatchdog();
            $watchdogEffective = true;

            self::injectStartupFailure('gate');
            $gate = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($gate === false) {
                throw new \RuntimeException('Unable to create the PHPUnit supervisor gate.');
            }
            self::$startupGates = $gate;
            self::mutateJournal(static function(array &$journal): void {
                $journal['startupGate'] = 'created';
            });

            self::injectStartupFailure('fork');
            $childPid = pcntl_fork();
            if ($childPid === -1) {
                throw new \RuntimeException('Unable to fork the supervised PHPUnit process.');
            }

            if ($childPid === 0) {
                self::closeStartupGate(0);
                $released = fread(self::$startupGates[1], 1);
                self::closeStartupGate(1);
                if ($released !== '1') {
                    fwrite(STDERR, "Search Manager PHPUnit supervisor exited before ownership was established.\n");
                    exit(self::CLEANUP_FAILURE_EXIT_CODE);
                }

                self::$startupGates = [];
                self::$phpunitChild = true;
                self::$watchdogProcess = null;

                return;
            }

            self::$startupChildPid = $childPid;
            self::closeStartupGate(1);
            $observedChildIdentity = self::processIdentity($childPid);
            self::$startupChildIdentity = $observedChildIdentity;
            self::mutateJournal(static function(array &$journal) use ($childPid, $observedChildIdentity): void {
                $journal['startupChild'] = [
                    'pid' => $childPid,
                    'identity' => $observedChildIdentity,
                    'state' => 'gate-blocked-direct-child',
                ];
            });
            self::injectStartupFailure('child-identity');
            if ($observedChildIdentity === null) {
                throw new \RuntimeException('Unable to identify the supervised PHPUnit process.');
            }
            $childIdentity = $observedChildIdentity;

            self::mutateJournal(static function(array &$journal) use ($childIdentity): void {
                $journal['phpunit'] = $childIdentity;
                $journal['startupChild']['state'] = 'released';
            });
            if (fwrite(self::$startupGates[0], '1') !== 1) {
                throw new \RuntimeException('Unable to release the supervised PHPUnit process.');
            }
            self::closeStartupGate(0);
            self::$startupGates = [];
        } catch (\Throwable $startupFailure) {
            self::cleanupFailedStartup($startupFailure, $watchdogEffective);

            throw $startupFailure;
        }

        if (($_SERVER['SM_A12_PROCESS_OWNER_TRACE'] ?? null) === '1') {
            fwrite(STDERR, 'SM_A12_PROCESS_OWNER_START ' . json_encode([
                'runId' => self::$runId,
                'runRoot' => self::$runRoot,
                'storageRoot' => self::$storageRoot,
                'journalDirectory' => self::$journalDirectory,
                'supervisor' => self::processIdentity(getmypid()),
                'phpunit' => $childIdentity,
                'watchdog' => self::currentJournal()['watchdog'] ?? null,
                'command' => self::currentJournal()['command'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        }

        self::supervise($childPid, $childIdentity);
    }

    /** @return array<string, mixed>|null */
    public static function lastStartupFailureEvidence(): ?array
    {
        return self::$lastStartupFailureEvidence;
    }

    public static function isActive(): bool
    {
        return self::$active && self::$phpunitChild;
    }

    public static function runRoot(): string
    {
        self::assertActiveChild();

        return self::$runRoot;
    }

    public static function reservePath(string $label): string
    {
        self::assertActiveChild();
        $label = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', $label), '-');
        if ($label === '') {
            throw new \InvalidArgumentException('A process-owned path label cannot be empty.');
        }

        $path = self::$runRoot . DIRECTORY_SEPARATOR . strtolower($label) . '-' . bin2hex(random_bytes(8));
        self::registerPath($path);

        return $path;
    }

    public static function createDirectory(string $label): string
    {
        $path = self::reservePath($label);
        if (!mkdir($path, 0700) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create process-owned directory: {$path}");
        }

        return $path;
    }

    public static function createStorageDirectory(string $label): string
    {
        self::assertActiveChild();
        $label = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', $label), '-');
        if ($label === '') {
            throw new \InvalidArgumentException('A process-owned storage path label cannot be empty.');
        }

        $path = self::$storageRoot . DIRECTORY_SEPARATOR . strtolower($label) . '-' . bin2hex(random_bytes(8));
        self::registerPath($path);
        if (!mkdir($path, 0700) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create process-owned storage directory: {$path}");
        }

        return $path;
    }

    public static function registerPath(string $path): void
    {
        self::assertActiveChild();
        $path = self::normaliseAbsolutePath($path);
        self::assertWithinOwnedRoot($path, self::$runRoot, self::$storageRoot, 'process-owned path');

        self::mutateJournal(static function(array &$journal) use ($path): void {
            $journal['paths'][$path] = [
                'path' => $path,
                'registeredAt' => gmdate(DATE_ATOM),
            ];
            ksort($journal['paths'], SORT_STRING);
        });
    }

    /** @param resource $process */
    public static function registerProcess($process, string $label): array
    {
        self::assertActiveChild();
        $status = proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        $identity = self::processIdentity($pid);
        if ($identity === null) {
            throw new \RuntimeException("Unable to identify process {$pid} for {$label}.");
        }

        $identity['label'] = $label;
        $key = self::identityKey($identity);
        self::mutateJournal(static function(array &$journal) use ($identity, $key): void {
            $journal['processes'][$key] = $identity;
            ksort($journal['processes'], SORT_STRING);
        });

        return $identity;
    }

    /** @param array{pid: int, startTicks: string} $identity */
    public static function unregisterProcess(array $identity): void
    {
        if (!self::isActive()) {
            return;
        }

        $key = self::identityKey($identity);
        self::mutateJournal(static function(array &$journal) use ($key): void {
            unset($journal['processes'][$key]);
        });
    }

    public static function registerRollbackRow(string $table, string $uid): void
    {
        self::assertActiveChild();
        if ($table !== 'searchmanager_pending_syncs' || !preg_match('/^[a-f0-9-]{36}$/i', $uid)) {
            throw new \InvalidArgumentException('Only an exact pending-sync UUID rollback row can be registered.');
        }

        self::mutateJournal(static function(array &$journal) use ($table, $uid): void {
            $journal['rollbackRows'][$table . ':' . $uid] = [
                'table' => $table,
                'uid' => $uid,
                'contract' => 'independent uncommitted transaction; connection termination rolls back automatically',
            ];
            ksort($journal['rollbackRows'], SORT_STRING);
        });
    }

    /** @param array<string, mixed> $state */
    public static function publishProbeState(array $state): void
    {
        self::assertActiveChild();
        self::mutateJournal(static function(array &$journal) use ($state): void {
            $journal['probe'] = $state;
        });
    }

    public static function requestSyntheticCleanupFailure(string $message): void
    {
        self::assertActiveChild();
        self::mutateJournal(static function(array &$journal) use ($message): void {
            $journal['syntheticCleanupFailure'] = $message;
        });
    }

    /** @return array<string, mixed> */
    public static function readExternalJournal(string $journalDirectory): array
    {
        return self::readJournalFromDirectory(self::validatedJournalDirectory($journalDirectory));
    }

    /** @return array<string, mixed> */
    public static function readExternalReport(string $journalDirectory): array
    {
        $journalDirectory = self::validatedJournalDirectory($journalDirectory);
        $reportPath = $journalDirectory . DIRECTORY_SEPARATOR . 'report.json';
        $contents = file_get_contents($reportPath);
        if (!is_string($contents)) {
            throw new \RuntimeException("Process-owner report is not available: {$reportPath}");
        }

        $wrapper = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($wrapper) || !is_array($wrapper['payload'] ?? null) || !is_string($wrapper['hmac'] ?? null)) {
            throw new \RuntimeException("Invalid process-owner report: {$reportPath}");
        }
        $secret = trim((string)file_get_contents($journalDirectory . DIRECTORY_SEPARATOR . 'secret'));
        $payload = json_encode($wrapper['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $payload, $secret), $wrapper['hmac'])) {
            throw new \RuntimeException("Process-owner report authentication failed: {$reportPath}");
        }

        return $wrapper['payload'];
    }

    public static function acknowledgeExternalReport(string $journalDirectory): void
    {
        $journalDirectory = self::validatedJournalDirectory($journalDirectory);
        $report = self::readExternalReport($journalDirectory);
        if (!($report['resourcesClean'] ?? false)) {
            throw new \RuntimeException('Cannot acknowledge a process-owner report with uncleared resources.');
        }

        self::removeExactTree($journalDirectory, self::journalRoot());
        self::removeEmptyJournalRoot();
    }

    /** @return array{pid: int, startTicks: string}|null */
    public static function processIdentity(int $pid): ?array
    {
        if ($pid <= 0) {
            return null;
        }

        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (!is_string($stat)) {
            return null;
        }
        $closingParenthesis = strrpos($stat, ') ');
        if ($closingParenthesis === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $closingParenthesis + 2)));
        if (!is_array($fields) || !isset($fields[19])) {
            return null;
        }

        return ['pid' => $pid, 'startTicks' => (string)$fields[19]];
    }

    public static function watchdogMain(string $journalDirectory): int
    {
        try {
            $journalDirectory = self::validatedJournalDirectory($journalDirectory);
            if (!self::publishWatchdogReadiness($journalDirectory)) {
                return 0;
            }
            while (true) {
                $journal = self::readJournalFromDirectory($journalDirectory);
                $supervisorAlive = self::identityIsAlive($journal['supervisor'] ?? null);
                if (($journal['releaseRequested'] ?? false) || !$supervisorAlive) {
                    self::performCleanup($journalDirectory, $supervisorAlive ? 'released' : 'supervisor-terminated');

                    return 0;
                }
                usleep(50000);
            }
        } catch (\Throwable $exception) {
            $message = 'Search Manager process watchdog failed: ' . $exception->getMessage() . PHP_EOL;
            @file_put_contents($journalDirectory . DIRECTORY_SEPARATOR . 'watchdog-fatal.log', $message, FILE_APPEND | LOCK_EX);

            return self::CLEANUP_FAILURE_EXIT_CODE;
        }
    }

    private static function assertRuntimeSupport(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill', 'proc_open', 'stream_socket_pair'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException("Search Manager's PHPUnit process owner requires {$function}().");
            }
        }
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc/self')) {
            throw new \RuntimeException('Search Manager\'s PHPUnit process owner requires Linux /proc PID identities.');
        }
    }

    private static function createRunBoundary(): void
    {
        self::$runId = bin2hex(random_bytes(16));
        self::$secret = bin2hex(random_bytes(32));
        self::$journalDirectory = self::journalRoot() . DIRECTORY_SEPARATOR . self::$runId;
        self::$runRoot = self::normaliseAbsolutePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::RUN_PREFIX . self::$runId);
        self::$storageRoot = self::normaliseAbsolutePath(
            dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . self::STORAGE_PREFIX . self::$runId,
        );

        if (!mkdir(self::$journalDirectory, 0700) && !is_dir(self::$journalDirectory)) {
            throw new \RuntimeException('Unable to create the process-owner journal directory.');
        }
        self::injectStartupFailure('journal-directory');
        if (file_put_contents(self::$journalDirectory . DIRECTORY_SEPARATOR . 'secret', self::$secret . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to create the process-owner journal secret.');
        }
        if (!chmod(self::$journalDirectory . DIRECTORY_SEPARATOR . 'secret', 0600)) {
            throw new \RuntimeException('Unable to protect the process-owner journal secret.');
        }
        self::injectStartupFailure('secret');

        self::$active = true;
        self::writeJournal([
            'version' => 1,
            'runId' => self::$runId,
            'runRoot' => self::$runRoot,
            'storageRoot' => self::$storageRoot,
            'journalDirectory' => self::$journalDirectory,
            'createdAt' => gmdate(DATE_ATOM),
            'createdAtUnix' => microtime(true),
            'command' => array_values(array_map('strval', $_SERVER['argv'] ?? [])),
            'supervisor' => self::processIdentity(getmypid()),
            'watchdog' => null,
            'phpunit' => null,
            'startupGate' => null,
            'startupChild' => null,
            'paths' => [
                self::$runRoot => ['path' => self::$runRoot, 'registeredAt' => gmdate(DATE_ATOM), 'boundary' => true, 'state' => 'reserved'],
                self::$storageRoot => ['path' => self::$storageRoot, 'registeredAt' => gmdate(DATE_ATOM), 'boundary' => true, 'state' => 'reserved'],
            ],
            'processes' => [],
            'rollbackRows' => [],
            'probe' => null,
            'releaseRequested' => false,
            'originalOutcome' => null,
            'peakMemoryKb' => null,
            'syntheticCleanupFailure' => null,
        ]);
        self::injectStartupFailure('journal');

        if (!mkdir(self::$runRoot, 0700) && !is_dir(self::$runRoot)) {
            throw new \RuntimeException('Unable to create the process-owned run root.');
        }
        self::mutateJournal(static function(array &$journal): void {
            $journal['paths'][self::$runRoot]['state'] = 'created';
        });
        self::injectStartupFailure('run-root');
        if (!mkdir(self::$storageRoot, 0700) && !is_dir(self::$storageRoot)) {
            throw new \RuntimeException('Unable to create the process-owned storage root.');
        }
        self::mutateJournal(static function(array &$journal): void {
            $journal['paths'][self::$storageRoot]['state'] = 'created';
        });
        self::injectStartupFailure('storage-root');
    }

    private static function startWatchdog(): void
    {
        self::injectStartupFailure('watchdog-proc-open');
        $watchdogScript = __DIR__ . '/ProcessRunWatchdog.php';
        $watchdogLog = self::$journalDirectory . DIRECTORY_SEPARATOR . 'watchdog.log';
        $process = proc_open([
            '/usr/bin/setsid',
            PHP_BINARY,
            $watchdogScript,
            self::$journalDirectory,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $watchdogLog, 'a'],
            2 => ['file', $watchdogLog, 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the process-owner watchdog.');
        }

        self::$watchdogProcess = $process;
        $status = proc_get_status($process);
        $identity = self::processIdentity((int)($status['pid'] ?? 0));
        self::$watchdogIdentity = $identity;
        self::injectStartupFailure('watchdog-identity');
        if ($identity === null) {
            throw new \RuntimeException('Unable to identify the process-owner watchdog.');
        }

        self::mutateJournal(static function(array &$journal) use ($identity): void {
            $journal['watchdog'] = $identity;
        });
        self::waitForWatchdogReadiness($identity, 3.0);
    }

    /** @param array{pid: int, startTicks: string} $identity */
    private static function waitForWatchdogReadiness(array $identity, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $readyPath = self::$journalDirectory . DIRECTORY_SEPARATOR . 'watchdog.ready.json';
            if (is_file($readyPath)) {
                $ready = self::readAuthenticatedPayload($readyPath, self::$journalDirectory);
                if (($ready['identity'] ?? null) !== $identity) {
                    throw new \RuntimeException('The process-owner watchdog readiness identity does not match.');
                }

                return;
            }
            if (!self::identityIsAlive($identity)) {
                throw new \RuntimeException('The process-owner watchdog exited before publishing readiness.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('The process-owner watchdog did not publish readiness.');
    }

    private static function publishWatchdogReadiness(string $journalDirectory): bool
    {
        $deadline = microtime(true) + 5.0;
        do {
            $journal = self::readJournalFromDirectory($journalDirectory);
            if (!self::identityIsAlive($journal['supervisor'] ?? null)) {
                self::performCleanup($journalDirectory, 'supervisor-terminated-during-startup');

                return false;
            }
            $identity = self::processIdentity(getmypid());
            if ($identity !== null && ($journal['watchdog'] ?? null) === $identity) {
                self::writeAuthenticatedPayload(
                    $journalDirectory . DIRECTORY_SEPARATOR . 'watchdog.ready.json',
                    ['identity' => $identity, 'publishedAt' => gmdate(DATE_ATOM)],
                    $journalDirectory,
                );

                return true;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('The watchdog was not registered with its exact process identity.');
    }

    /** @param array{pid: int, startTicks: string} $childIdentity */
    private static function supervise(int $childPid, array $childIdentity): never
    {
        $forwardedSignal = null;
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP, SIGQUIT] as $signal) {
            pcntl_signal($signal, static function(int $received) use (&$forwardedSignal, $childIdentity): void {
                $forwardedSignal ??= $received;
                if (self::identityIsAlive($childIdentity)) {
                    posix_kill($childIdentity['pid'], $received);
                }
            });
        }

        do {
            $waited = pcntl_waitpid($childPid, $status);
        } while ($waited === -1 && pcntl_get_last_error() === PCNTL_EINTR);

        $outcome = ['exitCode' => null, 'signal' => null];
        if ($waited === $childPid) {
            if (pcntl_wifexited($status)) {
                $outcome['exitCode'] = pcntl_wexitstatus($status);
            } elseif (pcntl_wifsignaled($status)) {
                $outcome['signal'] = pcntl_wtermsig($status);
            }
        } else {
            $outcome['exitCode'] = self::CLEANUP_FAILURE_EXIT_CODE;
        }
        $rusage = getrusage(self::RUSAGE_CHILDREN_MODE);
        $peakMemoryKb = is_array($rusage) ? ($rusage['ru_maxrss'] ?? null) : null;
        self::mutateJournal(static function(array &$journal) use ($outcome, $peakMemoryKb): void {
            $journal['originalOutcome'] = $outcome;
            $journal['peakMemoryKb'] = is_int($peakMemoryKb) ? $peakMemoryKb : null;
            $journal['releaseRequested'] = true;
        });

        $report = self::waitForReport(self::$journalDirectory, 15.0);
        self::reapWatchdog();
        $cleanupFailure = $report === null || !($report['success'] ?? false);
        if ($cleanupFailure) {
            $message = $report === null
                ? 'Process-owner watchdog did not produce a final report.'
                : implode(' | ', array_map('strval', $report['errors'] ?? ['Unknown cleanup failure.']));
            fwrite(STDERR, "Search Manager process cleanup failed: {$message}\n");
        }

        if ($report !== null && ($report['resourcesClean'] ?? false)) {
            self::removeExactTree(self::$journalDirectory, self::journalRoot());
            self::removeEmptyJournalRoot();
        }

        $signal = is_int($outcome['signal']) ? $outcome['signal'] : $forwardedSignal;
        if (is_int($signal) && $signal > 0) {
            pcntl_signal($signal, SIG_DFL);
            posix_kill(getmypid(), $signal);
            exit(128 + $signal);
        }

        $exitCode = is_int($outcome['exitCode']) ? $outcome['exitCode'] : self::CLEANUP_FAILURE_EXIT_CODE;
        if ($exitCode === 0 && $cleanupFailure) {
            $exitCode = self::CLEANUP_FAILURE_EXIT_CODE;
        }
        exit($exitCode);
    }

    private static function performCleanup(string $journalDirectory, string $reason): void
    {
        $lock = fopen($journalDirectory . DIRECTORY_SEPARATOR . 'cleanup.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return;
        }

        $started = microtime(true);
        $errors = [];
        $processResults = [];
        $pathResults = [];
        try {
            $journal = self::readJournalFromDirectory($journalDirectory);
            $runRoot = self::normaliseAbsolutePath((string)($journal['runRoot'] ?? ''));
            $storageRoot = self::normaliseAbsolutePath((string)($journal['storageRoot'] ?? ''));
            self::assertRunRoot($runRoot, (string)($journal['runId'] ?? ''));
            self::assertStorageRoot($storageRoot, (string)($journal['runId'] ?? ''));

            if ($reason !== 'released') {
                foreach (array_filter([$journal['phpunit'] ?? null]) as $identity) {
                    $processResults[] = self::terminateIdentity($identity, 'phpunit');
                }
            }
            foreach ($journal['processes'] ?? [] as $identity) {
                $processResults[] = self::terminateIdentity($identity, (string)($identity['label'] ?? 'owned-child'));
            }

            foreach ($journal['paths'] ?? [] as $owned) {
                $path = self::normaliseAbsolutePath((string)($owned['path'] ?? ''));
                try {
                    self::assertWithinOwnedRoot($path, $runRoot, $storageRoot, 'journaled path');
                    $pathResults[$path] = 'validated';
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if ($errors === []) {
                self::removeExactTree($runRoot, dirname($runRoot));
                $pathResults[$runRoot] = 'removed';
                self::removeExactTree($storageRoot, dirname($storageRoot));
                $pathResults[$storageRoot] = 'removed';
            }
            if (is_string($journal['syntheticCleanupFailure'] ?? null)) {
                $errors[] = (string)$journal['syntheticCleanupFailure'];
            }

            $resourcesClean = !file_exists($runRoot) && !is_link($runRoot)
                && !file_exists($storageRoot) && !is_link($storageRoot);
            foreach ($processResults as $result) {
                if (!($result['absent'] ?? false)) {
                    $resourcesClean = false;
                }
            }

            $report = [
                'runId' => $journal['runId'] ?? null,
                'reason' => $reason,
                'command' => $journal['command'] ?? [],
                'supervisor' => $journal['supervisor'] ?? null,
                'watchdog' => $journal['watchdog'] ?? null,
                'phpunit' => $journal['phpunit'] ?? null,
                'originalOutcome' => $journal['originalOutcome'] ?? null,
                'peakMemoryKb' => $journal['peakMemoryKb'] ?? null,
                'rollbackRows' => array_values($journal['rollbackRows'] ?? []),
                'processes' => $processResults,
                'paths' => $pathResults,
                'resourcesClean' => $resourcesClean,
                'success' => $resourcesClean && $errors === [],
                'errors' => $errors,
                'elapsedSeconds' => microtime(true) - (float)($journal['createdAtUnix'] ?? $started),
                'cleanupSeconds' => microtime(true) - $started,
                'finishedAt' => gmdate(DATE_ATOM),
            ];
            $secret = trim((string)file_get_contents($journalDirectory . DIRECTORY_SEPARATOR . 'secret'));
            if ($secret === '') {
                throw new \RuntimeException('The process-owner report cannot be authenticated without its run secret.');
            }
            $payload = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $wrapper = [
                'payload' => $report,
                'hmac' => hash_hmac('sha256', $payload, $secret),
            ];
            file_put_contents(
                $journalDirectory . DIRECTORY_SEPARATOR . 'report.json',
                json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
                LOCK_EX,
            );
            chmod($journalDirectory . DIRECTORY_SEPARATOR . 'report.json', 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param mixed $identity */
    private static function terminateIdentity($identity, string $label): array
    {
        if (!is_array($identity) || !isset($identity['pid'], $identity['startTicks'])) {
            return ['label' => $label, 'identity' => $identity, 'action' => 'invalid', 'absent' => false];
        }
        if (!self::identityIsAlive($identity)) {
            return ['label' => $label, 'identity' => $identity, 'action' => 'already-absent', 'absent' => true];
        }

        posix_kill((int)$identity['pid'], SIGTERM);
        $deadline = microtime(true) + 1.0;
        while (self::identityIsAlive($identity) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (self::identityIsAlive($identity)) {
            posix_kill((int)$identity['pid'], SIGKILL);
        }
        $deadline = microtime(true) + 3.0;
        while (self::identityIsAlive($identity) && microtime(true) < $deadline) {
            usleep(10000);
        }

        return [
            'label' => $label,
            'identity' => $identity,
            'action' => 'terminated',
            'absent' => !self::identityIsAlive($identity),
        ];
    }

    /** @param mixed $identity */
    private static function identityIsAlive($identity): bool
    {
        if (!is_array($identity) || !isset($identity['pid'], $identity['startTicks'])) {
            return false;
        }
        $current = self::processIdentity((int)$identity['pid']);

        return $current !== null
            && hash_equals((string)$identity['startTicks'], $current['startTicks'])
            && !in_array(self::processState((int)$identity['pid']), ['Z', 'X', 'x'], true);
    }

    private static function processState(int $pid): ?string
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (!is_string($stat)) {
            return null;
        }
        $closingParenthesis = strrpos($stat, ') ');
        if ($closingParenthesis === false) {
            return null;
        }

        return substr($stat, $closingParenthesis + 2, 1) ?: null;
    }

    private static function recoverStaleRuns(): void
    {
        $root = self::journalRoot();
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item->isDir() || $item->isLink() || !preg_match('/^[a-f0-9]{32}$/', $item->getBasename())) {
                continue;
            }
            $directory = $item->getPathname();
            try {
                $journal = self::readJournalFromDirectory($directory);
                if (self::identityIsAlive($journal['supervisor'] ?? null)) {
                    continue;
                }
                $reportPath = $directory . DIRECTORY_SEPARATOR . 'report.json';
                if (!is_file($reportPath)) {
                    self::performCleanup($directory, 'next-start-recovery');
                }
                $report = self::readExternalReport($directory);
                if (!($report['resourcesClean'] ?? false)) {
                    throw new \RuntimeException('A stale process-owner run still has uncleared resources.');
                }
                if (!($report['success'] ?? false)) {
                    fwrite(STDERR, 'Recovered prior Search Manager cleanup warning: '
                        . implode(' | ', array_map('strval', $report['errors'] ?? [])) . PHP_EOL);
                }
                self::removeExactTree($directory, $root);
                self::removeEmptyJournalRoot();
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    "Unable to recover exact stale process-owner journal {$directory}: {$exception->getMessage()}",
                    0,
                    $exception,
                );
            }
        }
    }

    private static function cleanupFailedStartup(\Throwable $startupFailure, bool $watchdogEffective): void
    {
        $runId = self::$runId;
        $runRoot = self::$runRoot;
        $storageRoot = self::$storageRoot;
        $journalDirectory = self::$journalDirectory;
        $watchdogIdentity = self::$watchdogIdentity;
        $childIdentity = self::$startupChildIdentity;
        $cleanupErrors = [];
        $route = $watchdogEffective ? 'watched' : 'local';

        self::closeAllStartupGates($cleanupErrors);
        self::reapStartupChild($cleanupErrors);

        if ($watchdogEffective && $journalDirectory !== null) {
            try {
                self::mutateJournal(static function(array &$journal): void {
                    $journal['releaseRequested'] = true;
                    if (self::startupCleanupFailureMode() === 'watched') {
                        $journal['syntheticCleanupFailure'] = 'injected watched startup cleanup failure';
                    }
                });
            } catch (\Throwable $exception) {
                $cleanupErrors[] = 'Unable to request watched startup cleanup: ' . $exception->getMessage();
            }

            $report = null;
            try {
                $report = self::waitForReport($journalDirectory, 5.0);
            } catch (\Throwable $exception) {
                $cleanupErrors[] = 'Unable to authenticate watched startup cleanup: ' . $exception->getMessage();
            }
            self::reapWatchdog($cleanupErrors);
            if ($report === null) {
                $cleanupErrors[] = 'The startup watchdog did not produce an authenticated cleanup report.';
            } else {
                foreach ($report['errors'] ?? [] as $error) {
                    $cleanupErrors[] = 'Watched startup cleanup: ' . (string)$error;
                }
                if (!($report['resourcesClean'] ?? false)) {
                    $cleanupErrors[] = 'The startup watchdog reported uncleared resources.';
                }
            }
            if ($report !== null && ($report['resourcesClean'] ?? false)) {
                try {
                    self::removeExactJournalDirectory($journalDirectory);
                } catch (\Throwable $exception) {
                    $cleanupErrors[] = 'Unable to remove the completed startup journal: ' . $exception->getMessage();
                }
            }
        } else {
            self::reapWatchdog($cleanupErrors);
        }

        self::removeStartupPaths($runId, $runRoot, $storageRoot, $journalDirectory, $cleanupErrors);
        if (self::startupCleanupFailureMode() === 'local' && !$watchdogEffective) {
            $cleanupErrors[] = 'Injected local startup cleanup failure.';
        }

        $resources = [
            'runRootAbsent' => self::pathIsAbsent($runRoot),
            'storageRootAbsent' => self::pathIsAbsent($storageRoot),
            'journalAbsent' => self::pathIsAbsent($journalDirectory),
            'watchdogAbsent' => !self::identityIsAlive($watchdogIdentity),
            'childAbsent' => !self::identityIsAlive($childIdentity),
            'gateHandlesClosed' => self::$startupGates === [],
            'watchdogHandleClosed' => !is_resource(self::$watchdogProcess),
        ];
        foreach ($resources as $label => $clean) {
            if (!$clean) {
                $cleanupErrors[] = "Startup cleanup left {$label}.";
            }
        }

        self::$lastStartupFailureEvidence = [
            'originalClass' => $startupFailure::class,
            'originalMessage' => $startupFailure->getMessage(),
            'route' => $route,
            'runId' => $runId,
            'runRoot' => $runRoot,
            'storageRoot' => $storageRoot,
            'journalDirectory' => $journalDirectory,
            'watchdog' => $watchdogIdentity,
            'child' => $childIdentity,
            'resources' => $resources,
            'cleanupErrors' => array_values(array_unique($cleanupErrors)),
        ];
        foreach (self::$lastStartupFailureEvidence['cleanupErrors'] as $cleanupError) {
            fwrite(STDERR, "Search Manager startup cleanup warning: {$cleanupError}\n");
        }

        self::resetStartupState();
    }

    /** @param list<string> $errors */
    private static function closeAllStartupGates(array &$errors): void
    {
        foreach (array_keys(self::$startupGates) as $index) {
            try {
                self::closeStartupGate((int)$index);
            } catch (\Throwable $exception) {
                $errors[] = 'Unable to close a startup gate: ' . $exception->getMessage();
            }
        }
        self::$startupGates = [];
    }

    private static function closeStartupGate(int $index): void
    {
        if (isset(self::$startupGates[$index]) && is_resource(self::$startupGates[$index])) {
            fclose(self::$startupGates[$index]);
        }
        unset(self::$startupGates[$index]);
    }

    /** @param list<string> $errors */
    private static function reapStartupChild(array &$errors): void
    {
        $pid = self::$startupChildPid;
        if ($pid === null) {
            return;
        }

        $deadline = microtime(true) + 1.0;
        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid || $waited === -1) {
                self::$startupChildPid = null;

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        if (self::$startupChildIdentity !== null) {
            $result = self::terminateIdentity(self::$startupChildIdentity, 'startup-phpunit-child');
            if (!($result['absent'] ?? false)) {
                $errors[] = 'Unable to terminate the exact startup PHPUnit child identity.';
            }
        } else {
            $errors[] = 'The gate-blocked startup child did not exit and has no validated identity.';
        }
        pcntl_waitpid($pid, $status);
        self::$startupChildPid = null;
    }

    /** @param list<string> $errors */
    private static function removeStartupPaths(
        ?string $runId,
        ?string $runRoot,
        ?string $storageRoot,
        ?string $journalDirectory,
        array &$errors,
    ): void {
        foreach ([['path' => $storageRoot, 'kind' => 'storage'], ['path' => $runRoot, 'kind' => 'run']] as $owned) {
            if (!is_string($owned['path']) || $owned['path'] === '') {
                continue;
            }
            try {
                if (!is_string($runId) || $runId === '') {
                    throw new \RuntimeException('No exact run identity is available for startup cleanup.');
                }
                if ($owned['kind'] === 'storage') {
                    self::assertStorageRoot($owned['path'], $runId);
                } else {
                    self::assertRunRoot($owned['path'], $runId);
                }
                self::removeExactTree($owned['path'], dirname($owned['path']));
            } catch (\Throwable $exception) {
                $errors[] = "Unable to remove exact startup {$owned['kind']} root: {$exception->getMessage()}";
            }
        }
        if (is_string($journalDirectory) && $journalDirectory !== '') {
            try {
                self::removeExactJournalDirectory($journalDirectory);
            } catch (\Throwable $exception) {
                $errors[] = 'Unable to remove the exact startup journal: ' . $exception->getMessage();
            }
        }
    }

    private static function removeExactJournalDirectory(string $journalDirectory): void
    {
        $journalDirectory = self::normaliseAbsolutePath($journalDirectory);
        $root = self::journalRootPath();
        self::assertWithin($journalDirectory, $root, 'process-owner journal');
        if (!preg_match('/^[a-f0-9]{32}$/', basename($journalDirectory))) {
            throw new \InvalidArgumentException("Invalid process-owner journal identity: {$journalDirectory}");
        }
        self::removeExactTree($journalDirectory, $root);
        self::removeEmptyJournalRoot();
    }

    private static function injectStartupFailure(string $point): void
    {
        if (
            ($_SERVER['SM_A12_PROCESS_OWNER_STARTUP_PROBE'] ?? null) === '1'
            && ($_SERVER['SM_A12_PROCESS_OWNER_STARTUP_FAILURE'] ?? null) === $point
        ) {
            throw new \RuntimeException("Injected process-owner startup failure at {$point}.");
        }
    }

    private static function startupCleanupFailureMode(): ?string
    {
        if (($_SERVER['SM_A12_PROCESS_OWNER_STARTUP_PROBE'] ?? null) !== '1') {
            return null;
        }
        $mode = $_SERVER['SM_A12_PROCESS_OWNER_STARTUP_CLEANUP_FAILURE'] ?? null;

        return in_array($mode, ['local', 'watched'], true) ? $mode : null;
    }

    private static function pathIsAbsent(?string $path): bool
    {
        return $path === null || (!file_exists($path) && !is_link($path));
    }

    private static function resetStartupState(): void
    {
        self::$active = false;
        self::$phpunitChild = false;
        self::$runId = null;
        self::$runRoot = null;
        self::$storageRoot = null;
        self::$journalDirectory = null;
        self::$secret = null;
        self::$watchdogIdentity = null;
        self::$startupChildPid = null;
        self::$startupChildIdentity = null;
        self::$startupGates = [];
        self::$watchdogProcess = null;
        self::$startupJournalRootPreexisted = false;
    }

    private static function assertActiveChild(): void
    {
        if (!self::isActive() || self::$runRoot === null || self::$storageRoot === null) {
            throw new \LogicException('The process run owner is not active in the PHPUnit child.');
        }
    }

    private static function currentJournal(): array
    {
        if (self::$journalDirectory === null) {
            throw new \LogicException('No active process-owner journal exists.');
        }

        return self::readJournalFromDirectory(self::$journalDirectory);
    }

    /** @param callable(array<string, mixed>&): void $mutator */
    private static function mutateJournal(callable $mutator): void
    {
        if (self::$journalDirectory === null) {
            throw new \LogicException('No active process-owner journal exists.');
        }
        $lock = fopen(self::$journalDirectory . DIRECTORY_SEPARATOR . 'journal.lock', 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('Unable to open the process-owner journal lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the process-owner journal.');
            }
            $journal = self::readJournalFromDirectory(self::$journalDirectory);
            $mutator($journal);
            self::writeJournal($journal);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $journal */
    private static function writeJournal(array $journal): void
    {
        if (self::$journalDirectory === null || self::$secret === null) {
            throw new \LogicException('No active process-owner journal exists.');
        }
        $payload = json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $wrapper = [
            'payload' => $journal,
            'hmac' => hash_hmac('sha256', $payload, self::$secret),
        ];
        $temporary = self::$journalDirectory . DIRECTORY_SEPARATOR . 'journal.json.tmp.' . getmypid();
        file_put_contents(
            $temporary,
            json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            LOCK_EX,
        );
        chmod($temporary, 0600);
        if (!rename($temporary, self::$journalDirectory . DIRECTORY_SEPARATOR . 'journal.json')) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to atomically replace the process-owner journal.');
        }
    }

    /** @return array<string, mixed> */
    private static function readJournalFromDirectory(string $directory): array
    {
        $secret = trim((string)file_get_contents($directory . DIRECTORY_SEPARATOR . 'secret'));
        $contents = file_get_contents($directory . DIRECTORY_SEPARATOR . 'journal.json');
        if (!is_string($contents) || $secret === '') {
            throw new \RuntimeException("Incomplete process-owner journal: {$directory}");
        }
        $wrapper = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($wrapper) || !is_array($wrapper['payload'] ?? null) || !is_string($wrapper['hmac'] ?? null)) {
            throw new \RuntimeException("Malformed process-owner journal: {$directory}");
        }
        $payload = json_encode($wrapper['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $wrapper['hmac'])) {
            throw new \RuntimeException("Process-owner journal authentication failed: {$directory}");
        }

        return $wrapper['payload'];
    }

    /** @param array<string, mixed> $payload */
    private static function writeAuthenticatedPayload(string $path, array $payload, string $journalDirectory): void
    {
        $secret = trim((string)file_get_contents($journalDirectory . DIRECTORY_SEPARATOR . 'secret'));
        if ($secret === '') {
            throw new \RuntimeException('An authenticated process-owner payload requires its run secret.');
        }
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $wrapper = [
            'payload' => $payload,
            'hmac' => hash_hmac('sha256', $encodedPayload, $secret),
        ];
        $temporary = $path . '.tmp.' . getmypid();
        if (file_put_contents(
            $temporary,
            json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            LOCK_EX,
        ) === false) {
            throw new \RuntimeException("Unable to write authenticated process-owner payload: {$path}");
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException("Unable to publish authenticated process-owner payload: {$path}");
        }
    }

    /** @return array<string, mixed> */
    private static function readAuthenticatedPayload(string $path, string $journalDirectory): array
    {
        $contents = file_get_contents($path);
        $secret = trim((string)file_get_contents($journalDirectory . DIRECTORY_SEPARATOR . 'secret'));
        if (!is_string($contents) || $secret === '') {
            throw new \RuntimeException("Incomplete authenticated process-owner payload: {$path}");
        }
        $wrapper = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($wrapper) || !is_array($wrapper['payload'] ?? null) || !is_string($wrapper['hmac'] ?? null)) {
            throw new \RuntimeException("Malformed authenticated process-owner payload: {$path}");
        }
        $payload = json_encode($wrapper['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $wrapper['hmac'])) {
            throw new \RuntimeException("Process-owner payload authentication failed: {$path}");
        }

        return $wrapper['payload'];
    }

    private static function journalRoot(): string
    {
        $root = self::journalRootPath();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException("Unable to create process-owner journal root: {$root}");
        }

        return $root;
    }

    private static function journalRootPath(): string
    {
        return self::normaliseAbsolutePath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::JOURNAL_DIRECTORY);
    }

    private static function removeEmptyJournalRoot(): void
    {
        $root = self::journalRootPath();
        if (!self::$startupJournalRootPreexisted && is_dir($root)) {
            @rmdir($root);
        }
    }

    private static function validatedJournalDirectory(string $directory): string
    {
        $directory = self::normaliseAbsolutePath($directory);
        self::assertWithin($directory, self::journalRoot(), 'process-owner journal');
        if (!preg_match('/^[a-f0-9]{32}$/', basename($directory))) {
            throw new \InvalidArgumentException("Invalid process-owner journal identity: {$directory}");
        }

        return $directory;
    }

    private static function assertRunRoot(string $runRoot, string $runId): void
    {
        $expected = self::normaliseAbsolutePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::RUN_PREFIX . $runId);
        if (!hash_equals($expected, $runRoot)) {
            throw new \RuntimeException("Run-root identity mismatch: {$runRoot}");
        }
    }

    private static function assertStorageRoot(string $storageRoot, string $runId): void
    {
        $expected = self::normaliseAbsolutePath(
            dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . self::STORAGE_PREFIX . $runId,
        );
        if (!hash_equals($expected, $storageRoot)) {
            throw new \RuntimeException("Storage-root identity mismatch: {$storageRoot}");
        }
    }

    private static function assertWithinOwnedRoot(
        string $path,
        string $runRoot,
        string $storageRoot,
        string $label,
    ): void {
        try {
            self::assertWithin($path, $runRoot, $label);

            return;
        } catch (\InvalidArgumentException) {
            self::assertWithin($path, $storageRoot, $label);
        }
    }

    private static function assertWithin(string $path, string $root, string $label): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("Refusing {$label} outside exact boundary {$root}: {$path}");
        }
    }

    private static function normaliseAbsolutePath(string $path): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new \InvalidArgumentException("An exact absolute path is required: {$path}");
        }
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \InvalidArgumentException("Parent traversal is forbidden in owned paths: {$path}");
            }
            $parts[] = $part;
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private static function removeExactTree(string $path, string $allowedRoot): void
    {
        $path = self::normaliseAbsolutePath($path);
        $allowedRoot = self::normaliseAbsolutePath($allowedRoot);
        self::assertWithin($path, $allowedRoot, 'cleanup target');
        if ($path === $allowedRoot) {
            throw new \InvalidArgumentException("Refusing to remove the cleanup boundary itself: {$path}");
        }
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Unable to remove exact owned path: {$path}");
            }

            return;
        }

        chmod($path, 0700);
        $entries = iterator_to_array(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            self::removeExactTree($entry->getPathname(), $path);
        }
        if (!rmdir($path)) {
            throw new \RuntimeException("Unable to remove exact owned directory: {$path}");
        }
    }

    private static function identityKey(array $identity): string
    {
        return (int)$identity['pid'] . ':' . (string)$identity['startTicks'];
    }

    /** @return array<string, mixed>|null */
    private static function waitForReport(string $directory, float $seconds): ?array
    {
        $deadline = microtime(true) + $seconds;
        do {
            $path = $directory . DIRECTORY_SEPARATOR . 'report.json';
            if (is_file($path)) {
                return self::readExternalReport($directory);
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** @param list<string>|null $errors */
    private static function reapWatchdog(?array &$errors = null): void
    {
        if (!is_resource(self::$watchdogProcess)) {
            return;
        }
        $deadline = microtime(true) + 3.0;
        do {
            $status = proc_get_status(self::$watchdogProcess);
            if (!($status['running'] ?? false)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $status = proc_get_status(self::$watchdogProcess);
        if ($status['running'] ?? false) {
            if (self::$watchdogIdentity !== null && self::identityIsAlive(self::$watchdogIdentity)) {
                $result = self::terminateIdentity(self::$watchdogIdentity, 'startup-watchdog');
                if (!($result['absent'] ?? false) && is_array($errors)) {
                    $errors[] = 'Unable to terminate the exact startup watchdog identity.';
                }
            } elseif (!proc_terminate(self::$watchdogProcess, SIGKILL) && is_array($errors)) {
                $errors[] = 'Unable to terminate the startup watchdog process handle.';
            }
        }
        proc_close(self::$watchdogProcess);
        self::$watchdogProcess = null;
    }
}
