<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins controller-message i18n handling for audit #154-#157.
 */
#[CoversClass(PromotionsController::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(AnalyticsController::class)]
#[CoversClass(BackendsController::class)]
#[CoversClass(SettingsController::class)]
final class ControllerMessageI18nTest extends TestCase
{
    public function testDeleteJsonFailureMessagesUseStaticTranslationKeys(): void
    {
        $this->assertControllerMethodContains(
            'PromotionsController.php',
            'actionDelete',
            "Craft::t('search-manager', 'Could not delete promotion')",
        );
        $this->assertControllerMethodNotContains(
            'PromotionsController.php',
            'actionDelete',
            "'error' => 'Could not delete promotion'",
        );

        $this->assertControllerMethodContains(
            'QueryRulesController.php',
            'actionDelete',
            "Craft::t('search-manager', 'Could not delete query rule')",
        );
        $this->assertControllerMethodNotContains(
            'QueryRulesController.php',
            'actionDelete',
            "'error' => 'Could not delete query rule'",
        );

        $this->assertControllerMethodContains(
            'AnalyticsController.php',
            'actionDelete',
            "Craft::t('search-manager', 'Could not delete analytics record')",
        );
        $this->assertControllerMethodNotContains(
            'AnalyticsController.php',
            'actionDelete',
            "'error' => 'Could not delete analytic'",
        );
    }

    public function testBackendConnectionTestMessagesUseStaticKeysAndNamedPlaceholder(): void
    {
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionTest',
            "Craft::t('search-manager', 'Connection successful')",
        );
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionTest',
            "Craft::t('search-manager', 'Backend is not available. Check your settings.')",
        );
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionTest',
            "Craft::t('search-manager', 'Unknown backend type: {backendType}',",
        );
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionTest',
            "'backendType' =>",
        );
        $this->assertControllerMethodNotContains(
            'BackendsController.php',
            'actionTest',
            '"Unknown backend type: {$',
        );
        $this->assertControllerMethodNotContains(
            'BackendsController.php',
            'actionTest',
            "'message' => 'Connection successful'",
        );
        $this->assertControllerMethodNotContains(
            'BackendsController.php',
            'actionTest',
            "'error' => 'Backend is not available. Check your settings.'",
        );
    }

    public function testBackendInfoUnknownTypeUsesStaticKeyAndNamedPlaceholder(): void
    {
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionInfo',
            "Craft::t('search-manager', 'Unknown backend type: {backendType}',",
        );
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionInfo',
            "'backendType' => \$configuredBackend->backendType",
        );
        $this->assertControllerMethodNotContains(
            'BackendsController.php',
            'actionInfo',
            '"Unknown backend type: {$configuredBackend->backendType}"',
        );
    }

    public function testBackendBulkActionFallbacksUseStaticTranslationKey(): void
    {
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionBulkDisable',
            "Craft::t('search-manager', 'Unknown error')",
        );
        $this->assertControllerMethodContains(
            'BackendsController.php',
            'actionBulkDelete',
            "Craft::t('search-manager', 'Unknown error')",
        );
        $this->assertControllerMethodNotContains(
            'BackendsController.php',
            'actionBulkDisable',
            ": 'Unknown error'",
        );
        $this->assertControllerMethodNotContains(
            'BackendsController.php',
            'actionBulkDelete',
            ": 'Unknown error'",
        );
    }

    public function testSettingsPromotionTestFallbackUsesNoPeriodStaticKey(): void
    {
        $this->assertControllerMethodContains(
            'SettingsController.php',
            'actionTestPromotions',
            "Craft::t('search-manager', 'Element not found')",
        );
        $this->assertControllerMethodNotContains(
            'SettingsController.php',
            'actionTestPromotions',
            ": 'Element not found'",
        );
        $this->assertControllerMethodNotContains(
            'SettingsController.php',
            'actionTestPromotions',
            "Craft::t('search-manager', 'Element not found.')",
        );
    }

    private function assertControllerMethodContains(string $filename, string $method, string $needle): void
    {
        self::assertStringContainsString($needle, $this->controllerMethodBody($filename, $method));
    }

    private function assertControllerMethodNotContains(string $filename, string $method, string $needle): void
    {
        self::assertStringNotContainsString($needle, $this->controllerMethodBody($filename, $method));
    }

    private function controllerMethodBody(string $filename, string $method): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/' . $filename);
        $this->assertIsString($source);

        preg_match(
            '/public function ' . preg_quote($method, '/') . '\(\): Response\s+\{(?<body>.*?)(?:\n    \}|\n    public function )/s',
            $source,
            $matches,
        );

        $body = $matches['body'] ?? '';
        $this->assertNotSame('', $body, $method . ' body should be captured.');

        return $body;
    }
}
