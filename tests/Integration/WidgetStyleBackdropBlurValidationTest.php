<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\helpers\Json;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * backdropBlur is boolean end-to-end (the widget maps it to blur(4px)|none),
 * so the style validator clamps it to 0/1 like every sibling numeric key.
 *
 * @since 5.53.0
 */
class WidgetStyleBackdropBlurValidationTest extends TestCase
{
    private const HANDLE = 'sm-audit-413-style';

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteStyle();
    }

    protected function tearDown(): void
    {
        $this->deleteStyle();
        parent::tearDown();
    }

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

    public function testWholeNumberStyleValuePersists(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $style = $this->newStyle(['modalBorderWidth' => '2']);

        self::assertTrue(SearchManager::$plugin->widgetStyles->save($style), print_r($style->getErrors(), true));

        $reloaded = SearchManager::$plugin->widgetStyles->getByHandle(self::HANDLE);
        self::assertNotNull($reloaded);
        self::assertSame('2', $reloaded->getStyles()['modalBorderWidth'] ?? null);
    }

    public function testGarbageStyleValueIsRejectedWithoutOverwritingPersistedJson(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $style = $this->newStyle(['modalBorderWidth' => '2']);
        self::assertTrue(SearchManager::$plugin->widgetStyles->save($style), print_r($style->getErrors(), true));

        $style->styles = ['modalBorderWidth' => 'not-a-number'];

        self::assertFalse(SearchManager::$plugin->widgetStyles->save($style));
        self::assertSame(
            ['Modal Border Width must be a whole number.'],
            $style->getErrors('styles.modalBorderWidth'),
        );

        $storedJson = Craft::$app->getDb()->createCommand(
            'SELECT [[styles]] FROM {{%searchmanager_widget_styles}} WHERE [[handle]] = :handle',
            [':handle' => self::HANDLE],
        )->queryScalar();
        self::assertIsString($storedJson);
        self::assertSame('2', Json::decode($storedJson)['modalBorderWidth'] ?? null);
    }

    /**
     * @param array<string, mixed> $styles
     */
    private function newStyle(array $styles): WidgetStyle
    {
        $style = new WidgetStyle();
        $style->name = 'Audit 413 Style';
        $style->handle = self::HANDLE;
        $style->type = WidgetStyle::TYPE_MODAL;
        $style->styles = $styles;

        return $style;
    }

    private function deleteStyle(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['handle' => self::HANDLE])
            ->execute();
    }
}
