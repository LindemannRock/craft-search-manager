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
use craft\elements\User;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\HeaderCollection;

/**
 * @since 5.54.0
 */
#[CoversClass(AnalyticsController::class)]
final class AnalyticsControllerFailureLoggingTest extends TestCase
{
    private const PREFIX = '__sm_pr134_';
    public const EXCEPTION_MESSAGE = 'forced analytics report failure';

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Craft::$app->getSites()->refreshSites();
        }
    }

    #[DataProvider('disclosureProvider')]
    public function testReportFailureLogsExactlyOnceAndPreservesDisclosure(bool $devMode, string $expectedError): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $site = Craft::$app->getSites()->getAllSites(true)[0] ?? null;
        self::assertNotNull($site);
        $this->actAsSiteScopedUser((int)$site->id, (string)$site->uid);
        $this->swapPluginComponent('search-manager', 'analytics', new Pr134FailingAnalyticsService());
        $this->installRequest([
            'type' => 'chart',
            'siteId' => null,
            'dateRange' => 'today',
        ]);
        $controller = new Pr134AnalyticsController('analytics', SearchManager::$plugin);
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $originalDevMode = $generalConfig->devMode;

        try {
            $generalConfig->devMode = $devMode;
            $response = $controller->actionGetData();
        } finally {
            $generalConfig->devMode = $originalDevMode;
        }

        self::assertSame([
            'success' => false,
            'error' => $expectedError,
        ], $response->data);
        self::assertCount(1, $controller->errorLogs);
        self::assertSame([
            'message' => 'Failed to load analytics data',
            'params' => [
                'type' => 'chart',
                'siteScope' => [(int)$site->id],
                'dateRange' => 'today',
                'error' => self::EXCEPTION_MESSAGE,
            ],
        ], $controller->errorLogs[0]);

        if (!$devMode) {
            self::assertStringNotContainsString(self::EXCEPTION_MESSAGE, (string)$response->data['error']);
        }
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function disclosureProvider(): iterable
    {
        yield 'production' => [false, 'An error occurred while loading analytics data.'];
        yield 'development' => [true, self::EXCEPTION_MESSAGE];
    }

    private function actAsSiteScopedUser(int $siteId, string $siteUid): User
    {
        $user = $this->createTestUser(self::PREFIX . 'user_');
        $this->grantPermissions($user, [
            'accessCp',
            'searchManager:viewAnalytics',
            "editSite:{$siteUid}",
        ]);
        $this->actingAs($user);
        Craft::$app->getSites()->refreshSites();
        self::assertSame([$siteId], Craft::$app->getSites()->getEditableSiteIds());

        return $user;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function installRequest(array $params): void
    {
        Craft::$app->set('response', new Response());
        Craft::$app->set('request', new class($params) extends \craft\console\Request {
            private HeaderCollection $headers;

            /** @param array<string, mixed> $params */
            public function __construct(private array $params)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
            }

            public function getHeaders(): HeaderCollection
            {
                return $this->headers;
            }

            public function getParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getBodyParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getQueryParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getAcceptsJson(): bool
            {
                return true;
            }
        });
    }
}

final class Pr134AnalyticsController extends AnalyticsController
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $errorLogs = [];

    public function requirePermission(string $permissionName): void
    {
    }

    public function requireAcceptsJson(): void
    {
    }

    protected function logError(string $message, array $params = []): void
    {
        $this->errorLogs[] = compact('message', 'params');
    }
}

final class Pr134FailingAnalyticsService extends AnalyticsService
{
    public function getChartData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        throw new \RuntimeException(AnalyticsControllerFailureLoggingTest::EXCEPTION_MESSAGE);
    }
}
