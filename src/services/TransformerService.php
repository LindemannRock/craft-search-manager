<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\events\TransformEvent;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\BaseTransformer;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
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

    private bool $defaultTransformersRegistered = false;

    /** @var array<string, string> */
    private array $dynamicTransformers = [];

    /**
     * @var array<string, TransformerInterface>|null Batch-scoped transformer instances.
     */
    private ?array $transformerReuseCache = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
        $this->registerDefaultTransformers();
        $this->dynamicTransformers = $this->loadDynamicTransformers();
        $this->_transformers = array_replace($this->_transformers, $this->dynamicTransformers);
        $this->defaultTransformersRegistered = true;
    }

    /**
     * Register default transformers
     */
    private function registerDefaultTransformers(): void
    {
        // AutoTransformer is the default fallback for all element types.
        // Register element-specific transformers here for richer indexing:

        if (class_exists('lindemannrock\\docsmanager\\elements\\SourceDoc')) {
            $this->registerTransformer(
                'lindemannrock\docsmanager\elements\SourceDoc',
                DocsManagerTransformer::class,
            );
        }

        if (class_exists(CommerceElementTypeHelper::productElementType())) {
            $this->registerTransformer(
                CommerceElementTypeHelper::productElementType(),
                CommerceTransformer::class,
            );
        }

        if (class_exists(CommerceElementTypeHelper::variantElementType())) {
            $this->registerTransformer(
                CommerceElementTypeHelper::variantElementType(),
                CommerceTransformer::class,
            );
        }
    }

    // =========================================================================
    // TRANSFORMER REGISTRATION
    // =========================================================================

    /**
     * Register a transformer for an element type
     *
     */
    public function registerTransformer(string $elementType, string $transformerClass): void
    {
        $this->_transformers[$elementType] = $transformerClass;

        if ($this->defaultTransformersRegistered && SearchManager::$plugin !== null) {
            $this->dynamicTransformers[$elementType] = $transformerClass;
            try {
                Craft::$app->getCache()->set(
                    $this->dynamicTransformerCacheKey(),
                    $this->dynamicTransformers,
                );
            } catch (\Throwable $e) {
                $this->logWarning('Unable to persist dynamic transformer registration', [
                    'elementType' => $elementType,
                    'transformer' => $transformerClass,
                    'error' => $e->getMessage(),
                ]);
            }
            SearchManager::$plugin->dependencies->clearIndexCatalogue();
        }

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
     */
    public function getTransformer(ElementInterface $element, ?string $transformerClass = null, ?array $headingLevels = null): ?TransformerInterface
    {
        if (!SearchManager::$plugin->dependencies->getClassAvailability(
            get_class($element),
            ElementInterface::class,
        )['available']) {
            return null;
        }

        $resolvedClass = $this->resolveTransformerClass($element, $transformerClass);
        if (
            $resolvedClass === null
            || !SearchManager::$plugin->dependencies->getClassAvailability(
                $resolvedClass,
                TransformerInterface::class,
            )['available']
        ) {
            return null;
        }

        if ($this->transformerReuseCache !== null) {
            $cacheKey = $this->transformerCacheKey($element, $resolvedClass, $headingLevels);
            if (!array_key_exists($cacheKey, $this->transformerReuseCache)) {
                $this->transformerReuseCache[$cacheKey] = $this->createTransformer($resolvedClass);
            }

            return $this->transformerReuseCache[$cacheKey];
        }

        if ($transformerClass && $transformerClass !== '') {
            $this->logDebug('Using transformer from index config', [
                'transformer' => $transformerClass,
                'elementType' => get_class($element),
            ]);
        }

        return $this->createTransformer($resolvedClass);
    }

    /**
     * @since 5.53.0
     */
    public function resolveTransformerClass(ElementInterface $element, ?string $transformerClass = null): ?string
    {
        return $this->resolveTransformerClassForElementType(get_class($element), $transformerClass);
    }

    /**
     * @since 5.53.0
     */
    public function resolveTransformerClassForElementType(string $elementType, ?string $transformerClass = null): ?string
    {
        return $this->resolveTransformerClassForElementTypeInternal($elementType, $transformerClass, true);
    }

    /**
     * Resolve a transformer class without producing fallback diagnostics.
     *
     * @since 5.54.0
     */
    public function resolveTransformerClassForElementTypeSilently(string $elementType, ?string $transformerClass = null): ?string
    {
        return $this->resolveTransformerClassForElementTypeInternal($elementType, $transformerClass, false);
    }

    private function resolveTransformerClassForElementTypeInternal(
        string $elementType,
        ?string $transformerClass,
        bool $logFallback,
    ): ?string {
        if ($transformerClass && trim($transformerClass) !== '') {
            return trim($transformerClass);
        }

        if (isset($this->_transformers[$elementType])) {
            return $this->_transformers[$elementType];
        }

        foreach ($this->_transformers as $type => $registeredTransformerClass) {
            if ($elementType === $type || is_a($elementType, $type, true)) {
                return $registeredTransformerClass;
            }
        }

        if ($logFallback) {
            $this->logDebug('Using AutoTransformer for element type', [
                'elementType' => $elementType,
            ]);
        }

        return AutoTransformer::class;
    }

    /**
     * @since 5.53.0
     */
    public function supportsSplitSections(string $elementType, ?string $transformerClass = null): bool
    {
        $resolvedClass = $this->resolveTransformerClassForElementType($elementType, $transformerClass);
        if ($resolvedClass === null || !class_exists($resolvedClass)) {
            return false;
        }

        if (
            ($elementType === 'lindemannrock\\docsmanager\\elements\\SourceDoc' || is_a($elementType, 'lindemannrock\\docsmanager\\elements\\SourceDoc', true))
            && is_a($resolvedClass, DocsManagerTransformer::class, true)
        ) {
            return true;
        }

        return is_a($resolvedClass, AutoTransformer::class, true);
    }

    /**
     * @param array<int>|null $headingLevels
     */
    private function transformerCacheKey(ElementInterface $element, string $transformerClass, ?array $headingLevels): string
    {
        $levels = $headingLevels;
        if (is_array($levels)) {
            $levels = array_values(array_unique(array_map('intval', $levels)));
            sort($levels);
        }

        return implode('|', [
            $transformerClass,
            get_class($element),
            json_encode($levels),
        ]);
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
     */
    public function transform(ElementInterface $element, string $indexName = '', ?string $transformerClass = null, ?array $headingLevels = null): ?array
    {
        $result = $this->transformWithResult($element, $indexName, $transformerClass, $headingLevels);

        return $result['status'] === 'transformed' ? $result['data'] : null;
    }

    /**
     * Transform an element while distinguishing intentional skips from failures.
     *
     * @param array<int>|null $headingLevels
     * @return array{status: 'transformed'|'skipped'|'failed', data: array<string, mixed>|null, error: string|null}
     * @since 5.54.0
     */
    public function transformWithResult(
        ElementInterface $element,
        string $indexName = '',
        ?string $transformerClass = null,
        ?array $headingLevels = null,
    ): array {
        try {
            $transformer = $this->getTransformer($element, $transformerClass, $headingLevels);

            if (!$transformer) {
                $resolvedClass = $this->resolveTransformerClass($element, $transformerClass);

                return [
                    'status' => 'failed',
                    'data' => null,
                    'error' => "Transformer '{$resolvedClass}' could not be created.",
                ];
            }

            // Configure heading levels for BaseTransformer-family transformers.
            if ($headingLevels !== null && $transformer instanceof BaseTransformer) {
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
                    return [
                        'status' => 'skipped',
                        'data' => null,
                        'error' => null,
                    ];
                }
            }

            $data = $transformer->transform($element);

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

            return [
                'status' => 'transformed',
                'data' => $data,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to transform element', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reuse compatible transformer instances for the duration of one batch.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @since 5.53.0
     */
    public function withTransformerReuse(callable $callback): mixed
    {
        $previousCache = $this->transformerReuseCache;
        $this->transformerReuseCache = [];

        try {
            return $callback();
        } finally {
            $this->transformerReuseCache = $previousCache;
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadDynamicTransformers(): array
    {
        try {
            $registered = Craft::$app->getCache()->get($this->dynamicTransformerCacheKey());
        } catch (\Throwable $e) {
            $this->logWarning('Unable to load dynamic transformer registrations', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
        if (!is_array($registered)) {
            return [];
        }

        return array_filter(
            $registered,
            static fn(mixed $transformer, mixed $elementType): bool => is_string($elementType)
                && $elementType !== ''
                && is_string($transformer)
                && $transformer !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function dynamicTransformerCacheKey(): string
    {
        return PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'dynamic-transformers') . 'registry';
    }
}
