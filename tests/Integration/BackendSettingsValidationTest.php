<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\BackendSettings;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins legacy backend settings validation.
 *
 * @since 5.53.0
 */
final class BackendSettingsValidationTest extends TestCase
{
    public function testInvalidDisabledBackendSettingsFailValidation(): void
    {
        $settings = new BackendSettings();
        $settings->backend = 'algolia';
        $settings->enabled = false;
        $settings->config = [
            'applicationId' => '',
            'adminApiKey' => '',
        ];

        self::assertFalse($settings->validate());
        self::assertSame(['Application ID cannot be blank.'], $settings->getErrors('applicationId'));
        self::assertSame(['Admin API Key cannot be blank.'], $settings->getErrors('apiKey'));
    }

    public function testInvalidEnabledBackendSettingsFailValidation(): void
    {
        $settings = new BackendSettings();
        $settings->backend = 'algolia';
        $settings->enabled = true;
        $settings->config = [
            'applicationId' => '',
            'adminApiKey' => '',
        ];

        self::assertFalse($settings->validate());
        self::assertSame(['Application ID cannot be blank.'], $settings->getErrors('applicationId'));
        self::assertSame(['Admin API Key cannot be blank.'], $settings->getErrors('apiKey'));
    }

    public function testValidDisabledBackendSettingsPassValidation(): void
    {
        $settings = new BackendSettings();
        $settings->backend = 'algolia';
        $settings->enabled = false;
        $settings->config = [
            'applicationId' => 'test-application-id',
            'adminApiKey' => 'test-admin-api-key',
        ];

        self::assertTrue($settings->validate());
        self::assertFalse($settings->hasErrors());
    }
}
