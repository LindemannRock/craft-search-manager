<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\events\TransformEvent;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\BaseTransformer;
use lindemannrock\searchmanager\transformers\DocsManagerTransformer;
use yii\base\Component;

/**
 * Transformer Service
 *
 * Manages transformers and provides element-to-document transformation
 *
 * @since 5.0.0
 */
class TransformerService extends Component
{
    use LoggingTrait;

    /**
     * Fired before an element is transformed into a search document.
     *
     * Listeners can inspect the element and set `$event->handled = true`
     * to skip transformation entirely (the element won't be indexed).
     *
     * @since 5.39.0
     * @see TransformEvent
     */
    public const EVENT_BEFORE_TRANSFORM = 'beforeTransform';

    /**
     * Fired after an element is transformed into a search document.
     *
     * Listeners can modify [[TransformEvent::$data]] to add custom fields,
     * remove sensitive content, or enrich the document before it's indexed.
     * Especially useful with AutoTransformer where you don't control the
     * transform logic.
     *
     * @since 5.39.0
     * @see TransformEvent
     */
    public const EVENT_AFTER_TRANSFORM = 'afterTransform';

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
        // AutoTransformer is the default fallback for all element types.
        // Register element-specific transformers here for richer indexing:

        if (PluginHelper::isPluginEnabled('docs-manager')) {
            $this->registerTransformer(
                'lindemannrock\docsmanager\elements\SourceDoc',
                DocsManagerTransformer::class,
            );
        }
    }

    // =========================================================================
    // TRANSFORMER REGISTRATION
    // =========================================================================

    /**
     * Register a transformer for an element type
     *
     * @since 5.0.0
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
     *
     * @since 5.0.0
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
     * Transform an element into a search document
     *
     * Fires EVENT_BEFORE_TRANSFORM and EVENT_AFTER_TRANSFORM to allow
     * third-party plugins to modify, enrich, or skip the transformation.
     *
     * @param ElementInterface $element The element to transform
     * @param string $indexName The index handle (for event context)
     * @param string|null $transformerClass Override transformer class (from index config)
     * @param array|null $headingLevels Heading levels to extract (from index config)
     * @return array|null Transformed data, or null if skipped/failed
     * @since 5.0.0
     */
    public function transform(ElementInterface $element, string $indexName = '', ?string $transformerClass = null, ?array $headingLevels = null): ?array
    {
        $transformer = $this->getTransformer($element, $transformerClass);

        if (!$transformer) {
            return null;
        }

        // Configure heading levels if the transformer supports it
        if ($headingLevels !== null && method_exists($transformer, 'setHeadingLevels')) {
            $transformer->setHeadingLevels($headingLevels);
        }

        $resolvedClass = get_class($transformer);

        // Fire before event — allows skipping transformation
        if ($this->hasEventHandlers(self::EVENT_BEFORE_TRANSFORM)) {
            $beforeEvent = new TransformEvent([
                'element' => $element,
                'indexName' => $indexName,
                'transformerClass' => $resolvedClass,
            ]);
            $this->trigger(self::EVENT_BEFORE_TRANSFORM, $beforeEvent);

            if ($beforeEvent->handled) {
                return null;
            }
        }

        try {
            $data = $transformer->transform($element);

            // Finalize _contentClean (prose-only content for showCodeSnippets support).
            // BaseTransformer accumulates code-free text during stripHtml() calls —
            // this builds _contentClean automatically for all transformers.
            if ($transformer instanceof BaseTransformer) {
                $data = $transformer->finalizeContentClean($data);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to transform element', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Fire after event — allows enriching/modifying document data
        if ($this->hasEventHandlers(self::EVENT_AFTER_TRANSFORM)) {
            $afterEvent = new TransformEvent([
                'element' => $element,
                'indexName' => $indexName,
                'transformerClass' => $resolvedClass,
                'document' => $data,
            ]);
            $this->trigger(self::EVENT_AFTER_TRANSFORM, $afterEvent);

            $data = $afterEvent->document;
        }

        return $data;
    }
}
