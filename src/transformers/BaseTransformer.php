<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\transformers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\VolumeFolder;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
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
    protected array $headingLevels = [2, 3, 4];

    /**
     * @var string[] Accumulated prose-only text from stripHtml() calls (code blocks removed).
     */
    private array $codeFreeParts = [];

    /**
     * @var string[] Accumulated full text from stripHtml() calls (code blocks included).
     * Used to compare against codeFreeParts to detect whether code was actually present.
     */
    private array $fullParts = [];

    /**
     * Set which heading levels to extract
     *
     * @param array<int>|null $levels
     */
    public function setHeadingLevels(?array $levels): void
    {
        if ($levels === null) {
            return;
        }

        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $levels),
            fn($level) => $level >= 1 && $level <= 6
        )));

        if (!empty($normalized)) {
            sort($normalized);
            $this->headingLevels = $normalized;
        }
    }

    /**
     * Get normalized heading levels
     *
     * @return array<int>
     */
    protected function getHeadingLevels(): array
    {
        return !empty($this->headingLevels) ? $this->headingLevels : [2, 3, 4];
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
        $backendId = SearchHitIdentityHelper::backendId($element->id, $element->siteId);
        $documentType = $this->resolveDocumentType($element);

        return [
            'objectID' => $element->id,
            'id' => $element->id,
            'elementId' => $element->id,
            'backendId' => $backendId,
            'type' => $documentType,
            'elementType' => $documentType,
            'title' => $this->elementTitle($element),
            'slug' => $this->elementStringValue($element, 'slug'),
            'url' => $element->url ?? '',
            'siteId' => $element->siteId,
            'dateCreated' => $element->dateCreated?->getTimestamp(),
            'dateUpdated' => $element->dateUpdated?->getTimestamp(),
        ];
    }

    /**
     * Get source-backed hierarchy/path metadata for element kinds that have a tree context.
     *
     * @param ElementInterface $element
     * @return array<string, mixed>
     */
    protected function getHierarchyMetadata(ElementInterface $element): array
    {
        if ($element instanceof Entry) {
            $section = $element->getSection();
            if ($section?->type !== Section::TYPE_STRUCTURE) {
                return [];
            }

            return $this->structuredElementHierarchyMetadata($element);
        }

        if ($element instanceof Category) {
            return $this->structuredElementHierarchyMetadata($element);
        }

        if ($element instanceof Asset) {
            return $this->assetHierarchyMetadata($element);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredElementHierarchyMetadata(ElementInterface $element): array
    {
        $level = $element->level ?? null;

        return $this->filterHierarchyMetadata([
            'ancestors' => $this->ancestorItemsFromElementSource($element->getAncestors()),
            'level' => is_numeric($level) ? (int)$level : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assetHierarchyMetadata(Asset $element): array
    {
        if ($this->elementStringValue($element, 'url') === '') {
            return [];
        }

        try {
            $rootUrl = $element->getVolume()->getRootUrl();
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($rootUrl) || trim($rootUrl) === '') {
            return [];
        }

        try {
            $folder = $element->getFolder();
        } catch (\Throwable) {
            return [];
        }

        return $this->filterHierarchyMetadata([
            'ancestors' => $this->ancestorItemsFromFolder($folder),
            'folderPath' => trim((string)$folder->path),
        ]);
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function ancestorItemsFromElementSource(ElementQueryInterface|ElementCollection $source): array
    {
        return $this->ancestorItemsFromElements($source->all());
    }

    /**
     * @param iterable<ElementInterface> $ancestors
     * @return array<int, array{id: int, title: string}>
     */
    private function ancestorItemsFromElements(iterable $ancestors): array
    {
        $items = [];

        foreach ($ancestors as $ancestor) {
            $id = $ancestor->id ?? null;
            $title = $this->elementTitle($ancestor);
            if (!is_numeric($id) || $title === '') {
                continue;
            }

            $items[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function ancestorItemsFromFolder(VolumeFolder $folder): array
    {
        $folders = [];
        for ($current = $folder; $current !== null; $current = $current->getParent()) {
            array_unshift($folders, $current);
        }

        $items = [];
        foreach ($folders as $ancestor) {
            $id = $ancestor->id ?? null;
            $title = trim((string)($ancestor->name ?? ''));
            if (!is_numeric($id) || $title === '') {
                continue;
            }

            $items[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function filterHierarchyMetadata(array $metadata): array
    {
        return array_filter($metadata, static function(mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            return !is_array($value) || $value !== [];
        });
    }

    private function elementStringValue(ElementInterface $element, string $property): string
    {
        $value = $element->{$property} ?? null;
        return is_scalar($value) ? trim((string)$value) : '';
    }

    protected function elementTitle(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\User) {
            foreach (['fullName', 'username', 'email'] as $property) {
                $value = $this->elementStringValue($element, $property);
                if ($value !== '') {
                    return $value;
                }
            }

            return $element->id !== null ? '#' . $element->id : '';
        }

        return $this->elementStringValue($element, 'title');
    }

    protected function resolveDocumentType(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\Entry) {
            return 'entry';
        }

        if (is_a($element, CommerceElementTypeHelper::productElementType())) {
            return 'product';
        }

        if (is_a($element, CommerceElementTypeHelper::variantElementType())) {
            return 'variant';
        }

        if ($element instanceof \craft\elements\Category) {
            return 'category';
        }

        if ($element instanceof \craft\elements\Asset) {
            return 'asset';
        }

        if ($element instanceof \craft\elements\User) {
            return 'user';
        }

        if (method_exists($element, 'refHandle')) {
            $refHandle = $element::refHandle();
            if (is_string($refHandle) && $refHandle !== '') {
                return $this->normalizeDocumentType($refHandle);
            }
        }

        $className = get_class($element);
        $shortName = basename(str_replace('\\', '/', $className));
        if ($shortName !== '') {
            return $this->normalizeDocumentType($shortName);
        }

        return $this->normalizeDocumentType($element::displayName());
    }

    private function normalizeDocumentType(string $value): string
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '-$0', trim($value));
        $normalized = strtolower((string)$normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);

        return trim((string)$normalized, '-') ?: 'element';
    }

    /**
     * Strip HTML tags and clean text for indexing
     *
     * @param string|null $html
     * @return string
     */
    protected function stripHtml(?string $html): string
    {
        if (!$html) {
            return '';
        }

        // Strip tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        $result = trim($text);

        // Only accumulate when <pre> blocks are present — that's the only tag
        // where stripHtmlWithoutCode() produces different output (removes code content).
        // finalizeContentClean() compares the two and stores _contentClean if they differ.
        if (stripos($html, '<pre') !== false) {
            $this->codeFreeParts[] = $this->stripHtmlWithoutCode($html);
            $this->fullParts[] = $result;
        }

        return $result;
    }

    /**
     * Strip HTML tags and clean text, removing code blocks entirely
     *
     * Unlike stripHtml() which keeps text content from code blocks,
     * this removes <pre> blocks and their contents before stripping tags,
     * producing prose-only text for snippets when showCodeSnippets is false.
     *
     * @param string|null $html Raw HTML content
     * @return string Plain text without code block content
     */
    protected function stripHtmlWithoutCode(?string $html): string
    {
        if (!$html) {
            return '';
        }

        // Remove <pre> blocks and their contents (code blocks, syntax highlighting)
        $text = (string) preg_replace('/<pre[^>]*>.*?<\/pre>/is', ' ', $html);

        // Strip remaining tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove extra whitespace
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Finalize _contentClean on transformer output
     *
     * Called automatically by TransformerService after transform().
     * Builds _contentClean from prose-only text accumulated during stripHtml() calls.
     * Only sets _contentClean when it meaningfully differs from content
     * (i.e. the source HTML actually contained code blocks).
     *
     * @param array $data Transformer output
     * @return array Data with _contentClean added if applicable
     */
    public function finalizeContentClean(array $data): array
    {
        if (!empty($this->codeFreeParts)) {
            $cleanContent = implode(' ', $this->codeFreeParts);
            $cleanContent = trim((string) preg_replace('/\s+/', ' ', $cleanContent));

            $fullContent = implode(' ', $this->fullParts);
            $fullContent = trim((string) preg_replace('/\s+/', ' ', $fullContent));

            // Only store when it differs from full content (page has code blocks)
            if (!empty($cleanContent) && $cleanContent !== $fullContent) {
                $data['_contentClean'] = $cleanContent;
            }
        }

        // Reset for next element
        $this->codeFreeParts = [];
        $this->fullParts = [];

        return $data;
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
        // Strip any residual HTML without accumulating for _contentClean.
        // All callers pass already-stripped text, but this is defensive.
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = trim((string) preg_replace('/\s+/', ' ', $content));

        if (mb_strlen($content) <= $length) {
            return $content;
        }

        return mb_substr($content, 0, $length) . '...';
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
        $headings = [];

        $allowedLevels = $this->getHeadingLevels();

        // Detect if content is HTML or markdown
        if (preg_match('/<[^>]+>/', $content)) {
            // HTML: extract <h2>, <h3>, <h4> tags with offsets for description extraction
            $pattern = '/<h([1-6])([^>]*)>(.*?)<\/h\1>/is';
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $i => $match) {
                    $level = (int) $match[1][0];
                    if (!in_array($level, $allowedLevels, true)) {
                        continue;
                    }
                    $text = strip_tags($match[3][0]);
                    // Strip leading # from permalink anchors (e.g. <a>#</a>Heading)
                    $text = ltrim($text, '#');
                    // Try to extract id attribute from tag attributes
                    $id = '';
                    if (preg_match('/id="([^"]*)"/', $match[2][0], $idMatch)) {
                        $id = $idMatch[1];
                    }
                    if (empty($id)) {
                        $id = $this->generateHeadingId($text);
                    }

                    if (!empty(trim($text))) {
                        $afterOffset = $match[0][1] + strlen($match[0][0]);
                        $nextOffset = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : null;

                        $headings[] = [
                            'text' => trim($text),
                            'id' => $id,
                            'level' => $level,
                            'description' => $this->extractDescriptionFromHtml($content, $afterOffset, $nextOffset),
                        ];
                    }
                }
            }
        } else {
            // Markdown: extract # through ###### lines, then filter by allowed levels
            $pattern = '/^(#{1,6})\s+(.+)$/m';
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $i => $match) {
                    $level = strlen($match[1][0]);
                    if (!in_array($level, $allowedLevels, true)) {
                        continue;
                    }
                    $text = trim($match[2][0]);
                    $id = $this->generateHeadingId($text);

                    if (!empty($text)) {
                        $afterOffset = $match[0][1] + strlen($match[0][0]);
                        $nextOffset = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : null;

                        $headings[] = [
                            'text' => $text,
                            'id' => $id,
                            'level' => $level,
                            'description' => $this->extractDescriptionFromMarkdown($content, $afterOffset, $nextOffset),
                        ];
                    }
                }
            }
        }

        return $headings;
    }

    /**
     * Generate a URL-safe heading ID from text
     *
     * @param string $text Heading text
     * @return string URL-safe ID
     */
    protected function generateHeadingId(string $text): string
    {
        return \craft\helpers\StringHelper::toKebabCase($text);
    }

    /**
     * Extract a description snippet from HTML content after a heading
     *
     * Looks for the first <p> tag between the heading and the next heading,
     * strips HTML, and truncates to a readable snippet.
     */
    private function extractDescriptionFromHtml(string $content, int $afterOffset, ?int $nextOffset): string
    {
        $endOffset = $nextOffset ?? strlen($content);
        $between = substr($content, $afterOffset, $endOffset - $afterOffset);

        // Try to get first paragraph
        $description = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $between, $pMatch)) {
            $description = trim(strip_tags($pMatch[1]));
        }

        // Fall back to stripping all HTML and taking text
        if (empty($description)) {
            $description = trim(strip_tags($between));
        }

        return $this->cleanDescription($description);
    }

    /**
     * Extract a description snippet from markdown content after a heading
     *
     * Takes the first non-empty paragraph lines between headings,
     * strips markdown syntax, and truncates.
     */
    private function extractDescriptionFromMarkdown(string $content, int $afterOffset, ?int $nextOffset): string
    {
        $endOffset = $nextOffset ?? strlen($content);
        $between = substr($content, $afterOffset, $endOffset - $afterOffset);

        // Remove code blocks
        $between = (string) preg_replace('/```[\s\S]*?```/', '', $between);

        // Remove markdown links but keep text
        $between = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $between);

        // Remove emphasis markers
        $between = (string) preg_replace('/[*_]{1,2}([^*_]+)[*_]{1,2}/', '$1', $between);

        // Remove inline code backticks
        $between = (string) preg_replace('/`([^`]+)`/', '$1', $between);

        // Remove list markers
        $between = (string) preg_replace('/^\s*[-*+]\s+/m', '', $between);
        $between = (string) preg_replace('/^\s*\d+\.\s+/m', '', $between);

        // Take first non-empty lines as the description
        $lines = array_filter(array_map('trim', explode("\n", $between)));
        $description = implode(' ', array_slice($lines, 0, 2));

        return $this->cleanDescription($description);
    }

    /**
     * Clean whitespace from a description string
     *
     * No truncation — visual line clamping (resultDescLines) handles display length,
     * consistent with how parent result descriptions work.
     */
    private function cleanDescription(string $description): string
    {
        $description = (string) preg_replace('/\s+/', ' ', trim($description));

        return $description;
    }

    // =========================================================================
    // ABSTRACT METHODS (must be implemented by subclasses)
    // =========================================================================

    abstract public function transform(ElementInterface $element): array;
}
