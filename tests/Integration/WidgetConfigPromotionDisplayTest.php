<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\models\WidgetConfig;

/**
 * Promotion display config: getters normalize values and validation
 * rejects unknown modes/positions.
 *
 * @since 5.53.0
 */
class WidgetConfigPromotionDisplayTest extends IntegrationTestCase
{
    public function testPromotionDisplayDefaultsToNone(): void
    {
        $config = new WidgetConfig();

        self::assertSame('none', $config->getPromotionDisplay());
        self::assertSame('Featured', $config->getPromotionBadgeText());
        self::assertSame('inline', $config->getPromotionBadgePosition());
    }

    public function testPromotionSettingsRoundTrip(): void
    {
        $config = new WidgetConfig();
        $config->settings = [
            'behavior' => [
                'promotionDisplay' => 'tint',
                'promotionBadgeText' => 'Sponsored',
                'promotionBadgePosition' => 'inline',
            ],
        ];

        self::assertSame('tint', $config->getPromotionDisplay());
        self::assertSame('Sponsored', $config->getPromotionBadgeText());
        self::assertSame('inline', $config->getPromotionBadgePosition());
    }

    public function testGettersNormalizeInvalidValues(): void
    {
        $config = new WidgetConfig();
        $config->settings = [
            'behavior' => [
                'promotionDisplay' => 'sparkles',
                'promotionBadgeText' => '   ',
                'promotionBadgePosition' => 'bottom',
            ],
        ];

        self::assertSame('none', $config->getPromotionDisplay());
        self::assertSame('Featured', $config->getPromotionBadgeText());
        self::assertSame('inline', $config->getPromotionBadgePosition());
    }

    public function testValidationRejectsUnknownModeAndPosition(): void
    {
        $config = new WidgetConfig();
        $config->settings = [
            'behavior' => [
                'promotionDisplay' => 'sparkles',
                'promotionBadgePosition' => 'bottom',
            ],
        ];
        $config->validateSettings();

        self::assertNotEmpty($config->getErrors('settings.behavior.promotionDisplay'));
        self::assertNotEmpty($config->getErrors('settings.behavior.promotionBadgePosition'));
    }
}
