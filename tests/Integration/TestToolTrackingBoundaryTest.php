<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit batch 8 hardening.
 *
 * @since 5.53.0
 */
final class TestToolTrackingBoundaryTest extends TestCase
{
    public function testCpSettingsTestToolDoesNotCallPublicTrackingEndpoints(): void
    {
        $publicTrackingEndpoints = [
            'search-manager/search/track-search',
            'search-manager/search/track-click',
            '/actions/search-manager/search/track-search',
            '/actions/search-manager/search/track-click',
        ];

        foreach ([
            'src/templates/settings/test/_partials/search.twig',
            'src/web/assets/testtool/src/test-tool.js',
            'src/web/assets/testtool/dist/test-tool.js',
        ] as $path) {
            $source = $this->readPluginFileContents($path);

            foreach ($publicTrackingEndpoints as $endpoint) {
                self::assertStringNotContainsString(
                    $endpoint,
                    $source,
                    $path . ' must keep CP/internal test searches off the public CSRF-free tracking endpoint.',
                );
            }
        }
    }

    private function readPluginFileContents(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
