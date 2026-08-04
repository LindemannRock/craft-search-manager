<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for audit #165 through #170.
 *
 * @since 5.53.0
 */
final class BackendPathPresentationSecurityTest extends TestCase
{
    private const MARKER = 'audit-pass-29';

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['query' => self::MARKER])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['matchValue' => self::MARKER])
            ->execute();

        parent::tearDown();
    }

    public function testBackendStoragePathIsEscapedBeforeRawInfoBoxRender(): void
    {
        $source = $this->readPluginFile('src/templates/backends/edit.twig');

        self::assertStringContainsString('{path: resolvedStoragePath|e}', $source);
        self::assertStringNotContainsString('{path: resolvedStoragePath})', $source);
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        if ($contents === false) {
            self::fail('Unable to read plugin file: ' . $path);
        }

        return $contents;
    }
}
