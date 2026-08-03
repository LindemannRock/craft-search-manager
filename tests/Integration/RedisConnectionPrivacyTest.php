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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for audit #165 through #170.
 *
 * @since 5.53.0
 */
final class RedisConnectionPrivacyTest extends TestCase
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

    public function testRedisEnvResolutionDoesNotLogResolvedSecrets(): void
    {
        $storage = $this->readPluginFile('src/search/storage/RedisStorage.php');
        $factory = $this->readPluginFile('src/services/RedisNativeConnectionFactory.php');

        self::assertStringNotContainsString("'Resolved env var'", $storage);
        self::assertStringNotContainsString("'resolved' => \$resolved", $storage);
        self::assertStringNotContainsString('App::env(', $storage);
        self::assertStringContainsString('App::env($matches[1])', $factory);
        self::assertStringNotContainsString("'resolved' => \$resolved", $factory);
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
