<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;

/**
 * Public hits must never ship executable URL schemes: the presenter
 * neutralizes javascript:/data:/vbscript:/file: URLs (including
 * whitespace-obfuscated variants) on hits, section URLs, and headings.
 *
 * @since 5.53.0
 */
class PublicHitUrlSchemeSanitizationTest extends IntegrationTestCase
{
    public function testDangerousHitUrlsAreNeutralized(): void
    {
        $hit = SearchHitPresenter::present([
            'elementId' => 1,
            'title' => 'Hostile',
            'url' => "java\tscript:alert(1)",
            'sectionUrl' => 'data:text/html,<script>1</script>',
            'sectionType' => 'heading',
            'headings' => [
                ['title' => 'H', 'url' => 'JAVASCRIPT:alert(2)', 'sectionUrl' => 'vbscript:x'],
            ],
        ]);

        // The security property: no dangerous URL survives, whether the
        // presenter keeps the key as '' or drops it entirely downstream.
        self::assertSame('', $hit['url'] ?? '');
        self::assertSame('', $hit['sectionUrl'] ?? '');
        self::assertSame('', $hit['headings'][0]['url'] ?? '');
        self::assertSame('', $hit['headings'][0]['sectionUrl'] ?? '');
        self::assertStringNotContainsString('script', json_encode($hit['headings']) ?: '');
    }

    public function testSafeUrlsPassThroughUnchanged(): void
    {
        $hit = SearchHitPresenter::present([
            'elementId' => 2,
            'title' => 'Safe',
            'url' => 'https://example.com/page?a=1',
            'headings' => [
                ['title' => 'H', 'url' => '/docs/page#anchor'],
            ],
        ]);

        self::assertSame('https://example.com/page?a=1', $hit['url']);
        self::assertSame('/docs/page#anchor', $hit['headings'][0]['url']);
    }
}
