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
 * Regression coverage for audit Batch 5 findings.
 *
 * @since 5.53.0
 */
final class IndexingMutexContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', 'audit-batch-5-'])
            ->execute();

        parent::tearDown();
    }

    public function testIndexDocumentWithResultUsesPerDocumentMutexAroundReplacement(): void
    {
        $source = $this->readPluginSource('src/search/SearchEngine.php');
        $body = $this->methodBody($source, 'indexDocumentWithKeyResult');

        self::assertStringContainsString('$lockName = $this->indexDocumentLockName($siteId, $documentKey);', $body);
        self::assertStringContainsString('getMutex()->acquire($lockName, 30)', $body);
        self::assertStringContainsString('finally', $body);
        self::assertStringContainsString('getMutex()->release($lockName)', $body);
        $lockPosition = strpos($body, 'getMutex()->acquire($lockName, 30)');
        $replacementPosition = strpos($body, '$oldDocLength = $this->documentLength($siteId, $elementId, $documentKey);');
        self::assertIsInt($lockPosition);
        self::assertIsInt($replacementPosition);
        self::assertLessThan(
            $replacementPosition,
            $lockPosition,
            'The per-document mutex must be acquired before reading/replacing old document storage.',
        );

        $lockBody = $this->methodBody($source, 'indexDocumentLockName');
        self::assertStringContainsString('search-manager:index-document:%s:%d:%s', $lockBody);
        self::assertStringContainsString('$this->indexHandle', $lockBody);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/(?:public|private) function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
