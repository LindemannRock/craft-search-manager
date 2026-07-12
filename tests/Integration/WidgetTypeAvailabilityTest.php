<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\base\InvalidConfigException;

/**
 * @since 5.53.0
 */
#[CoversClass(WidgetConfig::class)]
#[CoversClass(WidgetConfigService::class)]
final class WidgetTypeAvailabilityTest extends TestCase
{
    public function testPostedPageWidgetTypeFailsValidation(): void
    {
        $widget = new WidgetConfig();
        $widget->handle = 'page-widget';
        $widget->name = 'Page Widget';
        $widget->type = 'page';
        $widget->settings = WidgetConfig::defaultSettings();

        self::assertFalse($widget->validate(['type']));
        self::assertContains('Only modal widgets are available in this version.', $widget->getErrors('type'));
    }

    public function testConfigInlineWidgetTypeFailsLoudlyAtLoad(): void
    {
        $service = new WidgetConfigService();
        $method = new \ReflectionMethod($service, 'createFromConfig');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Widget "inline-widget" uses unsupported type "inline". Only modal widgets are available in this version.');

        $method->invoke($service, 'inline-widget', [
            'name' => 'Inline Widget',
            'type' => 'inline',
        ]);
    }
}
