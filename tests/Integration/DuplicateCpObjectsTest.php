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
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\services\WidgetStyleService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(PromotionsController::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(WidgetsController::class)]
final class DuplicateCpObjectsTest extends TestCase
{
    public const PREFIX = 'sm-duplicate-cp';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?string $originalDefaultWidgetHandle = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultWidgetHandle = SearchManager::$plugin->getSettings()->defaultWidgetHandle;
        $this->purgeRows();
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultWidgetHandle = $this->originalDefaultWidgetHandle;
        $settings->saveToDatabase();

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }

        $this->purgeRows();
        parent::tearDown();
    }

    public function testPromotionDuplicateCreatesDisabledCopyAndJsonMessage(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            self::markTestSkipped('No live entry/index pair available for promotion duplicate coverage.');
        }
        [$index, $element] = $pair;
        $this->installStubBackend();
        $this->actWithPermission('searchManager:createPromotions');

        $promotion = new Promotion();
        $promotion->title = self::PREFIX . ' Promotion';
        $promotion->indexHandle = $index->handle;
        $promotion->query = self::PREFIX . ' query';
        $promotion->matchType = 'exact';
        $promotion->elementId = (int)$element->id;
        $promotion->elementType = $element::class;
        $promotion->position = 3;
        $promotion->siteId = (int)$element->siteId;
        $promotion->enabled = true;
        self::assertTrue($promotion->save(), print_r($promotion->getErrors(), true));

        $this->withPostJson(['promotionId' => $promotion->id]);
        $response = (new PromotionsController('promotions', SearchManager::$plugin))->actionDuplicate();

        self::assertTrue($response->data['success'] ?? false);
        self::assertSame('Promotion duplicated', $response->data['message'] ?? null);
        self::assertArrayNotHasKey('redirectUrl', $response->data);

        $copy = $this->latestPromotionCopy();
        self::assertNotNull($copy);
        self::assertNotSame($promotion->id, $copy->id);
        self::assertFalse($copy->enabled);
        self::assertSame($promotion->query, $copy->query);
        self::assertSame($promotion->elementId, $copy->elementId);
        self::assertStringStartsWith($promotion->title . ' ', (string)$copy->title);
    }

    public function testQueryRuleDuplicateCreatesDisabledCopyAndJsonMessage(): void
    {
        $this->installStubBackend();
        $this->actWithPermission('searchManager:createQueryRules');

        $rule = new QueryRule();
        $rule->name = self::PREFIX . ' Rule';
        $rule->matchType = QueryRule::MATCH_EXACT;
        $rule->matchValue = self::PREFIX . ' query';
        $rule->actionType = QueryRule::ACTION_SYNONYM;
        $rule->actionValue = ['terms' => ['alpha', 'beta']];
        $rule->priority = 2;
        $rule->enabled = true;
        self::assertTrue($rule->save(), print_r($rule->getErrors(), true));

        $this->withPostJson(['ruleId' => $rule->id]);
        $response = (new QueryRulesController('query-rules', SearchManager::$plugin))->actionDuplicate();

        self::assertTrue($response->data['success'] ?? false);
        self::assertSame('Query rule duplicated', $response->data['message'] ?? null);
        self::assertArrayNotHasKey('redirectUrl', $response->data);

        $copy = $this->latestQueryRuleCopy();
        self::assertNotNull($copy);
        self::assertNotSame($rule->id, $copy->id);
        self::assertFalse($copy->enabled);
        self::assertSame($rule->matchValue, $copy->matchValue);
        self::assertSame($rule->actionValue, $copy->actionValue);
        self::assertStringStartsWith($rule->name . ' ', $copy->name);
    }

    public function testWidgetConfigDuplicateCreatesDisabledUniqueCopyAndStripsRawApiKey(): void
    {
        $this->actWithPermission('searchManager:createWidgetConfigs');
        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = '';
        $settings['apiKey'] = 'sm_pub_' . str_repeat('a', 32);

        $source = new WidgetConfig();
        $source->name = self::PREFIX . ' Widget';
        $source->handle = self::PREFIX . '-widget';
        $source->enabled = true;
        $source->settings = $settings;
        self::assertTrue(SearchManager::$plugin->widgetConfigs->save($source), print_r($source->getErrors(), true));

        $defaultWidgetHandle = SearchManager::$plugin->getSettings()->defaultWidgetHandle;

        $this->withPostJson(['configId' => $source->id]);
        $response = (new WidgetsController('widgets', SearchManager::$plugin))->actionDuplicate();

        self::assertTrue($response->data['success'] ?? false);
        self::assertSame('Widget config duplicated', $response->data['message'] ?? null);
        self::assertArrayNotHasKey('redirectUrl', $response->data);
        self::assertSame($defaultWidgetHandle, SearchManager::$plugin->getSettings()->defaultWidgetHandle);

        $copy = $this->latestWidgetConfigCopy();
        self::assertNotNull($copy);
        self::assertFalse($copy->enabled);
        self::assertNotSame($source->handle, $copy->handle);
        self::assertNotSame(SearchManager::$plugin->getSettings()->defaultWidgetHandle, $copy->handle);
        self::assertStringStartsWith($source->handle . '-copy', $copy->handle);
        self::assertStringStartsWith($source->name . ' ', $copy->name);

        $copiedSettings = $copy->getSettingsArray();
        self::assertArrayNotHasKey('apiKey', $copiedSettings);
        self::assertArrayNotHasKey('apiKeyId', $copiedSettings);
        self::assertSame('', $copiedSettings['apiKeyHandle'] ?? null);
    }

    public function testWidgetStyleDuplicateCreatesDisabledUniqueCopy(): void
    {
        $this->actWithPermission('searchManager:createWidgetStyles');

        $source = new WidgetStyle();
        $source->name = self::PREFIX . ' Style';
        $source->handle = self::PREFIX . '-style';
        $source->type = WidgetStyle::TYPE_MODAL;
        $source->enabled = true;
        $source->styles = ['modalMaxWidth' => '720'];
        self::assertTrue(SearchManager::$plugin->widgetStyles->save($source), print_r($source->getErrors(), true));

        $this->withPostJson(['styleId' => $source->id]);
        $response = (new WidgetsController('widgets', SearchManager::$plugin))->actionDuplicateStyle();

        self::assertTrue($response->data['success'] ?? false);
        self::assertSame('Widget style duplicated', $response->data['message'] ?? null);
        self::assertArrayNotHasKey('redirectUrl', $response->data);

        $copy = $this->latestWidgetStyleCopy();
        self::assertNotNull($copy);
        self::assertFalse($copy->enabled);
        self::assertNotSame($source->handle, $copy->handle);
        self::assertStringStartsWith($source->handle . '-copy', $copy->handle);
        self::assertStringStartsWith($source->name . ' ', $copy->name);
        self::assertSame('720', $copy->getStyles()['modalMaxWidth'] ?? null);
    }

    public function testConfigBackedWidgetRowsAreGuardedAndDuplicateActionsRequireCreatePermission(): void
    {
        $this->assertMethodContains('WidgetsController.php', 'actionDuplicate', "\$this->requirePermission('searchManager:createWidgetConfigs');");
        $this->assertMethodContains('WidgetsController.php', 'actionDuplicate', 'Config-backed widget configs cannot be duplicated.');
        $this->assertMethodNotContains('WidgetsController.php', 'actionDuplicate', 'searchManager:deleteWidgetConfigs');

        $this->assertMethodContains('WidgetsController.php', 'actionDuplicateStyle', "\$this->requirePermission('searchManager:createWidgetStyles');");
        $this->assertMethodContains('WidgetsController.php', 'actionDuplicateStyle', 'Config-backed widget styles cannot be duplicated.');
        $this->assertMethodNotContains('WidgetsController.php', 'actionDuplicateStyle', 'searchManager:deleteWidgetStyles');

        $this->assertMethodContains('PromotionsController.php', 'actionDuplicate', "\$this->requirePermission('searchManager:createPromotions');");
        $this->assertMethodNotContains('PromotionsController.php', 'actionDuplicate', 'searchManager:deletePromotions');
        $this->assertMethodContains('QueryRulesController.php', 'actionDuplicate', "\$this->requirePermission('searchManager:createQueryRules');");
        $this->assertMethodNotContains('QueryRulesController.php', 'actionDuplicate', 'searchManager:deleteQueryRules');
    }

    public function testConfigBackedWidgetConfigDuplicateDoesNotSave(): void
    {
        $this->actWithPermission('searchManager:createWidgetConfigs');
        $this->swapPluginComponent('search-manager', 'widgetConfigs', new class extends WidgetConfigService {
            public function getById(int $id): ?WidgetConfig
            {
                $config = new WidgetConfig();
                $config->id = $id;
                $config->handle = DuplicateCpObjectsTest::PREFIX . '-config-widget';
                $config->name = 'Config Widget';
                $config->source = 'config';
                $config->settings = WidgetConfig::defaultSettings();
                return $config;
            }

            public function save(WidgetConfig $config): bool
            {
                throw new \RuntimeException('Config-backed duplicate should not save.');
            }
        });

        $this->withPostJson(['configId' => 123456]);
        $response = (new WidgetsController('widgets', SearchManager::$plugin))->actionDuplicate();

        self::assertFalse($response->data['success'] ?? true);
        self::assertSame(0, $this->countRowsLike('{{%searchmanager_widget_configs}}', 'handle', self::PREFIX));
    }

    public function testConfigBackedWidgetStyleDuplicateDoesNotSave(): void
    {
        $this->actWithPermission('searchManager:createWidgetStyles');
        $this->swapPluginComponent('search-manager', 'widgetStyles', new class extends WidgetStyleService {
            public function getById(int $id): ?WidgetStyle
            {
                $style = new WidgetStyle();
                $style->id = $id;
                $style->handle = DuplicateCpObjectsTest::PREFIX . '-config-style';
                $style->name = 'Config Style';
                $style->source = 'config';
                return $style;
            }

            public function save(WidgetStyle $style): bool
            {
                throw new \RuntimeException('Config-backed duplicate should not save.');
            }
        });

        $this->withPostJson(['styleId' => 123456]);
        $response = (new WidgetsController('widgets', SearchManager::$plugin))->actionDuplicateStyle();

        self::assertFalse($response->data['success'] ?? true);
        self::assertSame(0, $this->countRowsLike('{{%searchmanager_widget_styles}}', 'handle', self::PREFIX));
    }

    private function actWithPermission(string $permission): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $user->admin = true;
        self::assertTrue(Craft::$app->getElements()->saveElement($user, false), print_r($user->getErrors(), true));
        $this->grantPermissions($user, ['accessCp', $permission]);
        $this->actingAs($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostJson(array $params): void
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
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function latestPromotionCopy(): ?Promotion
    {
        $row = (new Query())
            ->from('{{%searchmanager_promotions}}')
            ->where(['like', 'title', self::PREFIX . ' Promotion%', false])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row ? Promotion::findById((int)$row['id']) : null;
    }

    private function latestQueryRuleCopy(): ?QueryRule
    {
        $row = (new Query())
            ->from('{{%searchmanager_query_rules}}')
            ->where(['like', 'name', self::PREFIX . ' Rule%', false])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $row ? QueryRule::findById((int)$row['id']) : null;
    }

    private function latestWidgetConfigCopy(): ?WidgetConfig
    {
        $row = (new Query())
            ->from('{{%searchmanager_widget_configs}}')
            ->where(['like', 'handle', self::PREFIX . '-widget-copy%', false])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if (!$row) {
            return null;
        }

        return SearchManager::$plugin->widgetConfigs->getById((int)$row['id']);
    }

    private function latestWidgetStyleCopy(): ?WidgetStyle
    {
        $row = (new Query())
            ->from('{{%searchmanager_widget_styles}}')
            ->where(['like', 'handle', self::PREFIX . '-style-copy%', false])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if (!$row) {
            return null;
        }

        return SearchManager::$plugin->widgetStyles->getById((int)$row['id']);
    }

    private function assertMethodContains(string $filename, string $method, string $needle): void
    {
        self::assertStringContainsString($needle, $this->methodBody($filename, $method));
    }

    private function assertMethodNotContains(string $filename, string $method, string $needle): void
    {
        self::assertStringNotContainsString($needle, $this->methodBody($filename, $method));
    }

    private function methodBody(string $filename, string $method): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/' . $filename);
        $this->assertIsString($source);

        $methodPos = strpos($source, 'public function ' . $method . '(');
        $this->assertIsInt($methodPos, $method . ' should exist.');

        $bracePos = strpos($source, '{', $methodPos);
        $this->assertIsInt($bracePos, $method . ' opening brace should exist.');

        $depth = 0;
        $length = strlen($source);
        for ($i = $bracePos; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $bracePos + 1, $i - $bracePos - 1);
                }
            }
        }

        self::fail($method . ' body should be captured.');
    }

    private function countRowsLike(string $table, string $column, string $prefix): int
    {
        return (int)(new Query())
            ->from($table)
            ->where(['like', $column, $prefix . '%', false])
            ->count();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['like', 'name', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'title', self::PREFIX . '%', false])
            ->execute();
    }
}
