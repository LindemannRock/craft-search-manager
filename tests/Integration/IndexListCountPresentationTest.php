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
use craft\elements\Entry;
use craft\elements\User;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Rendered-list coverage for comparable index counts and backend documents.
 *
 * @since 5.54.0
 */
final class IndexListCountPresentationTest extends TestCase
{
    public function testPageOnlyListOmitsDocumentsAndPreservesHealthPresentation(): void
    {
        $html = $this->renderIndexList([
            $this->index(101, 'equal-page', 12_345, 12_345, 12_345),
            $this->index(102, 'missing-page', 2_000, 1_500, 1_500),
            $this->index(103, 'stale-page', 1_000, 1_250, 1_250),
        ]);
        $xpath = $this->xpath($html);

        self::assertSame(0, $xpath->query('//th[@data-column="documents"]')->count());
        self::assertSame(0, $xpath->query('//td[@data-column="documents"]')->count());
        self::assertNodeText('12,345', $xpath, '//tr[@data-id="101"]/td[@data-column="craft"]');
        self::assertNodeText('12,345', $xpath, '//tr[@data-id="101"]/td[@data-column="indexed"]/span[contains(@class, "lr-text-green")]');
        self::assertNodeText('1,500', $xpath, '//tr[@data-id="102"]/td[@data-column="indexed"]/span[contains(@class, "lr-text-amber") and @title="500 missing"]');
        self::assertNodeText('1,250', $xpath, '//tr[@data-id="103"]/td[@data-column="indexed"]/span[contains(@class, "lr-text-red") and @title="250 stale"]');
    }

    public function testMixedListShowsOneDocumentsColumnAndSeparatesSplitCountUnits(): void
    {
        $html = $this->renderIndexList([
            $this->index(201, 'page', 50, 50, 50),
            $this->index(202, 'split', 29, 29, 286, true),
        ]);
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//th[@data-column="documents"]')->count());
        self::assertNodeText('Documents', $xpath, '//th[@data-column="documents"]');
        self::assertSame(2, $xpath->query('//td[@data-column="documents"]')->count());
        self::assertNodeText('50', $xpath, '//tr[@data-id="201"]/td[@data-column="indexed"]/span[contains(@class, "lr-text-green")]');
        self::assertNodeText('50', $xpath, '//tr[@data-id="201"]/td[@data-column="documents"]');
        self::assertNodeText('29', $xpath, '//tr[@data-id="202"]/td[@data-column="indexed"]/span[contains(@class, "lr-text-green")]');
        self::assertNodeText('286', $xpath, '//tr[@data-id="202"]/td[@data-column="documents"]');
    }

    public function testUnavailableSplitComparisonIsNeutralAndNeverUsesDocumentTotal(): void
    {
        $html = $this->renderIndexList([
            $this->index(301, 'unavailable-split', 24, null, 251, true),
        ]);
        $xpath = $this->xpath($html);

        $indexed = $xpath->query('//tr[@data-id="301"]/td[@data-column="indexed"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $indexed);
        self::assertSame('—', trim($indexed->textContent));
        self::assertStringNotContainsString('251', $indexed->textContent);
        self::assertSame(1, $xpath->query('//tr[@data-id="301"]/td[@data-column="indexed"]/span[contains(@class, "light") and not(@title)]')->count());
        self::assertNodeText('251', $xpath, '//tr[@data-id="301"]/td[@data-column="documents"]');
    }

    public function testListPresentationDoesNotTransformSplitOrWalkTheCorpus(): void
    {
        $template = $this->readPluginFile('src/templates/indices/index.twig');
        $controller = $this->methodSource(\lindemannrock\searchmanager\controllers\IndicesController::class, 'actionIndex');
        $presentationSource = $template . "\n" . $controller;

        self::assertStringContainsString('hasSplitSectionIndices', $template);
        self::assertStringContainsString('array_slice($indices, $offset, $limit)', $controller);
        self::assertStringNotContainsString('transform(', $presentationSource);
        self::assertStringNotContainsString('SplitSectionDocumentHelper', $presentationSource);
        self::assertStringNotContainsString('SectionSplitter', $presentationSource);
        self::assertStringNotContainsString('->each(', $presentationSource);
    }

    /**
     * @param list<IndexListCountModel> $indices
     */
    private function renderIndexList(array $indices): string
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $currentUser = new User();

        $catalogue = [];
        foreach ($indices as $index) {
            $catalogue[$index->handle] = [
                'displayName' => $index->name,
                'status' => [
                    'label' => 'Enabled',
                    'value' => 'enabled',
                    'colorSet' => 'status',
                    'title' => null,
                ],
                'actions' => [
                    'targetedRebuild' => ['allowed' => true, 'reason' => null],
                    'syncCount' => ['visible' => true, 'allowed' => true, 'reason' => null],
                    'clearData' => ['allowed' => true, 'reason' => null],
                    'clearCache' => ['allowed' => true, 'reason' => null],
                    'delete' => ['allowed' => true, 'reason' => null],
                ],
            ];
        }

        $originalRequest = Craft::$app->getRequest();
        $originalResponse = Craft::$app->getResponse();
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            return Craft::$app->getView()->renderTemplate(
                'search-manager/indices/index',
                [
                    'indices' => $indices,
                    'indexCatalogue' => $catalogue,
                    'collisionHandles' => [],
                    'statusFilter' => 'all',
                    'sourceFilter' => 'all',
                    'backendFilter' => 'all',
                    'search' => '',
                    'sort' => 'source',
                    'dir' => 'asc',
                    'page' => 1,
                    'limit' => 50,
                    'totalCount' => count($indices),
                    'canCreate' => false,
                    'canEdit' => false,
                    'canDelete' => false,
                    'canRebuild' => false,
                    'canClear' => false,
                    'canClearCache' => false,
                    'canManageBackends' => false,
                    'elementTypeLabels' => [Entry::class => 'Entry'],
                    'currentUser' => $currentUser,
                ],
                View::TEMPLATE_MODE_CP,
            );
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
            $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
        }
    }

    private function index(
        int $id,
        string $handle,
        int $expectedCount,
        ?int $comparisonCount,
        int $documentCount,
        bool $splitSections = false,
    ): IndexListCountModel {
        $index = new IndexListCountModel();
        $index->id = $id;
        $index->name = $handle;
        $index->handle = $handle;
        $index->elementType = Entry::class;
        $index->source = 'database';
        $index->expectedCountValue = $expectedCount;
        $index->comparisonCountValue = $comparisonCount;
        $index->documentCount = $documentCount;
        $index->splitSections = $splitSections;

        return $index;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }

    private static function assertNodeText(string $expected, \DOMXPath $xpath, string $expression): void
    {
        $nodes = $xpath->query($expression);
        self::assertSame(1, $nodes->count(), $expression);
        self::assertSame($expected, trim($nodes->item(0)?->textContent ?? ''));
    }

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $filename = $reflection->getFileName();
        self::assertIsString($filename);
        $lines = file($filename);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}

/**
 * Fixed-count model used to render the index listing without backend work.
 *
 * @since 5.54.0
 */
final class IndexListCountModel extends SearchIndex
{
    public int $expectedCountValue = 0;
    public ?int $comparisonCountValue = null;

    public function getExpectedCount(): int
    {
        return $this->expectedCountValue;
    }

    public function getComparisonCount(): ?int
    {
        return $this->comparisonCountValue;
    }

    public function usesSplitSections(): bool
    {
        return $this->splitSections;
    }

    public function getEffectiveBackendType(): ?string
    {
        return null;
    }

    public function getConfiguredBackend(): ?ConfiguredBackend
    {
        return null;
    }
}
