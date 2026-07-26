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
use craft\elements\Entry;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;
use yii\web\BadRequestHttpException;

/**
 * Regression coverage for the owner-smoke PR1.24 amendment.
 *
 * @since 5.54.0
 */
final class Pr1Debt7SmokeAmendmentRegressionTest extends TestCase
{
    private mixed $originalConfigCache = null;

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
    }

    protected function tearDown(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }

        $this->setConfigCache($this->originalConfigCache);
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        parent::tearDown();
    }

    public function testRenderedTestPageExcludesCanonicalErrorFixtures(): void
    {
        $html = $this->renderTestPage();

        foreach ([
            'fixture-broken-everything',
            'fixture-not-an-element',
            'fixture-bad-criteria-key',
            'fixture-missing-section-handle',
        ] as $handle) {
            self::assertStringNotContainsString(
                'value="' . $handle . '"',
                $html,
                "{$handle} must not be offered by the real CP Test selector.",
            );
        }
    }

    public function testRenderedTestPageUsesCanonicalIdentityAndResolvedSiteLabels(): void
    {
        $html = $this->renderTestPage();

        self::assertStringContainsString(
            'Fixture Valid Minimal (fixture-valid-minimal) — All Sites',
            $html,
        );
        self::assertStringContainsString(
            'Auto Transform Index (test-entries-en) — En',
            $html,
        );
        self::assertStringNotContainsString(
            'fixture-valid-minimal (fixture-valid-minimal)',
            $html,
        );
        self::assertMatchesRegularExpression(
            '/<option value="fixture-valid-minimal" selected>/',
            $html,
        );

        $template = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');
        self::assertStringNotContainsString('craft.searchManager.getIndices()', $template);
        self::assertStringNotContainsString('craft.app.sites.getSiteById', $template);
    }

    public function testCanonicalTestChoiceProjectionCoversEffectiveEligibilityAndSiteScope(): void
    {
        $sites = Craft::$app->getSites()->getAllSites();
        self::assertGreaterThanOrEqual(2, count($sites));
        $siteIds = [(int)$sites[1]->id, (int)$sites[0]->id];
        $expectedSiteNames = [
            (int)$sites[0]->id => $sites[0]->name,
            (int)$sites[1]->id => $sites[1]->name,
        ];
        ksort($expectedSiteNames, SORT_NUMERIC);

        $this->withConfigFileIndices([
            'choice-healthy' => [
                'name' => 'Healthy Config',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
            'choice-warning' => [
                'name' => ' ',
                'elementType' => Entry::class,
                'enabled' => true,
                'siteId' => $siteIds,
            ],
            'choice-single' => [
                'name' => 'Single Site',
                'elementType' => Entry::class,
                'enabled' => true,
                'siteId' => (int)$sites[0]->id,
            ],
            'choice-unresolved-site' => [
                'name' => 'Unresolved Site',
                'elementType' => Entry::class,
                'enabled' => true,
                'siteId' => PHP_INT_MAX,
            ],
            'choice-disabled' => [
                'name' => 'Disabled Config',
                'elementType' => Entry::class,
                'enabled' => false,
            ],
            'choice-error' => [
                'name' => 'Error Config',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
            'choice-unavailable' => [
                'name' => 'Unavailable Config',
                'elementType' => \stdClass::class,
                'enabled' => true,
            ],
            'choice-equal' => [
                'name' => 'choice-equal',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
        ]);
        $validation = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $validation->addFinding(
            'choice-warning',
            ConfigIndexValidationResult::SEVERITY_WARNING,
            'name',
            'Synthetic warning-only configuration.',
        );
        $validation->addFinding(
            'choice-error',
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'criteria',
            'Synthetic configuration error.',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new Pr1Debt7FixedConfigIndexValidator($validation),
        );
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        $projection = SearchManager::$plugin->dependencies->getTestIndexChoices();
        $labels = array_column($projection['choices'], 'label', 'value');

        self::assertSame('Healthy Config (choice-healthy) — All Sites', $labels['choice-healthy']);
        self::assertSame(
            'choice-warning — ' . implode(', ', $expectedSiteNames),
            $labels['choice-warning'],
        );
        self::assertSame('choice-equal — All Sites', $labels['choice-equal']);
        self::assertSame(
            'Single Site (choice-single) — ' . $sites[0]->name,
            $labels['choice-single'],
        );
        self::assertSame(
            'Unresolved Site (choice-unresolved-site) — Site #' . PHP_INT_MAX,
            $labels['choice-unresolved-site'],
        );
        self::assertArrayNotHasKey('choice-disabled', $labels);
        self::assertArrayNotHasKey('choice-error', $labels);
        self::assertArrayNotHasKey('choice-unavailable', $labels);
        self::assertSame('enabled', $catalogue['choice-warning']['state']);
        self::assertNotEmpty($catalogue['choice-warning']['findings']);
        self::assertSame('error', $catalogue['choice-unavailable']['state']);
        self::assertSame((int)$sites[0]->id, $projection['indexSiteIds']['choice-single']);
        self::assertSame(null, $projection['indexSiteIds']['choice-warning']);

        $databaseHandles = array_keys(array_filter(
            $catalogue,
            static fn(array $record): bool => $record['source'] === 'database' && $record['available'],
        ));
        self::assertNotEmpty($databaseHandles);
        self::assertArrayHasKey($databaseHandles[0], $labels);
    }

    public function testSharedTestIndexResolverRejectsEveryUnavailableCategory(): void
    {
        $this->withConfigFileIndices([
            'guard-valid' => [
                'name' => 'Guard Valid',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
            'guard-disabled' => [
                'name' => 'Guard Disabled',
                'elementType' => Entry::class,
                'enabled' => false,
            ],
            'guard-error' => [
                'name' => 'Guard Error',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
            'guard-unavailable' => [
                'name' => 'Guard Unavailable',
                'elementType' => \stdClass::class,
                'enabled' => true,
            ],
        ]);
        $validation = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $validation->addFinding(
            'guard-error',
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'criteria',
            'Synthetic configuration error.',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new Pr1Debt7FixedConfigIndexValidator($validation),
        );
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $controller = new SettingsController('settings', SearchManager::$plugin);
        $resolver = new \ReflectionMethod($controller, 'requireAvailableTestIndex');
        self::assertSame('guard-valid', $resolver->invoke($controller, 'guard-valid')->handle);

        foreach ([
            '__missing_test_index__',
            'guard-disabled',
            'guard-error',
            'guard-unavailable',
        ] as $handle) {
            try {
                $resolver->invoke($controller, $handle);
                self::fail("{$handle} reached the CP Test action boundary.");
            } catch (BadRequestHttpException $exception) {
                self::assertSame(400, $exception->statusCode);
            }
        }
    }

    public function testEveryTestActionRejectsForgedUnavailableHandleBeforeWork(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->withSettingsManagerPermissions();
        $backend = $this->installStubBackend();
        $autocomplete = new Pr1Debt7RecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $outcomes = [];
        foreach ([
            'search' => 'actionTestSearch',
            'autocomplete' => 'actionTestAutocomplete',
            'promotions' => 'actionTestPromotions',
            'queryRules' => 'actionTestQueryRules',
        ] as $label => $action) {
            $this->withPostJson([
                'query' => 'smokey',
                'indexHandle' => 'fixture-broken-everything',
            ]);
            Craft::$app->getResponse()->data = null;

            $controller = new SettingsController('settings', SearchManager::$plugin);
            $response = $controller->$action();
            self::assertIsArray($response->data);
            $outcomes[$label] = (bool)($response->data['success'] ?? false);
        }

        self::assertSame([
            'outcomes' => [
                'search' => false,
                'autocomplete' => false,
                'promotions' => false,
                'queryRules' => false,
            ],
            'searchCalls' => 0,
            'autocompleteCalls' => 0,
        ], [
            'outcomes' => $outcomes,
            'searchCalls' => count($backend->callsFor('search')),
            'autocompleteCalls' => count($autocomplete->calls),
        ]);

        $controller = $this->readPluginFile('src/controllers/SettingsController.php');
        foreach ([
            'actionTestSearch',
            'actionTestAutocomplete',
            'actionTestPromotions',
            'actionTestQueryRules',
        ] as $action) {
            $start = strpos($controller, "function {$action}");
            self::assertNotFalse($start);
            $end = strpos($controller, "\n    public function ", $start + 1);
            $body = substr($controller, $start, $end === false ? null : $end - $start);
            self::assertStringContainsString('requireAvailableTestIndex(', $body);
        }
    }

    private function renderTestPage(): string
    {
        $this->withRequest('GET');
        $this->withSettingsManagerPermissions();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $controller = new SettingsController('settings', SearchManager::$plugin);
        $variablesMethod = new \ReflectionMethod($controller, '_settingsTemplateVariables');
        $variables = $variablesMethod->invoke(
            $controller,
            'test',
            SearchManager::$plugin->getSettings(),
        );
        self::assertIsArray($variables);

        return Craft::$app->getView()->renderTemplate(
            'search-manager/settings/test/_partials/search',
            $variables,
            View::TEMPLATE_MODE_CP,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostJson(array $params): void
    {
        $this->withRequest('POST');
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function withRequest(string $method): void
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

        $_SERVER['REQUEST_METHOD'] = $method;
    }

    private function withSettingsManagerPermissions(): void
    {
        $user = $this->createTestUser('__sm_debt7_smoke_user_', [
            'admin' => true,
        ]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents);

        return $contents;
    }
}

/**
 * @since 5.54.0
 */
final class Pr1Debt7RecordingAutocompleteService extends AutocompleteService
{
    /** @var list<array{query: string, indexHandle: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $this->calls[] = [
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];

        return [
            'suggestions' => [],
            'meta' => [],
        ];
    }
}

/**
 * @since 5.54.0
 */
final class Pr1Debt7FixedConfigIndexValidator extends ConfigIndexValidator
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
