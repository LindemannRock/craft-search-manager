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
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins audit Pass 36 fixes #197-#198.
 */
final class ConfiguredBackendValidationI18nTest extends TestCase
{
    public function testConfiguredBackendValidationTranslatesSchemaFieldLabels(): void
    {
        $previousLanguage = Craft::$app->language;
        Craft::$app->language = 'de';

        try {
            $backend = new ConfiguredBackend();
            $backend->backendType = 'algolia';
            $backend->settings = [
                'applicationId' => '',
                'adminApiKey' => '',
            ];

            self::assertFalse($backend->validate(['settings']));

            $errors = $backend->getErrors('settings.applicationId');
            self::assertNotEmpty($errors);
            self::assertStringContainsString('Anwendungs-ID', $errors[0]);
            self::assertStringNotContainsString('Application ID', $errors[0]);
        } finally {
            Craft::$app->language = $previousLanguage;
        }
    }
}
