<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$vendorCandidates = [
    $packageRoot . '/vendor',
    dirname($packageRoot, 2) . '/vendor',
];
$vendorRoot = null;
foreach ($vendorCandidates as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved !== false && is_file($resolved . '/autoload.php') && is_file($resolved . '/bin/phpstan')) {
        $vendorRoot = $resolved;
        break;
    }
}
if ($vendorRoot === null) {
    fwrite(STDERR, "PHPStan is unavailable. Run composer install before committing.\n");
    exit(2);
}

$probePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'search-manager-quality-platform-' . bin2hex(random_bytes(8)) . '.php';
$probeSource = <<<'PHP'
<?php
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(stdClass::class)]
#[CoversClass(RuntimeException::class)]
final class SearchManagerRepeatableAttributeProbe
{
}
PHP;

try {
    if (file_put_contents($probePath, $probeSource) === false) {
        throw new RuntimeException('Unable to create the temporary quality-platform probe.');
    }

    $command = [
        PHP_BINARY,
        $vendorRoot . '/bin/phpstan',
        'analyse',
        '--no-progress',
        '--no-ansi',
        '--error-format=raw',
        '--level=5',
        '--autoload-file=' . $vendorRoot . '/autoload.php',
        $probePath,
    ];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $packageRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the quality-platform probe.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        fwrite(STDERR, "The installed PHPStan/PHPUnit toolchain rejected a repeatable-attribute compatibility probe.\n");
        if (is_string($stdout) && $stdout !== '') {
            fwrite(STDERR, $stdout);
        }
        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        exit($status);
    }

    fwrite(STDOUT, 'Standalone quality-platform probe passed under PHP ' . PHP_VERSION . ".\n");
} finally {
    if (is_file($probePath) && !unlink($probePath)) {
        fwrite(STDERR, "Unable to remove the temporary quality-platform probe.\n");
        exit(1);
    }
}
