<?php

namespace lindemannrock\searchmanager\interfaces;

use craft\base\ElementInterface;

/**
 * Transformer Interface
 *
 * All transformers must implement this interface
 * Transformers convert Craft elements into searchable documents
 */
interface TransformerInterface
{
    /**
     * Transform an element into a searchable document
     *
     * @param ElementInterface $element The element to transform
     * @return array The transformed data ready for indexing
     */
    public function transform(ElementInterface $element): array;

    /**
     * Check if this transformer supports the given element
     *
     * @param ElementInterface $element The element to check
     * @return bool Whether this transformer supports the element
     */
    public function supports(ElementInterface $element): bool;
}
