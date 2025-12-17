<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\EntryTransformer;
use yii\base\Component;

/**
 * Transformer Service
 *
 * Manages transformers and provides element-to-document transformation
 */
class TransformerService extends Component
{
    use LoggingTrait;

    private array $_transformers = [];

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
        $this->registerDefaultTransformers();
    }

    /**
     * Register default transformers
     */
    private function registerDefaultTransformers(): void
    {
        // AutoTransformer is now the default for all element types (fallback at line 99)
        // No need to register specific transformers - AutoTransformer handles everything

        // If you want element-specific defaults, register them here:
        // $this->registerTransformer(\craft\elements\Entry::class, EntryTransformer::class);
    }

    // =========================================================================
    // TRANSFORMER REGISTRATION
    // =========================================================================

    /**
     * Register a transformer for an element type
     */
    public function registerTransformer(string $elementType, string $transformerClass): void
    {
        $this->_transformers[$elementType] = $transformerClass;

        $this->logDebug('Registered transformer', [
            'elementType' => $elementType,
            'transformer' => $transformerClass,
        ]);
    }

    /**
     * Get transformer for an element
     *
     * Returns transformer in this priority:
     * 1. Index-specific transformer (if specified in index config)
     * 2. Registered transformer for element type
     * 3. AutoTransformer (fallback - uses Craft's searchable fields)
     */
    public function getTransformer(ElementInterface $element, ?string $transformerClass = null): ?TransformerInterface
    {
        // If transformer class specified, use it (empty string = null)
        if ($transformerClass && $transformerClass !== '') {
            $this->logDebug('Using transformer from index config', [
                'transformer' => $transformerClass,
                'elementType' => get_class($element),
            ]);
            return $this->createTransformer($transformerClass);
        }

        $elementClass = get_class($element);

        // Check for exact match
        if (isset($this->_transformers[$elementClass])) {
            return $this->createTransformer($this->_transformers[$elementClass]);
        }

        // Check for parent classes
        foreach ($this->_transformers as $type => $transformerClass) {
            if ($element instanceof $type) {
                return $this->createTransformer($transformerClass);
            }
        }

        // Fall back to AutoTransformer (like Bramble Search)
        // This automatically indexes Craft's searchable fields
        $this->logDebug('Using AutoTransformer for element type', [
            'elementType' => $elementClass,
        ]);

        return new AutoTransformer();
    }

    /**
     * Create transformer instance
     */
    private function createTransformer(string $transformerClass): ?TransformerInterface
    {
        try {
            $transformer = new $transformerClass();

            if (!$transformer instanceof TransformerInterface) {
                throw new \Exception("Transformer must implement TransformerInterface");
            }

            return $transformer;
        } catch (\Throwable $e) {
            $this->logError('Failed to create transformer', [
                'transformerClass' => $transformerClass,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Transform an element
     */
    public function transform(ElementInterface $element): ?array
    {
        $transformer = $this->getTransformer($element);

        if (!$transformer) {
            return null;
        }

        try {
            return $transformer->transform($element);
        } catch (\Throwable $e) {
            $this->logError('Failed to transform element', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
