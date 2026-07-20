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
use craft\events\ElementEvent;
use craft\services\Elements;
use craft\web\View;
use lindemannrock\searchmanager\controllers\PendingSyncsController;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Action;
use yii\base\Event;
use yii\web\ForbiddenHttpException;

/**
 * @since 5.54.0
 */
final class EditionPendingSyncsConsoleGateTest extends TestCase
{
    private const ACTION_IDS = [
        'index',
        'get-data',
        'retry',
        'delete',
        'purge-abandoned',
    ];

    public function testStandardRejectsEveryPendingSyncConsoleAction(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $controller = new PendingSyncsController('pending-syncs', SearchManager::$plugin);

        foreach (self::ACTION_IDS as $actionId) {
            try {
                $controller->beforeAction(new Action($actionId, $controller));
                self::fail("{$actionId} should require Search Manager Pro.");
            } catch (ForbiddenHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testProAllowsEveryPendingSyncConsoleAction(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);

        foreach (self::ACTION_IDS as $actionId) {
            self::assertNull(
                SearchManager::$plugin->requireProOrPrompt('Pending Syncs'),
                "{$actionId} should pass the shared Pro edition gate.",
            );
        }
    }

    public function testPendingSyncNavigationRequiresProAndConfiguredBackend(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        self::assertFalse($this->pendingSyncSection()['when']);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        self::assertSame(ConfiguredBackend::findAllEnabled() !== [], $this->pendingSyncSection()['when']);
    }

    public function testPendingSyncDirectUrlPromptUsesTheSharedUpgradeSurface(): void
    {
        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/_partials/upgrade-prompt',
            ['featureName' => 'Pending Syncs'],
            View::TEMPLATE_MODE_CP,
        );

        self::assertStringContainsString('Pending Syncs requires Search Manager Pro', $html);
        self::assertStringContainsString('plugin-store/search-manager', $html);
    }

    public function testStandardSaveEventStillDrainsThroughBatchSyncJob(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        self::assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $settings = SearchManager::$plugin->getSettings();
        $originalAutoIndex = $settings->autoIndex;
        $stub = $this->installStubBackend();

        try {
            $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
            $settings->autoIndex = true;

            Event::trigger(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                new ElementEvent(['element' => $element]),
            );

            self::assertNotNull($this->fetchPendingRow(
                $index->handle,
                (int) $element->id,
                (int) $element->siteId,
            ));

            (new BatchSyncJob())->execute(Craft::$app->queue);

            self::assertNotSame([], $stub->callsFor('batchIndex'));
            self::assertNull($this->fetchPendingRow(
                $index->handle,
                (int) $element->id,
                (int) $element->siteId,
            ));
        } finally {
            $settings->autoIndex = $originalAutoIndex;
        }
    }

    /**
     * @return array{key: string, when: bool}
     */
    private function pendingSyncSection(): array
    {
        foreach (SearchManager::$plugin->getCpSections(SearchManager::$plugin->getSettings()) as $section) {
            if ($section['key'] === 'pending-syncs') {
                return $section;
            }
        }

        self::fail('Pending Syncs CP section was not registered.');
    }
}
