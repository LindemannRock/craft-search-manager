<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\SetupService;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins setup-readiness detection.
 *
 * The IP salt readiness gate must mirror the runtime hash gate in
 * AnalyticsTrackingService (empty / unresolved-placeholder / trimmed-empty all
 * count as "not configured").
 *
 * @since 5.53.0
 */
final class SetupServiceTest extends TestCase
{
    private SetupService $setup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
                new FixedConfigIndexValidator(
                new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_ABSENT),
            ),
        );
        $this->setup = SearchManager::$plugin->setup;
    }

    public function testIpSaltConfiguredWhenSaltPresent(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = str_repeat('a', 40);

        self::assertTrue(
            $this->setup->isIpSaltConfigured($settings),
            'A real salt value must count as configured.',
        );
    }

    public function testIpSaltNotConfiguredWhenEmpty(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = '';

        self::assertFalse(
            $this->setup->isIpSaltConfigured($settings),
            'An empty salt must not count as configured.',
        );
    }

    public function testIpSaltNotConfiguredForUnresolvedPlaceholder(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = '$SEARCH_MANAGER_IP_SALT';

        self::assertFalse(
            $this->setup->isIpSaltConfigured($settings),
            'The unresolved default env placeholder must not count as configured.',
        );
    }

    public function testGetStatusCompleteWhenSaltConfigured(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = str_repeat('a', 40);

        $status = $this->setup->getStatus($settings);

        self::assertTrue($status['complete']);
        self::assertTrue($status['ipSaltConfigured']);
        self::assertSame([], $status['missing']);
    }

    public function testGetStatusIncompleteWhenSaltMissing(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = '';

        $status = $this->setup->getStatus($settings);

        self::assertFalse($status['complete']);
        self::assertFalse($status['ipSaltConfigured']);
        self::assertSame(['ipSalt'], $status['missing']);
    }

    public function testGetStatusSurfacesConfigIndexFindings(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = str_repeat('a', 40);
        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $result->addFinding('broken-index', ConfigIndexValidationResult::SEVERITY_ERROR, 'backend', 'Missing backend');
        $this->swapPluginComponent('search-manager', 'configIndexValidator', new FixedConfigIndexValidator($result));

        $status = $this->setup->getStatus($settings);

        self::assertFalse($status['complete']);
        self::assertFalse($status['configIndicesValid']);
        self::assertFalse($status['configIndicesClean']);
        self::assertSame(['configIndices'], $status['missing']);
        self::assertSame('broken-index', $status['configIndexFindings'][0]['handle']);
        self::assertSame('backend', $status['configIndexFindings'][0]['key']);
    }

    public function testGetStatusRemainsCompleteForWarningOnlyConfigFindings(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = str_repeat('a', 40);
        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $result->addFinding(
            'warning-index',
            ConfigIndexValidationResult::SEVERITY_WARNING,
            'name',
            'Empty name',
        );
        $this->swapPluginComponent('search-manager', 'configIndexValidator', new FixedConfigIndexValidator($result));

        $status = $this->setup->getStatus($settings);

        self::assertTrue($status['complete']);
        self::assertTrue($status['configIndicesValid']);
        self::assertFalse($status['configIndicesClean']);
        self::assertSame([], $status['missing']);
    }

    public function testGetStatusGroupsConfigIndexFindingsByHandleAndSeverity(): void
    {
        $settings = new Settings();
        $settings->ipHashSalt = str_repeat('a', 40);
        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $result->addFinding('broken-index', ConfigIndexValidationResult::SEVERITY_WARNING, 'name', 'Empty name');
        $result->addFinding('broken-index', ConfigIndexValidationResult::SEVERITY_ERROR, 'backend', 'Missing backend');
        $result->addFinding('warning-index', ConfigIndexValidationResult::SEVERITY_WARNING, 'name', 'Empty name');
        $result->addFinding(null, ConfigIndexValidationResult::SEVERITY_ERROR, 'indices', 'Invalid section');
        $this->swapPluginComponent('search-manager', 'configIndexValidator', new FixedConfigIndexValidator($result));

        $groups = $this->setup->getStatus($settings)['configIndexFindingGroups'];

        self::assertCount(3, $groups);
        self::assertSame('broken-index', $groups[0]['handle']);
        self::assertSame(ConfigIndexValidationResult::SEVERITY_ERROR, $groups[0]['severity']);
        self::assertCount(2, $groups[0]['findings']);
        self::assertSame('warning-index', $groups[1]['handle']);
        self::assertSame(ConfigIndexValidationResult::SEVERITY_WARNING, $groups[1]['severity']);
        self::assertNull($groups[2]['handle']);
        self::assertSame(ConfigIndexValidationResult::SEVERITY_ERROR, $groups[2]['severity']);
    }
}
