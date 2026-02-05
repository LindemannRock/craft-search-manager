<?php

namespace lindemannrock\searchmanager\interfaces;

use craft\base\ElementInterface;

/**
 * Transformer Interface
 *
 * All transformers must implement this interface
 * Transformers convert Craft elements into searchable documents
 *
 * @since 5.0.0
 */
interface TransformerInterface
{
    /**
     * Transform an element into a searchable document
     *
     * @param ElementInterface $element The element to transform
     * @return array The transformed data ready for indexing
     * @since 5.0.0
     */
    public function transform(ElementInterface $element): array;

    /**
     * Check if this transformer supports the given element
     *
     * @param ElementInterface $element The element to check
     * @return bool Whether this transformer supports the element
     * @since 5.0.0
     */
    public function supports(ElementInterface $element): bool;
}
