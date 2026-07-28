<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\helpers\ElementHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Collects AutoTransformer searchable content, field mirrors, and rich text sources.
 *
 * @since 5.53.0
 */
class SearchAutoContentHelper
{
    use LoggingTrait;

    public function __construct(
        private readonly NativeFieldKeywordHelper $nativeFieldKeywordHelper,
        private readonly SearchFieldTypeContentHelper $fieldTypeContentHelper,
    ) {
        $this->setLoggingHandle('search-manager');
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
            } catch (\Throwable $e) {
                $this->logExtractionWarning(
                    $element,
                    'searchable-attribute',
                    $e,
                    attribute: $attribute,
                );
                continue;
            }
        }

        if ($element->getFieldLayout()) {
            foreach ($element->getFieldLayout()->getCustomFields() as $field) {
                $content = null;
                $boundary = 'field-materialization';

                try {
                    if (!($field instanceof Field)) {
                        continue;
                    }

                    if (!$field->searchable) {
                        continue;
                    }

                    $isRichTextField = $this->fieldTypeContentHelper->isRichTextField($field);
                    $isBodyFieldHandle = $this->isBodyFieldHandle($field->handle);
                    $isElementContainerField = $field instanceof ElementContainerFieldInterface;

                    if ($isRichTextField || $isElementContainerField) {
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
                        }

                        if ($isElementContainerField) {
                            $boundary = 'container-materialization';
                            $containerContent = $this->containerContent($field, $field->handle, $fieldValue);
                            $content = array_merge(
                                isset($content) ? (array)$content : [],
                                $containerContent['content'],
                            );
                            if ($content === [] && $this->nativeFieldKeywordHelper->supports($field)) {
                                $content = $this->nativeFieldKeywordHelper->getSearchKeywords($field, $element);
                            }
                            $richTextContent = array_merge($richTextContent, $containerContent['richText']);
                            $richTextSources = array_merge($richTextSources, $containerContent['richTextSources']);
                            $bodyCleanParts = array_merge($bodyCleanParts, $containerContent['bodyCleanParts']);
                            if ($isRichTextField && $containerContent['content'] !== []) {
                                $bodyCleanParts[] = implode(' ', $containerContent['content']);
                            }
                        }
                        $boundary = 'field-materialization';
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
                } catch (\Throwable $e) {
                    $this->logExtractionWarning(
                        $element,
                        $boundary,
                        $e,
                        field: $field,
                    );
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
    private function containerContent(
        Field & ElementContainerFieldInterface $field,
        string $fieldHandle,
        mixed $fieldValue,
    ): array {
        if ($field instanceof Matrix) {
            return $this->nestedElementsContent(
                $fieldHandle,
                $this->matrixEntries($fieldValue),
            );
        }

        if ($field instanceof ContentBlock) {
            return $this->nestedElementsContent(
                $fieldHandle,
                $fieldValue instanceof ElementInterface ? [$fieldValue] : [],
            );
        }

        if (is_a($field, 'craft\ckeditor\Field')) {
            return $this->nestedElementsContent(
                $fieldHandle,
                $this->ckeditorEntries($field, $fieldValue),
            );
        }

        return $this->emptyNestedContent();
    }

    /**
     * @return list<ElementInterface>
     */
    private function matrixEntries(mixed $fieldValue): array
    {
        if (is_object($fieldValue) && method_exists($fieldValue, 'all')) {
            $fieldValue = $fieldValue->all();
        }

        if (!is_array($fieldValue)) {
            return [];
        }

        return array_values(array_filter(
            $fieldValue,
            static fn(mixed $entry): bool => $entry instanceof ElementInterface,
        ));
    }

    /**
     * @return list<ElementInterface>
     */
    private function ckeditorEntries(Field $field, mixed $fieldValue): array
    {
        if (
            !method_exists($field, 'getEntryTypes')
            || $field->getEntryTypes() === []
            || !is_object($fieldValue)
            || !method_exists($fieldValue, 'getChunks')
        ) {
            return [];
        }

        $entries = [];
        foreach ($fieldValue->getChunks(false) as $chunk) {
            if (
                !is_a($chunk, 'craft\ckeditor\data\Entry')
                || !method_exists($chunk, 'getEntry')
            ) {
                continue;
            }

            $entry = $chunk->getEntry();
            if ($entry instanceof ElementInterface) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<ElementInterface> $nestedElements
     * @return array{content: list<string>, richText: list<string>, richTextSources: list<array{handle: string, html: string}>, bodyCleanParts: list<string>}
     */
    private function nestedElementsContent(string $fieldHandle, array $nestedElements): array
    {
        $result = $this->emptyNestedContent();

        foreach ($nestedElements as $nestedElement) {
            if ($nestedElement->title) {
                $result['content'][] = (string)$nestedElement->title;
            }

            $fieldLayout = $nestedElement->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $nestedField) {
                if (
                    !$nestedField instanceof Field
                    || !$nestedField->searchable
                    || $nestedField->handle === null
                    || $nestedField->handle === ''
                ) {
                    continue;
                }

                $nestedHandle = $fieldHandle . '.' . $nestedField->handle;
                $boundary = 'nested-field-value';
                try {
                    $nestedValue = $nestedElement->getFieldValue($nestedField->handle);

                    if ($nestedValue === null || $nestedValue === '' || $nestedValue === []) {
                        continue;
                    }

                    $boundary = 'nested-field-content';
                    if ($this->fieldTypeContentHelper->isRichTextField($nestedField)) {
                        $rawHtml = (string)$nestedValue;
                        if ($rawHtml !== '') {
                            $result['richText'][] = $rawHtml;
                            $result['richTextSources'][] = [
                                'handle' => $nestedHandle,
                                'html' => $rawHtml,
                            ];
                            $cleanBody = $this->fieldTypeContentHelper->cleanBody($rawHtml);
                            if ($cleanBody !== '') {
                                $result['bodyCleanParts'][] = $cleanBody;
                            }

                            $cleanContent = $this->fieldTypeContentHelper->process(
                                $nestedField,
                                $nestedValue,
                                $nestedElement,
                            );
                            if (is_string($cleanContent) && $cleanContent !== '') {
                                $result['content'][] = $cleanContent;
                            }
                        }
                    }

                    if ($nestedField instanceof ElementContainerFieldInterface) {
                        $boundary = 'nested-container-materialization';
                        $child = $this->containerContent($nestedField, $nestedHandle, $nestedValue);
                        $result = $this->mergeNestedContent($result, $child);

                        continue;
                    }

                    if ($this->fieldTypeContentHelper->isRichTextField($nestedField)) {
                        continue;
                    }

                    $boundary = 'nested-field-keywords';
                    $keywords = trim((string)preg_replace(
                        '/\s+/',
                        ' ',
                        $nestedField->getSearchKeywords($nestedValue, $nestedElement),
                    ));
                } catch (\Throwable $e) {
                    $this->logExtractionWarning(
                        $nestedElement,
                        $boundary,
                        $e,
                        field: $nestedField,
                        fieldHandle: $nestedHandle,
                    );
                    continue;
                }

                if ($keywords !== '') {
                    $result['content'][] = $this->fieldTypeContentHelper->cleanBody($keywords);
                }
            }
        }

        $result['content'] = array_values(array_filter($result['content']));

        return $result;
    }

    /**
     * @param array{content: list<string>, richText: list<string>, richTextSources: list<array{handle: string, html: string}>, bodyCleanParts: list<string>} $left
     * @param array{content: list<string>, richText: list<string>, richTextSources: list<array{handle: string, html: string}>, bodyCleanParts: list<string>} $right
     * @return array{content: list<string>, richText: list<string>, richTextSources: list<array{handle: string, html: string}>, bodyCleanParts: list<string>}
     */
    private function mergeNestedContent(array $left, array $right): array
    {
        return [
            'content' => array_merge($left['content'], $right['content']),
            'richText' => array_merge($left['richText'], $right['richText']),
            'richTextSources' => array_merge($left['richTextSources'], $right['richTextSources']),
            'bodyCleanParts' => array_merge($left['bodyCleanParts'], $right['bodyCleanParts']),
        ];
    }

    /**
     * @return array{content: list<string>, richText: list<string>, richTextSources: list<array{handle: string, html: string}>, bodyCleanParts: list<string>}
     */
    private function emptyNestedContent(): array
    {
        return [
            'content' => [],
            'richText' => [],
            'richTextSources' => [],
            'bodyCleanParts' => [],
        ];
    }

    private function logExtractionWarning(
        ElementInterface $element,
        string $boundary,
        \Throwable $exception,
        ?string $attribute = null,
        ?Field $field = null,
        ?string $fieldHandle = null,
    ): void {
        $context = [
            'elementId' => $element->id !== null ? (int)$element->id : null,
            'elementType' => get_class($element),
            'boundary' => $boundary,
        ];
        if ($attribute !== null) {
            $context['attribute'] = $attribute;
        }
        if ($field !== null) {
            $context['fieldHandle'] = $fieldHandle ?? $field->handle;
            $context['fieldClass'] = get_class($field);
        }
        $context['exceptionClass'] = get_class($exception);

        $this->logWarning('Automatic search content extraction skipped', $context);
    }
}
