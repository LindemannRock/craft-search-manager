<?php
/**
 * LindemannRock Search Manager
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
final class AuditPass34Test extends TestCase
{
    public function testGqlResolverCapsAnalyticsParamsLikeApiController(): void
    {
        // #189: SearchResolver and ApiController must share the same tracking metadata normalizer.
        $resolver = $this->readPluginFile('src/gql/resolvers/SearchResolver.php');
        $api = $this->readPluginFile('src/controllers/ApiController.php');

        self::assertStringContainsString('use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;', $resolver);
        self::assertStringContainsString("TrackingMetadataHelper::source(self::trimmedString(\$arguments['source'] ?? null)) ?? 'graphql'", $resolver);
        self::assertStringContainsString("TrackingMetadataHelper::platform(self::trimmedString(\$arguments['platform'] ?? null))", $resolver);
        self::assertStringContainsString("TrackingMetadataHelper::appVersion(self::trimmedString(\$arguments['appVersion'] ?? null))", $resolver);
        self::assertStringContainsString('use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;', $api);

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

    public function testWidgetStyleRulesMessageIsTranslated(): void
    {
        // #190: the match validator message must be wrapped (reusing the existing key).
        $model = $this->readPluginFile('src/models/WidgetStyle.php');

        self::assertStringContainsString(
            "'message' => Craft::t('search-manager', 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.')",
            $model,
        );
        self::assertStringNotContainsString(
            "'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.'",
            $model,
        );
    }

    public function testConfiguredBackendSelectOptionsAreTranslated(): void
    {
        // #191: None + Default ({name}) dropdown labels translated; real backend names untouched.
        $model = $this->readPluginFile('src/models/ConfiguredBackend.php');

        self::assertStringContainsString("\$defaultLabel = Craft::t('search-manager', 'None');", $model);
        self::assertStringContainsString("\$options[''] = Craft::t('search-manager', 'Default ({name})', ['name' => \$defaultLabel]);", $model);

        self::assertStringNotContainsString("\$defaultLabel = 'None';", $model);
        self::assertStringNotContainsString('"Default ({$defaultLabel})"', $model);

        // The new composite key exists; None/Default are reused existing keys.
        $en = require dirname(__DIR__, 2) . '/src/translations/en/search-manager.php';
        self::assertArrayHasKey('Default ({name})', $en);
        self::assertArrayHasKey('None', $en);
    }

    public function testIndicesEditInfoBoxesAreTranslated(): void
    {
        // #192: the two backend info-box messages route through |t() with a {link} placeholder.
        $twig = $this->readPluginFile('src/templates/indices/edit.twig');

        self::assertStringContainsString(
            "'<strong>No configured backends yet.</strong> {link} to use a different search service for this index.'|t('search-manager', {link: createBackendLink})",
            $twig,
        );
        self::assertStringContainsString(
            "'<strong>Custom Backend:</strong> This index uses a different backend than the global default. Data will be stored and searched in the selected backend.'|t('search-manager')",
            $twig,
        );
        // Only the intended link HTML is built/passed: the anchor text itself is translated.
        self::assertStringContainsString("'Create a backend'|t('search-manager')", $twig);

        // No raw (untranslated) copies remain: the old inline-anchor form is gone, and each
        // message text occurs exactly once — as the |t() argument asserted above, never raw.
        self::assertStringNotContainsString('<strong>No configured backends yet.</strong> <a href=', $twig);
        self::assertSame(1, substr_count($twig, "search service for this index.'"));
        self::assertSame(1, substr_count($twig, "searched in the selected backend.'"));

        $en = require dirname(__DIR__, 2) . '/src/translations/en/search-manager.php';
        self::assertArrayHasKey('<strong>No configured backends yet.</strong> {link} to use a different search service for this index.', $en);
        self::assertArrayHasKey('<strong>Custom Backend:</strong> This index uses a different backend than the global default. Data will be stored and searched in the selected backend.', $en);
        self::assertArrayHasKey('Create a backend', $en);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
