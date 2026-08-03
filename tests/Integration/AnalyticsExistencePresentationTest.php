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
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Focused regressions for audit #250 and #251.
 *
 * @since 5.53.0
 */
#[CoversClass(AnalyticsController::class)]
final class AnalyticsExistencePresentationTest extends TestCase
{
    public function testAnalyticsTemplatesUseControllerProvidedExistenceFlags(): void
    {
        foreach ([
            'src/templates/analytics/index.twig',
            'src/templates/analytics/_partials/query-rules.twig',
            'src/templates/analytics/_partials/promotions.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);

            self::assertStringNotContainsString('craft.app.db', $source);
            self::assertStringNotContainsString('SELECT COUNT(*) FROM {{%searchmanager_query_rules}}', $source);
            self::assertStringNotContainsString('SELECT COUNT(*) FROM {{%searchmanager_promotions}}', $source);
        }

        self::assertStringContainsString(
            '{% if queryRulesExist %}',
            $this->readPluginFile('src/templates/analytics/index.twig'),
        );
        self::assertStringContainsString(
            '{% if not queryRulesExist %}',
            $this->readPluginFile('src/templates/analytics/_partials/query-rules.twig'),
        );
        self::assertStringContainsString(
            '{% if promotionsExist %}',
            $this->readPluginFile('src/templates/analytics/index.twig'),
        );
        self::assertStringContainsString(
            '{% if not promotionsExist %}',
            $this->readPluginFile('src/templates/analytics/_partials/promotions.twig'),
        );
    }

    public function testAnalyticsControllerPassesRuleAndPromotionExistenceFlags(): void
    {
        $source = $this->readPluginFile('src/controllers/AnalyticsController.php');
        $body = $this->methodSource($source, 'public function actionIndex');

        self::assertStringContainsString(
            '$queryRulesExist = SearchManager::$plugin->queryRules->getQueryRuleCount() > 0;',
            $body,
        );
        self::assertStringContainsString(
            '$promotionsExist = SearchManager::$plugin->promotions->getPromotionCount() > 0;',
            $body,
        );
        self::assertStringContainsString("'queryRulesExist' => \$queryRulesExist,", $body);
        self::assertStringContainsString("'promotionsExist' => \$promotionsExist,", $body);
        self::assertStringContainsString(
            "return \$this->renderTemplate('search-manager/analytics/index', [",
            $body,
        );
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        if ($contents === false) {
            self::fail('Unable to read plugin file: ' . $path);
        }

        return $contents;
    }

    private function methodSource(string $source, string $needle): string
    {
        $start = strpos($source, $needle);

        if ($start === false) {
            self::fail('Unable to find source snippet: ' . $needle);
        }

        $next = strpos($source, "\n    /**", $start + strlen($needle));

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }
}
