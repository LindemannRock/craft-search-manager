<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\searchmanager\helpers\SearchCommerceDocumentHelper;

/**
 * Commerce Transformer
 *
 * Transforms Craft Commerce Product and Variant elements into storefront-friendly
 * search documents without requiring Commerce classes at load time.
 *
 * @since 5.53.0
 */
class CommerceTransformer extends AutoTransformer
{
    protected function getElementType(): string
    {
        return ElementInterface::class;
    }

    public function supports(ElementInterface $element): bool
    {
        return SearchCommerceDocumentHelper::supports($element);
    }

    public function transform(ElementInterface $element): array
    {
        $data = parent::transform($element);

        return SearchCommerceDocumentHelper::augment($element, $data);
    }
}
