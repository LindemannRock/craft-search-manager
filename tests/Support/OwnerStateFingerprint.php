<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use craft\db\Query;

require dirname(__DIR__) . '/bootstrap.php';

$outputPath = $argv[1] ?? null;
if (!is_string($outputPath) || $outputPath === '') {
    fwrite(STDERR, "Usage: php OwnerStateFingerprint.php OUTPUT.json [EXACT-EVIDENCE-PATH ...]\n");
    exit(2);
}
$outputPath = exactPath($outputPath);
$excludedEvidencePaths = array_values(array_unique(array_map(
    'exactPath',
    array_merge([$outputPath], array_slice($argv, 2)),
)));
sort($excludedEvidencePaths, SORT_STRING);
$reportedExcludedEvidencePaths = array_values(array_filter(
    $excludedEvidencePaths,
    static fn(string $path): bool => dirname($path) === exactPath(sys_get_temp_dir())
        && (fnmatch('search-manager-*', basename($path)) || fnmatch('sm-*', basename($path))),
));

$db = Craft::$app->getDb();
$schema = $db->getSchema();
$tables = [];
foreach ($schema->getTableNames() as $table) {
    if (
        str_contains($table, 'searchmanager_')
        || preg_match('/(?:^|_)(?:addresses|assets|assets_sites|categories|drafts|elements|elements_owners|elements_sites|entries|entries_authors|globalsets|relations|revisions|structureelements|tags|users|usergroups|usergroups_users|userpermissions|userpermissions_usergroups|userpermissions_users|userpreferences|authenticator|recoverycodes|tokens|webauthn|sessions|queue)$/', $table)
        || str_contains($table, 'shortlink')
        || str_contains($table, 'smartlink')
    ) {
        $tables[] = $table;
    }
}
sort($tables, SORT_STRING);
$queueTable = $schema->getRawTableName('{{%queue}}');
$pendingSyncTable = $schema->getRawTableName('{{%searchmanager_pending_syncs}}');
$analyticsTables = array_map(
    static fn(string $table): string => $schema->getRawTableName($table),
    [
        '{{%searchmanager_analytics}}',
        '{{%searchmanager_rule_analytics}}',
        '{{%searchmanager_promotion_analytics}}',
    ],
);

$tableFingerprints = [];
foreach ($tables as $table) {
    $tableSchema = $schema->getTableSchema($table, true);
    if ($tableSchema === null) {
        throw new RuntimeException("Unable to load table schema for {$table}.");
    }
    $orderBy = $tableSchema->primaryKey;
    if ($orderBy === []) {
        $orderBy = array_keys($tableSchema->columns);
    }
    $orderBy = array_fill_keys($orderBy, SORT_ASC);

    $hash = hash_init('sha256');
    $count = 0;
    $sessionRows = [];
    $ownedStateRows = [];
    foreach ((new Query())->from($table)->orderBy($orderBy)->batch(500, $db) as $batch) {
        foreach ($batch as $row) {
            hash_update($hash, stableJson($row) . "\n");
            $count++;
            if (str_ends_with($table, 'sessions')) {
                $id = (string)$row['id'];
                $sessionRows[$id] = [
                    'id' => (int)$row['id'],
                    'userId' => isset($row['userId']) ? (int)$row['userId'] : null,
                    'uid' => $row['uid'] ?? null,
                    'dateCreated' => $row['dateCreated'] ?? null,
                    'dateUpdated' => $row['dateUpdated'] ?? null,
                    'tokenSha256' => hash('sha256', (string)($row['token'] ?? '')),
                    'rowSha256' => hash('sha256', stableJson($row)),
                ];
            }
            if ($table === $queueTable) {
                $id = (string)$row['id'];
                $ownedStateRows[$id] = [
                    'id' => (int)$row['id'],
                    'jobSha256' => hash('sha256', (string)($row['job'] ?? '')),
                    'rowSha256' => hash('sha256', stableJson($row)),
                ];
            } elseif ($table === $pendingSyncTable || in_array($table, $analyticsTables, true)) {
                $id = (string)$row['id'];
                $ownedStateRows[$id] = [
                    'id' => (int)$row['id'],
                    'rowSha256' => hash('sha256', stableJson($row)),
                ];
            }
        }
    }
    $tableFingerprints[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
    if ($sessionRows !== []) {
        $tableFingerprints[$table]['sessionRows'] = $sessionRows;
    }
    if ($ownedStateRows !== []) {
        $tableFingerprints[$table]['ownedStateRows'] = $ownedStateRows;
    }
}

$protected = [];
foreach (['__sm_pr1_debt9__', '__smcachetest_'] as $prefix) {
    foreach (['searchmanager_rule_analytics', 'searchmanager_promotion_analytics'] as $table) {
        $actualTable = array_values(array_filter(
            $tables,
            static fn(string $candidate): bool => str_ends_with($candidate, $table),
        ))[0] ?? null;
        if ($actualTable === null) {
            continue;
        }
        $rows = (new Query())
            ->select(['id', 'query', 'uid'])
            ->from($actualTable)
            ->where(['like', 'query', $prefix . '%', false])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $protected[$prefix][$actualTable] = [
            'rows' => count($rows),
            'sha256' => hash('sha256', stableJson($rows)),
        ];
    }
}

$paths = [];
$storage = Craft::getAlias('@storage');
if (is_string($storage)) {
    foreach (glob($storage . '/search-manager*') ?: [] as $path) {
        $paths[$path] = fingerprintPath($path);
    }
}
foreach ((new Query())->select(['settings'])->from('{{%searchmanager_backends}}')->column() as $settingsJson) {
    $settings = json_decode((string)$settingsJson, true);
    $storagePath = is_array($settings) ? ($settings['storagePath'] ?? null) : null;
    if (!is_string($storagePath) || $storagePath === '') {
        continue;
    }
    $resolved = Craft::getAlias($storagePath);
    if (is_string($resolved) && file_exists($resolved)) {
        $paths[$resolved] = fingerprintPath($resolved);
    }
}
ksort($paths, SORT_STRING);
$processOwnerJournalRoot = exactPath(dirname(__DIR__, 2) . '/.phpunit.cache/a12-process-owners');
$processOwnerJournal = [
    'path' => $processOwnerJournalRoot,
    'exists' => file_exists($processOwnerJournalRoot) || is_link($processOwnerJournalRoot),
    'fingerprint' => fingerprintPath($processOwnerJournalRoot),
];

$temporaryPaths = [];
foreach (['search-manager-*', 'sm-*'] as $pattern) {
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $pattern) ?: [] as $path) {
        $path = exactPath($path);
        if (!in_array($path, $excludedEvidencePaths, true)) {
            $temporaryPaths[$path] = fingerprintPath($path);
        }
    }
}
ksort($temporaryPaths, SORT_STRING);

$fingerprint = [
    'capturedAt' => gmdate(DATE_ATOM),
    'manifest' => [
        'databaseTables' => array_values($tables),
        'databaseSelection' => [
            'allSearchManagerTables' => true,
            'coreOwnerTables' => [
                'addresses',
                'assets',
                'assets_sites',
                'categories',
                'drafts',
                'elements',
                'elements_owners',
                'elements_sites',
                'entries',
                'entries_authors',
                'globalsets',
                'relations',
                'revisions',
                'structureelements',
                'tags',
                'users',
                'usergroups',
                'userpermissions',
                'userpermissions_usergroups',
                'userpermissions_users',
                'usergroups_users',
                'userpreferences',
                'authenticator',
                'recoverycodes',
                'tokens',
                'webauthn',
                'sessions',
                'queue',
            ],
            'allShortLinkAndSmartLinkTables' => true,
        ],
        'sessionEvidence' => 'Per-row identity, timestamps, token SHA-256, and whole-row SHA-256; no session token or payload is retained.',
        'ownedStateEvidence' => 'Queue IDs include job-payload and whole-row SHA-256; pending-sync and all three analytics families include exact IDs and whole-row SHA-256.',
        'protectedAnalyticsPrefixes' => ['__sm_pr1_debt9__', '__smcachetest_'],
        'protectedAnalyticsTables' => ['searchmanager_rule_analytics', 'searchmanager_promotion_analytics'],
        'filesystemRoots' => array_keys($paths),
        'processOwnerJournalRoot' => $processOwnerJournalRoot,
        'temporaryDirectory' => exactPath(sys_get_temp_dir()),
        'temporaryPatterns' => ['search-manager-*', 'sm-*'],
        'excludedEvidencePaths' => $reportedExcludedEvidencePaths,
    ],
    'database' => $tableFingerprints,
    'protectedAnalytics' => $protected,
    'filesystem' => $paths,
    'processOwnerJournal' => $processOwnerJournal,
    'temporaryPaths' => $temporaryPaths,
];
file_put_contents($outputPath, json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
chmod($outputPath, 0600);

echo json_encode([
    'output' => $outputPath,
    'tables' => count($tableFingerprints),
    'filesystemRoots' => count($paths),
    'temporaryRoots' => count($temporaryPaths),
    'sha256' => hash('sha256', stableJson($fingerprint)),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @param mixed $value */
function stableJson($value): string
{
    $normalise = static function(mixed $item) use (&$normalise): mixed {
        if (is_array($item)) {
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        }
        if (is_string($item) && !mb_check_encoding($item, 'UTF-8')) {
            return ['__base64' => base64_encode($item)];
        }

        return $item;
    };

    return json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function exactPath(string $path): string
{
    $resolved = realpath($path);
    if ($resolved !== false) {
        return $resolved;
    }

    $directory = realpath(dirname($path));
    if ($directory === false) {
        throw new RuntimeException("Fingerprint path directory does not exist: {$path}");
    }

    return $directory . DIRECTORY_SEPARATOR . basename($path);
}

/** @return array{files: int, directories: int, bytes: int, sha256: string} */
function fingerprintPath(string $root): array
{
    $entries = [];
    if (is_dir($root) && !is_link($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $entries[] = $item->getPathname();
        }
    } elseif (file_exists($root) || is_link($root)) {
        $entries[] = $root;
    }
    sort($entries, SORT_STRING);

    $hash = hash_init('sha256');
    $files = 0;
    $directories = 0;
    $bytes = 0;
    foreach ($entries as $path) {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        $mode = substr(sprintf('%o', fileperms($path) ?: 0), -4);
        if (is_link($path)) {
            hash_update($hash, "L\0{$relative}\0{$mode}\0" . (string)readlink($path) . "\n");
        } elseif (is_dir($path)) {
            $directories++;
            hash_update($hash, "D\0{$relative}\0{$mode}\n");
        } else {
            $files++;
            $size = filesize($path) ?: 0;
            $bytes += $size;
            hash_update($hash, "F\0{$relative}\0{$mode}\0{$size}\0" . hash_file('sha256', $path) . "\n");
        }
    }

    return [
        'files' => $files,
        'directories' => $directories,
        'bytes' => $bytes,
        'sha256' => hash_final($hash),
    ];
}
