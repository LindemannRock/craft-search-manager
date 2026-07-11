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
     * @return array{parts: array<int, mixed>, fields: array<string, string>, richText: array<int, string>, bodyClean: string}
     */
    public function collect(ElementInterface $element): array
    {
        $searchableContent = [];
        $richTextContent = [];
        $bodyCleanParts = [];
        $fields = [];

        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
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

                    if ($this->nativeFieldKeywordHelper->supports($field)) {
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

                        if ($isRichTextField) {
                            $rawHtml = (string)$fieldValue;
                            if (!empty($rawHtml)) {
                                $richTextContent[] = $rawHtml;
                                $cleanBody = $this->fieldTypeContentHelper->cleanBody($rawHtml);
                                if ($cleanBody !== '') {
                                    $bodyCleanParts[] = $cleanBody;
                                }
                            }
                        }

                        $content = $this->fieldTypeContentHelper->process($field, $fieldValue, $element);
                        if (!$isRichTextField && $isBodyFieldHandle && is_scalar($content)) {
                            $cleanBody = $this->fieldTypeContentHelper->cleanBody((string)$content);
                            if ($cleanBody !== '') {
                                $bodyCleanParts[] = $cleanBody;
                            }
                        }
                    }

                    $isBodySource = $isRichTextField || $isBodyFieldHandle;

                    if (!empty($content) && !$isBodySource) {
                        if (is_array($content)) {
                            $searchableContent = array_merge($searchableContent, $content);
                        } else {
                            $searchableContent[] = $content;
                        }
                        $fields[$field->handle] = is_array($content) ? implode(' ', $content) : $content;
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
}
