<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(WidgetConfig::class)]
final class WidgetConfigApiKeyValidationTest extends TestCase
{
    public function testEmptyApiKeyRemainsValid(): void
    {
        $widget = $this->makeWidgetConfig('');

        self::assertTrue($widget->validate(['settings']));
        self::assertFalse($widget->hasErrors('settings.apiKey'));
    }

    public function testPublicApiKeyIsValid(): void
    {
        $widget = $this->makeWidgetConfig('sm_pub_' . str_repeat('a', 32));

        self::assertTrue($widget->validate(['settings']));
        self::assertFalse($widget->hasErrors('settings.apiKey'));
    }

    public function testServerApiKeyIsInvalid(): void
    {
        $widget = $this->makeWidgetConfig('sm_srv_' . str_repeat('a', 32));

        self::assertFalse($widget->validate(['settings']));
        self::assertTrue($widget->hasErrors('settings.apiKey'));
    }

    public function testArbitraryApiKeyIsInvalid(): void
    {
        $widget = $this->makeWidgetConfig('not-a-search-manager-public-key');

        self::assertFalse($widget->validate(['settings']));
        self::assertTrue($widget->hasErrors('settings.apiKey'));
    }

    private function makeWidgetConfig(string $apiKey): WidgetConfig
    {
        $settings = WidgetConfig::defaultSettings();
        $settings['apiKey'] = $apiKey;

        $widget = new WidgetConfig();
        $widget->handle = 'api-key-validation';
        $widget->name = 'API Key Validation';
        $widget->settings = $settings;

        return $widget;
    }
}
