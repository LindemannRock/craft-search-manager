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
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins controller-message i18n handling for audit #154-#157.
 */
#[CoversClass(PromotionsController::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(AnalyticsController::class)]
#[CoversClass(BackendsController::class)]
#[CoversClass(IndicesController::class)]
#[CoversClass(SettingsController::class)]
#[CoversClass(UtilitiesController::class)]
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

    public function testAudit350ExceptionMessagesAreDevModeGated(): void
    {
        $this->assertControllerMethodContains(
            'IndicesController.php',
            'actionSyncCount',
            "'error' => Craft::\$app->getConfig()->getGeneral()->devMode",
        );
        $this->assertControllerMethodContains(
            'IndicesController.php',
            'actionSyncCount',
            ": Craft::t('search-manager', 'Failed to sync count')",
        );
        $this->assertControllerMethodNotContains(
            'IndicesController.php',
            'actionSyncCount',
            "Craft::t('search-manager', 'Failed to sync count: {error}'",
        );

        $clearRedisStorage = $this->controllerMethodBody('UtilitiesController.php', 'clearRedisStorage');
        self::assertStringContainsString("\$this->logError('Failed to clear Redis storage'", $clearRedisStorage);
        self::assertStringContainsString("'error' => Craft::\$app->getConfig()->getGeneral()->devMode", $clearRedisStorage);
        self::assertStringContainsString(": Craft::t('search-manager', 'Failed to clear {type} storage'", $clearRedisStorage);
        self::assertStringNotContainsString("Craft::t('search-manager', 'Redis connection failed: {error}'", $clearRedisStorage);

        foreach (['getDatabaseStats' => 'database', 'getRedisStats' => 'Redis'] as $method => $label) {
            $methodBody = $this->controllerMethodBody('UtilitiesController.php', $method);
            self::assertStringContainsString("\$this->logError('Failed to get {$label} storage stats'", $methodBody);
            self::assertStringContainsString("'error' => Craft::\$app->getConfig()->getGeneral()->devMode", $methodBody);
            self::assertStringContainsString(": Craft::t('search-manager', 'Failed to get storage statistics')", $methodBody);
        }
    }

    public function testControllerResponseExceptionMessagesRequireDevModeGate(): void
    {
        foreach ($this->controllerResponseGetMessageSites() as $site) {
            self::assertStringContainsString(
                'getConfig()->getGeneral()->devMode',
                $site['context'],
                $site['location'] . ' returns an exception message without the CP devMode gate.',
            );
        }
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
            '/(?:public|private|protected) function ' . preg_quote($method, '/') . '\(.*?^    \}$/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        $this->assertNotSame('', $body, $method . ' body should be captured.');

        return $body;
    }

    /**
     * @return list<array{location: string, context: string}>
     */
    private function controllerResponseGetMessageSites(): array
    {
        $controllerFiles = glob(dirname(__DIR__, 2) . '/src/controllers/*.php') ?: [];
        $sites = [];

        foreach ($controllerFiles as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            preg_match_all('/\$[a-zA-Z_]\w*->getMessage\(\)/', $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$match, $offset]) {
                if ($this->isLogBoundGetMessage($source, $offset)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $sites[] = [
                    'location' => basename($path) . ':' . $line,
                    'context' => substr(
                        $source,
                        max(0, $offset - 180),
                        strlen($match) + 360,
                    ),
                ];
            }
        }

        self::assertNotEmpty($sites, 'Controller response-bound getMessage() sites should be enumerated.');

        return $sites;
    }

    private function isLogBoundGetMessage(string $source, int $offset): bool
    {
        $beforeOffset = substr($source, 0, $offset);

        preg_match_all('/\$this->log[A-Z][A-Za-z]*\(/', $beforeOffset, $matches, PREG_OFFSET_CAPTURE);
        $lastMatch = end($matches[0]);
        $lastLog = $lastMatch === false ? false : $lastMatch[1];

        if ($lastLog === false) {
            return false;
        }

        $lastStatementEnd = strrpos($beforeOffset, "]);");

        return $lastStatementEnd === false || $lastLog > $lastStatementEnd;
    }
}
