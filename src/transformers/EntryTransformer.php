<?php

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use craft\elements\Entry;

/**
 * Entry Transformer
 *
 * Default transformer for Craft entries
 * Can be extended or overridden in project-specific transformers
 *
 * @since 5.0.0
 */
class EntryTransformer extends BaseTransformer
{
    // =========================================================================
    // ELEMENT TYPE
    // =========================================================================

    protected function getElementType(): string
    {
        return Entry::class;
    }

    // =========================================================================
    // TRANSFORMATION
    // =========================================================================

    /**
     * Transform an entry into a searchable document
     *
     * @param ElementInterface|Entry $element
     * @return array
     * @since 5.0.0
     */
    public function transform(ElementInterface $element): array
    {
        // Start with common data
        $data = $this->getCommonData($element);

        // Ensure this is actually an Entry
        if (!($element instanceof \craft\elements\Entry)) {
            return $data;
        }

        // Add entry-specific fields
        $data['type'] = 'entry';
        $data['section'] = $element->section->handle ?? null;
        $data['sectionName'] = $element->section->name ?? null;
        $data['entryType'] = $element->type->handle ?? null;
        $data['slug'] = $element->slug;
        $data['postDate'] = $element->postDate?->getTimestamp();
        $data['expiryDate'] = $element->expiryDate?->getTimestamp();

        // Author information
        if ($element->author) {
            $data['authorId'] = $element->author->id;
            $data['authorName'] = $element->author->fullName ?? $element->author->username;
        }

        // Get searchable content from common fields
        $searchableContent = [];

        // Title
        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        // Check for common content fields (extend this based on your needs)
        $commonContentFields = ['body', 'content', 'description', 'summary', 'intro'];

        foreach ($commonContentFields as $fieldHandle) {
            try {
                if (isset($element->$fieldHandle) && $element->$fieldHandle) {
                    $content = (string)$element->$fieldHandle;
                    $searchableContent[] = $this->stripHtml($content);
                }
            } catch (\Throwable $e) {
                // Field doesn't exist or can't be accessed, skip it
                continue;
            }
        }

        // Combine all searchable content
        $data['content'] = implode(' ', $searchableContent);
        $data['excerpt'] = $this->getExcerpt($data['content'], 200);

        // Categories
        try {
            $categories = $element->getFieldValue('categories');
            if ($categories) {
                $data['categories'] = [];
                foreach ($categories->all() as $category) {
                    $data['categories'][] = $category->title;
                }
            }
        } catch (\Throwable $e) {
            // No categories field
        }

        // Tags
        try {
            $tags = $element->getFieldValue('tags');
            if ($tags) {
                $data['tags'] = [];
                foreach ($tags->all() as $tag) {
                    $data['tags'][] = $tag->title;
                }
            }
        } catch (\Throwable $e) {
            // No tags field
        }

        // Featured image
        try {
            $featuredImage = $element->getFieldValue('featuredImage');
            if ($featuredImage && $featuredImage->one()) {
                $image = $featuredImage->one();
                $data['featuredImage'] = $image->getUrl();
                $data['featuredImageAlt'] = $image->title;
            }
        } catch (\Throwable $e) {
            // No featured image field
        }

        return $data;
    }
}
