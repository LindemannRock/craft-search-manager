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
 * Resolves and validates the one Craft project boundary used by the test run.
 *
 * @since 5.54.0
 */
final readonly class TestProjectBoundary
{
    public const PROJECT_ROOT_ENV = 'SEARCH_MANAGER_TEST_PROJECT_ROOT';
    public const DISPOSABLE_ENV = 'SEARCH_MANAGER_TEST_PROJECT_DISPOSABLE';

    private function __construct(
        public string $packageRoot,
        public string $projectRoot,
        public string $vendorRoot,
        public string $storageRoot,
        public string $templatesRoot,
        public string $fixtureTemplatesRoot,
        public bool $disposable,
    ) {
    }

    /**
     * @param array<string, mixed>|null $environment
     */
    public static function resolve(?array $environment = null, ?string $packageRoot = null): self
    {
        $environment ??= array_merge($_ENV, $_SERVER);
        $packageRoot = self::validatedDirectory($packageRoot ?? dirname(__DIR__, 2), 'package root');
        $configuredRoot = $environment[self::PROJECT_ROOT_ENV] ?? null;
        if ($configuredRoot !== null && (!is_string($configuredRoot) || trim($configuredRoot) === '')) {
            throw new \InvalidArgumentException(self::PROJECT_ROOT_ENV . ' must be a non-empty absolute path.');
        }

        $projectRoot = $configuredRoot !== null
            ? self::validatedDirectory($configuredRoot, 'configured test project root')
            : self::discoverLocalProjectRoot($packageRoot);
        $disposable = self::environmentFlag($environment[self::DISPOSABLE_ENV] ?? null);

        if ($disposable && $configuredRoot === null) {
            throw new \InvalidArgumentException(
                self::DISPOSABLE_ENV . '=1 requires an explicit ' . self::PROJECT_ROOT_ENV . '.',
            );
        }

        $vendorRoot = self::validatedDirectory($projectRoot . '/vendor', 'test project vendor root');
        $storageRoot = self::validatedDirectory($projectRoot . '/storage', 'test project storage root');
        $templatesRoot = self::validatedDirectory($projectRoot . '/templates', 'test project templates root');
        $fixtureTemplatesRoot = self::validatedDirectory(
            $packageRoot . '/tests/Fixtures/Project/templates',
            'package fixture templates root',
        );
        self::assertFile($projectRoot . '/bootstrap.php', 'test project bootstrap');
        self::assertFile($vendorRoot . '/autoload.php', 'test project Composer autoloader');
        self::assertFile($vendorRoot . '/craftcms/cms/bootstrap/console.php', 'Craft console bootstrap');
        self::assertFile(
            $vendorRoot . '/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
            'Base integration-test bootstrap',
        );

        return new self(
            packageRoot: $packageRoot,
            projectRoot: $projectRoot,
            vendorRoot: $vendorRoot,
            storageRoot: $storageRoot,
            templatesRoot: $templatesRoot,
            fixtureTemplatesRoot: $fixtureTemplatesRoot,
            disposable: $disposable,
        );
    }

    public function vendorAutoload(): string
    {
        return $this->vendorRoot . '/autoload.php';
    }

    public function baseBootstrap(): string
    {
        return $this->vendorRoot . '/lindemannrock/craft-plugin-base/src/testing/bootstrap.php';
    }

    /** @return array<string, bool|string> */
    public function identity(): array
    {
        return [
            'disposable' => $this->disposable,
            'packageRoot' => $this->packageRoot,
            'projectRoot' => $this->projectRoot,
            'storageRoot' => $this->storageRoot,
            'templatesRoot' => $this->templatesRoot,
            'fixtureTemplatesRoot' => $this->fixtureTemplatesRoot,
            'vendorRoot' => $this->vendorRoot,
        ];
    }

    public function identityHash(): string
    {
        return hash('sha256', json_encode($this->identity(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function discoverLocalProjectRoot(string $packageRoot): string
    {
        $candidate = dirname($packageRoot, 2);
        if (is_file($candidate . '/bootstrap.php') && is_dir($candidate . '/vendor')) {
            return self::validatedDirectory($candidate, 'local test project root');
        }

        throw new \RuntimeException(
            'Unable to resolve the local Craft project. Set ' . self::PROJECT_ROOT_ENV
            . ' to an explicit runner-owned project root.',
        );
    }

    private static function validatedDirectory(string $path, string $label): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new \InvalidArgumentException("The {$label} must be an absolute path: {$path}");
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException("The {$label} does not exist: {$path}");
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private static function assertFile(string $path, string $label): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("The {$label} does not exist: {$path}");
        }
    }

    private static function environmentFlag(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'yes'], true);
    }
}
