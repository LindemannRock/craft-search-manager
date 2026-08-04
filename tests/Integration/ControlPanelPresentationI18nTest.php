<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins audit Pass 34 fixes #189-#192: the GraphQL analytics-param caps (sibling of
 * #180) and three CP-visible i18n residuals (Yii rules() message, a PHP select-options
 * builder, and info-box HTML messages).
 */
final class ControlPanelPresentationI18nTest extends TestCase
{
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

    public function testIndicesEditReadOnlyMetaFieldsetIsBalanced(): void
    {
        $twig = $this->readPluginFile('src/templates/indices/edit.twig');

        self::assertStringContainsString(
            "\t{% if not isNew %}\n\t\t<hr>\n\t\t<fieldset>\n\t\t<dl class=\"meta read-only\">",
            $twig,
        );
        self::assertSame(substr_count($twig, '<fieldset>'), substr_count($twig, '</fieldset>'));
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
