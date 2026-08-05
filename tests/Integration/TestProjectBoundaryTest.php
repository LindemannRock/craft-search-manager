<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\Support\DeterministicFixtureManifest;
use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Verifies the explicit local/disposable project and fixture identity boundary.
 *
 * @since 5.54.0
 */
final class TestProjectBoundaryTest extends TestCase
{
    public function testLocalProjectIsSelectedWithoutAnExplicitOverride(): void
    {
        $projectRoot = $this->fakeProjectRoot();
        $packageRoot = $projectRoot . '/plugins/search-manager';
        $fixtureTemplates = $packageRoot . '/tests/Fixtures/Project/templates';
        if (!mkdir($fixtureTemplates, 0700, true) && !is_dir($fixtureTemplates)) {
            throw new \RuntimeException('Unable to create the synthetic local package boundary.');
        }
        $boundary = TestProjectBoundary::resolve([], $packageRoot);

        self::assertFalse($boundary->disposable);
        self::assertSame(realpath($projectRoot), $boundary->projectRoot);
        self::assertSame(realpath($packageRoot), $boundary->packageRoot);
    }

    public function testExplicitRunnerOwnedProjectIsSelectedAndValidated(): void
    {
        $projectRoot = $this->fakeProjectRoot();
        $boundary = TestProjectBoundary::resolve([
            TestProjectBoundary::PROJECT_ROOT_ENV => $projectRoot,
            TestProjectBoundary::DISPOSABLE_ENV => '1',
        ]);

        self::assertTrue($boundary->disposable);
        self::assertSame(realpath($projectRoot), $boundary->projectRoot);
        self::assertSame(realpath($projectRoot . '/storage'), $boundary->storageRoot);
    }

    public function testDisposableModeRequiresAnExplicitProjectRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(TestProjectBoundary::PROJECT_ROOT_ENV);

        TestProjectBoundary::resolve([TestProjectBoundary::DISPOSABLE_ENV => '1']);
    }

    public function testMissingProjectBoundaryFailsBeforeBootstrap(): void
    {
        $missing = $this->reserveOwnedTempPath('missing-project-boundary');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configured test project root does not exist');

        TestProjectBoundary::resolve([TestProjectBoundary::PROJECT_ROOT_ENV => $missing]);
    }

    public function testBoundaryAndFixtureIdentitiesAreStableAndReal(): void
    {
        $boundary = TestProjectBoundary::resolve();
        $manifest = DeterministicFixtureManifest::load();

        self::assertSame($boundary->identityHash(), TestProjectBoundary::resolve()->identityHash());
        self::assertSame($manifest, DeterministicFixtureManifest::load());
        self::assertSame(DeterministicFixtureManifest::EXPECTED_HASH, DeterministicFixtureManifest::hash());
        $uids = array_filter(
            DeterministicFixtureManifest::identities(),
            static fn(string $path): bool => str_ends_with($path, '.uid'),
            ARRAY_FILTER_USE_KEY,
        );
        self::assertNotEmpty($uids);
        self::assertCount(count(array_unique($uids)), $uids);
        DeterministicFixtureManifest::assertExpectedHash();
        DeterministicFixtureManifest::assertRealDependenciesInstalled();
        self::addToAssertionCount(2);
    }

    private function fakeProjectRoot(): string
    {
        $root = $this->createOwnedTempDirectory('fake-craft-project');
        foreach (['vendor/craftcms/cms/bootstrap', 'vendor/lindemannrock/craft-plugin-base/src/testing', 'storage', 'templates'] as $directory) {
            $path = $root . '/' . $directory;
            self::assertTrue(mkdir($path, 0700, true));
        }
        foreach (['bootstrap.php', 'vendor/autoload.php', 'vendor/craftcms/cms/bootstrap/console.php', 'vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php'] as $file) {
            self::assertNotFalse(file_put_contents($root . '/' . $file, "<?php\n"));
        }

        return $root;
    }
}
