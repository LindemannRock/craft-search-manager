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
use craft\web\Request;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\web\Response;

/**
 * @since 5.54.0
 */
#[CoversClass(SettingsController::class)]
final class SettingsFailureRedisplayTest extends TestCase
{
    public function testInvalidSearchSettingsRedisplayRendersAndPreservesInput(): void
    {
        $settings = Settings::loadFromDatabase();
        $settings->bm25K1 = 99.0;
        self::assertFalse($settings->validate(['bm25K1']));
        $user = $this->createTestUser('__sm_458_settings_user_');
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);
        $originalRequest = Craft::$app->getRequest();
        $originalResponse = Craft::$app->getResponse();
        $originalUser = Craft::$app->getUser();
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new \craft\web\Response());
        $renderUser = new class() extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?\craft\elements\User
            {
                return null;
            }
        };
        $renderUser->setIdentity($user);
        Craft::$app->set('user', $renderUser);

        try {
            $controller = new class('settings', SearchManager::$plugin) extends SettingsController {
                public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
                {
                    $variables['currentUser'] = Craft::$app->getUser()->getIdentity();
                    $html = Craft::$app->getView()->renderTemplate($template, $variables, $templateMode);
                    $response = new Response();
                    $response->data = $html;

                    return $response;
                }
            };

            $method = new \ReflectionMethod($controller, '_renderSettingsTemplate');
            $response = $method->invoke($controller, 'search', $settings);

            self::assertInstanceOf(Response::class, $response);
            self::assertIsString($response->data);
            self::assertStringContainsString('Search Settings', $response->data);
            self::assertMatchesRegularExpression(
                '/name="settings\\[bm25K1\\]"[^>]*value="99"/',
                $response->data,
            );
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
            Craft::$app->set('user', $originalUser);
        }
    }

    public function testEverySettingsSectionUsesOneCompleteVariableAssembler(): void
    {
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_settingsTemplateVariables');
        $settings = Settings::loadFromDatabase();

        $expectedKeys = [
            'general' => ['settings', 'backends', 'enabledBackends', 'widgets', 'enabledWidgets'],
            'indexing' => ['settings'],
            'analytics' => ['settings'],
            'search' => ['settings', 'nativeSearchCoverageReport', 'nativeSearchHasLocalBackend', 'nativeSearchDefaultBackendIsLocal', 'nativeSearchLocalBackendOptions'],
            'language' => ['settings'],
            'highlighting' => ['settings'],
            'autocomplete' => ['settings'],
            'snippets' => ['settings'],
            'cache' => ['settings'],
            'interface' => ['settings'],
            'test' => ['settings', 'cacheEnabled', 'backends', 'snippetOptions', 'testIndexChoices', 'indexSiteIds'],
        ];

        foreach ($expectedKeys as $section => $keys) {
            $variables = $method->invoke($controller, $section, $settings);
            self::assertIsArray($variables);
            self::assertSame($keys, array_keys($variables), "Unexpected {$section} template variables.");
        }

        $source = $this->readPluginFile('src/controllers/SettingsController.php');
        foreach (array_keys($expectedKeys) as $section) {
            self::assertStringContainsString(
                "\$this->_settingsTemplateVariables('{$section}', \$settings)",
                $source,
                "The {$section} GET action must use the shared settings variable assembler.",
            );
        }

        self::assertMatchesRegularExpression(
            '/private function _renderSettingsTemplate.*?\\$this->_settingsTemplateVariables\\(\\$section, \\$settings\\)/s',
            $source,
        );
    }

    public function testWidgetFailureVariablesMatchTheirEditTemplates(): void
    {
        $source = $this->readPluginFile('src/controllers/WidgetsController.php');
        $saveBody = $this->methodBody($source, 'actionSave');
        $saveStyleBody = $this->methodBody($source, 'actionSaveStyle');

        self::assertStringContainsString(
            "'snippetOptions' => SnippetOptionsHelper::widgetDefaults(),",
            $saveBody,
        );
        self::assertStringContainsString(
            "'usageCount' => \$styleId ? (int)(\$styleUsageCounts[\$widgetStyle->handle] ?? 0) : null,",
            $saveStyleBody,
        );
        self::assertStringContainsString(
            'SearchManager::$plugin->dependencies->getStyleUsageCountsByHandle()',
            $saveStyleBody,
        );
        self::assertSame(2, substr_count($saveStyleBody, 'setRouteParams($errorRouteParams)'));
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match('/public function ' . preg_quote($method, '/') . '\(.*?^    }$/ms', $source, $methodMatches);
        self::assertNotEmpty($methodMatches, $method . ' source should be found.');

        return $methodMatches[0];
    }

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents, $relativePath . ' should be readable.');

        return $contents;
    }
}
