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
use craft\elements\User;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for cause-aware setup and config-index recovery details.
 *
 * @since 5.54.0
 */
final class ConfigIndexRecoveryPresentationTest extends TestCase
{
    private const HANDLE = 'sm-config-recovery';

    private mixed $originalConfigCache = null;
    private User $renderIdentity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $user = $this->createTestUser('__sm_config_recovery_user_');
        $this->grantPermissions($user, ['accessCp']);
        $this->actingAs($user);
        $this->renderIdentity = $user;
    }

    protected function tearDown(): void
    {
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{bool, bool, bool, string|null}>
     */
    public static function setupCauseProvider(): iterable
    {
        yield 'backend readiness only' => [
            true,
            true,
            false,
            'Resolve unavailable backend assignments.',
        ];
        yield 'config errors only' => [
            true,
            false,
            true,
            'Resolve configuration index issues before rebuilding affected indices.',
        ];
        yield 'missing IP salt only' => [
            false,
            true,
            true,
            'Finish setup before tracking search analytics.',
        ];
        yield 'backend and config blockers' => [
            true,
            false,
            false,
            'Resolve unavailable backend assignments and configuration index issues before rebuilding affected indices.',
        ];
        yield 'backend and IP salt blockers' => [
            false,
            true,
            false,
            'Resolve unavailable backend assignments, and finish setup before tracking search analytics.',
        ];
        yield 'config and IP salt blockers' => [
            false,
            false,
            true,
            'Resolve configuration index issues before rebuilding affected indices, and finish setup before tracking search analytics.',
        ];
        yield 'all blockers' => [
            false,
            false,
            false,
            'Resolve unavailable backend assignments and configuration index issues before rebuilding affected indices, and finish setup before tracking search analytics.',
        ];
        yield 'fully complete' => [true, true, true, null];
    }

    #[DataProvider('setupCauseProvider')]
    public function testSharedSetupSummaryUsesOneExactCauseAwareMessage(
        bool $ipSaltConfigured,
        bool $configIndicesValid,
        bool $backendReadinessValid,
        ?string $expectedMessage,
    ): void {
        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/_partials/setup-incomplete-summary',
            [
                'selectedSubnavItem' => 'indices',
                'setupStatus' => [
                    'complete' => $ipSaltConfigured && $configIndicesValid && $backendReadinessValid,
                    'setupUrl' => 'search-manager/setup',
                    'ipSaltConfigured' => $ipSaltConfigured,
                    'configIndicesValid' => $configIndicesValid,
                    'backendReadinessValid' => $backendReadinessValid,
                ],
            ],
            View::TEMPLATE_MODE_CP,
        );

        $messages = [
            'Resolve unavailable backend assignments.',
            'Resolve configuration index issues before rebuilding affected indices.',
            'Finish setup before tracking search analytics.',
            'Resolve unavailable backend assignments and configuration index issues before rebuilding affected indices.',
            'Resolve unavailable backend assignments, and finish setup before tracking search analytics.',
            'Resolve configuration index issues before rebuilding affected indices, and finish setup before tracking search analytics.',
            'Resolve unavailable backend assignments and configuration index issues before rebuilding affected indices, and finish setup before tracking search analytics.',
        ];

        self::assertSame($expectedMessage !== null, str_contains($html, 'lr-info-box--setup-incomplete'));
        foreach ($messages as $message) {
            self::assertSame($message === $expectedMessage, str_contains($html, $message), $message);
        }
        if ($expectedMessage !== null) {
            self::assertSame(1, substr_count($html, $expectedMessage));
        }
        self::assertStringNotContainsString(
            'Select a valid default backend or index backend before using this action.',
            $html,
        );
    }

    public function testSharedSetupSummaryIsSuppressedOnSetupSubnavigation(): void
    {
        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/_partials/setup-incomplete-summary',
            [
                'selectedSubnavItem' => 'setup',
                'setupStatus' => [
                    'complete' => false,
                    'setupUrl' => 'search-manager/setup',
                    'ipSaltConfigured' => false,
                    'configIndicesValid' => false,
                    'backendReadinessValid' => true,
                ],
            ],
            View::TEMPLATE_MODE_CP,
        );

        self::assertSame('', trim($html));
    }

    public function testSharedSetupSummaryDoesNotBuildCombinedCopyAtRuntime(): void
    {
        $source = $this->pluginFile('src/templates/_partials/setup-incomplete-summary.twig');

        self::assertStringNotContainsString('setupMessages', $source);
        self::assertStringNotContainsString('|merge(', $source);
        self::assertStringNotContainsString('|join(', $source);
        self::assertStringNotContainsString('backendReadinessMessage', $source);
        self::assertStringNotContainsString(
            '{pluginName} found configuration index issues that can affect indexing.',
            $source,
        );
        self::assertSame(1, substr_count($source, 'message: setupMessage'));
    }

    public function testSetupStateMessagesExistInEveryLocaleInTheSameOrder(): void
    {
        $keys = [
            'Resolve unavailable backend assignments.',
            'Resolve configuration index issues before rebuilding affected indices.',
            'Finish setup before tracking search analytics.',
            'Resolve unavailable backend assignments and configuration index issues before rebuilding affected indices.',
            'Resolve unavailable backend assignments, and finish setup before tracking search analytics.',
            'Resolve configuration index issues before rebuilding affected indices, and finish setup before tracking search analytics.',
            'Resolve unavailable backend assignments and configuration index issues before rebuilding affected indices, and finish setup before tracking search analytics.',
        ];

        foreach (glob(dirname(__DIR__, 2) . '/src/translations/*/search-manager.php') ?: [] as $file) {
            $translations = require $file;
            self::assertIsArray($translations);
            $orderedKeys = array_keys($translations);
            $positions = [];
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $translations, $file);
                self::assertNotSame('', trim((string)$translations[$key]), $file);
                $positions[] = array_search($key, $orderedKeys, true);
            }

            self::assertTrue(
                is_int($positions[0])
                && is_int($positions[1])
                && is_int($positions[2])
                && is_int($positions[3])
                && is_int($positions[4])
                && is_int($positions[5])
                && is_int($positions[6])
                && $positions[0] < $positions[1]
                && $positions[1] < $positions[2]
                && $positions[2] < $positions[3]
                && $positions[3] < $positions[4]
                && $positions[4] < $positions[5]
                && $positions[5] < $positions[6],
                $file,
            );
        }
    }

    public function testEveryExistingCpSetupConsumerUsesTheSharedSummaryPartial(): void
    {
        $templateRoot = dirname(__DIR__, 2) . '/src/templates';
        $consumers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (str_contains($source, 'search-manager/_partials/setup-incomplete-summary')) {
                $consumers[] = $file->getPathname();
            }
            if (!str_ends_with($file->getPathname(), '/_partials/setup-incomplete-summary.twig')) {
                self::assertStringNotContainsString(
                    'lindemannrock-base/_components/setup-incomplete',
                    $source,
                    $file->getPathname(),
                );
            }
        }

        self::assertCount(22, $consumers);
    }

    public function testCatalogueRetainsEveryApplicableFindingInValidatorOrder(): void
    {
        $result = $this->validationResult([
            [null, 'warning', 'global-warning', 'Global warning'],
            [self::HANDLE, 'error', 'first-error', 'First error'],
            ['other-index', 'error', 'other-error', 'Other error'],
            [self::HANDLE, 'warning', 'local-warning', 'Local warning'],
            [null, 'error', 'global-error', 'Global error'],
            [self::HANDLE, 'error', 'second-error', 'Second error'],
        ]);

        $record = $this->catalogueRecord($result);

        self::assertSame(
            ['global-warning', 'first-error', 'local-warning', 'global-error', 'second-error'],
            array_column($record['findings'], 'key'),
        );
        self::assertSame('First error', $record['errorTitle']);
        self::assertSame('error', $record['state']);
    }

    public function testOneErrorRendersOneColoredErrorBox(): void
    {
        $record = $this->catalogueRecord($this->validationResult([
            [self::HANDLE, 'error', 'backend', 'Missing backend'],
        ]));

        $html = $this->renderFindings($record['findings']);

        self::assertSame(1, substr_count($html, 'lr-info-box--error'));
        self::assertSame(1, substr_count($html, 'lr-info-box--colored'));
        self::assertSame(1, substr_count($html, '<li>'));
        self::assertSame(1, substr_count($html, '<strong>Error:</strong>'));
        self::assertSame(1, substr_count($html, 'Missing backend'));
    }

    public function testMultipleErrorsAndWarningsRenderOnceInOrderInsideOneErrorBox(): void
    {
        $record = $this->catalogueRecord($this->validationResult([
            [self::HANDLE, 'warning', 'name', 'First warning'],
            [self::HANDLE, 'error', 'backend', 'Second error'],
            [self::HANDLE, 'warning', 'criteria', 'Third warning'],
            [self::HANDLE, 'error', 'transformer', 'Fourth error'],
        ]));

        $html = $this->renderFindings($record['findings']);

        self::assertSame(1, substr_count($html, 'lr-info-box--error'));
        self::assertSame(4, substr_count($html, '<li>'));
        foreach (['First warning', 'Second error', 'Third warning', 'Fourth error'] as $message) {
            self::assertSame(1, substr_count($html, $message));
        }
        self::assertTrue(
            strpos($html, 'First warning') < strpos($html, 'Second error')
            && strpos($html, 'Second error') < strpos($html, 'Third warning')
            && strpos($html, 'Third warning') < strpos($html, 'Fourth error'),
        );
    }

    public function testWarningOnlyFindingsUseWarningBoxWithoutChangingEffectiveStatus(): void
    {
        $record = $this->catalogueRecord($this->validationResult([
            [self::HANDLE, 'warning', 'name', 'Name is empty'],
        ]));

        $html = $this->renderFindings($record['findings']);

        self::assertSame('enabled', $record['state']);
        self::assertNull($record['errorTitle']);
        self::assertStringContainsString('lr-info-box--warning', $html);
        self::assertStringNotContainsString('lr-info-box--error', $html);
        self::assertStringContainsString('<strong>Warning:</strong>', $html);
    }

    public function testCleanConfigIndexRendersNoFindingsBox(): void
    {
        $record = $this->catalogueRecord($this->validationResult([]));

        self::assertSame([], $record['findings']);
        self::assertSame('', trim($this->renderFindings($record['findings'])));
    }

    public function testDatabaseIndexIgnoresConfigFindingsAndHasNoRecoveryBox(): void
    {
        $index = new SearchIndex([
            'name' => 'Database Index',
            'handle' => 'sm-database-recovery',
            'elementType' => Entry::class,
            'source' => 'database',
            'enabled' => true,
        ]);
        $validation = $this->validationResult([
            [null, 'error', 'global-error', 'Global config error'],
            [$index->handle, 'error', 'local-error', 'Handle-specific config error'],
        ]);
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator($validation),
        );
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $record = $this->withOnlySearchIndices([$index], function() use ($index): array {
            SearchManager::$plugin->dependencies->clearIndexCatalogue();

            return SearchManager::$plugin->dependencies->getIndexCatalogue()[$index->handle];
        });

        self::assertSame([], $record['findings']);
        self::assertSame('enabled', $record['state']);
        self::assertSame('', trim($this->renderFindings($record['findings'])));
    }

    public function testFindingEmphasisIsBoldAndAdversarialContentRemainsEscaped(): void
    {
        $message = 'Unsafe <script>alert("x")</script> & "quote" field';
        $emphasis = '<script>alert("x")</script>';
        $record = $this->catalogueRecord($this->validationResult([
            [self::HANDLE, 'error', 'unsafe', $message, $emphasis],
        ]));

        $html = $this->renderFindings($record['findings']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString(
            '<strong>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</strong>',
            $html,
        );
        self::assertStringContainsString('&amp; &quot;quote&quot; field', $html);
    }

    public function testSetupConfigViewAndDatabaseEditShareOneFindingComponent(): void
    {
        $setup = $this->pluginFile('src/templates/setup.twig');
        $view = $this->pluginFile('src/templates/indices/view.twig');
        $edit = $this->pluginFile('src/templates/indices/edit.twig');
        $controller = $this->pluginFile('src/controllers/IndicesController.php');
        $dependencies = $this->pluginFile('src/services/DependencyService.php');
        $component = 'search-manager/_components/_index-findings';

        self::assertStringContainsString($component, $setup);
        self::assertStringContainsString($component, $view);
        self::assertStringContainsString($component, $edit);
        self::assertStringNotContainsString('configIndexValidator->validate()', $controller);
        self::assertSame(1, substr_count($dependencies, 'configIndexValidator->validate()'));
        self::assertLessThan(
            strpos($view, 'indexFindingsSummary'),
            strpos($view, 'setupIncompleteSummary'),
        );
        self::assertLessThan(
            strpos($edit, 'indexFindingsSummary'),
            strpos($edit, 'setupIncompleteSummary'),
        );
        self::assertStringContainsString(
            '[setupIncompleteSummary, indexFindingsSummary, validationSummary]',
            $edit,
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/src/templates/_components/_config-index-findings.twig',
        );
    }

    public function testDatabaseEditAndConfigViewRenderTheSameRecoveryBox(): void
    {
        $message = 'Disabled provider recovery detail.';
        $findings = [[
            'severity' => ConfigIndexValidationResult::SEVERITY_ERROR,
            'handle' => self::HANDLE,
            'key' => 'element-provider-disabled',
            'message' => $message,
            'emphasis' => 'provider',
        ]];
        $status = [
            'label' => 'Error',
            'value' => 'error',
            'colorSet' => 'status',
            'title' => $message,
        ];
        $reference = [
            'displayName' => 'Recovery Index',
            'findings' => $findings,
            'status' => $status,
        ];

        $configIndex = new SearchIndex([
            'name' => 'Recovery Index',
            'handle' => self::HANDLE,
            'elementType' => Entry::class,
            'source' => 'config',
            'enabled' => true,
        ]);
        $databaseIndex = new SearchIndex([
            'id' => 987654,
            'name' => 'Recovery Index',
            'handle' => self::HANDLE,
            'elementType' => Entry::class,
            'source' => 'database',
            'enabled' => true,
        ]);

        $viewHtml = $this->renderCpTemplate('search-manager/indices/view', [
            'index' => $configIndex,
            'indexReference' => $reference,
            'elementTypeLabels' => [Entry::class => 'Entry'],
        ]);
        $editHtml = $this->renderCpTemplate('search-manager/indices/edit', [
            'index' => $databaseIndex,
            'isNew' => false,
            'indexReference' => $reference,
            'defaultHeadingLevels' => [2, 3, 4],
            'elementTypeOptions' => [Entry::class => 'Entry'],
            'docsManagerTransformerAvailable' => false,
            'defaultTransformerPlaceholder' => Entry::class,
            'transformerPlaceholders' => [],
            'splitSectionsByElementType' => [Entry::class => true],
        ]);

        foreach ([$viewHtml, $editHtml] as $html) {
            self::assertSame(2, substr_count($html, $message));
            self::assertSame(1, substr_count($html, 'lr-info-box--error'));
            self::assertStringContainsString('title="' . $message . '"', $html);
        }
    }

    /**
     * @param list<array{0: string|null, 1: string, 2: string, 3: string, 4?: string|null}> $findings
     */
    private function validationResult(array $findings): ConfigIndexValidationResult
    {
        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        foreach ($findings as $finding) {
            $result->addFinding(
                $finding[0],
                $finding[1],
                $finding[2],
                $finding[3],
                $finding[4] ?? null,
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogueRecord(ConfigIndexValidationResult $validation): array
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = [
            'indices' => [
                self::HANDLE => [
                    'name' => 'Recovery Index',
                    'elementType' => Entry::class,
                    'enabled' => true,
                ],
            ],
        ];
        $this->setConfigCache($cache);
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator($validation),
        );
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        return SearchManager::$plugin->dependencies->getIndexCatalogue()[self::HANDLE];
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function renderFindings(array $findings): string
    {
        return Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_index-findings',
            [
                'findings' => $findings,
                'presentation' => 'box',
            ],
            View::TEMPLATE_MODE_CP,
        );
    }

    private function pluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderCpTemplate(string $template, array $variables): string
    {
        $originalRequest = Craft::$app->getRequest();
        $originalResponse = Craft::$app->getResponse();
        $originalUser = Craft::$app->getUser();
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $renderUser = new class() extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?User
            {
                return null;
            }
        };
        $renderUser->setIdentity($this->renderIdentity);
        Craft::$app->set('user', $renderUser);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $variables['currentUser'] = $this->renderIdentity;

            return Craft::$app->getView()->renderTemplate(
                $template,
                $variables,
                View::TEMPLATE_MODE_CP,
            );
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
            Craft::$app->set('user', $originalUser);
            $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
        }
    }
}
