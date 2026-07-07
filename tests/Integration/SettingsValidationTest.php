<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\models\Settings;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests settings model validation rules.
 *
 * @since 5.47.0
 */
final class SettingsValidationTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function indexPrefixProvider(): array
    {
        return [
            'null prefix' => [null, true],
            'empty prefix' => ['', true],
            'dev prefix' => ['dev_', true],
            'hyphenated prefix' => ['client-a_', true],
            'numeric prefix' => ['2026_', true],
            'parentheses' => ['s12211212121212122(&)&)(&)(*&dev_', false],
            'slash' => ['../dev_', false],
            'space' => ['dev env_', false],
        ];
    }

    #[DataProvider('indexPrefixProvider')]
    public function testIndexPrefixAllowsOnlyBackendSafeCharacters(?string $prefix, bool $valid): void
    {
        $settings = new Settings();
        $settings->indexPrefix = $prefix;

        self::assertSame($valid, $settings->validate(['indexPrefix']));
    }
}
