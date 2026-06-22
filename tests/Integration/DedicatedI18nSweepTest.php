<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the CP-visible strings fixed by the dedicated i18n sweep (audit pass 33):
 * test-tool Twig-section fallbacks (#178/#179), element-type option/column labels
 * (#181 + sweep), the language-preview manual-override label, the match-type example
 * tips in the promotion/query-rule editors, the utilities storage-stat labels, and
 * the widget type labels. These classes (Twig option-label builders, inline-JS label
 * strings, element-type maps) repeatedly re-surfaced in earlier passes; the assertions
 * below keep them from regressing to raw English.
 */
final class DedicatedI18nSweepTest extends TestCase
{
    public function testTestToolTwigSectionFallbacksAreTranslated(): void
    {
        // #178: search.twig Twig-section site dropdown fallback reuses the Site #{id} key.
        $search = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');
        self::assertStringContainsString("'Site #{id}'|t('search-manager', {id: siteId})", $search);
        self::assertStringContainsString("'Site #{id}'|t('search-manager', {id: index.siteId})", $search);
        self::assertStringNotContainsString("'Site ' ~ siteId", $search);
        self::assertStringNotContainsString("'Site ' ~ index.siteId", $search);

        // #179: backend.twig default-backend suffix reuses the Default key.
        $backend = $this->readPluginFile('src/templates/settings/test/_partials/backend.twig');
        self::assertStringContainsString("' — ' ~ 'Default'|t('search-manager')", $backend);
        self::assertStringNotContainsString("' — Default'", $backend);
    }

    public function testElementTypeDropdownLabelsAreTranslated(): void
    {
        // #181: index-edit element-type dropdown plural labels.
        $edit = $this->readPluginFile('src/templates/indices/edit.twig');
        foreach (['Entries', 'Assets', 'Categories', 'Users'] as $label) {
            self::assertStringContainsString("'{$label}'|t('search-manager')", $edit);
        }
        self::assertStringNotContainsString("'craft\\\\elements\\\\Asset': 'Assets',", $edit);
    }

    public function testElementTypeColumnLabelsUseTranslatedMap(): void
    {
        // Sweep: element-type column / deleted-element fallbacks map class -> translated
        // singular label, falling back to the class basename for unmapped (brand) types.
        foreach ([
            'src/templates/indices/view.twig',
            'src/templates/indices/index.twig',
            'src/templates/pending-syncs/index.twig',
            'src/templates/pending-syncs/_row.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);
            self::assertStringContainsString("'craft\\\\elements\\\\Entry': 'Entry'|t('search-manager')", $source);
            self::assertStringContainsString('elementTypeLabels[', $source);
        }
    }

    public function testLanguagePreviewManualOverrideIsTranslated(): void
    {
        $edit = $this->readPluginFile('src/templates/indices/edit.twig');
        self::assertStringContainsString(
            "{{ 'Manual override: {language}'|t('search-manager')|json_encode|raw }}.replace('{language}', manualLanguage)",
            $edit
        );
        self::assertStringNotContainsString("'Manual override: ' + manualLanguage", $edit);
    }

    public function testMatchTypeExampleTipsAreTranslated(): void
    {
        foreach ([
            'src/templates/promotions/edit.twig',
            'src/templates/query-rules/edit.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);
            // Tips now route through |t()|json_encode|raw instead of raw JS literals.
            self::assertStringContainsString("matchTypeTipText.textContent = {{ 'Example:", $source);
            self::assertStringNotContainsString("matchTypeTipText.textContent = 'Example:", $source);
        }
    }

    public function testUtilitiesStorageStatLabelsAreTranslated(): void
    {
        $util = $this->readPluginFile('src/templates/utilities/index.twig');
        self::assertStringContainsString('var storageStrings = {', $util);
        self::assertStringContainsString("rowsPlural: {{ '{count} rows'|t('search-manager')|json_encode|raw }}", $util);
        self::assertStringContainsString("notConfigured: {{ 'Not configured'|t('search-manager')|json_encode|raw }}", $util);
        // Raw English suffixes/labels are gone from the JS builder.
        self::assertStringNotContainsString("' rows)'", $util);
        self::assertStringNotContainsString("' keys)'", $util);
        self::assertStringNotContainsString("' (not configured)'", $util);
        self::assertStringNotContainsString("'Database (loading...)'", $util);
    }

    public function testWidgetTypeLabelsAreTranslated(): void
    {
        $controller = $this->readPluginFile('src/controllers/WidgetsController.php');
        self::assertStringContainsString("Craft::t('search-manager', \$label)", $controller);

        foreach ([
            'src/templates/widgets/view.twig',
            'src/templates/widgets/styles/edit.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);
            self::assertStringContainsString("'Search Page'|t('search-manager')", $source);
            self::assertStringContainsString("'Inline Search'|t('search-manager')", $source);
            self::assertStringNotContainsString("{modal: 'Modal', page: 'Search Page', inline: 'Inline Search'}", $source);
        }
    }

    public function testApiControllerCapsAnalyticsParams(): void
    {
        // #180: anonymous endpoint caps source/platform/appVersion to their column widths.
        $api = $this->readPluginFile('src/controllers/ApiController.php');
        self::assertStringContainsString("preg_replace('/[^a-zA-Z0-9_-]/', '', (string) \$source), 0, 50)", $api);
        self::assertStringContainsString("preg_replace('/[^a-zA-Z0-9 ._-]/', '', (string) \$platform), 0, 50)", $api);
        self::assertStringContainsString("preg_replace('/[^a-zA-Z0-9 ._-]/', '', (string) \$appVersion), 0, 20)", $api);
    }

    public function testNewKeysExistAcrossAllLocales(): void
    {
        $newKeys = [
            'Assets', 'Categories', 'User', 'Users',
            'Manual override: {language}',
            'Redirected to another page', 'Synonyms used', 'Not configured',
            '{count} row', '{count} rows', '{count} key', '{count} keys', '{count} file', '{count} files',
        ];
        $locales = ['en', 'de', 'fr', 'nl', 'es', 'ar', 'it', 'pt', 'ja', 'sv', 'da', 'no'];
        foreach ($locales as $locale) {
            $translations = require dirname(__DIR__, 2) . "/src/translations/{$locale}/search-manager.php";
            self::assertIsArray($translations);
            foreach ($newKeys as $key) {
                self::assertArrayHasKey($key, $translations, "Missing '{$key}' in {$locale}");
                self::assertNotSame('', (string) $translations[$key], "Empty '{$key}' in {$locale}");
            }
        }
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
