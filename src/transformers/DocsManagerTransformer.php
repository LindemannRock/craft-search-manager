<?php

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\records\SourceRecord;

/**
 * Docs Manager Transformer
 *
 * Transforms SourceDoc elements into searchable documents,
 * including the full HTML content, headings, and keywords.
 *
 * @since 5.39.0
 */
class DocsManagerTransformer extends BaseTransformer
{
    /**
     * @var array<int, string> Cached source names by ID (avoids repeated queries during batch indexing)
     */
    private static array $_sourceNames = [];

    protected function getElementType(): string
    {
        return SourceDoc::class;
    }

    /**
     * Transform a source doc page into a searchable document
     *
     * @param ElementInterface|SourceDoc $element
     * @return array
     */
    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        if (!($element instanceof SourceDoc)) {
            return $data;
        }

        $data['type'] = 'sourceDoc';
        $data['section'] = $this->getSourceName($element->sourceId);
        $data['slug'] = $element->slug;
        $data['category'] = $element->category;
        $data['description'] = $element->description ?? '';
        $data['sourceId'] = $element->sourceId;

        // Collect searchable content
        $searchableContent = [];

        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        if ($element->description) {
            $searchableContent[] = $element->description;
        }

        // Index the actual page content (the whole point)
        if ($element->htmlContent) {
            $searchableContent[] = $this->stripHtml($element->htmlContent);
        }

        // Extract headings for boosting and hierarchical display
        // Always use BaseTransformer::extractHeadings() which respects index headingLevels
        $headings = [];
        if (!empty($element->htmlContent)) {
            $headings = $this->extractHeadings($element->htmlContent);
        }

        if (!empty($headings)) {
            $headingTexts = array_column($headings, 'text');
            $headingTexts = array_filter($headingTexts);
            if (!empty($headingTexts)) {
                $data['headings'] = implode(' ', $headingTexts);
                $searchableContent[] = $data['headings'];
            }

            // Keep raw headings for hierarchical display in frontend
            $data['_headings'] = array_map(function($h) {
                $text = $h['text'] ?? '';
                $id = $h['id'] ?? ($h['anchor'] ?? '');
                if (empty($id) && !empty($text)) {
                    $id = $this->generateHeadingId($text);
                }
                return [
                    'text' => $text,
                    'id' => $id,
                    'level' => $h['level'] ?? 2,
                    'description' => $h['description'] ?? '',
                ];
            }, $headings);
        }

        // Index extracted keywords
        if (!empty($element->keywords)) {
            $data['keywords'] = implode(' ', $element->keywords);
            $searchableContent[] = $data['keywords'];
        }

        $data['content'] = implode(' ', array_filter($searchableContent));
        $data['excerpt'] = $this->getExcerpt($data['content'], 200);

        return $data;
    }

    /**
     * Get source display name with static caching
     */
    private function getSourceName(?int $sourceId): string
    {
        if (!$sourceId) {
            return 'Docs';
        }

        if (!isset(self::$_sourceNames[$sourceId])) {
            self::$_sourceNames[$sourceId] = SourceRecord::findOne($sourceId)?->name ?? 'Docs';
        }

        return self::$_sourceNames[$sourceId];
    }
}
