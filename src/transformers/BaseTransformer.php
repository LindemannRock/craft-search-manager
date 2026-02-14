<?php

namespace lindemannrock\searchmanager\transformers;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\logginglibrary\traits\LoggingTrait;
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
     *
     * @since 5.0.0
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
        return [
            'objectID' => $element->id,
            'id' => $element->id,
            'title' => $element->title ?? '',
            'url' => $element->url ?? '',
            'siteId' => $element->siteId,
            'dateCreated' => $element->dateCreated?->getTimestamp(),
            'dateUpdated' => $element->dateUpdated?->getTimestamp(),
        ];
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

        return trim($text);
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
        $content = $this->stripHtml($content);

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
