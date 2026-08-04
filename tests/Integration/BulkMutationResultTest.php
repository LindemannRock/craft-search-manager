<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\BulkMutationResult;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Model;

/**
 * @since 5.54.0
 */
#[CoversClass(BulkMutationResult::class)]
final class BulkMutationResultTest extends TestCase
{
    public function testIdentifiersAreNormalizedAndDeduplicatedInFirstSeenOrder(): void
    {
        $result = BulkMutationResult::fromIdentifiers([12, '7', 12, '12', 9]);

        self::assertTrue($result->canMutate());
        self::assertSame([12, 7, 9], $result->identifiers());
        self::assertSame([
            'status' => 'failure',
            'success' => false,
            'count' => 0,
            'skipped' => 0,
            'errors' => [],
        ], $result->toArray());
    }

    #[DataProvider('malformedIdentifierProvider')]
    public function testMalformedContainersAndValuesRejectTheWholeRequest(mixed $raw): void
    {
        $result = BulkMutationResult::fromIdentifiers($raw);

        self::assertFalse($result->canMutate());
        self::assertSame([], $result->identifiers());
        self::assertSame('failure', $result->toArray()['status']);
        self::assertFalse($result->toArray()['success']);
        self::assertSame(0, $result->toArray()['count']);
        self::assertNotEmpty($result->toArray()['errors']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedIdentifierProvider(): iterable
    {
        yield 'scalar container' => ['1'];
        yield 'associative container' => [['id' => 1]];
        yield 'zero' => [[0]];
        yield 'negative integer' => [[-1]];
        yield 'signed string' => [['+1']];
        yield 'decimal string' => [['1.0']];
        yield 'float' => [[1.0]];
        yield 'boolean' => [[true]];
        yield 'null' => [[null]];
        yield 'nested value' => [[[1]]];
        yield 'one malformed value rejects valid siblings' => [[1, 'bad', 2]];
    }

    public function testAllSuccessSchema(): void
    {
        $result = BulkMutationResult::fromIdentifiers([1, 2]);
        $result->addSuccess(2);

        self::assertSame([
            'status' => 'success',
            'success' => true,
            'count' => 2,
            'skipped' => 0,
            'errors' => [],
        ], $result->toArray());
    }

    public function testPartialSchemaIncludesSuccessesSkipsAndAllModelErrors(): void
    {
        $model = new class() extends Model {
            public function rules(): array
            {
                return [];
            }
        };
        $model->addError('first', 'First failure');
        $model->addError('second', 'Second failure');

        $result = BulkMutationResult::fromIdentifiers([1, 2, 3]);
        $result->addSuccess();
        $result->addSkip();
        $result->addModelErrors('Broken resource', $model, 'Fallback');

        self::assertSame([
            'status' => 'partial',
            'success' => false,
            'count' => 1,
            'skipped' => 1,
            'errors' => [
                'Broken resource: First failure',
                'Broken resource: Second failure',
            ],
        ], $result->toArray());
    }

    public function testZeroSuccessNeverClaimsCompatibilitySuccess(): void
    {
        $result = BulkMutationResult::fromIdentifiers([1]);
        $result->addSkip();
        $result->addNamedError('Resource', 'Persistence failed');

        self::assertSame([
            'status' => 'failure',
            'success' => false,
            'count' => 0,
            'skipped' => 1,
            'errors' => ['Resource: Persistence failed'],
        ], $result->toArray());
    }
}
