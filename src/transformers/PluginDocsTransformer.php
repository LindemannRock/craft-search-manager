<?php

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\plugindocs\elements\PluginDoc;
use lindemannrock\plugindocs\records\PluginRecord;

/**
 * Plugin Doc Page Transformer
 *
 * Transforms PluginDoc elements into searchable documents,
 * including the full HTML content, headings, and keywords.
 *
 * @since 5.39.0
 */
class PluginDocsTransformer extends BaseTransformer
{
    /**
     * @var array<int, string> Cached plugin names by ID (avoids repeated queries during batch indexing)
     */
    private static array $_pluginNames = [];

    protected function getElementType(): string
    {
        return PluginDoc::class;
    }

    /**
     * Transform a plugin doc page into a searchable document
     *
     * @param ElementInterface|PluginDoc $element
     * @return array
     * @since 5.39.0
     */
    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        if (!($element instanceof PluginDoc)) {
            return $data;
        }

        $data['type'] = 'pluginDoc';
        $data['section'] = $this->getPluginName($element->pluginId);
        $data['slug'] = $element->slug;
        $data['category'] = $element->category;
        $data['description'] = $element->description ?? '';
        $data['pluginId'] = $element->pluginId;

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
     * Get plugin display name with static caching
     */
    private function getPluginName(?int $pluginId): string
    {
        if (!$pluginId) {
            return 'Docs';
        }

        if (!isset(self::$_pluginNames[$pluginId])) {
            self::$_pluginNames[$pluginId] = PluginRecord::findOne($pluginId)?->name ?? 'Docs';
        }

        return self::$_pluginNames[$pluginId];
    }
}
