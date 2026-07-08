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
