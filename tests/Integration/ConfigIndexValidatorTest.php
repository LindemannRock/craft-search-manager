<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use lindemannrock\searchmanager\helpers\SearchIndexCriteriaHelper;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers every config-index validation failure family.
 *
 * @since 5.54.0
 */
final class ConfigIndexValidatorTest extends TestCase
{
    private ConfigIndexValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConfigIndexValidator();
    }

    public function testEnvelopeStatesRemainDistinguishable(): void
    {
        self::assertSame(
            ConfigIndexValidationResult::STATUS_ABSENT,
            $this->validator->validateConfig([])->sectionStatus,
        );
        self::assertSame(
            ConfigIndexValidationResult::STATUS_INVALID_ROOT,
            $this->validator->validateConfig('invalid')->sectionStatus,
        );
        self::assertSame(
            ConfigIndexValidationResult::STATUS_INVALID_SECTION,
            $this->validator->validateConfig(['indices' => 'invalid'])->sectionStatus,
        );
        self::assertSame(
            ConfigIndexValidationResult::STATUS_PRESENT,
            $this->validator->validateConfig(['indices' => []])->sectionStatus,
        );
        self::assertSame(
            ConfigIndexValidationResult::STATUS_INVALID_ROOT,
            (new FixedRawConfigIndexValidator('invalid'))->validate()->sectionStatus,
        );

        $loadFailure = (new ThrowingConfigIndexValidator())->validate();
        self::assertSame(ConfigIndexValidationResult::STATUS_LOAD_FAILURE, $loadFailure->sectionStatus);
        self::assertTrue($loadFailure->hasErrors());
    }

    public function testUnknownIndexKeyIsReported(): void
    {
        $result = $this->validateIndex(['critera' => ['sections' => ['news']]]);

        $finding = $this->assertFinding($result, 'critera');
        self::assertSame('Unknown key critera.', $finding['message']);
        self::assertSame('critera', $finding['emphasis']);
        self::assertStringNotContainsString('test-index', $finding['message']);
    }

    public function testOuterHandleAndIndexItemShapeAreReported(): void
    {
        $result = $this->validator->validateConfig([
            'indices' => [
                '' => [],
                'bad item' => 'invalid',
            ],
        ]);

        $this->assertFinding($result, 'handle');
        $this->assertFinding($result, 'index');
    }

    #[DataProvider('invalidKnownValueProvider')]
    public function testEveryKnownKeyRejectsWrongTypes(string $key, mixed $value): void
    {
        $result = $this->validateIndex([$key => $value]);

        $this->assertFinding($result, $key);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidKnownValueProvider(): iterable
    {
        yield 'name' => ['name', []];
        yield 'elementType' => ['elementType', []];
        yield 'siteId' => ['siteId', '1'];
        yield 'criteria' => ['criteria', 'sections'];
        yield 'transformer' => ['transformer', []];
        yield 'language' => ['language', []];
        yield 'headingLevels' => ['headingLevels', '2,3'];
        yield 'backend' => ['backend', []];
        yield 'enabled' => ['enabled', 'false'];
        yield 'enableAnalytics' => ['enableAnalytics', 1];
        yield 'disableStopWords' => ['disableStopWords', 'off'];
        yield 'skipEntriesWithoutUrl' => ['skipEntriesWithoutUrl', []];
        yield 'splitSections' => ['splitSections', 'false'];
        yield 'retrievableFields' => ['retrievableFields', true];
    }

    public function testElementTypeMustExistAndImplementElementInterface(): void
    {
        $missing = $this->validateIndex(['elementType' => 'missing\\search\\elements\\Nope']);
        $wrongContract = $this->validateIndex(['elementType' => \stdClass::class]);

        $this->assertFinding($missing, 'elementType');
        $this->assertFinding($wrongContract, 'elementType');
    }

    public function testStrictNormalizedValuesAreReported(): void
    {
        $language = $this->validateIndex(['language' => 'english']);
        $headingRange = $this->validateIndex(['headingLevels' => [0, 2]]);
        $headingDuplicate = $this->validateIndex(['headingLevels' => [2, 2]]);
        $siteDuplicate = $this->validateIndex(['siteId' => [1, 1]]);
        $retrievableFields = $this->validateIndex(['retrievableFields' => ['valid', 'not a handle']]);

        $this->assertFinding($language, 'language');
        $this->assertFinding($headingRange, 'headingLevels');
        $this->assertFinding($headingDuplicate, 'headingLevels');
        $this->assertFinding($siteDuplicate, 'siteId');
        $this->assertFinding($retrievableFields, 'retrievableFields');
    }

    public function testEmptyNameIsANonBlockingWarning(): void
    {
        $result = $this->validateIndex(['name' => '   ']);
        $finding = $this->assertFinding($result, 'name');

        self::assertSame(ConfigIndexValidationResult::SEVERITY_WARNING, $finding['severity']);
        self::assertFalse($result->hasErrors());
    }

    public function testSiteIdsMustBeStrictAndExist(): void
    {
        $result = $this->validateIndex(['siteId' => PHP_INT_MAX]);

        $finding = $this->assertFinding($result, 'siteId');
        self::assertStringContainsString((string)PHP_INT_MAX, $finding['message']);
    }

    public function testCriteriaSelectorSchemaAndMissingHandlesAreReported(): void
    {
        $unknown = $this->validateIndex([
            'elementType' => Entry::class,
            'criteria' => ['section' => ['news']],
        ]);
        $empty = $this->validateIndex([
            'elementType' => Entry::class,
            'criteria' => ['sections' => []],
        ]);
        $nonScalar = $this->validateIndex([
            'elementType' => Entry::class,
            'criteria' => ['sections' => [[]]],
        ]);
        $missing = $this->validateIndex([
            'elementType' => Entry::class,
            'criteria' => ['sections' => ['__sm_missing_section_handle']],
        ]);

        $this->assertFinding($unknown, 'criteria.section');
        $this->assertFinding($empty, 'criteria.sections');
        $this->assertFinding($nonScalar, 'criteria.sections');
        $finding = $this->assertFinding($missing, 'criteria.sections');
        self::assertStringContainsString('__sm_missing_section_handle', $finding['message']);
    }

    public function testCriteriaSelectorMapMatchesSupportedElementTypes(): void
    {
        self::assertSame('sections', SearchIndexCriteriaHelper::selectorForElementType(Entry::class));
        self::assertSame('volumes', SearchIndexCriteriaHelper::selectorForElementType(Asset::class));
        self::assertSame('groups', SearchIndexCriteriaHelper::selectorForElementType(Category::class));
        self::assertSame(
            'sourceHandles',
            SearchIndexCriteriaHelper::selectorForElementType('lindemannrock\\docsmanager\\elements\\SourceDoc'),
        );
        self::assertNull(SearchIndexCriteriaHelper::selectorForElementType(\stdClass::class));
    }

    public function testClosureCriteriaAreMarkedRuntimeOnlyWithoutExecution(): void
    {
        $executed = false;
        $result = $this->validateIndex([
            'criteria' => static function() use (&$executed): void {
                $executed = true;
            },
        ]);

        self::assertFalse($executed);
        self::assertSame([], $result->getFindings());
        self::assertSame(['test-index'], $result->getRuntimeOnlyCriteriaHandles());
    }

    public function testNonJsonCriteriaAreReported(): void
    {
        $stream = fopen('php://memory', 'rb');
        self::assertIsResource($stream);

        try {
            $result = $this->validateIndex(['criteria' => ['sections' => [$stream]]]);
        } finally {
            fclose($stream);
        }

        $this->assertFinding($result, 'criteria');
    }

    public function testMissingBackendIsReported(): void
    {
        $result = $this->validateIndex(['backend' => '__sm_missing_backend']);

        $this->assertFinding($result, 'backend');
    }

    public function testDisabledBackendIsReported(): void
    {
        $backend = new ConfiguredBackend();
        $backend->handle = 'disabled';
        $backend->enabled = false;
        $validator = new FixedBackendConfigIndexValidator($backend);

        $result = $validator->validateConfig([
            'indices' => [
                'test-index' => ['backend' => 'disabled'],
            ],
        ]);

        $this->assertFinding($result, 'backend');
    }

    public function testBadTransformerIsReported(): void
    {
        $result = $this->validateIndex(['transformer' => 'missing\\search\\transformers\\Nope']);

        $this->assertFinding($result, 'transformer');
    }

    public function testUnsupportedSplitSectionsCombinationIsReported(): void
    {
        $result = $this->validateIndex([
            'transformer' => ValidatorPlainTransformer::class,
            'splitSections' => true,
        ]);

        $this->assertFinding($result, 'splitSections');
    }

    public function testValidMinimalIndexHasNoFindings(): void
    {
        $result = $this->validateIndex([
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
            'enabled' => true,
            'headingLevels' => [2, 3, 4],
            'retrievableFields' => ['*', '-wysiwyg'],
        ]);

        self::assertSame([], $result->getFindings());
    }

    /** @param array<string, mixed> $config */
    private function validateIndex(array $config): ConfigIndexValidationResult
    {
        return $this->validator->validateConfig([
            'indices' => [
                'test-index' => $config,
            ],
        ]);
    }

    /**
     * @return array{severity: string, handle: string|null, key: string, message: string, emphasis?: string}
     */
    private function assertFinding(ConfigIndexValidationResult $result, string $key): array
    {
        foreach ($result->getFindings() as $finding) {
            if ($finding['key'] === $key) {
                self::assertContains($finding['severity'], [
                    ConfigIndexValidationResult::SEVERITY_ERROR,
                    ConfigIndexValidationResult::SEVERITY_WARNING,
                ]);
                return $finding;
            }
        }

        self::fail("Expected finding for key '{$key}'.");
    }
}

/**
 * @since 5.54.0
 */
final class ThrowingConfigIndexValidator extends ConfigIndexValidator
{
    protected function readConfigFile(): mixed
    {
        throw new \RuntimeException('synthetic load failure');
    }
}

/**
 * @since 5.54.0
 */
final class FixedRawConfigIndexValidator extends ConfigIndexValidator
{
    public function __construct(private readonly mixed $rawConfig, array $config = [])
    {
        parent::__construct($config);
    }

    protected function readConfigFile(): mixed
    {
        return $this->rawConfig;
    }
}

/**
 * @since 5.54.0
 */
final class FixedBackendConfigIndexValidator extends ConfigIndexValidator
{
    public function __construct(private readonly ConfiguredBackend $backend, array $config = [])
    {
        parent::__construct($config);
    }

    protected function findBackendByHandle(string $handle): ?ConfiguredBackend
    {
        return $handle === $this->backend->handle ? $this->backend : null;
    }
}

/**
 * @since 5.54.0
 */
final class ValidatorPlainTransformer implements TransformerInterface
{
    public function transform(ElementInterface $element): array
    {
        return [];
    }

    public function supports(ElementInterface $element): bool
    {
        return true;
    }
}
