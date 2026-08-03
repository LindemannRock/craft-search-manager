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
final class TrackingOriginContractTest extends TestCase
{
    public function testCsrfFreeTrackingUsesTrustedOriginGuard(): void
    {
        $source = $this->readPluginFileContents('src/controllers/SearchController.php');

        self::assertStringContainsString('$this->enableCsrfValidation = false;', $source);
        self::assertStringContainsString('$this->requireTrustedTrackingOrigin()', $source);
        self::assertStringContainsString('Cross-origin tracking requests are not allowed.', $source);
        self::assertSame(42, SearchController::normalizeTrackingElementId('42'));
        self::assertNull(SearchController::normalizeTrackingElementId('-1'));
        self::assertSame(3, SearchController::normalizeTrackingPosition('3'));
        self::assertNull(SearchController::normalizeTrackingPosition('1001'));
    }

    public function testTrustedTrackingOriginMatchingIsExactOriginOnly(): void
    {
        $allowedOrigins = [
            'https://frontend.example.com/',
            'http://localhost:3000',
        ];

        self::assertSame(
            ['https://frontend.example.com:443', 'http://localhost:3000'],
            SearchController::normalizeTrackingOrigins($allowedOrigins),
        );
        self::assertSame(
            ['https://frontend.example.com:443', 'http://localhost:3000'],
            SearchController::normalizeTrackingOrigins('https://frontend.example.com/, http://localhost:3000'),
        );
        self::assertNull(SearchController::normalizeTrackingOrigin('https://frontend.example.com/path'));
        self::assertNull(SearchController::normalizeTrackingOrigin('*.example.com'));

        self::assertTrue(SearchController::trackingOriginAllowed(
            'https://craft.example.com',
            'https://craft.example.com',
            [],
        ));
        self::assertTrue(SearchController::trackingOriginAllowed(
            'https://frontend.example.com',
            'https://craft.example.com',
            $allowedOrigins,
        ));
        self::assertFalse(SearchController::trackingOriginAllowed(
            'https://evil.example.com',
            'https://craft.example.com',
            $allowedOrigins,
        ));
        self::assertFalse(SearchController::trackingOriginAllowed(
            'https://frontend.example.com:8443',
            'https://craft.example.com',
            $allowedOrigins,
        ));
    }

    public function testAllowedTrackingOriginEmitsCorsAndOptionsPreflight(): void
    {
        $source = $this->readPluginFileContents('src/controllers/SearchController.php');

        self::assertStringContainsString("'Access-Control-Allow-Origin', rtrim(trim(\$origin), '/')", $source);
        self::assertStringContainsString("'Vary', 'Origin'", $source);
        self::assertStringContainsString("'Access-Control-Allow-Methods', 'POST, OPTIONS'", $source);
        self::assertStringContainsString("'Access-Control-Allow-Headers', 'Content-Type, Accept, X-Search-Manager-Key'", $source);
        self::assertStringContainsString("\$request->getMethod()) === 'OPTIONS'", $source);
        self::assertStringContainsString("Craft::\$app->getResponse()->setStatusCode(204);", $source);
        self::assertStringNotContainsString('Access-Control-Allow-Origin\', \'*', $source);
    }

    public function testTrackingAllowedOriginsIsConfigOnlySetting(): void
    {
        $settings = $this->readPluginFileContents('src/models/Settings.php');
        $config = $this->readPluginFileContents('src/config.php');

        self::assertStringContainsString('public array|string $trackingAllowedOrigins = [];', $settings);
        self::assertStringContainsString("'trackingAllowedOrigins',", $settings);
        self::assertStringContainsString("'trackingAllowedOrigins' => App::env('SEARCH_MANAGER_TRACKING_ALLOWED_ORIGINS') ?: []", $config);
    }

    private function readPluginFileContents(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
