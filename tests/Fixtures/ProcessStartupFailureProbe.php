<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;

define('PHPUNIT_COMPOSER_INSTALL', true);
require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** @return array<string, array<string, int|string>> */
function processOwnerJournalFingerprint(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $fingerprint = [
        '.' => [
            'type' => 'directory',
            'mode' => fileperms($root) & 0777,
            'size' => 0,
            'sha256' => '',
        ],
    ];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relative = substr($path, strlen($root) + 1);
        $fingerprint[$relative] = [
            'type' => $entry->isLink() ? 'link' : ($entry->isDir() ? 'directory' : 'file'),
            'mode' => $entry->getPerms() & 0777,
            'size' => $entry->isFile() ? $entry->getSize() : 0,
            'sha256' => $entry->isFile() ? hash_file('sha256', $path) : '',
        ];
    }
    ksort($fingerprint, SORT_STRING);

    return $fingerprint;
}

$journalRoot = dirname(__DIR__, 2) . '/.phpunit.cache/a12-process-owners';
$before = processOwnerJournalFingerprint($journalRoot);
$exception = null;
try {
    ProcessRunOwner::bootstrap();
} catch (Throwable $caught) {
    $exception = ['class' => $caught::class, 'message' => $caught->getMessage()];
}
$after = processOwnerJournalFingerprint($journalRoot);
$evidence = ProcessRunOwner::lastStartupFailureEvidence();
$success = is_array($exception)
    && is_array($evidence)
    && $exception['class'] === ($evidence['originalClass'] ?? null)
    && $exception['message'] === ($evidence['originalMessage'] ?? null)
    && !in_array(false, $evidence['resources'] ?? [false], true)
    && $before === $after;

fwrite(STDOUT, json_encode([
    'success' => $success,
    'exception' => $exception,
    'evidence' => $evidence,
    'journalFingerprintBefore' => $before,
    'journalFingerprintAfter' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

exit($success ? 0 : 1);
