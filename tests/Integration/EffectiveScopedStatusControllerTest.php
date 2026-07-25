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
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
#[CoversClass(DependencyService::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(PromotionsController::class)]
final class EffectiveScopedStatusControllerTest extends TestCase
{
    private const PREFIX = 'sm-effective-status-';

    private mixed $originalConfigCache = null;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->setSearchManagerConfig([]);
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $user = $this->createTestUser(self::PREFIX, ['admin' => true]);
        $this->grantPermissions($user, [
            'accessCp',
            'searchManager:manageQueryRules',
            'searchManager:managePromotions',
        ]);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function familyProvider(): array
    {
        return [
            'query rules' => ['rule'],
            'promotions' => ['promotion'],
        ];
    }

    #[DataProvider('familyProvider')]
    public function testListControllerFiltersAndSortsByDisplayedEffectiveStatus(string $family): void
    {
        $healthy = self::PREFIX . 'healthy-index';
        $disabledIndex = self::PREFIX . 'disabled-index';
        $configError = self::PREFIX . 'config-error-index';
        $missing = self::PREFIX . 'missing-index';
        $this->insertIndex($healthy, true);
        $this->insertIndex($disabledIndex, false);
        $this->setSearchManagerConfig([
            'indices' => [
                $configError => [
                    'name' => 'Broken Config Index',
                    'elementType' => 'missing\\ElementType',
                    'enabled' => true,
                ],
            ],
        ]);

        $this->insertEntity($family, 'A Error Config Enabled', $configError, true);
        $this->insertEntity($family, 'B Error Config Disabled', $configError, false);
        $this->insertEntity($family, 'C Error Missing Enabled', $missing, true);
        $this->insertEntity($family, 'D Error Missing Disabled', $missing, false);
        $this->insertEntity($family, 'E Disabled Healthy', $healthy, false);
        $this->insertEntity($family, 'F Enabled Healthy', $healthy, true);
        $this->insertEntity($family, 'G Enabled Disabled Index', $disabledIndex, true);
        $firstDuplicateId = $this->insertEntity($family, 'H Enabled Duplicate', $healthy, true);
        $secondDuplicateId = $this->insertEntity($family, 'H Enabled Duplicate', $healthy, true);

        $all = $this->runIndex($family, ['search' => self::PREFIX]);
        self::assertSame([
            'A Error Config Enabled' => 'error',
            'B Error Config Disabled' => 'error',
            'C Error Missing Enabled' => 'error',
            'D Error Missing Disabled' => 'error',
            'E Disabled Healthy' => 'disabled',
            'F Enabled Healthy' => 'enabled',
            'G Enabled Disabled Index' => 'enabled',
            'H Enabled Duplicate' => 'enabled',
        ], $this->statusMap($family, $all));
        self::assertCount(9, $this->entities($family, $all));

        self::assertSame(
            ['A Error Config Enabled', 'B Error Config Disabled', 'C Error Missing Enabled', 'D Error Missing Disabled'],
            $this->entityLabels($family, $this->runIndex($family, [
                'status' => 'error',
                'search' => self::PREFIX,
                'sort' => 'enabled',
                'dir' => 'asc',
            ])),
        );
        self::assertSame(
            ['E Disabled Healthy'],
            $this->entityLabels($family, $this->runIndex($family, [
                'status' => 'disabled',
                'search' => self::PREFIX,
            ])),
        );
        self::assertSame(
            ['F Enabled Healthy', 'G Enabled Disabled Index', 'H Enabled Duplicate', 'H Enabled Duplicate'],
            $this->entityLabels($family, $this->runIndex($family, [
                'status' => 'enabled',
                'search' => self::PREFIX,
            ])),
        );

        self::assertSame(
            [
                'A Error Config Enabled',
                'B Error Config Disabled',
                'C Error Missing Enabled',
                'D Error Missing Disabled',
                'E Disabled Healthy',
                'F Enabled Healthy',
                'G Enabled Disabled Index',
                'H Enabled Duplicate',
                'H Enabled Duplicate',
            ],
            $this->entityLabels($family, $this->runIndex($family, [
                'search' => self::PREFIX,
                'sort' => 'enabled',
                'dir' => 'asc',
            ])),
        );
        self::assertSame(
            [
                'H Enabled Duplicate',
                'H Enabled Duplicate',
                'G Enabled Disabled Index',
                'F Enabled Healthy',
                'E Disabled Healthy',
                'D Error Missing Disabled',
                'C Error Missing Enabled',
                'B Error Config Disabled',
                'A Error Config Enabled',
            ],
            $this->entityLabels($family, $this->runIndex($family, [
                'search' => self::PREFIX,
                'sort' => 'enabled',
                'dir' => 'desc',
            ])),
        );

        $ascending = $this->runIndex($family, [
            'search' => self::PREFIX,
            'sort' => 'enabled',
            'dir' => 'asc',
        ]);
        $descending = $this->runIndex($family, [
            'search' => self::PREFIX,
            'sort' => 'enabled',
            'dir' => 'desc',
        ]);
        self::assertSame(
            [$firstDuplicateId, $secondDuplicateId],
            $this->entityIdsForLabel($family, $ascending, 'H Enabled Duplicate'),
        );
        self::assertSame(
            [$secondDuplicateId, $firstDuplicateId],
            $this->entityIdsForLabel($family, $descending, 'H Enabled Duplicate'),
        );
    }

    public function testBaseStatusContractOwnsErrorEnabledDisabledAndFilterColors(): void
    {
        $dependencies = SearchManager::$plugin->dependencies;
        foreach (['error', 'enabled', 'disabled'] as $state) {
            $status = $dependencies->resolveEffectiveStatus(
                $state === 'enabled',
                $state === 'error' ? ['state' => 'error', 'errorTitle' => 'Broken.'] : [],
            );
            self::assertSame('status', $status['colorSet']);
            self::assertArrayHasKey($state, ColorHelper::getColorSet('status'));
        }

        self::assertSame(
            ColorHelper::getPaletteColor('red')['color'],
            ColorHelper::getFilterColor('status', 'error', 'error'),
        );
        $obsoleteColorSet = 'index' . 'Status';
        self::assertFalse(ColorHelper::hasColorSet($obsoleteColorSet));
        self::assertStringNotContainsString(
            $obsoleteColorSet,
            implode("\n", [
                $this->readPluginFile('src/SearchManager.php'),
                $this->readPluginFile('src/services/DependencyService.php'),
                $this->readPluginFile('src/templates/_components/_effective-status.twig'),
            ]),
        );
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function runIndex(string $family, array $queryParams): array
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
            Craft::$app->set('response', new Response());
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Craft::$app->getRequest()->setQueryParams($queryParams);

        $response = $family === 'rule'
            ? (new CapturingQueryRulesController('query-rules', SearchManager::$plugin))->actionIndex()
            : (new CapturingPromotionsController('promotions', SearchManager::$plugin))->actionIndex();

        self::assertIsArray($response->data);
        return $response->data;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, string>
     */
    private function statusMap(string $family, array $variables): array
    {
        $map = [];
        foreach ($this->entities($family, $variables) as $entity) {
            $label = $family === 'rule' ? $entity->name : $entity->title;
            $map[substr((string)$label, strlen(self::PREFIX))] = $variables['effectiveStatuses'][(int)$entity->id]['value'];
        }

        ksort($map);
        return $map;
    }

    /**
     * @param array<string, mixed> $variables
     * @return list<string>
     */
    private function entityLabels(string $family, array $variables): array
    {
        return array_map(
            static fn(QueryRule|Promotion $entity): string => substr(
                (string)($entity instanceof QueryRule ? $entity->name : $entity->title),
                strlen(self::PREFIX),
            ),
            $this->entities($family, $variables),
        );
    }

    /**
     * @param array<string, mixed> $variables
     * @return list<QueryRule|Promotion>
     */
    private function entities(string $family, array $variables): array
    {
        $entities = $variables[$family === 'rule' ? 'rules' : 'promotions'];
        self::assertIsArray($entities);

        return $entities;
    }

    /**
     * @param array<string, mixed> $variables
     * @return list<int>
     */
    private function entityIdsForLabel(string $family, array $variables, string $label): array
    {
        return array_values(array_map(
            static fn(QueryRule|Promotion $entity): int => (int)$entity->id,
            array_filter(
                $this->entities($family, $variables),
                static fn(QueryRule|Promotion $entity): bool => substr(
                    (string)($entity instanceof QueryRule ? $entity->name : $entity->title),
                    strlen(self::PREFIX),
                ) === $label,
            ),
        ));
    }

    private function insertEntity(string $family, string $suffix, string $indexHandle, bool $enabled): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $label = self::PREFIX . $suffix;
        if ($family === 'rule') {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_query_rules}}', [
                'name' => $label,
                'indexHandle' => $indexHandle,
                'matchType' => QueryRule::MATCH_EXACT,
                'matchValue' => $label,
                'actionType' => QueryRule::ACTION_SYNONYM,
                'actionValue' => '{"terms":["status"]}',
                'priority' => 0,
                'siteId' => null,
                'enabled' => (int)$enabled,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
            return (int)Craft::$app->getDb()->getLastInsertID();
        }

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => $indexHandle,
            'title' => $label,
            'query' => $label,
            'matchType' => 'exact',
            'elementId' => 1,
            'elementType' => Entry::class,
            'position' => 1,
            'siteId' => null,
            'enabled' => (int)$enabled,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertIndex(string $handle, bool $enabled): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'enabled' => (int)$enabled,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'backend' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setSearchManagerConfig(array $config): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = $config;
        $this->setConfigCache($cache);
        $validation = (new ConfigIndexValidator())->validateConfig($config);
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new EffectiveStatusConfigIndexValidator($validation),
        );
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['like', 'name', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'title', self::PREFIX . '%', false])
            ->execute();
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->column();
        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $ids)])
                ->execute();
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => array_map('intval', $ids)])
                ->execute();
        }
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}

final class CapturingQueryRulesController extends QueryRulesController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = $variables;

        return $response;
    }
}

final class CapturingPromotionsController extends PromotionsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = $variables;

        return $response;
    }
}

final class EffectiveStatusConfigIndexValidator extends ConfigIndexValidator
{
    public function __construct(
        private readonly ConfigIndexValidationResult $result,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function validate(): ConfigIndexValidationResult
    {
        return $this->result;
    }
}
