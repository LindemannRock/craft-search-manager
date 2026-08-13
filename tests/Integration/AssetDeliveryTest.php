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
use craft\db\Connection;
use craft\web\AssetBundle;
use craft\web\AssetManager;
use craft\web\View;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\web\assets\analytics\AnalyticsAsset;
use lindemannrock\searchmanager\web\assets\highlighter\SearchHighlighterAsset;
use lindemannrock\searchmanager\web\assets\searchwidget\SearchWidgetAsset;
use lindemannrock\searchmanager\web\assets\testtool\TestToolAsset;
use lindemannrock\searchmanager\web\assets\widgetconfig\WidgetConfigAsset;
use lindemannrock\searchmanager\web\assets\widgetpreview\WidgetPreviewAsset;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers static asset delivery for control-panel and frontend features.
 *
 * @since 5.54.3
 */
final class AssetDeliveryTest extends TestCase
{
    /**
     * @param class-string<AssetBundle> $bundleClass
     * @param list<string> $js
     * @param list<string> $css
     * @param list<class-string<AssetBundle>> $depends
     */
    #[DataProvider('assetBundleDefinitions')]
    public function testBundlesResolveComposerAliasAndPreserveDefinitions(
        string $bundleClass,
        string $sourceSuffix,
        array $js,
        array $css,
        array $depends,
    ): void {
        $originalAlias = Craft::getAlias('@lindemannrock/searchmanager');
        $originalDb = Craft::$app->getDb();
        $originalPlugin = SearchManager::$plugin;
        $aliasRoot = $this->createOwnedTempDirectory('asset-package');
        $offlineDb = new Connection([
            'dsn' => 'unsupported:search-manager-asset-test',
        ]);

        try {
            Craft::setAlias('@lindemannrock/searchmanager', $aliasRoot);
            Craft::$app->set('db', $offlineDb);
            SearchManager::$plugin = null;

            $bundle = new $bundleClass();

            self::assertFalse($offlineDb->getIsActive());
            self::assertSame($aliasRoot . $sourceSuffix, $bundle->sourcePath);
            self::assertSame($js, $bundle->js);
            self::assertSame($css, $bundle->css);
            self::assertSame($depends, $bundle->depends);
        } finally {
            SearchManager::$plugin = $originalPlugin;
            Craft::$app->set('db', $originalDb);
            Craft::setAlias('@lindemannrock/searchmanager', $originalAlias);
        }
    }

    public function testFrontendWidgetUsesPrepublishedBundleUrlWithoutRuntimePublication(): void
    {
        $originalAssetManager = Craft::$app->getAssetManager();
        $view = Craft::$app->getView();
        $publicationRoot = $this->createOwnedTempDirectory('asset-publication');
        $packageDist = Craft::getAlias('@lindemannrock/searchmanager/web/assets/searchwidget/dist');
        $baseUrl = 'https://cdn.example.test/search-manager-widget';
        $assetManager = new class([ 'basePath' => $publicationRoot, 'baseUrl' => '/runtime-resources', 'blockedPath' => $packageDist, 'bundles' => [ SearchWidgetAsset::class => [ 'basePath' => $packageDist, 'baseUrl' => $baseUrl, ], ], ]) extends AssetManager {
            public string $blockedPath;

            /** @var list<string> */
            public array $publicationPaths = [];

            /**
             * @inheritdoc
             */
            public function publish($path, $options = []): array
            {
                $resolvedPath = Craft::getAlias($path);
                $this->publicationPaths[] = $resolvedPath;
                if ($resolvedPath === $this->blockedPath) {
                    throw new \RuntimeException('Runtime publication of the widget package is unavailable.');
                }

                return parent::publish($path, $options);
            }
        };
        $widget = new WidgetConfig([
            'handle' => 'asset-delivery',
            'name' => 'Asset Delivery',
            'type' => 'modal',
            'enabled' => true,
            'settings' => WidgetConfig::defaultSettings(),
        ]);
        $service = new class($widget) extends WidgetConfigService {
            public function __construct(private readonly WidgetConfig $widget)
            {
                parent::__construct();
            }

            public function getConfigForWidget(?string $handle = null): WidgetConfig
            {
                return $this->widget;
            }
        };

        try {
            $this->swapPluginComponent('search-manager', 'widgetConfigs', $service);
            Craft::$app->set('assetManager', $assetManager);
            $view->clear();

            $html = $view->renderTemplate('search-manager/_widget/search-modal');
            $html .= $view->renderTemplate('search-manager/_widget/search-modal');

            self::assertNotContains($packageDist, $assetManager->publicationPaths);
            self::assertSame(2, substr_count($html, '<search-modal'));
            self::assertSame([SearchWidgetAsset::class], array_keys($view->assetBundles));
            self::assertInstanceOf(SearchWidgetAsset::class, $view->assetBundles[SearchWidgetAsset::class]);
            self::assertSame($packageDist, $view->assetBundles[SearchWidgetAsset::class]->sourcePath);
            self::assertSame($packageDist, $view->assetBundles[SearchWidgetAsset::class]->basePath);
            self::assertSame($baseUrl, $view->assetBundles[SearchWidgetAsset::class]->baseUrl);
            self::assertSame(['SearchModalWidget.js'], $view->assetBundles[SearchWidgetAsset::class]->js);
            self::assertSame(
                [$baseUrl . '/SearchModalWidget.js'],
                array_keys($view->jsFiles[View::POS_END] ?? []),
            );
        } finally {
            $view->clear();
            Craft::$app->set('assetManager', $originalAssetManager);
        }
    }

    public function testCustomerArchiveIncludesBuiltAssetFiles(): void
    {
        $expected = [
            'src/web/assets/analytics/dist/analytics.js',
            'src/web/assets/highlighter/dist/SearchManagerHighlighter.js',
            'src/web/assets/searchwidget/dist/SearchModalWidget.js',
            'src/web/assets/testtool/dist/test-tool.css',
            'src/web/assets/testtool/dist/test-tool.js',
            'src/web/assets/widgetconfig/dist/widget-config.js',
            'src/web/assets/widgetpreview/dist/widget-preview.js',
        ];
        $packageRoot = dirname(__DIR__, 2);

        foreach ($expected as $path) {
            self::assertFileExists($packageRoot . '/' . $path, $path);
        }

        $pipes = [];
        $process = proc_open(
            ['git', '-c', 'safe.directory=' . $packageRoot, 'check-attr', 'export-ignore', '--', ...$expected],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $packageRoot,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($error) ? $error : '');
        self::assertIsString($output);

        foreach ($expected as $path) {
            self::assertStringContainsString("{$path}: export-ignore: unspecified", $output, $path);
        }
    }

    /**
     * @return iterable<string, array{class-string<AssetBundle>, string, list<string>, list<string>, list<class-string<AssetBundle>>}>
     */
    public static function assetBundleDefinitions(): iterable
    {
        yield 'analytics' => [
            AnalyticsAsset::class,
            '/web/assets/analytics/dist',
            ['analytics.js'],
            [],
            [\lindemannrock\base\web\assets\analytics\AnalyticsAsset::class],
        ];
        yield 'highlighter' => [
            SearchHighlighterAsset::class,
            '/web/assets/highlighter/dist',
            ['SearchManagerHighlighter.js'],
            [],
            [],
        ];
        yield 'test tool' => [
            TestToolAsset::class,
            '/web/assets/testtool/dist',
            ['test-tool.js'],
            ['test-tool.css'],
            [SearchHighlighterAsset::class],
        ];
        yield 'widget config' => [
            WidgetConfigAsset::class,
            '/web/assets/widgetconfig/dist',
            ['widget-config.js'],
            [],
            [],
        ];
        yield 'widget preview' => [
            WidgetPreviewAsset::class,
            '/web/assets/widgetpreview/dist',
            ['widget-preview.js'],
            [],
            [],
        ];
        yield 'frontend search widget' => [
            SearchWidgetAsset::class,
            '/web/assets/searchwidget/dist',
            ['SearchModalWidget.js'],
            [],
            [],
        ];
    }
}
