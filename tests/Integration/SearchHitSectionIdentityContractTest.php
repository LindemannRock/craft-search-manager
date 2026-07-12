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
        self::assertSame(123, $hit['elementId'] ?? null);
        self::assertSame('123_1_overview', $hit['backendId'] ?? null);
        self::assertArrayNotHasKey('id', $hit);
        self::assertArrayNotHasKey('objectID', $hit);
        self::assertArrayNotHasKey('sectionBody', $hit);
        self::assertArrayNotHasKey('_sectionBody', $hit);
    }

    public function testPresenterRemovesLegacyIdentitySectionAndInternalKeysAcrossHitShapes(): void
    {
        $base = [
            'id' => 123,
            'objectID' => '123_1',
            'elementId' => 123,
            'siteId' => 1,
            'backendId' => '123_1',
            'title' => 'Contract hit',
            'type' => 'entry',
            'elementType' => 'entry',
            'section' => 'Legacy Section',
            'sectionHandle' => 'legacy',
            'sectionType' => 'channel',
            'group' => 'Legacy Group',
            'groupHandle' => 'legacyGroup',
            'category' => 'legacy-doc-category',
            'entrySection' => 'Entries',
            'entrySectionHandle' => 'entries',
            'entrySectionType' => 'channel',
            'categoryGroup' => 'Topics',
            'categoryGroupHandle' => 'topics',
            'docCategory' => 'Guides',
            '_testInternal' => 'private',
        ];

        $shapes = [
            'page entry' => $base,
            'page product' => array_merge($base, [
                'type' => 'product',
                'entrySection' => null,
                'entrySectionHandle' => null,
                'entrySectionType' => null,
                'productType' => 'Clothing',
                'productTypeHandle' => 'clothing',
            ]),
            'split heading' => array_merge($base, [
                'backendId' => '123_1_install',
                'sectionType' => 'heading',
                'sectionId' => 'install',
                'sectionTitle' => 'Install',
                'sectionLevel' => 2,
                'sectionAnchor' => 'install',
                'sectionUrl' => '/docs#install',
                'sectionIndex' => 1,
            ]),
            'split intro' => array_merge($base, [
                'backendId' => '123_1_intro',
                'sectionType' => 'intro',
                'sectionId' => 'intro',
                'sectionTitle' => 'Contract hit',
                'sectionLevel' => null,
                'sectionAnchor' => null,
                'sectionUrl' => '/docs',
                'sectionIndex' => 0,
            ]),
            'promoted hit' => array_merge($base, [
                'backendId' => '123_1_promoted-page',
                'sectionType' => 'promoted-page',
                'sectionId' => 'promoted-page',
                'sectionTitle' => 'Contract hit',
                'sectionUrl' => '/docs',
                'sectionIndex' => 0,
                'promoted' => true,
                'score' => null,
            ]),
        ];

        foreach ($shapes as $label => $rawHit) {
            $hit = SearchHitPresenter::present($rawHit);

            self::assertArrayNotHasKey('id', $hit, $label);
            self::assertArrayNotHasKey('objectID', $hit, $label);
            self::assertArrayNotHasKey('section', $hit, $label);
            self::assertArrayNotHasKey('sectionHandle', $hit, $label);
            self::assertArrayNotHasKey('group', $hit, $label);
            self::assertArrayNotHasKey('groupHandle', $hit, $label);
            self::assertArrayNotHasKey('category', $hit, $label);
            self::assertArrayNotHasKey('elementType', $hit, $label);
            self::assertArrayNotHasKey('_testInternal', $hit, $label);
        }

        $pageEntry = SearchHitPresenter::present($shapes['page entry']);
        self::assertArrayNotHasKey('sectionType', $pageEntry);
        self::assertSame('Entries', $pageEntry['entrySection'] ?? null);
        self::assertSame('entries', $pageEntry['entrySectionHandle'] ?? null);
        self::assertSame('channel', $pageEntry['entrySectionType'] ?? null);

        foreach (['split heading' => 'heading', 'split intro' => 'intro', 'promoted hit' => 'promoted-page'] as $label => $sectionType) {
            $hit = SearchHitPresenter::present($shapes[$label]);
            self::assertSame($sectionType, $hit['sectionType'] ?? null, $label);
        }
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
