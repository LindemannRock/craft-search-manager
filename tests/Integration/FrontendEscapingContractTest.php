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
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for the display/UX audit batch (#414, #417, #418,
 * #420, #422, and #424).
 *
 * @since 5.54.0
 */
final class FrontendEscapingContractTest extends TestCase
{
    public function testExecutableTwigJavascriptValuesUseJsonEncoding(): void
    {
        $templateRoot = dirname(__DIR__, 2) . '/src/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateRoot));
        $unsafeOccurrences = [];
        $javascriptTemplates = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (!preg_match('/{%\s*js\b|<script\b/i', $source)) {
                continue;
            }

            $relativePath = 'src/templates/' . substr($file->getPathname(), strlen($templateRoot) + 1);
            $javascriptTemplates[] = $relativePath;
            foreach ([
                '/{%\s*js\b[^%]*%}(.*?){%\s*endjs\s*%}/si',
                '/<script\b[^>]*>(.*?)<\/script>/si',
            ] as $contextPattern) {
                preg_match_all($contextPattern, $source, $contexts, PREG_OFFSET_CAPTURE);
                foreach ($contexts[1] as [$context, $contextOffset]) {
                    preg_match_all('/{{.*?}}/s', $context, $outputs, PREG_OFFSET_CAPTURE);
                    foreach ($outputs[0] as [$output, $outputOffset]) {
                        if (!preg_match('/\|\s*json_encode\s*\|\s*raw\b/', $output)) {
                            $line = substr_count(substr($source, 0, $contextOffset + $outputOffset), "\n") + 1;
                            $unsafeOccurrences[] = $relativePath . ':' . $line . ' ' . trim($output);
                        }
                    }
                }
            }
        }

        sort($javascriptTemplates);
        self::assertNotEmpty($javascriptTemplates);
        self::assertSame([], $unsafeOccurrences);

        $hostileValue = "Can't close \"the string\"\n</script><script>alert(1)</script>";
        $encoded = Craft::$app->getView()->renderString(
            '{{ value|json_encode|raw }}',
            ['value' => $hostileValue],
        );
        self::assertSame($hostileValue, json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('</script>', $encoded);
    }
}
