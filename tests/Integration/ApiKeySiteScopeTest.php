<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Pins the slice 2c siteId contract: each backend builds a consistent
 * single-site filter (all-sites = no filter), and ApiKeyService::assertSiteInScope
 * validates a requested site against the selected indices' scope.
 *
 * @since 5.47.0
 */
final class ApiKeySiteScopeTest extends TestCase
{
    // ---- Backend siteId filter consistency ----------------------------------

    public function testAlgoliaSiteIdFilterSyntax(): void
    {
        $this->assertSame('siteId:5', AlgoliaBackend::siteIdFilter(5));
        $this->assertNull(AlgoliaBackend::siteIdFilter(null));
        $this->assertNull(AlgoliaBackend::siteIdFilter('*'));
    }

    public function testTypesenseSiteIdFilterSyntax(): void
    {
        $this->assertSame('siteId:=5', TypesenseBackend::siteIdFilter(5));
        $this->assertNull(TypesenseBackend::siteIdFilter(null));
        $this->assertNull(TypesenseBackend::siteIdFilter('*'));
    }

    public function testMeilisearchSiteIdFilterSyntax(): void
    {
        $this->assertSame('siteId = 5', MeilisearchBackend::siteIdFilter(5));
        $this->assertNull(MeilisearchBackend::siteIdFilter(null));
        $this->assertNull(MeilisearchBackend::siteIdFilter('*'));
    }

    public function testAllBackendsAgreeOnAllSitesVsSingleSite(): void
    {
        // All-sites (null / '*') → no filter on every backend.
        foreach ([null, '*'] as $allSites) {
            $this->assertNull(AlgoliaBackend::siteIdFilter($allSites));
            $this->assertNull(TypesenseBackend::siteIdFilter($allSites));
            $this->assertNull(MeilisearchBackend::siteIdFilter($allSites));
        }
        // A concrete site → a (backend-specific) filter on every backend.
        $this->assertNotNull(AlgoliaBackend::siteIdFilter(2));
        $this->assertNotNull(TypesenseBackend::siteIdFilter(2));
        $this->assertNotNull(MeilisearchBackend::siteIdFilter(2));
    }

    public function testAlgoliaMergesExistingFilter(): void
    {
        // existing only → unchanged
        $this->assertSame('type:doc', AlgoliaBackend::siteIdFilter(null, 'type:doc'));
        // existing + siteId → combined
        $this->assertSame('(type:doc) AND siteId:5', AlgoliaBackend::siteIdFilter(5, 'type:doc'));
        // all-sites + existing → existing preserved
        $this->assertSame('type:doc', AlgoliaBackend::siteIdFilter('*', 'type:doc'));
    }

    public function testTypesenseMergesExistingFilter(): void
    {
        $this->assertSame('cat:=1', TypesenseBackend::siteIdFilter(null, 'cat:=1'));
        $this->assertSame('(cat:=1) && siteId:=5', TypesenseBackend::siteIdFilter(5, 'cat:=1'));
        $this->assertSame('cat:=1', TypesenseBackend::siteIdFilter('*', 'cat:=1'));
    }

    public function testMeilisearchMergesExistingFilter(): void
    {
        $this->assertSame('type = doc', MeilisearchBackend::siteIdFilter(null, 'type = doc'));
        $this->assertSame('(type = doc) AND siteId = 5', MeilisearchBackend::siteIdFilter(5, 'type = doc'));
        $this->assertSame('type = doc', MeilisearchBackend::siteIdFilter('*', 'type = doc'));
    }

    // ---- ApiKeyService::assertSiteInScope() ---------------------------------

    public function testAllSitesIndexAcceptsAnyValidSite(): void
    {
        $this->svc()->assertSiteInScope($this->validSiteId(), $this->index('docs', null));
        $this->addToAssertionCount(1);
    }

    public function testSiteLimitedIndexAcceptsIncludedSite(): void
    {
        $site = $this->validSiteId();
        $this->svc()->assertSiteInScope($site, $this->index('docs', [$site, $site + 1]));
        $this->addToAssertionCount(1);
    }

    public function testNoIndicesOnlyValidatesSiteExists(): void
    {
        $this->svc()->assertSiteInScope($this->validSiteId());
        $this->addToAssertionCount(1);
    }

    public function testNonexistentSiteRejectedWith400(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->svc()->assertSiteInScope($this->bogusSiteId(), $this->index('docs', null));
    }

    public function testSiteLimitedIndexRejectsExcludedSiteWith403(): void
    {
        $site = $this->validSiteId();
        $this->expectException(ForbiddenHttpException::class);
        $this->svc()->assertSiteInScope($site, $this->index('docs', [$site + 50, $site + 51]));
    }

    public function testMultiIndexRejectsIfAnyExcludesSite(): void
    {
        $site = $this->validSiteId();
        $this->expectException(ForbiddenHttpException::class);
        $this->svc()->assertSiteInScope(
            $site,
            $this->index('covers-all', null),          // all-sites → ok on its own
            $this->index('site-limited', [$site + 50]), // excludes the site → 403
        );
    }

    private function svc(): \lindemannrock\searchmanager\services\ApiKeyService
    {
        return SearchManager::$plugin->apiKeys;
    }

    private function validSiteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    private function bogusSiteId(): int
    {
        $ids = array_map(fn($s) => $s->id, Craft::$app->getSites()->getAllSites());

        return (empty($ids) ? 0 : max($ids)) + 1000;
    }

    private function index(string $handle, int|array|null $siteId): SearchIndex
    {
        $index = new SearchIndex();
        $index->handle = $handle;
        $index->siteId = $siteId;

        return $index;
    }
}
