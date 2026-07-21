<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\web\View;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.54.0
 */
final class ConfigIndexStatusBadgeTest extends TestCase
{
    public function testIndexListReplacesOnlyErrorFlaggedConfigStatusBadge(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $error = $this->index('config-error', 'Error Fixture', 'config', true);
        $enabled = $this->index('config-healthy', 'Healthy Config Fixture', 'config', true);
        $disabled = $this->index('database-disabled', 'Disabled Database Fixture', 'database', false);
        $errors = ['config-error' => 'Configured backend "missing" does not exist.'];

        $errorHtml = $this->renderStatus($error, $errors);
        $enabledHtml = $this->renderStatus($enabled, $errors);
        $disabledHtml = $this->renderStatus($disabled, $errors);

        self::assertStringContainsString('Error', $errorHtml);
        self::assertStringContainsString('Configured backend &quot;missing&quot; does not exist.', $errorHtml);
        self::assertStringContainsString('Enabled', $enabledHtml);
        self::assertStringNotContainsString('Error', $enabledHtml);
        self::assertStringContainsString('Disabled', $disabledHtml);

        $controllerSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/IndicesController.php');
        self::assertSame(1, substr_count($controllerSource, 'configIndexValidator->validate()'));
    }

    /** @param array<string, string> $errors */
    private function renderStatus(SearchIndex $index, array $errors): string
    {
        return Craft::$app->getView()->renderTemplate('search-manager/indices/_status-badge', [
            'item' => $index,
            'configIndexErrors' => $errors,
        ], View::TEMPLATE_MODE_CP);
    }

    private function index(string $handle, string $name, string $source, bool $enabled): SearchIndex
    {
        $index = new SearchIndex();
        $index->handle = $handle;
        $index->name = $name;
        $index->elementType = 'missing\\ElementType';
        $index->source = $source;
        $index->enabled = $enabled;

        return $index;
    }
}
