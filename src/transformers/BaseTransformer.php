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
 */
abstract class BaseTransformer extends Component implements TransformerInterface
{
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
    // ABSTRACT METHODS (must be implemented by subclasses)
    // =========================================================================

    abstract public function transform(ElementInterface $element): array;
}
