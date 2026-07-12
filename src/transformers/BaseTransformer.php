<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchContentBuilderHelper;
use lindemannrock\searchmanager\helpers\SearchContentCleaner;
use lindemannrock\searchmanager\helpers\SearchDocumentDataHelper;
use lindemannrock\searchmanager\helpers\SearchElementHierarchyHelper;
use lindemannrock\searchmanager\helpers\SearchHeadingHelper;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use yii\base\Component;

/**
 * Base Transformer
 *
 * Abstract base class for all transformers
 * Provides common functionality for converting Craft elements into searchable documents
 *
 * @since 5.0.0
 */
abstract class BaseTransformer extends Component implements TransformerInterface
{
    /**
     * @var array<int> Heading levels to extract (default: H2-H4)
     */
    protected array $headingLevels = SearchHeadingHelper::DEFAULT_LEVELS;

    private ?SearchContentCleaner $contentCleaner = null;

    /**
     * Set which heading levels to extract
     *
     * @param array<int>|null $levels
     */
    public function setHeadingLevels(?array $levels): void
    {
        $this->headingLevels = SearchHeadingHelper::normalizeLevels($levels, $this->headingLevels);
    }

    /**
     * Get normalized heading levels
     *
     * @return array<int>
     */
    protected function getHeadingLevels(): array
    {
        return SearchHeadingHelper::normalizeLevels($this->headingLevels);
    }

    protected function contentCleaner(): SearchContentCleaner
    {
        if ($this->contentCleaner === null) {
            $this->contentCleaner = new SearchContentCleaner();
        }

        return $this->contentCleaner;
    }
    use LoggingTrait;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // INTERFACE IMPLEMENTATION
    // =========================================================================

    /**
     * Check if this transformer supports the given element
     */
    public function supports(ElementInterface $element): bool
    {
        $supportedType = $this->getElementType();
        return $element instanceof $supportedType;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the element type this transformer supports
     *
     * @return string Fully qualified element class name
     */
    abstract protected function getElementType(): string;

    /**
     * Get common element data (ID, title, URL, etc.)
     *
     * @param ElementInterface $element
     * @return array
     */
    protected function getCommonData(ElementInterface $element): array
    {
        return SearchDocumentDataHelper::commonData($element);
    }

    /**
     * Get source-backed hierarchy/path metadata for element kinds that have a tree context.
     *
     * @param ElementInterface $element
     * @return array<string, mixed>
     */
    protected function getHierarchyMetadata(ElementInterface $element): array
    {
        return SearchElementHierarchyHelper::metadata($element);
    }

    protected function resolveDocumentType(ElementInterface $element): string
    {
        return SearchDocumentDataHelper::documentType($element);
    }

    /**
     * Strip HTML tags and clean text for indexing
     *
     * @param string|null $html
     * @return string
     */
    protected function stripHtml(?string $html): string
    {
        return $this->contentCleaner()->stripHtml($html);
    }

    /**
     * Get excerpt from content
     *
     * @param string $content
     * @param int $length Maximum length in characters
     * @return string
     */
    protected function getExcerpt(string $content, int $length = 200): string
    {
        return SearchContentBuilderHelper::excerpt($content, $length);
    }

    // =========================================================================
    // HEADING EXTRACTION
    // =========================================================================

    /**
     * Extract headings from HTML or markdown content
     *
     * Parses H2, H3, H4 tags from HTML and returns structured heading data.
     * Also detects markdown-style headings (## / ### / ####).
     * Includes a description snippet from the paragraph text following each heading.
     *
     * @param string $content HTML or markdown content
     * @return array Array of ['text' => string, 'id' => string, 'level' => int, 'description' => string]
     */
    protected function extractHeadings(string $content): array
    {
        return SearchHeadingHelper::extract($content, $this->getHeadingLevels());
    }

    // =========================================================================
    // ABSTRACT METHODS (must be implemented by subclasses)
    // =========================================================================

    abstract public function transform(ElementInterface $element): array;
}
