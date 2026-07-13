<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(SettingsController::class)]
final class SettingsSaveCacheInvalidationTest extends TestCase
{
    public function testSettingsSaveClearsSearchAndAutocompleteCachesAfterPersisting(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/SettingsController.php');
        self::assertIsString($source);

        $body = $this->methodBody($source, 'actionSave');
        $savePosition = strpos($body, '$settings->saveToDatabase($attributesToValidate)');
        $searchClearPosition = strpos($body, 'SearchManager::$plugin->backend->clearAllSearchCache();');
        $autocompleteClearPosition = strpos($body, 'SearchManager::$plugin->autocomplete->clearCache();');
        $noticePosition = strpos($body, "Craft::\$app->getSession()->setNotice(Craft::t('search-manager', 'Settings saved'));");

        self::assertIsInt($savePosition, 'actionSave must persist settings.');
        self::assertIsInt($searchClearPosition, 'actionSave must clear the search-results cache.');
        self::assertIsInt($autocompleteClearPosition, 'actionSave must clear the autocomplete cache.');
        self::assertIsInt($noticePosition, 'actionSave must keep the existing success notice.');
        self::assertLessThan($searchClearPosition, $savePosition);
        self::assertLessThan($autocompleteClearPosition, $searchClearPosition);
        self::assertLessThan($noticePosition, $autocompleteClearPosition);
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match('/public function ' . preg_quote($method, '/') . '\(.*?^    }$/ms', $source, $methodMatches);
        self::assertNotEmpty($methodMatches, $method . ' source should be found.');

        return $methodMatches[0];
    }
}
