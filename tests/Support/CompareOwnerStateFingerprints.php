<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

$beforePath = $argv[1] ?? null;
$afterPath = $argv[2] ?? null;
if (!is_string($beforePath) || !is_string($afterPath) || $beforePath === '' || $afterPath === '') {
    fwrite(STDERR, "Usage: php CompareOwnerStateFingerprints.php BEFORE.json AFTER.json\n");
    exit(2);
}

$before = readFingerprint($beforePath);
$after = readFingerprint($afterPath);
$errors = [];
$sessionActivity = [];

compareExact($errors, 'manifest', $before['manifest'] ?? null, $after['manifest'] ?? null);
compareExact($errors, 'protectedAnalytics', $before['protectedAnalytics'] ?? null, $after['protectedAnalytics'] ?? null);
compareExact($errors, 'filesystem', $before['filesystem'] ?? null, $after['filesystem'] ?? null);
compareExact($errors, 'processOwnerJournal', $before['processOwnerJournal'] ?? null, $after['processOwnerJournal'] ?? null);
compareExact($errors, 'temporaryPaths', $before['temporaryPaths'] ?? null, $after['temporaryPaths'] ?? null);

$beforeDatabase = $before['database'] ?? null;
$afterDatabase = $after['database'] ?? null;
if (!is_array($beforeDatabase) || !is_array($afterDatabase)) {
    $errors[] = 'database: missing fingerprint map';
} elseif (array_keys($beforeDatabase) !== array_keys($afterDatabase)) {
    $errors[] = 'database: captured table manifest changed';
} else {
    foreach ($beforeDatabase as $table => $beforeTable) {
        $afterTable = $afterDatabase[$table];
        if (!str_ends_with((string)$table, 'sessions')) {
            compareExact($errors, "database.{$table}", $beforeTable, $afterTable);
            continue;
        }

        compareExact($errors, "database.{$table}.rows", $beforeTable['rows'] ?? null, $afterTable['rows'] ?? null);
        $beforeRows = $beforeTable['sessionRows'] ?? null;
        $afterRows = $afterTable['sessionRows'] ?? null;
        if (!is_array($beforeRows) || !is_array($afterRows) || array_keys($beforeRows) !== array_keys($afterRows)) {
            $errors[] = "database.{$table}: session identity set changed";
            continue;
        }

        foreach ($beforeRows as $id => $beforeRow) {
            $afterRow = $afterRows[$id];
            if (($beforeRow['rowSha256'] ?? null) === ($afterRow['rowSha256'] ?? null)) {
                continue;
            }

            $beforeStable = $beforeRow;
            $afterStable = $afterRow;
            unset($beforeStable['dateUpdated'], $beforeStable['rowSha256'], $afterStable['dateUpdated'], $afterStable['rowSha256']);
            if ($beforeStable !== $afterStable || ($beforeRow['dateUpdated'] ?? null) === ($afterRow['dateUpdated'] ?? null)) {
                $errors[] = "database.{$table}.sessionRows.{$id}: change is not attributable solely to dateUpdated";
                continue;
            }

            $sessionActivity[] = [
                'table' => $table,
                'id' => (int)$id,
                'userId' => $beforeRow['userId'] ?? null,
                'beforeDateUpdated' => $beforeRow['dateUpdated'] ?? null,
                'afterDateUpdated' => $afterRow['dateUpdated'] ?? null,
            ];
        }

        if (($beforeTable['sha256'] ?? null) !== ($afterTable['sha256'] ?? null) && $sessionActivity === []) {
            $errors[] = "database.{$table}: aggregate hash changed without attributable per-row activity";
        }
    }
}

$result = [
    'before' => $beforePath,
    'after' => $afterPath,
    'matched' => $errors === [],
    'capturedTables' => is_array($beforeDatabase) ? count($beforeDatabase) : 0,
    'sessionDateUpdates' => $sessionActivity,
    'errors' => $errors,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

exit($errors === [] ? 0 : 1);

/** @return array<string, mixed> */
function readFingerprint(string $path): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Unable to read fingerprint: {$path}");
    }
    $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException("Fingerprint is not a JSON object: {$path}");
    }

    return $value;
}

/** @param list<string> $errors */
function compareExact(array &$errors, string $label, mixed $before, mixed $after): void
{
    if ($before !== $after) {
        $errors[] = "{$label}: fingerprint changed";
    }
}
