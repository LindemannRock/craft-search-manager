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
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the contract for `SearchController::parseWidgetCacheTelemetry()` — the
 * helper that resolves an analytics `executionTime` value from widget-supplied
 * cache state in `/search/track-search` request bodies.
 *
 * The widget reads `meta.cached` (bool) and `meta.took` (ms) from the final
 * search response and forwards them on the intent ping. This helper converts
 * those into the canonical `executionTime` semantics already used elsewhere:
 *
 *   - 0.0  → cache hit  (matches BackendService::search() cache-hit path)
 *   - >0   → cache miss (real ms)
 *   - null → telemetry absent or invalid (preserves backward-compat for
 *            legacy widget builds and non-widget callers)
 *
 * Tests are unit-scope: they invoke the static method directly with various
 * raw input shapes, no HTTP request harness needed. Live HTTP behaviour is
 * covered by the curl validation script.
 *
 * @since 5.46.0
 */
final class SearchControllerTrackSearchTest extends TestCase
{
    public function testMissingTelemetryFallsBackToNull(): void
    {
        $this->assertNull(SearchController::parseWidgetCacheTelemetry(null, null));
        $this->assertNull(SearchController::parseWidgetCacheTelemetry(null, '42'));
    }

    public function testCachedTrueReturnsZero(): void
    {
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('1', null));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('true', null));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('on', null));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('yes', null));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry(true, null));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry(1, null));
        // took is ignored on cache hit — meta.took is 0 anyway from BackendService
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('1', '0'));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('1', 'garbage'));
    }

    public function testCachedFalseWithValidTookReturnsTook(): void
    {
        $this->assertSame(42.5, SearchController::parseWidgetCacheTelemetry('0', '42.5'));
        $this->assertSame(0.0, SearchController::parseWidgetCacheTelemetry('false', '0'));
        $this->assertSame(60000.0, SearchController::parseWidgetCacheTelemetry('off', '60000'));
        $this->assertSame(123.456, SearchController::parseWidgetCacheTelemetry(false, 123.456));
    }

    public function testCachedFalseWithoutTookFallsBackToNull(): void
    {
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', null));
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('false', null));
    }

    public function testCachedFalseWithInvalidTookFallsBackToNull(): void
    {
        // Non-numeric strings.
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', 'abc'));
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', ''));
        // Negative.
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', '-1'));
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', -5));
        // Above the clamp (60s).
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', '60001'));
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('0', '999999'));
    }

    public function testMalformedCachedValueFallsBackToNull(): void
    {
        // String that isn't a recognised boolean variant.
        $this->assertNull(SearchController::parseWidgetCacheTelemetry('maybe', '42'));
        // Object / array — not boolean-like, shouldn't crash.
        $this->assertNull(SearchController::parseWidgetCacheTelemetry(['true'], '42'));
    }

    public function testTrackingSiteIdKeepsKnownSite(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();

        $this->assertSame($site->id, SearchController::normalizeTrackingSiteId((string)$site->id));
    }

    public function testTrackingSiteIdDiscardsUnknownSite(): void
    {
        $this->assertNull(SearchController::normalizeTrackingSiteId('2147483000'));
        $this->assertNull(SearchController::normalizeTrackingSiteId('not-a-site'));
        $this->assertNull(SearchController::normalizeTrackingSiteId('0'));
    }
}
