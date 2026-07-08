<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\BaseTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks the public transformer extension contract.
 *
 * @since 5.53.0
 */
#[CoversClass(SearchIndex::class)]
#[CoversClass(TransformerService::class)]
final class TransformerExtensionContractTest extends TestCase
{
    private const PROJECT_BASE_TRANSFORMER = 'modules\\searchmanager\\transformers\\ExampleBaseTransformer';
    private const PROJECT_AUTO_TRANSFORMER = 'modules\\searchmanager\\transformers\\ExampleAutoTransformer';
    private const PROJECT_INTERFACE_TRANSFORMER = 'modules\\searchmanager\\transformers\\ExampleInterfaceTransformer';
    private const PROJECT_COMMERCE_TRANSFORMER = 'modules\\searchmanager\\transformers\\ExampleCommercePostProcessorTransformer';

    public function testBlankTransformerClassRemainsValid(): void
    {
        $index = new SearchIndex();
        $index->transformerClass = '';

        $index->validateTransformerClass('transformerClass');

        self::assertSame([], $index->getErrors('transformerClass'));
    }

    public function testBaseTransformerSubclassPassesValidation(): void
    {
        self::assertTransformerClassValid(ContractBaseTransformer::class);
    }

    public function testAutoTransformerSubclassPassesValidation(): void
    {
        self::assertTransformerClassValid(ContractAutoTransformer::class);
    }

    public function testPlainTransformerInterfaceImplementationPassesValidation(): void
    {
        self::assertTransformerClassValid(ContractPlainTransformer::class);
        self::assertFalse(
            method_exists(ContractPlainTransformer::class, 'setHeadingLevels'),
            'Direct TransformerInterface implementations are supported, but BaseTransformer helpers and heading-level behavior are not automatic.'
        );
    }

    public function testNonTransformerInterfaceClassFailsValidation(): void
    {
        $index = new SearchIndex();
        $index->transformerClass = ContractNotATransformer::class;

        $index->validateTransformerClass('transformerClass');

        self::assertSame([
            'Transformer class must implement TransformerInterface: ' . ContractNotATransformer::class,
        ], $index->getErrors('transformerClass'));
    }

    public function testTransformerClassWithRequiredConstructorArgsFailsValidation(): void
    {
        $index = new SearchIndex();
        $index->transformerClass = ContractConstructorTransformer::class;

        $index->validateTransformerClass('transformerClass');

        self::assertSame([
            'Transformer class must be constructible without arguments: ' . ContractConstructorTransformer::class,
        ], $index->getErrors('transformerClass'));
    }

    public function testConfiguredTransformerBypassesSupportsAtRuntime(): void
    {
        $service = new TransformerService();
        $entry = new Entry();
        $entry->id = 123;
        $entry->siteId = 1;

        $data = $service->transform($entry, 'entries', ContractUnsupportedTransformer::class);

        self::assertSame([
            'elementId' => 123,
            'supportsWasBypassed' => true,
        ], $data);
    }

    public function testProjectProductTransformerIsAutoloadableValidAndRunnable(): void
    {
        $transformerClass = 'modules\\searchmanager\\transformers\\ProductTransformer';

        self::assertTrue(class_exists($transformerClass));
        self::assertSame(0, (new \ReflectionClass($transformerClass))->getConstructor()?->getNumberOfRequiredParameters() ?? 0);
        self::assertTransformerClassValid($transformerClass);

        $service = new TransformerService();
        $entry = new Entry();
        $entry->id = 123;
        $entry->siteId = 1;
        $entry->title = 'Project Product';

        $data = $service->transform($entry, 'products', $transformerClass);

        self::assertIsArray($data);
        self::assertSame(123, $data['elementId'] ?? null);
        self::assertSame('Project Product', $data['title'] ?? null);
    }

    public function testProjectBaseTransformerIsAutoloadableValidAndRunnable(): void
    {
        self::assertProjectTransformerClassIsValid(self::PROJECT_BASE_TRANSFORMER);

        $service = new TransformerService();
        $entry = self::entry();

        $data = $service->transform($entry, 'entries', self::PROJECT_BASE_TRANSFORMER, [5, 1]);

        self::assertIsArray($data);
        self::assertSame(123, $data['elementId'] ?? null);
        self::assertSame('Project Entry', $data['title'] ?? null);
        self::assertSame('base', $data['projectTransformer'] ?? null);
        self::assertSame([1, 5], $data['projectHeadingLevels'] ?? null);
        self::assertSame([1, 5], array_column($data['_projectHeadings'] ?? [], 'level'));
        self::assertStringContainsString('Example project body.', $data['content'] ?? '');
    }

    public function testProjectAutoTransformerIsAutoloadableValidAndRunnable(): void
    {
        self::assertProjectTransformerClassIsValid(self::PROJECT_AUTO_TRANSFORMER);

        $service = new TransformerService();
        $entry = self::entry();

        $data = $service->transform($entry, 'entries', self::PROJECT_AUTO_TRANSFORMER, [6, 2]);

        self::assertIsArray($data);
        self::assertSame(123, $data['elementId'] ?? null);
        self::assertSame('Project Entry', $data['title'] ?? null);
        self::assertSame('auto', $data['projectTransformer'] ?? null);
        self::assertSame([2, 6], $data['projectHeadingLevels'] ?? null);
        self::assertStringContainsString('Project Entry', $data['content'] ?? '');
    }

    public function testProjectInterfaceTransformerIsAutoloadableValidAndRunnableWithoutBaseHeadingBehavior(): void
    {
        self::assertProjectTransformerClassIsValid(self::PROJECT_INTERFACE_TRANSFORMER);
        self::assertFalse(
            method_exists(self::PROJECT_INTERFACE_TRANSFORMER, 'setHeadingLevels'),
            'Direct TransformerInterface implementations are supported, but BaseTransformer helpers and heading-level behavior are not automatic.'
        );

        $service = new TransformerService();
        $entry = self::entry();

        $data = $service->transform($entry, 'entries', self::PROJECT_INTERFACE_TRANSFORMER, [1, 5]);

        self::assertSame([
            'elementId' => 123,
            'siteId' => 1,
            'title' => 'Project Entry',
            'projectTransformer' => 'interface',
        ], $data);
    }

    public function testProjectConfiguredTransformerBypassesSupportsAtRuntime(): void
    {
        self::assertProjectTransformerClassIsValid(self::PROJECT_INTERFACE_TRANSFORMER);

        $service = new TransformerService();
        $unsupportedElement = new \craft\elements\User();
        $unsupportedElement->id = 456;
        $unsupportedElement->siteId = 1;
        $unsupportedElement->username = 'project-user';

        $transformer = $service->getTransformer($unsupportedElement, self::PROJECT_INTERFACE_TRANSFORMER);

        self::assertInstanceOf(TransformerInterface::class, $transformer);
        self::assertFalse($transformer->supports($unsupportedElement));
        self::assertSame([
            'elementId' => 456,
            'siteId' => 1,
            'title' => '',
            'projectTransformer' => 'interface',
        ], $service->transform($unsupportedElement, 'users', self::PROJECT_INTERFACE_TRANSFORMER));
    }

    public function testProjectCommercePostProcessorTransformerIsAutoloadableValidAndRunnableWhenCommerceElementsExist(): void
    {
        self::assertProjectTransformerClassIsValid(self::PROJECT_COMMERCE_TRANSFORMER);

        if (!class_exists(CommerceElementTypeHelper::productElementType())) {
            self::markTestSkipped('Craft Commerce Product elements are not available in this test environment.');
        }

        $productClass = CommerceElementTypeHelper::productElementType();
        $product = new $productClass();
        $product->id = 789;
        $product->siteId = 1;
        $product->title = 'Project Commerce Product';

        $data = (new TransformerService())->transform($product, 'products', self::PROJECT_COMMERCE_TRANSFORMER);

        self::assertIsArray($data);
        self::assertSame(789, $data['elementId'] ?? null);
        self::assertSame('Project Commerce Product', $data['title'] ?? null);
        self::assertSame('commerce-post-processor', $data['projectTransformer'] ?? null);
    }

    public function testHeadingLevelsArePassedToBaseAndAutoDescendants(): void
    {
        $service = new TransformerService();
        $entry = new Entry();
        $entry->id = 123;
        $entry->siteId = 1;

        self::assertSame([
            'elementId' => 123,
            'headingLevels' => [1, 5],
        ], $service->transform($entry, 'entries', ContractBaseTransformer::class, [5, 1]));

        self::assertSame([
            'elementId' => 123,
            'headingLevels' => [2, 6],
        ], $service->transform($entry, 'entries', ContractAutoTransformer::class, [6, 2]));
    }

    private static function assertTransformerClassValid(string $transformerClass): void
    {
        $index = new SearchIndex();
        $index->transformerClass = $transformerClass;

        $index->validateTransformerClass('transformerClass');

        self::assertSame([], $index->getErrors('transformerClass'));
    }

    private static function assertProjectTransformerClassIsValid(string $transformerClass): void
    {
        self::assertTrue(class_exists($transformerClass));
        self::assertSame(0, (new \ReflectionClass($transformerClass))->getConstructor()?->getNumberOfRequiredParameters() ?? 0);
        self::assertTransformerClassValid($transformerClass);
    }

    private static function entry(): Entry
    {
        $entry = new Entry();
        $entry->id = 123;
        $entry->siteId = 1;
        $entry->title = 'Project Entry';

        return $entry;
    }
}

final class ContractBaseTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => $element->id,
            'headingLevels' => $this->getHeadingLevels(),
        ];
    }
}

final class ContractAutoTransformer extends AutoTransformer
{
    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => $element->id,
            'headingLevels' => $this->getHeadingLevels(),
        ];
    }
}

final class ContractPlainTransformer implements TransformerInterface
{
    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => $element->id,
        ];
    }

    public function supports(ElementInterface $element): bool
    {
        return true;
    }
}

final class ContractUnsupportedTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function supports(ElementInterface $element): bool
    {
        return false;
    }

    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => $element->id,
            'supportsWasBypassed' => true,
        ];
    }
}

final class ContractConstructorTransformer implements TransformerInterface
{
    public function __construct(private readonly string $dependency)
    {
    }

    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => $element->id,
            'dependency' => $this->dependency,
        ];
    }

    public function supports(ElementInterface $element): bool
    {
        return true;
    }
}

final class ContractNotATransformer
{
}
