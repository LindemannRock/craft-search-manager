<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins audit Pass 34 fixes #189-#192: the GraphQL analytics-param caps (sibling of
 * #180) and three CP-visible i18n residuals (Yii rules() message, a PHP select-options
 * builder, and info-box HTML messages).
 */
final class GraphqlTrackingBoundaryTest extends TestCase
{
    public function testGqlResolverCapsAnalyticsParamsLikeApiController(): void
    {
        // #189: SearchResolver and ApiController must share the same tracking metadata normalizer.
        $resolver = $this->readPluginFile('src/gql/resolvers/SearchResolver.php');
        $api = $this->readPluginFile('src/controllers/ApiController.php');

        self::assertStringContainsString('use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;', $resolver);
        self::assertStringContainsString("'source' => \$arguments['analyticsSource'] ?? null,", $resolver);
        self::assertStringContainsString("'sourceDefault' => TrackingMetadataHelper::SOURCE_GRAPHQL,", $resolver);
        self::assertStringContainsString("TrackingMetadataHelper::platform(self::trimmedString(\$arguments['platform'] ?? null))", $resolver);
        self::assertStringContainsString("TrackingMetadataHelper::appVersion(self::trimmedString(\$arguments['appVersion'] ?? null))", $resolver);
        self::assertStringContainsString('use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;', $api);
        self::assertStringContainsString("'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,", $api);

        // The pre-fix path passed platform/appVersion through trimmedString() only (no cap).
        self::assertStringNotContainsString("foreach (['language', 'platform', 'appVersion'] as \$option)", $resolver);
        self::assertStringNotContainsString('function cappedTrackingValue', $resolver);
    }

    public function testTrackingMetadataHelperPreservesApiAndGqlCaps(): void
    {
        self::assertSame('ios-appbad', TrackingMetadataHelper::source('ios-app bad!'));
        self::assertSame(str_repeat('a', 50), TrackingMetadataHelper::source(str_repeat('a', 55)));
        self::assertNull(TrackingMetadataHelper::source('!@#$'));

        self::assertSame('iOS 17.2_beta', TrackingMetadataHelper::platform('iOS 17.2_beta!'));
        self::assertSame(str_repeat('p', 50), TrackingMetadataHelper::platform(str_repeat('p', 55)));

        self::assertSame('2.1.0 build_7', TrackingMetadataHelper::appVersion('2.1.0 build_7!'));
        self::assertSame(str_repeat('v', 20), TrackingMetadataHelper::appVersion(str_repeat('v', 25)));
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
