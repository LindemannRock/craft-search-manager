<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\console\controllers\SecurityController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\console\ExitCode;

/**
 * Covers safe `.env` replacement for the salt generator.
 *
 * @since 5.54.0
 */
final class SecurityGenerateSaltTest extends TestCase
{
    private ?string $fixtureDirectory = null;

    public function testMissingEnvPrintsManualAssignmentAndSucceedsWithoutCreatingTemporaryFile(): void
    {
        $controller = new RecordingSecurityController('security', SearchManager::$plugin);
        $controller->files = [];

        $exitCode = $controller->actionGenerateSalt();

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertStringContainsString('Warning: .env file not found', $controller->output);
        $this->assertManualAssignment($controller->output);
        self::assertSame([], $controller->calls);
    }

    public function testReadableEnvWithoutSaltIsReplacedAtomicallyAndPreservesMode(): void
    {
        $directory = $this->createFixtureDirectory();
        $envPath = $directory . DIRECTORY_SEPARATOR . '.env';
        $original = "APP_ENV=production";
        file_put_contents($envPath, $original);
        chmod($envPath, 0640);

        $controller = new NativeFixtureSecurityController('security', SearchManager::$plugin, $envPath);
        $exitCode = $controller->actionGenerateSalt();

        clearstatcache(true, $envPath);
        $updated = file_get_contents($envPath);

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertIsString($updated);
        self::assertStringStartsWith($original . "\n\n# Search Manager IP Hash Salt (generated ", $updated);
        self::assertMatchesRegularExpression('/SEARCH_MANAGER_IP_SALT="[0-9a-f]{64}"\n$/', $updated);
        self::assertSame(0640, fileperms($envPath) & 0777);
        self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '.*.tmp-*') ?: []);
        self::assertStringContainsString('✓ Added SEARCH_MANAGER_IP_SALT in .env file', $controller->output);
        $this->assertSecurityGuidance($controller->output);
    }

    public function testReadableExistingSaltRequiresConfirmationThenReplacesAtomically(): void
    {
        $directory = $this->createFixtureDirectory();
        $envPath = $directory . DIRECTORY_SEPARATOR . '.env';
        $original = "APP_ENV=production\nSEARCH_MANAGER_IP_SALT=\"old\"\nOTHER=value\n";
        file_put_contents($envPath, $original);
        chmod($envPath, 0600);

        $controller = new NativeFixtureSecurityController('security', SearchManager::$plugin, $envPath);
        $controller->confirmResult = true;

        $exitCode = $controller->actionGenerateSalt();
        $updated = file_get_contents($envPath);

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertSame(1, $controller->confirmationCount);
        self::assertIsString($updated);
        self::assertStringContainsString("APP_ENV=production\n", $updated);
        self::assertStringContainsString("\nOTHER=value\n", $updated);
        self::assertStringNotContainsString('SEARCH_MANAGER_IP_SALT="old"', $updated);
        self::assertMatchesRegularExpression('/SEARCH_MANAGER_IP_SALT="[0-9a-f]{64}"/', $updated);
        self::assertSame(0600, fileperms($envPath) & 0777);
        self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '.*.tmp-*') ?: []);
        self::assertStringContainsString('✓ Updated SEARCH_MANAGER_IP_SALT in .env file', $controller->output);
        $this->assertSecurityGuidance($controller->output);
    }

    public function testCancellationLeavesReadableExistingSaltUntouchedWithoutWriteAttempt(): void
    {
        $original = "SEARCH_MANAGER_IP_SALT=\"old\"\n";
        $controller = $this->recordingController($original);
        $controller->confirmResult = false;

        $exitCode = $controller->actionGenerateSalt();

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertSame(1, $controller->confirmationCount);
        self::assertSame($original, $controller->files[RecordingSecurityController::ENV_PATH]);
        self::assertSame([], $controller->temporaryFiles());
        self::assertNotContains('create-temp', $controller->calls);
        self::assertStringContainsString('Operation cancelled. Existing salt unchanged.', $controller->output);
    }

    public function testReadFailurePreservesOriginalAndPrintsManualAssignment(): void
    {
        $this->assertPreWriteFailure('read');
    }

    public function testMatchFailurePreservesOriginalAndPrintsManualAssignment(): void
    {
        $this->assertPreWriteFailure('match');
    }

    public function testReplacementFailureAfterConfirmationPreservesOriginalAndPrintsManualAssignment(): void
    {
        $original = "SEARCH_MANAGER_IP_SALT=\"old\"\n";
        $controller = $this->recordingController($original);
        $controller->failure = 'replace';

        $exitCode = $controller->actionGenerateSalt();

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $exitCode);
        self::assertSame(1, $controller->confirmationCount);
        self::assertSame($original, $controller->files[RecordingSecurityController::ENV_PATH]);
        self::assertSame([], $controller->temporaryFiles());
        self::assertNotContains('create-temp', $controller->calls);
        $this->assertManualAssignment($controller->output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function atomicFailureProvider(): iterable
    {
        yield 'temporary creation' => ['create'];
        yield 'wrong-directory temporary creation' => ['wrong-directory'];
        yield 'temporary open' => ['open'];
        yield 'temporary write' => ['write'];
        yield 'short temporary write' => ['short-write'];
        yield 'temporary flush' => ['flush'];
        yield 'temporary close' => ['close'];
        yield 'temporary verification read' => ['verify-read'];
        yield 'temporary verification mismatch' => ['verify-mismatch'];
        yield 'mode preservation' => ['chmod'];
        yield 'mode verification' => ['mode-verify'];
        yield 'atomic rename' => ['rename'];
    }

    #[DataProvider('atomicFailureProvider')]
    public function testAtomicFailurePreservesOriginalCleansTemporaryFileAndPrintsManualAssignment(string $failure): void
    {
        $original = "APP_ENV=production\n";
        $controller = $this->recordingController($original);
        $controller->failure = $failure;

        $exitCode = $controller->actionGenerateSalt();

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $exitCode);
        self::assertSame($original, $controller->files[RecordingSecurityController::ENV_PATH]);
        self::assertSame([], $controller->temporaryFiles());
        self::assertSame([], $controller->writesToEnvPath);
        $this->assertManualAssignment($controller->output);
        self::assertStringNotContainsString('✓ Added SEARCH_MANAGER_IP_SALT', $controller->output);
    }

    public function testUnavailableExistingModeStillAllowsVerifiedAtomicReplacement(): void
    {
        $original = "APP_ENV=production\n";
        $controller = $this->recordingController($original);
        $controller->modeAvailable = false;

        $exitCode = $controller->actionGenerateSalt();

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertStringStartsWith($original . "\n# Search Manager IP Hash Salt", $controller->files[RecordingSecurityController::ENV_PATH]);
        self::assertMatchesRegularExpression(
            '/SEARCH_MANAGER_IP_SALT="[0-9a-f]{64}"\n$/',
            $controller->files[RecordingSecurityController::ENV_PATH],
        );
        self::assertSame([], $controller->temporaryFiles());
        self::assertSame([], $controller->writesToEnvPath);
        self::assertContains('rename', $controller->calls);
    }

    private function assertPreWriteFailure(string $failure): void
    {
        $original = "APP_ENV=production\n";
        $controller = $this->recordingController($original);
        $controller->failure = $failure;

        $exitCode = $controller->actionGenerateSalt();

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $exitCode);
        self::assertSame($original, $controller->files[RecordingSecurityController::ENV_PATH]);
        self::assertSame([], $controller->temporaryFiles());
        self::assertNotContains('create-temp', $controller->calls);
        $this->assertManualAssignment($controller->output);
    }

    private function recordingController(string $content): RecordingSecurityController
    {
        $controller = new RecordingSecurityController('security', SearchManager::$plugin);
        $controller->files = [
            RecordingSecurityController::ENV_PATH => $content,
        ];
        $controller->modes = [
            RecordingSecurityController::ENV_PATH => 0100640,
        ];

        return $controller;
    }

    private function createFixtureDirectory(): string
    {
        $this->fixtureDirectory = $this->reserveOwnedTempPath('security-salt');
        mkdir($this->fixtureDirectory, 0700);

        return $this->fixtureDirectory;
    }

    private function assertManualAssignment(string $output): void
    {
        self::assertStringContainsString('Manually add this to your .env file:', $output);
        self::assertMatchesRegularExpression('/SEARCH_MANAGER_IP_SALT="[0-9a-f]{64}"/', $output);
    }

    private function assertSecurityGuidance(string $output): void
    {
        self::assertStringContainsString('Never commit .env to version control', $output);
        self::assertStringContainsString('Store the salt securely (password manager recommended)', $output);
        self::assertStringContainsString('Use the SAME salt across all environments (dev/staging/production)', $output);
        self::assertStringContainsString('Changing the salt will reset unique visitor tracking', $output);
    }
}

/**
 * Runs the native filesystem boundary against an isolated fixture `.env`.
 *
 * @since 5.54.0
 */
final class NativeFixtureSecurityController extends SecurityController
{
    public string $output = '';
    public bool $confirmResult = true;
    public int $confirmationCount = 0;

    public function __construct(
        string $id,
        \yii\base\Module $module,
        private readonly string $envPath,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function confirm($message, $default = false)
    {
        $this->confirmationCount++;
        return $this->confirmResult;
    }

    public function stdout($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    public function stderr($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    protected function getEnvPath(): string
    {
        return $this->envPath;
    }
}

/**
 * In-memory file handle for deterministic filesystem failure injection.
 *
 * @since 5.54.0
 */
final class RecordingSecurityFileHandle
{
    public string $content = '';

    public function __construct(public readonly string $path)
    {
    }
}

/**
 * Records the salt generator filesystem protocol without touching project files.
 *
 * @since 5.54.0
 */
final class RecordingSecurityController extends SecurityController
{
    public const ENV_PATH = '/project/.env';

    public string $output = '';
    public bool $confirmResult = true;
    public int $confirmationCount = 0;
    public ?string $failure = null;
    public bool $modeAvailable = true;

    /**
     * @var array<string, string>
     */
    public array $files = [];

    /**
     * @var array<string, int>
     */
    public array $modes = [];

    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var list<string>
     */
    public array $writesToEnvPath = [];

    public function confirm($message, $default = false)
    {
        $this->confirmationCount++;
        return $this->confirmResult;
    }

    public function stdout($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    public function stderr($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    /**
     * @return list<string>
     */
    public function temporaryFiles(): array
    {
        return array_values(array_filter(
            array_keys($this->files),
            static fn(string $path): bool => $path !== self::ENV_PATH,
        ));
    }

    protected function getEnvPath(): string
    {
        return self::ENV_PATH;
    }

    protected function fileExists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    protected function readFile(string $path): string|false
    {
        $this->calls[] = 'read:' . $path;

        if ($path === self::ENV_PATH && $this->failure === 'read') {
            return false;
        }

        if ($path !== self::ENV_PATH && $this->failure === 'verify-read') {
            return false;
        }

        $content = $this->files[$path] ?? false;
        if ($path !== self::ENV_PATH && $this->failure === 'verify-mismatch' && is_string($content)) {
            return $content . 'corrupt';
        }

        return $content;
    }

    protected function matchSaltAssignment(string $content): int|false
    {
        $this->calls[] = 'match';
        return $this->failure === 'match' ? false : parent::matchSaltAssignment($content);
    }

    protected function replaceSaltAssignment(string $content, string $salt): ?string
    {
        $this->calls[] = 'replace';
        return $this->failure === 'replace' ? null : parent::replaceSaltAssignment($content, $salt);
    }

    protected function createTemporaryFile(string $directory, string $prefix): string|false
    {
        $this->calls[] = 'create-temp';
        if ($this->failure === 'create') {
            return false;
        }

        $path = ($this->failure === 'wrong-directory' ? '/other' : $directory)
            . DIRECTORY_SEPARATOR
            . $prefix
            . 'fixture';
        $this->files[$path] = '';
        $this->modes[$path] = 0100600;

        return $path;
    }

    protected function openTemporaryFile(string $path): mixed
    {
        $this->calls[] = 'open';
        return $this->failure === 'open' ? false : new RecordingSecurityFileHandle($path);
    }

    protected function writeTemporaryFile(mixed $handle, string $content): int|false
    {
        $handle = self::recordingHandle($handle);
        $this->calls[] = 'write';

        if ($handle->path === self::ENV_PATH) {
            $this->writesToEnvPath[] = $content;
        }

        if ($this->failure === 'write') {
            return false;
        }

        if ($this->failure === 'short-write') {
            $written = max(0, strlen($content) - 1);
            $handle->content = substr($content, 0, $written);
            return $written;
        }

        $handle->content = $content;
        return strlen($content);
    }

    protected function flushTemporaryFile(mixed $handle): bool
    {
        self::recordingHandle($handle);
        $this->calls[] = 'flush';
        return $this->failure !== 'flush';
    }

    protected function closeTemporaryFile(mixed $handle): bool
    {
        $handle = self::recordingHandle($handle);
        $this->calls[] = 'close';
        $this->files[$handle->path] = $handle->content;
        return $this->failure !== 'close';
    }

    protected function getFileMode(string $path): int|false
    {
        $this->calls[] = 'mode:' . $path;

        if ($path === self::ENV_PATH && !$this->modeAvailable) {
            return false;
        }

        if ($path !== self::ENV_PATH && $this->failure === 'mode-verify') {
            return 0100600;
        }

        return $this->modes[$path] ?? false;
    }

    protected function setFileMode(string $path, int $mode): bool
    {
        $this->calls[] = 'chmod';
        if ($this->failure === 'chmod') {
            return false;
        }

        $this->modes[$path] = 0100000 | $mode;
        return true;
    }

    protected function renameFile(string $from, string $to): bool
    {
        $this->calls[] = 'rename';
        if ($this->failure === 'rename') {
            return false;
        }

        $this->files[$to] = $this->files[$from];
        $this->modes[$to] = $this->modes[$from];
        unset($this->files[$from], $this->modes[$from]);
        return true;
    }

    protected function deleteFile(string $path): bool
    {
        $this->calls[] = 'delete';
        unset($this->files[$path], $this->modes[$path]);
        return true;
    }

    private static function recordingHandle(mixed $handle): RecordingSecurityFileHandle
    {
        if (!$handle instanceof RecordingSecurityFileHandle) {
            throw new \RuntimeException('Expected a recording security file handle.');
        }

        return $handle;
    }
}
