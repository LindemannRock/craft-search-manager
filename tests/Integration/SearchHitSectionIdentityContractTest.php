<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\searchmanager\gql\types\SearchHitType;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins the section-capable hit identity and public field contract.
 *
 * @since 5.53.0
 */
#[CoversClass(SearchHitIdentityHelper::class)]
#[CoversClass(SearchHitPresenter::class)]
#[CoversClass(SearchHitType::class)]
final class SearchHitSectionIdentityContractTest extends TestCase
{
    public function testPageAndSectionDocumentIdsSeparateBackendIdentityFromElementIdentity(): void
    {
        self::assertSame('123_1', SearchHitIdentityHelper::pageDocumentId(123, 1));
        self::assertSame('123_1_overview', SearchHitIdentityHelper::sectionDocumentId(123, 1, 'overview'));

        $hit = SearchHitIdentityHelper::normalizeHit([
            'elementId' => 123,
            'siteId' => 1,
            'sectionId' => 'overview',
        ]);

        self::assertSame(123, $hit['id'] ?? null);
        self::assertSame(123, $hit['elementId'] ?? null);
        self::assertSame('123_1_overview', $hit['backendId'] ?? null);
    }

    public function testBackendIdIsUniqueAcrossSplitHitsThatShareElementId(): void
    {
        $hits = array_map(SearchHitIdentityHelper::normalizeHit(...), [
            [
                'elementId' => 123,
                'siteId' => 1,
                'sectionId' => 'intro',
                'sectionType' => 'intro',
            ],
            [
                'elementId' => 123,
                'siteId' => 1,
                'sectionId' => 'overview',
                'sectionType' => 'heading',
            ],
        ]);

        self::assertSame([123, 123], array_column($hits, 'id'));
        self::assertSame(['123_1_intro', '123_1_overview'], array_column($hits, 'backendId'));
        self::assertCount(2, array_unique(array_column($hits, 'backendId')));
    }

    public function testPresenterKeepsSectionMetadataAndStripsSectionBody(): void
    {
        $hit = SearchHitPresenter::present([
            'elementId' => 123,
            'siteId' => 1,
            'title' => 'Parent Page',
            'url' => 'https://example.test/docs',
            'sectionType' => 'heading',
            'sectionId' => 'overview',
            'sectionTitle' => 'Overview',
            'sectionLevel' => 2,
            'sectionAnchor' => 'overview',
            'sectionUrl' => 'https://example.test/docs#overview',
            'sectionIndex' => 1,
            'sectionBody' => 'Private section body',
            '_sectionBody' => 'Private alternate body',
        ]);

        self::assertSame('heading', $hit['sectionType'] ?? null);
        self::assertSame('overview', $hit['sectionId'] ?? null);
        self::assertSame('Overview', $hit['sectionTitle'] ?? null);
        self::assertSame(2, $hit['sectionLevel'] ?? null);
        self::assertSame('overview', $hit['sectionAnchor'] ?? null);
        self::assertSame('https://example.test/docs#overview', $hit['sectionUrl'] ?? null);
        self::assertSame(1, $hit['sectionIndex'] ?? null);
        self::assertArrayNotHasKey('sectionBody', $hit);
        self::assertArrayNotHasKey('_sectionBody', $hit);
    }

    public function testGraphQlHitTypeExposesAndResolvesSectionFields(): void
    {
        $fields = SearchHitType::getFieldDefinitions();
        foreach (['sectionType', 'sectionId', 'sectionTitle', 'sectionLevel', 'sectionAnchor', 'sectionUrl', 'sectionIndex'] as $field) {
            self::assertArrayHasKey($field, $fields);
        }

        $type = new SearchHitType([
            'name' => 'SearchManagerSearchHitSectionIdentityTest',
            'fields' => $fields,
        ]);
        $method = new \ReflectionMethod($type, 'resolve');
        $method->setAccessible(true);

        $resolveInfo = $this->createMock(ResolveInfo::class);
        $resolveInfo->fieldName = 'sectionLevel';
        self::assertSame(2, $method->invoke($type, ['sectionLevel' => '2'], [], null, $resolveInfo));

        $resolveInfo->fieldName = 'sectionIndex';
        self::assertSame(3, $method->invoke($type, ['sectionIndex' => '3'], [], null, $resolveInfo));

        $resolveInfo->fieldName = 'sectionType';
        self::assertSame('promoted-page', $method->invoke($type, ['sectionType' => 'promoted-page'], [], null, $resolveInfo));
    }
}
