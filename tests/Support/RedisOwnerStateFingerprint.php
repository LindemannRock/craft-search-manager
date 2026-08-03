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
    fwrite(STDERR, "Usage: php RedisOwnerStateFingerprint.php OUTPUT.json\n");
    exit(2);
}

$redis = new Redis();
if (!$redis->connect('redis', 6379, 2.0)) {
    throw new RuntimeException('Unable to connect to the DDEV Redis service.');
}

$databases = [0, 1];
$cache = Craft::$app->getCache();
if (property_exists($cache, 'redis')) {
    try {
        $connection = Craft::$app->get($cache->redis);
        if (is_object($connection) && property_exists($connection, 'database')) {
            $cacheDatabase = (int)$connection->database;
            $databases[] = $cacheDatabase;
            $databases[] = $cacheDatabase + 1;
        }
    } catch (Throwable) {
        // Database 0 and its Search Manager storage sibling remain covered.
    }
}
foreach ((new Query())->select(['settings'])->from('{{%searchmanager_backends}}')->where(['backendType' => 'redis'])->column() as $settingsJson) {
    $settings = json_decode((string)$settingsJson, true);
    $database = is_array($settings) ? ($settings['database'] ?? null) : null;
    if (is_numeric($database) && (string)$database !== '') {
        $databases[] = (int)$database;
    }
}
$databases = array_values(array_unique($databases));
sort($databases, SORT_NUMERIC);

$fingerprints = [];
foreach ($databases as $database) {
    if (!$redis->select($database)) {
        throw new RuntimeException("Unable to select Redis database {$database}.");
    }

    $keys = [];
    $iterator = null;
    do {
        $batch = $redis->scan($iterator, 'searchmanager*', 500);
        if (is_array($batch)) {
            $keys = array_merge($keys, $batch);
        }
    } while ($iterator !== 0);
    $keys = array_values(array_unique(array_map('strval', $keys)));
    foreach ($keys as $key) {
        if ($redis->type($key) === Redis::REDIS_SET) {
            $keys = array_merge($keys, array_map('strval', $redis->sMembers($key)));
        }
    }
    $keys = array_values(array_unique($keys));
    sort($keys, SORT_STRING);

    foreach ($keys as $key) {
        $type = $redis->type($key);
        $value = match ($type) {
            Redis::REDIS_STRING => $redis->get($key),
            Redis::REDIS_SET => sorted($redis->sMembers($key)),
            Redis::REDIS_LIST => $redis->lRange($key, 0, -1),
            Redis::REDIS_ZSET => $redis->zRange($key, 0, -1, true),
            Redis::REDIS_HASH => sortedHash($redis->hGetAll($key)),
            default => null,
        };
        $fingerprints[(string)$database][$key] = [
            'type' => $type,
            'sha256' => hash('sha256', serialize($value)),
        ];
    }
}

$result = [
    'capturedAt' => gmdate(DATE_ATOM),
    'manifest' => [
        'host' => 'redis',
        'port' => 6379,
        'databases' => $databases,
        'scanPattern' => 'searchmanager*',
        'setMemberKeysExpanded' => true,
    ],
    'keys' => $fingerprints,
];
file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
chmod($outputPath, 0600);

echo json_encode([
    'output' => $outputPath,
    'keys' => array_sum(array_map('count', $fingerprints)),
    'sha256' => hash('sha256', serialize($fingerprints)),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @param array<int, mixed> $values
 * @return array<int, mixed>
 */
function sorted(array $values): array
{
    sort($values, SORT_STRING);

    return $values;
}

/** @param array<string, mixed> $values
 * @return array<string, mixed>
 */
function sortedHash(array $values): array
{
    ksort($values, SORT_STRING);

    return $values;
}
