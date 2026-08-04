<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Support;

use PDO;

/**
 * Owns one disposable MySQL Craft project from creation through exact cleanup.
 *
 * @since 5.54.0
 */
final class DisposableCraftProject
{
    public const SOURCE_VENDOR_ENV = 'SEARCH_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';
    public const FAILURE_STAGE_ENV = 'SEARCH_MANAGER_FIXTURE_FAIL_STAGE';

    private const DATABASE_PREFIX = 'sm_a12_3p_';
    private const PLUGIN_HANDLES = [
        'logging-library',
        'ckeditor',
        'commerce',
        'code-highlighter',
        'docs-manager',
        'search-manager',
    ];

    private string $runId;
    private string $projectRoot;
    private string $databaseName;
    private string $vendorRoot;
    private bool $databaseCreated = false;
    private bool $grantCreated = false;
    private bool $projectCreated = false;

    /** @var list<array{command: list<string>, exitCode: int, stdout: string, stderr: string}> */
    private array $commands = [];

    public function __construct(private readonly string $packageRoot)
    {
        $this->runId = bin2hex(random_bytes(8));
        $this->projectRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'search-manager-fixture-' . $this->runId;
        $this->databaseName = self::DATABASE_PREFIX . $this->runId;
        $this->vendorRoot = $this->resolveVendorRoot();
    }

    /** @return array<string, mixed> */
    public function run(array $phpunitArguments = []): array
    {
        $originalFailure = null;
        $result = null;

        try {
            $this->createDatabase();
            $this->createProject();
            $this->installCraft();
            $this->installPlugins();
            $this->seedFixture();
            $result = $this->runPhpunit($phpunitArguments);
        } catch (\Throwable $exception) {
            $originalFailure = $exception;
        }

        try {
            $cleanup = $this->cleanup();
        } catch (\Throwable $cleanupFailure) {
            if ($originalFailure !== null) {
                throw new \RuntimeException(
                    'Disposable project failed and cleanup also failed: '
                    . $originalFailure->getMessage() . '; cleanup: ' . $cleanupFailure->getMessage(),
                    previous: $cleanupFailure,
                );
            }

            throw $cleanupFailure;
        }

        if ($originalFailure !== null) {
            throw new \RuntimeException(
                $originalFailure->getMessage() . "\nDisposable command evidence:\n"
                . json_encode($this->commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                previous: $originalFailure,
            );
        }

        return [
            'runId' => $this->runId,
            'projectRoot' => $this->projectRoot,
            'databaseName' => $this->databaseName,
            'fixtureManifestHash' => DeterministicFixtureManifest::hash(),
            'phpunit' => $result,
            'commands' => $this->commands,
            'cleanup' => $cleanup,
            'ownerBoundary' => [
                'databaseNameRejected' => 'db',
                'ownerProjectStorageRead' => false,
                'ownerProjectTemplatesRead' => false,
                'sourceVendorRoot' => $this->vendorRoot,
            ],
        ];
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool} */
    public function cleanup(): array
    {
        $failStage = $_SERVER[self::FAILURE_STAGE_ENV] ?? null;
        $errors = [];

        if ($this->grantCreated) {
            try {
                $this->adminPdo()->exec(
                    "REVOKE ALL PRIVILEGES ON `{$this->databaseName}`.* FROM 'db'@'%'",
                );
                $this->grantCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'grant: ' . $exception->getMessage();
            }
        }

        if ($this->databaseCreated) {
            try {
                $this->adminPdo()->exec('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'database: ' . $exception->getMessage();
            }
        }

        if ($this->projectCreated || is_dir($this->projectRoot)) {
            try {
                $this->removeOwnedProjectRoot();
                $this->projectCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'filesystem: ' . $exception->getMessage();
            }
        }

        $grantRemoved = false;
        try {
            $grantRemoved = !$this->grantExists();
            if (!$grantRemoved) {
                $errors[] = "grant: exact run-owned grant {$this->databaseName} remains";
            }
        } catch (\Throwable $exception) {
            $errors[] = 'grant verification: ' . $exception->getMessage();
        }

        if ($failStage === 'cleanup') {
            $errors[] = 'synthetic cleanup failure';
        }

        if ($errors !== []) {
            throw new \RuntimeException('Disposable cleanup failed: ' . implode('; ', $errors));
        }

        return [
            'projectRemoved' => !file_exists($this->projectRoot),
            'databaseRemoved' => !$this->databaseExists(),
            'grantRemoved' => $grantRemoved,
        ];
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    private function createDatabase(): void
    {
        $this->injectFailure('database');
        if (!preg_match('/^' . self::DATABASE_PREFIX . '[a-f0-9]{16}$/', $this->databaseName)) {
            throw new \LogicException('Refusing an invalid disposable database name.');
        }
        if ($this->databaseName === 'db' || $this->databaseExists() || $this->grantExists()) {
            throw new \RuntimeException('Disposable database boundary is not fresh.');
        }

        $admin = $this->adminPdo();
        $admin->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->databaseCreated = true;
        $admin->exec("GRANT ALL PRIVILEGES ON `{$this->databaseName}`.* TO 'db'@'%'");
        $this->grantCreated = true;
    }

    private function createProject(): void
    {
        $this->injectFailure('project');
        if (file_exists($this->projectRoot)) {
            throw new \RuntimeException('Disposable project root already exists.');
        }

        foreach (['config', 'storage', 'templates', 'web'] as $relative) {
            $path = $this->projectRoot . DIRECTORY_SEPARATOR . $relative;
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("Unable to create disposable path: {$path}");
            }
        }
        $this->projectCreated = true;

        $vendorLink = $this->projectRoot . '/vendor';
        if (!symlink($this->vendorRoot, $vendorLink)) {
            throw new \RuntimeException('Unable to link the explicit fixture vendor root.');
        }

        $this->writeOwnedFile('bootstrap.php', <<<'PHP'
<?php
define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
require_once CRAFT_VENDOR_PATH . '/autoload.php';
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
}
PHP);
        $this->writeOwnedFile('craft', <<<'PHP'
#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
exit($app->run());
PHP);
        chmod($this->projectRoot . '/craft', 0700);
        $this->writeOwnedFile('config/general.php', <<<'PHP'
<?php
use craft\config\GeneralConfig;
return GeneralConfig::create()
    ->allowAdminChanges(true)
    ->devMode(false)
    ->omitScriptNameInUrls();
PHP);
        $this->writeOwnedFile('config/app.php', "<?php\nreturn [\n"
            . "    'id' => 'search-manager-fixture-{$this->runId}',\n"
            . "    'aliases' => [\n"
            . "        '@root' => dirname(__DIR__),\n"
            . "        '@webroot' => dirname(__DIR__) . '/web',\n"
            . "        '@web' => '/',\n"
            . "    ],\n"
            . "];\n");
        $this->writeOwnedFile('config/db.php', <<<'PHP'
<?php
use craft\helpers\App;
return [
    'dsn' => App::env('CRAFT_DB_DSN'),
    'user' => App::env('CRAFT_DB_USER'),
    'password' => App::env('CRAFT_DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_0900_ai_ci',
    'schema' => App::env('CRAFT_DB_SCHEMA'),
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX'),
];
PHP);
        $this->writeSearchManagerConfig();
        $this->writeOwnedFile('.env', implode("\n", [
            'CRAFT_APP_ID=search-manager-fixture-' . $this->runId,
            'CRAFT_ENVIRONMENT=test',
            'CRAFT_EDITION=pro',
            'CRAFT_SECURITY_KEY=' . bin2hex(random_bytes(32)),
            'CRAFT_DB_DSN=mysql:host=db;port=3306;dbname=' . $this->databaseName,
            'CRAFT_DB_USER=db',
            'CRAFT_DB_PASSWORD=db',
            'CRAFT_DB_SCHEMA=',
            'CRAFT_DB_TABLE_PREFIX=',
            'PRIMARY_SITE_URL=https://fixture-primary.example.test',
            '',
        ]));

        $this->copyDirectory(
            $this->packageRoot . '/tests/Fixtures/Project/templates',
            $this->projectRoot . '/templates',
        );
    }

    private function installCraft(): void
    {
        $this->injectFailure('install');
        $this->runCommand([
            PHP_BINARY,
            $this->projectRoot . '/craft',
            'install',
            '--interactive=0',
            '--silent-exit-on-exception=0',
            '--site-name=Search Manager Fixture',
            '--site-url=https://fixture-primary.example.test',
            '--language=en-US',
            '--username=fixture-admin',
            '--email=fixture-admin@example.test',
            '--password=Fixture-A12-3P-Password-2026!',
        ], $this->projectRoot);
    }

    private function installPlugins(): void
    {
        $this->injectFailure('plugins');
        foreach (self::PLUGIN_HANDLES as $handle) {
            $this->runCommand([
                PHP_BINARY,
                $this->projectRoot . '/craft',
                'plugin/install',
                $handle,
                '--interactive=0',
                '--silent-exit-on-exception=0',
            ], $this->projectRoot);
        }
    }

    private function seedFixture(): void
    {
        $this->injectFailure('seed');
        $this->runCommand([
            PHP_BINARY,
            $this->packageRoot . '/tests/Fixtures/Project/seed.php',
        ], $this->packageRoot);
        $this->runCommand([
            PHP_BINARY,
            $this->packageRoot . '/tests/Fixtures/Project/seed.php',
        ], $this->packageRoot);
    }

    /** @param list<string> $phpunitArguments */
    private function runPhpunit(array $phpunitArguments): array
    {
        $this->injectFailure('phpunit');
        $command = [
            PHP_BINARY,
            $this->vendorRoot . '/bin/phpunit',
            '--configuration',
            $this->packageRoot . '/phpunit.xml.dist',
            '--colors=never',
            ...$phpunitArguments,
        ];

        return $this->runCommand($command, $this->packageRoot);
    }

    /**
     * @param list<string> $command
     * @return array{command: list<string>, exitCode: int, stdout: string, stderr: string}
     */
    private function runCommand(array $command, string $workingDirectory): array
    {
        $environment = $this->subprocessEnvironment();
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start disposable command.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = [
            'command' => $command,
            'exitCode' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
        $this->commands[] = $result;
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Disposable command failed (' . $exitCode . '): ' . implode(' ', $command)
                . "\n" . $result['stdout'] . "\n" . $result['stderr'],
            );
        }

        return $result;
    }

    /** @return array<string, string> */
    private function subprocessEnvironment(): array
    {
        $environment = [
            'PATH' => is_string($_SERVER['PATH'] ?? null) ? $_SERVER['PATH'] : '/usr/local/bin:/usr/bin:/bin',
            'LANG' => is_string($_SERVER['LANG'] ?? null) && $_SERVER['LANG'] !== '' ? $_SERVER['LANG'] : 'C.UTF-8',
            TestProjectBoundary::PROJECT_ROOT_ENV => $this->projectRoot,
            TestProjectBoundary::DISPOSABLE_ENV => '1',
            self::SOURCE_VENDOR_ENV => $this->vendorRoot,
        ];
        foreach (['XDEBUG_MODE', 'PHP_IDE_CONFIG'] as $name) {
            $value = $_SERVER[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function adminPdo(): PDO
    {
        return new PDO('mysql:host=db;port=3306;charset=utf8mb4', 'root', 'root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function databaseExists(): bool
    {
        $statement = $this->adminPdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name',
        );
        $statement->execute(['name' => $this->databaseName]);

        return (int)$statement->fetchColumn() === 1;
    }

    private function grantExists(): bool
    {
        $statement = $this->adminPdo()->prepare(
            "SELECT COUNT(*) FROM mysql.db WHERE Host = '%' AND Db = :name AND User = 'db'",
        );
        $statement->execute(['name' => $this->databaseName]);

        return (int)$statement->fetchColumn() === 1;
    }

    private function resolveVendorRoot(): string
    {
        $configured = $_SERVER[self::SOURCE_VENDOR_ENV] ?? null;
        if (!is_string($configured) || $configured === '' || $configured[0] !== DIRECTORY_SEPARATOR) {
            throw new \InvalidArgumentException(self::SOURCE_VENDOR_ENV . ' must name the explicit absolute dependency vendor root.');
        }
        $resolved = realpath($configured);
        if ($resolved === false || !is_dir($resolved) || !is_file($resolved . '/autoload.php')) {
            throw new \RuntimeException('The explicit fixture vendor root is not valid.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function writeOwnedFile(string $relativePath, string $contents): void
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . $relativePath;
        if (!str_starts_with($path, $this->projectRoot . DIRECTORY_SEPARATOR)) {
            throw new \LogicException('Refusing to write outside the disposable project root.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write disposable project file: {$path}");
        }
    }

    private function writeSearchManagerConfig(): void
    {
        $manifest = DeterministicFixtureManifest::load();
        $fixtureConfig = $manifest['searchManagerConfig'] ?? null;
        if (!is_array($fixtureConfig) || array_is_list($fixtureConfig)) {
            throw new \RuntimeException('Fixture Search Manager configuration must be a map.');
        }
        $indexPrefix = $fixtureConfig['indexPrefix'] ?? null;
        $manifestIndices = $fixtureConfig['indices'] ?? null;
        if (!is_string($indexPrefix) || $indexPrefix === '' || !is_array($manifestIndices)) {
            throw new \RuntimeException('Fixture Search Manager configuration is incomplete.');
        }

        $indices = [];
        foreach ($manifestIndices as $definition) {
            if (!is_array($definition)) {
                throw new \RuntimeException('Fixture config index definition must be a map.');
            }
            $handle = $definition['handle'] ?? null;
            if (!is_string($handle) || $handle === '') {
                throw new \RuntimeException('Fixture config index handle must be a non-empty string.');
            }
            unset($definition['handle']);
            $indices[$handle] = $definition;
        }

        $config = ['*' => [
            'indexPrefix' => $indexPrefix,
            'indices' => $indices,
        ]];
        $this->writeOwnedFile(
            'config/search-manager.php',
            "<?php\nreturn " . var_export($config, true) . ";\n",
        );
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $sourceRoot = realpath($source);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException("Fixture source directory does not exist: {$source}");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                    throw new \RuntimeException("Unable to create fixture directory: {$target}");
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException("Unable to copy fixture file: {$target}");
            }
        }
    }

    private function removeOwnedProjectRoot(): void
    {
        if (!preg_match('#^' . preg_quote(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), '#') . '/search-manager-fixture-[a-f0-9]{16}$#', $this->projectRoot)) {
            throw new \LogicException('Refusing cleanup outside the exact disposable project boundary.');
        }
        if (!file_exists($this->projectRoot)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($item->getPathname())) {
                    throw new \RuntimeException('Unable to remove owned fixture file: ' . $item->getPathname());
                }
            } elseif (!rmdir($item->getPathname())) {
                throw new \RuntimeException('Unable to remove owned fixture directory: ' . $item->getPathname());
            }
        }
        if (!rmdir($this->projectRoot)) {
            throw new \RuntimeException('Unable to remove the disposable project root.');
        }
    }

    private function injectFailure(string $stage): void
    {
        if (($_SERVER[self::FAILURE_STAGE_ENV] ?? null) === $stage) {
            throw new \RuntimeException("Synthetic disposable fixture {$stage} failure.");
        }
    }
}
