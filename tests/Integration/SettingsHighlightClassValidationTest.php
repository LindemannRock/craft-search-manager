<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\search\Highlighter;

/**
 * Settings highlightClass enforces the same plain-CSS-token format as the
 * WidgetStyle validator and the widget's client-side rule, via the shared
 * Highlighter::isValidClassTokenList() helper.
 *
 * @since 5.53.0
 */
class SettingsHighlightClassValidationTest extends IntegrationTestCase
{
    public function testGarbageHighlightClassIsRejected(): void
    {
        $settings = new Settings();
        $settings->highlightClass = 'ok"><script>alert(1)</script>';
        $settings->validateHighlightClass('highlightClass');

        self::assertNotEmpty($settings->getErrors('highlightClass'));
    }

    public function testValidTokenListsPass(): void
    {
        foreach (['search-highlight', 'my-class other_class', ''] as $value) {
            $settings = new Settings();
            $settings->highlightClass = $value;
            $settings->validateHighlightClass('highlightClass');

            self::assertEmpty($settings->getErrors('highlightClass'), "value '{$value}' should pass");
        }
    }

    public function testSharedHelperMatchesWidgetRule(): void
    {
        self::assertTrue(Highlighter::isValidClassTokenList('a-b c_d E9'));
        self::assertFalse(Highlighter::isValidClassTokenList('a;b'));
        self::assertFalse(Highlighter::isValidClassTokenList('a{color:red}'));
    }
}
