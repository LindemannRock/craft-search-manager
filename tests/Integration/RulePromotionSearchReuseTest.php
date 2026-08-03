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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for audit #165 through #170.
 *
 * @since 5.53.0
 */
final class RulePromotionSearchReuseTest extends TestCase
{
    private const MARKER = 'audit-pass-29';

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['query' => self::MARKER])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['matchValue' => self::MARKER])
            ->execute();

        parent::tearDown();
    }

    public function testBackendSearchReusesMatchedQueryRules(): void
    {
        $backendSource = $this->readPluginFile('src/services/BackendService.php');
        $serviceSource = $this->readPluginFile('src/services/QueryRuleService.php');

        self::assertStringContainsString(
            'SearchManager::$plugin->queryRules->getMatchingRules($query, $indexName, $siteId)',
            $backendSource,
        );
        self::assertStringContainsString(
            'getRedirectUrl($query, $indexName, $siteId, $matchedRules)',
            $backendSource,
        );
        self::assertStringContainsString(
            'expandWithSynonyms($query, $indexName, $siteId, $matchedRules)',
            $backendSource,
        );
        self::assertStringContainsString(
            '$matchedRules',
            $this->methodSource($backendSource, 'applyBoosts('),
        );
        self::assertStringContainsString('?array $matchedRules = null', $serviceSource);
        self::assertStringContainsString('$rules = $matchedRules ?? $this->getMatchingRules', $serviceSource);
    }

    public function testBoostMetadataHasNoLiveElementFallback(): void
    {
        $source = $this->readPluginFile('src/services/QueryRuleService.php');

        self::assertStringNotContainsString('getElementById', $source);
        self::assertStringNotContainsString('Element::find()', $source);
        self::assertStringNotContainsString('preloadBoostElements', $source);
        self::assertStringContainsString("\$sectionHandle = \$result['entrySectionHandle'] ?? null;", $source);
        self::assertStringContainsString("\$categoryIds = \$result['_categoryIds'] ?? null;", $source);
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
