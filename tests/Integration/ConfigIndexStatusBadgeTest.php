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
        $errorHtml = $this->renderStatus($error, 'error', 'Configured backend "missing" does not exist.');
        $enabledHtml = $this->renderStatus($enabled, 'enabled');
        $disabledHtml = $this->renderStatus($disabled, 'disabled');

        self::assertStringContainsString('Error', $errorHtml);
        self::assertStringContainsString('Configured backend &quot;missing&quot; does not exist.', $errorHtml);
        self::assertStringContainsString('Enabled', $enabledHtml);
        self::assertStringNotContainsString('Error', $enabledHtml);
        self::assertStringContainsString('Disabled', $disabledHtml);

        $controllerSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/IndicesController.php');
        self::assertSame(1, substr_count($controllerSource, 'dependencies->getIndexCatalogue()'));
        self::assertStringNotContainsString('configIndexValidator->validate()', $controllerSource);
    }

    private function renderStatus(SearchIndex $index, string $state, ?string $errorTitle = null): string
    {
        $status = SearchManager::$plugin->dependencies->resolveEffectiveStatus(
            $index->enabled,
            [
                'state' => $state,
                'errorTitle' => $errorTitle,
            ],
        );

        return Craft::$app->getView()->renderTemplate('search-manager/_components/_effective-status', [
            'status' => $status,
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
