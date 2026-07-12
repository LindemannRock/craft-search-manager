<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\helpers\ElementHelper;

/**
 * Collects AutoTransformer searchable content, field mirrors, and rich text sources.
 *
 * @since 5.53.0
 */
class SearchAutoContentHelper
{
    public function __construct(
        private readonly NativeFieldKeywordHelper $nativeFieldKeywordHelper,
        private readonly SearchFieldTypeContentHelper $fieldTypeContentHelper,
    ) {
    }

    /**
     * @return array{parts: array<int, mixed>, fields: array<string, string>, richText: array<int, string>, richTextSources: list<array{handle: string, html: string}>, bodyClean: string}
     */
    public function collect(ElementInterface $element): array
    {
        $searchableContent = [];
        $richTextContent = [];
        $richTextSources = [];
        $bodyCleanParts = [];
        $fields = [];

        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
            if ($element instanceof Asset && $attribute === 'filename') {
                continue;
            }

            try {
                $value = $element->getSearchKeywords($attribute);
                if (!empty($value)) {
                    $searchableContent[] = $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($element->getFieldLayout()) {
            foreach ($element->getFieldLayout()->getCustomFields() as $field) {
                try {
                    if (!($field instanceof Field)) {
                        continue;
                    }

                    if (!$field->searchable) {
                        continue;
                    }

                    $isRichTextField = $this->fieldTypeContentHelper->isRichTextField($field);
                    $isBodyFieldHandle = $this->isBodyFieldHandle($field->handle);
                    $isMatrixField = is_a($field, 'craft\fields\Matrix');

                    if ($isRichTextField || $isMatrixField) {
                        $fieldValue = $element->getFieldValue($field->handle);

                        if ($fieldValue === null || $fieldValue === '' || $fieldValue === []) {
                            continue;
                        }

                        if ($isRichTextField) {
                            $rawHtml = (string)$fieldValue;
                            if (!empty($rawHtml)) {
                                $richTextContent[] = $rawHtml;
                                $richTextSources[] = [
                                    'handle' => $field->handle,
                                    'html' => $rawHtml,
                                ];
                                $cleanBody = $this->fieldTypeContentHelper->cleanBody($rawHtml);
                                if ($cleanBody !== '') {
                                    $bodyCleanParts[] = $cleanBody;
                                }
                            }

                            $content = $this->fieldTypeContentHelper->process($field, $fieldValue, $element);
                        } else {
                            $matrixContent = $this->matrixContent($field->handle, $fieldValue);
                            $content = $matrixContent['content'];
                            if ($matrixContent['richTextSources'] === [] && $this->nativeFieldKeywordHelper->supports($field)) {
                                $content = $this->nativeFieldKeywordHelper->getSearchKeywords($field, $element);
                            }
                            $richTextContent = array_merge($richTextContent, $matrixContent['richText']);
                            $richTextSources = array_merge($richTextSources, $matrixContent['richTextSources']);
                            $bodyCleanParts = array_merge($bodyCleanParts, $matrixContent['bodyCleanParts']);
                        }
                    } elseif ($this->nativeFieldKeywordHelper->supports($field)) {
                        $content = $this->nativeFieldKeywordHelper->getSearchKeywords($field, $element);
                        if ($isBodyFieldHandle && is_scalar($content)) {
                            $cleanBody = $this->fieldTypeContentHelper->cleanBody((string)$content);
                            if ($cleanBody !== '') {
                                $bodyCleanParts[] = $cleanBody;
                            }
                        }
                    } else {
                        $fieldValue = $element->getFieldValue($field->handle);

                        if ($fieldValue === null || $fieldValue === '' || $fieldValue === []) {
                            continue;
                        }

                        $content = $this->fieldTypeContentHelper->process($field, $fieldValue, $element);
                        if ($isBodyFieldHandle && is_scalar($content)) {
                            $cleanBody = $this->fieldTypeContentHelper->cleanBody((string)$content);
                            if ($cleanBody !== '') {
                                $bodyCleanParts[] = $cleanBody;
                            }
                        }
                    }

                    $isBodySource = $isRichTextField || $isBodyFieldHandle;

                    if (!empty($content)) {
                        $fields[$field->handle] = is_array($content) ? implode(' ', $content) : $content;
                    }

                    if (!empty($content) && !$isBodySource) {
                        if (is_array($content)) {
                            $searchableContent = array_merge($searchableContent, $content);
                        } else {
                            $searchableContent[] = $content;
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return [
            'parts' => $searchableContent,
            'fields' => $fields,
            'richText' => $richTextContent,
            'richTextSources' => $richTextSources,
            'bodyClean' => trim((string)preg_replace('/\s+/', ' ', implode(' ', $bodyCleanParts))),
        ];
    }

    private function isBodyFieldHandle(string $handle): bool
    {
        $handle = strtolower($handle);

        return in_array($handle, [
            'body',
            'copy',
            'content',
            'maincontent',
            'articlebody',
        ], true);
    }

    /**
     * @return array{content: list<string>, richText: list<string>, richTextSources: list<array{handle: string, html: string}>, bodyCleanParts: list<string>}
     */
    private function matrixContent(string $fieldHandle, mixed $fieldValue): array
    {
        $content = [];
        $richText = [];
        $richTextSources = [];
        $bodyCleanParts = [];

        if (!is_object($fieldValue) && !is_array($fieldValue)) {
            return [
                'content' => $content,
                'richText' => $richText,
                'richTextSources' => $richTextSources,
                'bodyCleanParts' => $bodyCleanParts,
            ];
        }

        if (is_object($fieldValue) && method_exists($fieldValue, 'all')) {
            $blocks = $fieldValue->all();
        } elseif (is_array($fieldValue)) {
            $blocks = $fieldValue;
        } else {
            $blocks = [];
        }

        foreach ($blocks as $block) {
            if (!is_object($block) || !method_exists($block, 'getFieldLayout') || !method_exists($block, 'getFieldValue')) {
                continue;
            }

            $fieldLayout = $block->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $blockField) {
                try {
                    $blockValue = $block->getFieldValue($blockField->handle);
                } catch (\Throwable) {
                    continue;
                }

                if (!is_string($blockValue) || $blockValue === '') {
                    continue;
                }

                if ($this->fieldTypeContentHelper->isRichTextField($blockField)) {
                    $richText[] = $blockValue;
                    $richTextSources[] = [
                        'handle' => $fieldHandle . '.' . $blockField->handle,
                        'html' => $blockValue,
                    ];
                    $cleanBody = $this->fieldTypeContentHelper->cleanBody($blockValue);
                    if ($cleanBody !== '') {
                        $bodyCleanParts[] = $cleanBody;
                    }

                    continue;
                }

                $clean = $this->fieldTypeContentHelper->cleanBody($blockValue);
                if ($clean !== '') {
                    $content[] = $clean;
                }
            }
        }

        return [
            'content' => $content,
            'richText' => $richText,
            'richTextSources' => $richTextSources,
            'bodyCleanParts' => $bodyCleanParts,
        ];
    }
}
