<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\models\WidgetStyle;

/**
 * backdropBlur is boolean end-to-end (the widget maps it to blur(4px)|none),
 * so the style validator clamps it to 0/1 like every sibling numeric key.
 *
 * @since 5.53.0
 */
class WidgetStyleBackdropBlurValidationTest extends IntegrationTestCase
{
    public function testOutOfRangeBackdropBlurIsRejected(): void
    {
        $style = new WidgetStyle();
        $style->styles = ['backdropBlur' => '4'];
        $style->validateStyles();

        self::assertNotEmpty($style->getErrors('styles.backdropBlur'));
    }

    public function testBooleanBackdropBlurValuesPass(): void
    {
        foreach (['0', '1', ''] as $value) {
            $style = new WidgetStyle();
            $style->styles = ['backdropBlur' => $value];
            $style->validateStyles();

            self::assertEmpty($style->getErrors('styles.backdropBlur'), "value '{$value}' should pass");
        }
    }
}
