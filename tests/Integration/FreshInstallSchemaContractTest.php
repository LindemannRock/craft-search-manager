<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Composer\Autoload\ClassLoader;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the fresh-install MySQL schema and application-owned field default.
 *
 * @since 5.54.0
 */
final class FreshInstallSchemaContractTest extends TestCase
{
    private const DATABASE_PREFIX = 'sm_pr192_';
    private const PROJECT_PREFIX = 'search-manager-pr192-';
    private const PACKAGE_ROOT_ENV = 'SEARCH_MANAGER_FRESH_INSTALL_PACKAGE_ROOT';
    private const PLUGIN_HANDLES = [
        'logging-library',
        'ckeditor',
        'commerce',
        'code-highlighter',
        'docs-manager',
        'search-manager',
    ];

    private string $runId;
    private string $databaseName;
    private string $projectRoot;
    private bool $databaseCreated = false;
    private bool $projectCreated = false;

    /** @var list<array{command: list<string>, exitCode: int, stdout: string, stderr: string}> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->runId = bin2hex(random_bytes(8));
        $this->databaseName = self::DATABASE_PREFIX . $this->runId;
        $this->projectRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . self::PROJECT_PREFIX . $this->runId;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testRealInstallCreatesNoDefaultAndPersistsApplicationDefault(): void
    {
        $cleanup = null;
        $failure = null;

        try {
            $this->createDatabase();
            $this->createProject();

            $version = (string)$this->adminPdo()->query('SELECT VERSION()')->fetchColumn();
            self::assertStringStartsWith('8.0.40', $version);

            $this->installCraft();
            $this->installPlugins();

            $column = $this->columnDefinition();
            self::assertSame('text', $column['dataType']);
            self::assertSame('NO', $column['isNullable']);
            self::assertNull($column['columnDefault']);
            self::assertSame('JSON list of public custom field handles to return', $column['columnComment']);

            $createTable = $this->showCreateIndicesTable();
            $columnLine = $this->retrievableFieldsCreateLine($createTable);
            self::assertStringContainsString('`retrievableFields` text NOT NULL', $columnLine);
            self::assertStringContainsString("COMMENT 'JSON list of public custom field handles to return'", $columnLine);
            self::assertStringNotContainsString(' DEFAULT ', $columnLine);

            $application = $this->verifyApplicationDefault();
            self::assertSame(['*'], $application['modelDefault']);
            self::assertSame(['*'], $application['normalizedNull']);
            self::assertSame('["*"]', $application['stored']);
            self::assertSame(['*'], $application['hydrated']);
            self::assertSame('1.0.0', $application['schemaVersion']);
            self::assertSame('1.0.0', $application['storedSchemaVersion']);
            self::assertSame(self::PLUGIN_HANDLES, $application['installedPlugins']);

            foreach ($this->commands as $command) {
                self::assertSame(0, $command['exitCode'], implode(' ', $command['command']));
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        try {
            $cleanup = $this->cleanup();
        } catch (\Throwable $cleanupFailure) {
            if ($failure !== null) {
                throw new \RuntimeException(
                    'Fresh-install verification failed and cleanup also failed: '
                    . $failure->getMessage() . '; cleanup: ' . $cleanupFailure->getMessage(),
                    previous: $cleanupFailure,
                );
            }

            throw $cleanupFailure;
        }

        if ($failure !== null) {
            throw $failure;
        }

        self::assertSame([
            'databaseRemoved' => true,
            'projectRemoved' => true,
            'privilegesAbsent' => true,
        ], $cleanup);
    }

    public function testSetupFailureRemovesOnlyOwnedState(): void
    {
        $caught = null;

        try {
            $this->createDatabase();
            $this->createProject();
            throw new \RuntimeException('Synthetic setup failure.');
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $cleanup = $this->cleanup();
        }

        self::assertSame('Synthetic setup failure.', $caught->getMessage());
        self::assertSame([
            'databaseRemoved' => true,
            'projectRemoved' => true,
            'privilegesAbsent' => true,
        ], $cleanup);
    }

    public function testAssertionFailureRemovesOnlyOwnedState(): void
    {
        $caught = null;

        try {
            $this->createDatabase();
            $this->createProject();
            self::fail('Synthetic assertion failure.');
        } catch (AssertionFailedError $exception) {
            $caught = $exception;
        } finally {
            $cleanup = $this->cleanup();
        }

        self::assertSame('Synthetic assertion failure.', $caught->getMessage());
        self::assertSame([
            'databaseRemoved' => true,
            'projectRemoved' => true,
            'privilegesAbsent' => true,
        ], $cleanup);
    }

    public function testCleanupFailurePropagatesAfterOwnedStateIsRemoved(): void
    {
        $this->createDatabase();
        $this->createProject();

        $caught = null;
        try {
            $this->cleanup(true);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame('Synthetic cleanup failure.', $caught?->getMessage());
        self::assertFalse($this->databaseExists());
        self::assertSame(0, $this->databasePrivilegeCount());
        self::assertFileDoesNotExist($this->projectRoot);
    }

    private function createDatabase(): void
    {
        if (!preg_match('/^' . self::DATABASE_PREFIX . '[a-f0-9]{16}$/', $this->databaseName)) {
            throw new \LogicException('Refusing an invalid disposable database name.');
        }
        if (
            $this->databaseName === 'db'
            || $this->databaseExists()
            || $this->databasePrivilegeCount() !== 0
        ) {
            throw new \RuntimeException('Disposable database boundary is not fresh.');
        }

        $admin = $this->adminPdo();
        $admin->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->databaseCreated = true;
    }

    private function createProject(): void
    {
        if (file_exists($this->projectRoot)) {
            throw new \RuntimeException('Disposable project boundary is not fresh.');
        }
        if (!mkdir($this->projectRoot, 0700)) {
            throw new \RuntimeException('Unable to create the disposable project root.');
        }
        $this->projectCreated = true;

        foreach (['config', 'storage', 'templates', 'web'] as $relativePath) {
            $path = $this->projectRoot . DIRECTORY_SEPARATOR . $relativePath;
            if (!mkdir($path, 0700) && !is_dir($path)) {
                throw new \RuntimeException("Unable to create disposable path: {$path}");
            }
        }

        $vendorRoot = $this->vendorRoot();
        if (!symlink($vendorRoot, $this->projectRoot . '/vendor')) {
            throw new \RuntimeException('Unable to link the explicit dependency vendor root.');
        }

        $this->writeOwnedFile('bootstrap.php', <<<'PHP'
<?php
define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
$loader = require CRAFT_VENDOR_PATH . '/autoload.php';
$packageRoot = getenv('SEARCH_MANAGER_FRESH_INSTALL_PACKAGE_ROOT');
if (!is_string($packageRoot) || !is_dir($packageRoot . '/src')) {
    throw new RuntimeException('The isolated Search Manager package root is invalid.');
}
$loader->addClassMap(Composer\ClassMapGenerator\ClassMapGenerator::createMap($packageRoot . '/src'));
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
        if (!chmod($this->projectRoot . '/craft', 0700)) {
            throw new \RuntimeException('Unable to make the disposable Craft command executable.');
        }
        $this->writeOwnedFile('config/general.php', <<<'PHP'
<?php
use craft\config\GeneralConfig;
return GeneralConfig::create()
    ->allowAdminChanges(true)
    ->devMode(false)
    ->omitScriptNameInUrls();
PHP);
        $this->writeOwnedFile(
            'config/app.php',
            "<?php\nreturn ['id' => 'search-manager-pr192-{$this->runId}'];\n",
        );
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
        $this->writeOwnedFile('.env', implode("\n", [
            'CRAFT_APP_ID=search-manager-pr192-' . $this->runId,
            'CRAFT_ENVIRONMENT=test',
            'CRAFT_SECURITY_KEY=' . bin2hex(random_bytes(32)),
            'CRAFT_DB_DSN=mysql:host=' . $this->mysqlHost() . ';port=' . $this->mysqlPort() . ';dbname=' . $this->databaseName,
            'CRAFT_DB_USER=root',
            'CRAFT_DB_PASSWORD=root',
            'CRAFT_DB_SCHEMA=',
            'CRAFT_DB_TABLE_PREFIX=',
            'PRIMARY_SITE_URL=https://pr192.example.test',
            '',
        ]));
        $this->writeOwnedFile('verify.php', <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$index = new lindemannrock\searchmanager\models\SearchIndex();
$modelDefault = $index->retrievableFields;
$index->name = 'PR1.92 Omitted Field';
$index->handle = 'pr192-omitted-field';
$index->elementType = craft\elements\Entry::class;
$index->transformerClass = '';
$index->enabled = false;
$index->source = 'database';
if (!$index->save()) {
    throw new RuntimeException('Unable to save omitted-field index: ' . json_encode($index->getErrors()));
}

$stored = (new craft\db\Query())
    ->select(['retrievableFields'])
    ->from('{{%searchmanager_indices}}')
    ->where(['handle' => $index->handle])
    ->scalar();
$hydrated = lindemannrock\searchmanager\models\SearchIndex::findByHandle($index->handle);
$pluginRow = (new craft\db\Query())
    ->select(['schemaVersion'])
    ->from('{{%plugins}}')
    ->where(['handle' => 'search-manager'])
    ->one();
$installedPlugins = (new craft\db\Query())
    ->select(['handle'])
    ->from('{{%plugins}}')
    ->where(['handle' => ['logging-library', 'ckeditor', 'commerce', 'code-highlighter', 'docs-manager', 'search-manager']])
    ->orderBy(['id' => SORT_ASC])
    ->column();

echo json_encode([
    'modelDefault' => $modelDefault,
    'normalizedNull' => lindemannrock\searchmanager\models\SearchIndex::normalizeRetrievableFields(null),
    'stored' => $stored,
    'hydrated' => $hydrated?->retrievableFields,
    'schemaVersion' => lindemannrock\searchmanager\SearchManager::$plugin->schemaVersion,
    'storedSchemaVersion' => $pluginRow['schemaVersion'] ?? null,
    'installedPlugins' => $installedPlugins,
], JSON_THROW_ON_ERROR);
PHP);
    }

    private function installCraft(): void
    {
        $this->runCommand([
            PHP_BINARY,
            $this->projectRoot . '/craft',
            'install',
            '--interactive=0',
            '--silent-exit-on-exception=0',
            '--site-name=Search Manager PR1.92',
            '--site-url=https://pr192.example.test',
            '--language=en-US',
            '--username=pr192-admin',
            '--email=pr192-admin@example.test',
            '--password=PR1.92-Fresh-Install-2026!',
        ]);
    }

    private function installPlugins(): void
    {
        foreach (self::PLUGIN_HANDLES as $handle) {
            $this->runCommand([
                PHP_BINARY,
                $this->projectRoot . '/craft',
                'plugin/install',
                $handle,
                '--interactive=0',
                '--silent-exit-on-exception=0',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function verifyApplicationDefault(): array
    {
        $result = $this->runCommand([
            PHP_BINARY,
            $this->projectRoot . '/verify.php',
        ]);
        $decoded = json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The application verification result was not an array.');
        }

        return $decoded;
    }

    /** @return array{dataType: string, isNullable: string, columnDefault: mixed, columnComment: string} */
    private function columnDefinition(): array
    {
        $statement = $this->adminPdo()->prepare(<<<'SQL'
SELECT
    DATA_TYPE AS dataType,
    IS_NULLABLE AS isNullable,
    COLUMN_DEFAULT AS columnDefault,
    COLUMN_COMMENT AS columnComment
FROM information_schema.columns
WHERE table_schema = :database
  AND table_name = 'searchmanager_indices'
  AND column_name = 'retrievableFields'
SQL);
        $statement->execute(['database' => $this->databaseName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('The retrievableFields column was not created.');
        }

        return $row;
    }

    private function showCreateIndicesTable(): string
    {
        $statement = $this->adminPdo()->query(
            'SHOW CREATE TABLE `' . $this->databaseName . '`.`searchmanager_indices`',
        );
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !isset($row[1]) || !is_string($row[1])) {
            throw new \RuntimeException('SHOW CREATE TABLE did not return the Search Manager schema.');
        }

        return $row[1];
    }

    private function retrievableFieldsCreateLine(string $createTable): string
    {
        foreach (explode("\n", $createTable) as $line) {
            if (str_contains($line, '`retrievableFields`')) {
                return trim($line);
            }
        }

        throw new \RuntimeException('SHOW CREATE TABLE omitted retrievableFields.');
    }

    /** @param list<string> $command */
    private function runCommand(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->projectRoot, $this->subprocessEnvironment());
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
        return [
            'PATH' => is_string($_SERVER['PATH'] ?? null) ? $_SERVER['PATH'] : '/usr/local/bin:/usr/bin:/bin',
            'LANG' => is_string($_SERVER['LANG'] ?? null) && $_SERVER['LANG'] !== '' ? $_SERVER['LANG'] : 'C.UTF-8',
            self::PACKAGE_ROOT_ENV => dirname(__DIR__, 2),
            'XDEBUG_MODE' => 'off',
        ];
    }

    private function adminPdo(): PDO
    {
        return new PDO(
            'mysql:host=' . $this->mysqlHost() . ';port=' . $this->mysqlPort() . ';charset=utf8mb4',
            'root',
            'root',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function mysqlHost(): string
    {
        $host = $_SERVER['SEARCH_MANAGER_FRESH_INSTALL_MYSQL_HOST'] ?? 'db';

        return is_string($host) && $host !== '' ? $host : 'db';
    }

    private function mysqlPort(): int
    {
        $port = $_SERVER['SEARCH_MANAGER_FRESH_INSTALL_MYSQL_PORT'] ?? 3306;

        return is_numeric($port) ? (int)$port : 3306;
    }

    private function vendorRoot(): string
    {
        $classMapFile = (new \ReflectionClass(ClassLoader::class))->getFileName();
        if (!is_string($classMapFile)) {
            throw new \RuntimeException('Unable to resolve the Composer vendor root.');
        }
        $vendorRoot = realpath(dirname($classMapFile, 2));
        if ($vendorRoot === false || !is_file($vendorRoot . '/autoload.php')) {
            throw new \RuntimeException('The Composer vendor root is invalid.');
        }

        return $vendorRoot;
    }

    private function databaseExists(): bool
    {
        $statement = $this->adminPdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name',
        );
        $statement->execute(['name' => $this->databaseName]);

        return (int)$statement->fetchColumn() === 1;
    }

    private function databasePrivilegeCount(): int
    {
        $statement = $this->adminPdo()->prepare(
            'SELECT COUNT(*) FROM mysql.db WHERE BINARY Db = :database',
        );
        $statement->execute(['database' => $this->databaseName]);

        return (int)$statement->fetchColumn();
    }

    /** @return array{databaseRemoved: bool, projectRemoved: bool, privilegesAbsent: bool} */
    private function cleanup(bool $injectFailure = false): array
    {
        $errors = [];

        if ($this->databaseCreated) {
            try {
                $this->adminPdo()->exec('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'database: ' . $exception->getMessage();
            }
        }

        if ($this->projectCreated || file_exists($this->projectRoot)) {
            try {
                $this->removeOwnedProjectRoot();
                $this->projectCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'filesystem: ' . $exception->getMessage();
            }
        }

        try {
            $privilegeCount = $this->databasePrivilegeCount();
            if ($privilegeCount !== 0) {
                $errors[] = "privileges: {$privilegeCount} database privilege row(s) remain";
            }
        } catch (\Throwable $exception) {
            $errors[] = 'privileges: ' . $exception->getMessage();
        }

        if ($injectFailure) {
            $errors[] = 'Synthetic cleanup failure.';
        }
        if ($errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }

        return [
            'databaseRemoved' => !$this->databaseExists(),
            'projectRemoved' => !file_exists($this->projectRoot),
            'privilegesAbsent' => $this->databasePrivilegeCount() === 0,
        ];
    }

    private function removeOwnedProjectRoot(): void
    {
        $temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if (!preg_match(
            '#^' . preg_quote($temporaryRoot, '#') . '/' . self::PROJECT_PREFIX . '[a-f0-9]{16}$#',
            $this->projectRoot,
        )) {
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
}
