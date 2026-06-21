<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins backend delete i18n handling for audit #131.
 */
#[CoversClass(BackendsController::class)]
final class BackendsControllerDeleteI18nTest extends TestCase
{
    public function testDeleteFailureUsesStaticTranslationKeyAndPreservesModelErrorDetail(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/controllers/BackendsController.php';
        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        preg_match(
            '/public function actionDelete\(\): Response\s+\{(?<body>.*?)(?:\n    \}|\n    public function )/s',
            $source,
            $matches,
        );
        $body = $matches['body'] ?? '';

        self::assertNotSame('', $body, 'actionDelete body should be captured.');
        self::assertStringNotContainsString("Craft::t('search-manager', \$errorMessage)", $body);
        self::assertStringContainsString("Craft::t('search-manager', 'Could not delete backend')", $body);
        self::assertStringContainsString('$errors = $backend->getFirstErrors();', $body);
        self::assertStringContainsString('$error .= \': \' . reset($errors);', $body);
    }
}
