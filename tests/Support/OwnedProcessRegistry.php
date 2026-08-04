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
 * Owns subprocess handles from creation through termination and reap.
 *
 * @since 5.54.0
 */
final class OwnedProcessRegistry
{
    /** @var array<int, array{process: resource, pipes: array<int, resource>, identity: array{pid: int, startTicks: string, label: string}}> */
    private array $processes = [];

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    public function register($process, array $pipes, string $label = 'test-child'): void
    {
        $identity = ProcessRunOwner::registerProcess($process, $label);
        $this->processes[get_resource_id($process)] = [
            'process' => $process,
            'pipes' => $pipes,
            'identity' => $identity,
        ];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{exitCode: int, output: string, error: string, pid: int, signaled: bool, termSignal: int}
     */
    public function finish($process, array $pipes, bool $terminate = false): array
    {
        $id = get_resource_id($process);
        if ($terminate) {
            $this->terminate($process);
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $output = isset($pipes[1]) && is_resource($pipes[1]) ? (string)stream_get_contents($pipes[1]) : '';
        $error = isset($pipes[2]) && is_resource($pipes[2]) ? (string)stream_get_contents($pipes[2]) : '';
        foreach ([1, 2] as $pipeId) {
            if (isset($pipes[$pipeId]) && is_resource($pipes[$pipeId])) {
                fclose($pipes[$pipeId]);
            }
        }

        $status = proc_get_status($process);
        $exitCode = proc_close($process);
        $identity = $this->processes[$id]['identity'];
        ProcessRunOwner::unregisterProcess($identity);
        unset($this->processes[$id]);

        return [
            'exitCode' => (int)($status['exitcode'] >= 0 ? $status['exitcode'] : $exitCode),
            'output' => $output,
            'error' => $error,
            'pid' => $identity['pid'],
            'signaled' => $status['signaled'],
            'termSignal' => $status['termsig'],
        ];
    }

    public function cleanup(): void
    {
        $errors = [];
        foreach ($this->processes as $id => $owned) {
            try {
                $this->terminate($owned['process']);
                foreach ($owned['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($owned['process']);
            } catch (\Throwable $exception) {
                $errors[] = $exception;
            } finally {
                ProcessRunOwner::unregisterProcess($owned['identity']);
                unset($this->processes[$id]);
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('One or more owned subprocesses could not be reaped.', 0, $errors[0]);
        }
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }

        proc_terminate($process);
        $deadline = microtime(true) + 1.0;
        do {
            usleep(10000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process, 9);
        }
    }
}
