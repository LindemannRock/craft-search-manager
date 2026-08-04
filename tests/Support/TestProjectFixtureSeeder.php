<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Support;

use Craft;
use craft\base\ElementInterface;
use craft\behaviors\FieldLayoutBehavior;
use craft\ckeditor\Field as CkeditorField;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\Volume;
use lindemannrock\docsmanager\DocsManager;
use lindemannrock\docsmanager\elements\db\SourceDocQuery;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\records\SourceRecord;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Seeds the package-owned fixture through real Craft and installed plugin APIs.
 *
 * @since 5.54.0
 */
final class TestProjectFixtureSeeder
{
    /** @var array<string, mixed> */
    private array $manifest;

    /** @var array<string, int|string> */
    private array $resolved = [];

    public function __construct(private readonly TestProjectBoundary $boundary)
    {
        if (!$boundary->disposable) {
            throw new \LogicException('The deterministic fixture seeder may run only inside a disposable project.');
        }
        $this->manifest = DeterministicFixtureManifest::load();
        DeterministicFixtureManifest::assertExpectedHash();
        DeterministicFixtureManifest::assertRealDependenciesInstalled();
    }

    /** @return array<string, mixed> */
    public function seed(): array
    {
        $sites = $this->seedSites();
        $fields = $this->seedFields();
        [$section, $entryType] = $this->seedSection($sites, $fields);
        $this->seedEntries($sites, $section, $entryType);
        $this->seedAsset($sites);
        $this->seedCategory($sites);
        $this->seedUser($sites);
        $this->seedCommerce($sites, $fields);
        $this->seedDocsManager($sites);
        $this->seedSearchIndices($sites);

        ksort($this->resolved, SORT_STRING);
        $resolvedHash = hash(
            'sha256',
            json_encode($this->resolved, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $identity = [
            'manifestHash' => DeterministicFixtureManifest::hash(),
            'resolved' => $this->resolved,
            'resolvedHash' => $resolvedHash,
        ];
        $identityPath = $this->boundary->storageRoot . '/search-manager-fixture-identity.json';
        $encoded = json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $existing = is_file($identityPath) ? file_get_contents($identityPath) : false;
        if ($existing !== false && $existing !== $encoded) {
            throw new \RuntimeException('Fixture idempotence failed: resolved identity changed between seed passes.');
        }
        if (file_put_contents($identityPath, $encoded) === false) {
            throw new \RuntimeException('Unable to write the fixture identity record.');
        }

        return $identity;
    }

    /** @return list<Site> */
    private function seedSites(): array
    {
        $sitesService = Craft::$app->getSites();
        $siteRows = $this->listManifest('sites');
        $primaryData = $siteRows[0];
        $primary = $sitesService->getPrimarySite();
        $primary->name = $this->string($primaryData, 'name');
        $primary->handle = $this->string($primaryData, 'handle');
        $primary->language = $this->string($primaryData, 'language');
        $primary->baseUrl = $this->string($primaryData, 'baseUrl');
        $this->save($sitesService->saveSite($primary), $primary, 'primary fixture site');
        $primaryUid = $this->string($primaryData, 'uid');
        $this->forceUid('{{%sites}}', (int)$primary->id, $primaryUid);
        $primary->uid = $primaryUid;
        $this->resolved['sites.' . $primary->handle] = (int)$primary->id;

        $secondaryData = $siteRows[1];
        $secondary = $sitesService->getSiteByHandle($this->string($secondaryData, 'handle'));
        if ($secondary === null) {
            $secondary = new Site([
                'name' => $this->string($secondaryData, 'name'),
                'handle' => $this->string($secondaryData, 'handle'),
                'language' => $this->string($secondaryData, 'language'),
                'baseUrl' => $this->string($secondaryData, 'baseUrl'),
                'groupId' => $primary->groupId,
                'primary' => false,
                'enabled' => true,
                'uid' => $this->string($secondaryData, 'uid'),
            ]);
            $this->save($sitesService->saveSite($secondary), $secondary, 'secondary fixture site');
            $this->forceUid('{{%sites}}', (int)$secondary->id, $this->string($secondaryData, 'uid'));
        }
        $this->resolved['sites.' . $secondary->handle] = (int)$secondary->id;

        return [$primary, $secondary];
    }

    /** @return list<\craft\base\FieldInterface> */
    private function seedFields(): array
    {
        $fields = [];
        foreach ($this->listManifest('fields') as $data) {
            $handle = $this->string($data, 'handle');
            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            if ($field === null) {
                $class = $this->string($data, 'type');
                if (!is_a($class, PlainText::class, true) && !is_a($class, CkeditorField::class, true)) {
                    throw new \RuntimeException("Unsupported fixture field type: {$class}");
                }
                $field = new $class([
                    'name' => $this->string($data, 'name'),
                    'handle' => $handle,
                    'uid' => $this->string($data, 'uid'),
                    'searchable' => true,
                ]);
                $this->save(Craft::$app->getFields()->saveField($field), $field, "field {$handle}");
            }
            $this->resolved['fields.' . $handle] = (int)$field->id;
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param list<Site> $sites
     * @param list<\craft\base\FieldInterface> $fields
     * @return array{Section, EntryType}
     */
    private function seedSection(array $sites, array $fields): array
    {
        $data = $this->firstManifest('sections');
        $entries = Craft::$app->getEntries();
        $entryType = $entries->getEntryTypeByHandle($this->string($data, 'entryTypeHandle'));
        if ($entryType === null) {
            $entryType = new EntryType([
                'name' => $this->string($data, 'entryTypeName'),
                'handle' => $this->string($data, 'entryTypeHandle'),
                'uid' => $this->string($data, 'entryTypeUid'),
                'hasTitleField' => true,
            ]);
            $entryType->setFieldLayout($this->fieldLayout(Entry::class, $fields));
            $this->save($entries->saveEntryType($entryType), $entryType, 'fixture entry type');
        }

        $section = $entries->getSectionByHandle($this->string($data, 'handle'));
        if ($section === null) {
            $siteSettings = [];
            foreach ($sites as $site) {
                $siteSettings[] = new Section_SiteSettings([
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => 'fixture-pages/{slug}',
                    'template' => 'test-search.twig',
                ]);
            }
            $section = new Section([
                'name' => $this->string($data, 'name'),
                'handle' => $this->string($data, 'handle'),
                'type' => Section::TYPE_CHANNEL,
                'uid' => $this->string($data, 'uid'),
                'propagationMethod' => PropagationMethod::All,
            ]);
            $section->setEntryTypes([$entryType]);
            $section->setSiteSettings($siteSettings);
            $this->save($entries->saveSection($section), $section, 'fixture section');
        }
        $this->resolved['entryTypes.' . $entryType->handle] = (int)$entryType->id;
        $this->resolved['sections.' . $section->handle] = (int)$section->id;

        return [$section, $entryType];
    }

    /** @param list<Site> $sites */
    private function seedEntries(array $sites, Section $section, EntryType $entryType): void
    {
        foreach ($this->listManifest('entries') as $data) {
            $slug = $this->string($data, 'slug');
            $entry = Entry::find()->sectionId($section->id)->slug($slug)->siteId($sites[0]->id)->status(null)->one();
            if (!$entry instanceof Entry) {
                $entry = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId = $entryType->id;
                $entry->siteId = $this->supportedFixtureSiteId($entry, $sites, "entry {$slug}");
                $entry->enabled = true;
                $entry->enabledForSite = true;
                $entry->title = $this->string($data, 'title');
                $entry->slug = $slug;
                $entry->uid = $this->string($data, 'uid');
                $entry->setFieldValues([
                    'fixtureSummary' => $this->string($data, 'summary'),
                    'fixtureRichText' => $this->string($data, 'richText'),
                ]);
                $this->save(Craft::$app->getElements()->saveElement($entry, updateSearchIndex: false), $entry, "entry {$slug}");
            }
            if ($entry->title !== $this->string($data, 'title')) {
                throw new \RuntimeException('Fixture entry title mismatch: ' . var_export($entry->title, true));
            }
            $this->resolved['entries.' . $slug] = (int)$entry->id;
        }
    }

    /** @param list<Site> $sites */
    private function seedAsset(array $sites): void
    {
        $data = $this->mapManifest('assets');
        $assetRoot = $this->boundary->storageRoot . '/fixture-assets';
        if (!is_dir($assetRoot) && !mkdir($assetRoot, 0700, true) && !is_dir($assetRoot)) {
            throw new \RuntimeException('Unable to create the fixture asset root.');
        }
        $fsHandle = $this->string($data, 'filesystemHandle');
        $fs = Craft::$app->getFs()->getFilesystemByHandle($fsHandle);
        if ($fs === null) {
            $fs = new Local([
                'name' => 'Fixture Assets Filesystem',
                'handle' => $fsHandle,
                'uid' => $this->string($data, 'filesystemUid'),
                'path' => $assetRoot,
                'hasUrls' => false,
            ]);
            $this->save(Craft::$app->getFs()->saveFilesystem($fs), $fs, 'fixture filesystem');
        }
        $volumeHandle = $this->string($data, 'volumeHandle');
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if ($volume === null) {
            $volume = new Volume([
                'name' => 'Fixture Assets',
                'handle' => $volumeHandle,
                'uid' => $this->string($data, 'volumeUid'),
            ]);
            $volume->setFsHandle($fsHandle);
            $this->save(Craft::$app->getVolumes()->saveVolume($volume), $volume, 'fixture volume');
        }

        $filename = $this->string($data, 'filename');
        $asset = Asset::find()->volumeId($volume->id)->filename($filename)->siteId($sites[0]->id)->status(null)->one();
        if (!$asset instanceof Asset) {
            $temporary = $this->boundary->storageRoot . '/fixture-asset-upload-' . $filename;
            if (file_put_contents($temporary, $this->string($data, 'content')) === false) {
                throw new \RuntimeException('Unable to create the fixture asset upload file.');
            }
            $folder = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id);
            $asset = new Asset();
            $asset->setVolumeId((int)$volume->id);
            $asset->newFolderId = (int)$folder->id;
            $asset->siteId = $this->supportedFixtureSiteId($asset, $sites, 'fixture asset');
            $asset->title = 'Fixture Search Asset';
            $asset->uid = $this->string($data, 'assetUid');
            $asset->setFilename($filename);
            $asset->tempFilePath = $temporary;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $this->save(Craft::$app->getElements()->saveElement($asset, updateSearchIndex: false), $asset, 'fixture asset');
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->resolved['assets.' . $filename] = (int)$asset->id;
        $this->resolved['volumes.' . $volumeHandle] = (int)$volume->id;
    }

    /** @param list<Site> $sites */
    private function seedCategory(array $sites): void
    {
        $data = $this->mapManifest('categories');
        $handle = $this->string($data, 'groupHandle');
        $group = Craft::$app->getCategories()->getGroupByHandle($handle);
        if ($group === null) {
            $settings = [];
            foreach ($sites as $site) {
                $settings[] = new CategoryGroup_SiteSettings([
                    'siteId' => $site->id,
                    'hasUrls' => true,
                    'uriFormat' => 'fixture-topics/{slug}',
                    'template' => 'test-search.twig',
                ]);
            }
            $group = new CategoryGroup([
                'name' => 'Fixture Topics',
                'handle' => $handle,
                'uid' => $this->string($data, 'groupUid'),
                'maxLevels' => 2,
            ]);
            $group->setSiteSettings($settings);
            $configuredSiteIds = array_map('intval', array_keys($group->getSiteSettings()));
            $installedSiteIds = array_map('intval', Craft::$app->getSites()->getAllSiteIds());
            sort($configuredSiteIds, SORT_NUMERIC);
            sort($installedSiteIds, SORT_NUMERIC);
            if ($configuredSiteIds !== $installedSiteIds) {
                throw new \RuntimeException(
                    'Fixture category site settings do not match the deterministic installed sites: configured '
                    . implode(', ', $configuredSiteIds) . '; installed ' . implode(', ', $installedSiteIds),
                );
            }
            $this->save(Craft::$app->getCategories()->saveGroup($group), $group, 'fixture category group');
        }
        $slug = $this->string($data, 'slug');
        $category = Category::find()->groupId($group->id)->slug($slug)->siteId($sites[0]->id)->status(null)->one();
        if (!$category instanceof Category) {
            $category = new Category();
            $category->groupId = $group->id;
            $category->siteId = $this->supportedFixtureSiteId($category, $sites, 'fixture category');
            $category->title = 'Fixture Search Topic';
            $category->slug = $slug;
            $category->uid = $this->string($data, 'categoryUid');
            $this->save(Craft::$app->getElements()->saveElement($category, updateSearchIndex: false), $category, 'fixture category');
        }
        $this->resolved['categories.' . $slug] = (int)$category->id;
        $this->resolved['categoryGroups.' . $handle] = (int)$group->id;
    }

    /** @param list<Site> $sites */
    private function seedUser(array $sites): void
    {
        $data = $this->mapManifest('users');
        $username = $this->string($data, 'username');
        $user = User::find()->username($username)->status(null)->one();
        if (!$user instanceof User) {
            $user = User::find()->username('fixture-admin')->admin()->status(null)->one();
            if (!$user instanceof User) {
                throw new \RuntimeException('The disposable Craft administrator is unavailable for the deterministic user fixture.');
            }
            $user->siteId = $this->supportedFixtureSiteId($user, $sites, 'fixture user');
            $user->username = $username;
            $user->email = $this->string($data, 'email');
            $user->firstName = 'Fixture';
            $user->lastName = 'Search User';
            $user->uid = $this->string($data, 'uid');
            $this->save(Craft::$app->getElements()->saveElement($user, updateSearchIndex: false), $user, 'fixture user');
        }
        if (!$user->active || $user->pending) {
            throw new \RuntimeException('The deterministic fixture user is not active.');
        }
        $this->resolved['users.' . $username] = (int)$user->id;
    }

    /**
     * @param list<Site> $sites
     * @param list<\craft\base\FieldInterface> $fields
     */
    private function seedCommerce(array $sites, array $fields): void
    {
        $data = $this->mapManifest('commerce');
        $commerce = Commerce::getInstance();
        $handle = $this->string($data, 'productTypeHandle');
        $type = $commerce->getProductTypes()->getProductTypeByHandle($handle);
        if ($type === null) {
            $siteSettings = [];
            foreach ($sites as $site) {
                $siteId = (int)$site->id;
                $siteSettings[$siteId] = new ProductTypeSite([
                    'siteId' => $siteId,
                    'hasUrls' => true,
                    'uriFormat' => 'fixture-products/{slug}',
                    'template' => 'test-search.twig',
                    'enabledByDefault' => true,
                ]);
            }
            $type = new ProductType([
                'name' => 'Fixture Products',
                'handle' => $handle,
                'uid' => $this->string($data, 'productTypeUid'),
                'hasProductTitleField' => true,
                'hasVariantTitleField' => true,
                'maxVariants' => 10,
            ]);
            $productLayout = $type->getBehavior('productFieldLayout');
            $variantLayout = $type->getBehavior('variantFieldLayout');
            if (!$productLayout instanceof FieldLayoutBehavior || !$variantLayout instanceof FieldLayoutBehavior) {
                throw new \RuntimeException('Commerce product type field-layout behaviors are unavailable.');
            }
            $commerceFields = array_values(array_filter(
                $fields,
                static fn(\craft\base\FieldInterface $field): bool => in_array(
                    $field->handle,
                    ['heading', 'productDescription', 'wysiwyg'],
                    true,
                ),
            ));
            $productLayout->setFieldLayout($this->fieldLayout(Product::class, $commerceFields));
            $variantLayout->setFieldLayout($this->fieldLayout(Variant::class, []));
            $type->setSiteSettings($siteSettings);
            $this->save($commerce->getProductTypes()->saveProductType($type), $type, 'fixture product type');
        }

        $slug = $this->string($data, 'productSlug');
        $product = Product::find()->typeId($type->id)->slug($slug)->siteId($sites[0]->id)->status(null)->one();
        if (!$product instanceof Product) {
            $product = new Product();
            $product->typeId = $type->id;
            $product->siteId = $this->supportedFixtureSiteId($product, $sites, 'fixture product');
            $product->title = 'Fixture Eco Shirt';
            $product->slug = $slug;
            $product->postDate = new \DateTime('2026-01-01 00:00:00 UTC');
            $product->enabled = true;
            $product->enabledForSite = true;
            $product->uid = $this->string($data, 'productUid');
            $product->setFieldValues([
                'heading' => $this->string($data, 'heading'),
                'productDescription' => $this->string($data, 'productDescription'),
                'wysiwyg' => $this->string($data, 'wysiwyg'),
            ]);
            $variants = [];
            $skus = $data['variantSkus'] ?? null;
            $uids = $data['variantUids'] ?? null;
            if (!is_array($skus) || !is_array($uids) || count($skus) !== count($uids)) {
                throw new \RuntimeException('Commerce fixture variant identities are invalid.');
            }
            foreach ($skus as $position => $sku) {
                if (!is_string($sku) || !is_string($uids[$position] ?? null)) {
                    throw new \RuntimeException('Commerce fixture variant identity must be a string.');
                }
                $variant = new Variant();
                $variant->title = $position === 0 ? 'Red Shirt' : 'Blue Shirt';
                $variant->isDefault = $position === 0;
                $variant->enabled = true;
                $variant->uid = $uids[$position];
                $variant->setSku($sku);
                $variant->setPrice($position === 0 ? 25.0 : 27.0);
                $variants[] = $variant;
            }
            $product->setVariants($variants);
            $this->save(Craft::$app->getElements()->saveElement($product, updateSearchIndex: false), $product, 'fixture product');
        }
        $this->resolved['commerce.productTypes.' . $handle] = (int)$type->id;
        $this->resolved['commerce.products.' . $slug] = (int)$product->id;
        foreach ($product->getVariants(true) as $variant) {
            $this->resolved['commerce.variants.' . $variant->getSku()] = (int)$variant->id;
        }
    }

    /** @param list<Site> $sites */
    private function seedDocsManager(array $sites): void
    {
        $data = $this->mapManifest('docsManager');
        $handle = $this->string($data, 'sourceHandle');
        $source = SourceRecord::find()->where(['handle' => $handle])->one();
        if (!$source instanceof SourceRecord) {
            $source = new SourceRecord();
            $source->name = 'Fixture Docs';
            $source->handle = $handle;
            $source->kind = 'plugin';
            $source->sourceType = 'local';
            $source->localPath = $this->boundary->packageRoot . '/docs';
            $source->enabled = true;
            $source->uid = $this->string($data, 'sourceUid');
            $this->save($source->save(false), $source, 'fixture Docs Manager source');
        }
        $settings = DocsManager::getInstance()->getSettings();
        $settings->enabledSites = array_map(static fn(Site $site): int => (int)$site->id, $sites);
        $this->save($settings->saveToDatabase(['enabledSites']), $settings, 'Docs Manager site settings');

        $slug = $this->string($data, 'docSlug');
        $query = SourceDoc::find();
        if (!$query instanceof SourceDocQuery) {
            throw new \RuntimeException('Docs Manager did not provide its supported SourceDoc query.');
        }
        $doc = $query->sourceId($source->id)->slug($slug)->siteId($sites[0]->id)->status(null)->one();
        if (!$doc instanceof SourceDoc) {
            $doc = new SourceDoc();
            $doc->sourceId = (int)$source->id;
            $doc->siteId = $this->supportedFixtureSiteId($doc, $sites, 'fixture SourceDoc');
            $doc->slug = $slug;
            $doc->version = '';
            $doc->category = 'fixture';
            $doc->order = 1;
            $doc->title = $this->string($data, 'title');
            $doc->description = $this->string($data, 'description');
            $doc->htmlContent = $this->string($data, 'htmlContent');
            $doc->markdownSource = '# Fixture SourceDoc Search Guide';
            $doc->keywords = ['fixture', 'search'];
            $doc->uid = $this->string($data, 'docUid');
            $this->save(Craft::$app->getElements()->saveElement($doc, updateSearchIndex: false), $doc, 'fixture SourceDoc');
        }
        $this->resolved['docsManager.sources.' . $handle] = (int)$source->id;
        $this->resolved['docsManager.docs.' . $slug] = (int)$doc->id;
    }

    /** @param list<Site> $sites */
    private function seedSearchIndices(array $sites): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $backend = $this->mapManifest('backend');
        $backendHandle = $this->string($backend, 'handle');
        $backendId = (new \craft\db\Query())->select(['id'])->from('{{%searchmanager_backends}}')->where(['handle' => $backendHandle])->scalar();
        if ($backendId === false) {
            $db->createCommand()->insert('{{%searchmanager_backends}}', [
                'name' => 'Fixture MySQL',
                'handle' => $backendHandle,
                'backendType' => $this->string($backend, 'type'),
                'settings' => '{}',
                'enabled' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => $this->string($backend, 'uid'),
            ])->execute();
            $backendId = $db->getLastInsertID();
        }
        $this->resolved['search.backends.' . $backendHandle] = (int)$backendId;

        foreach ($this->listManifest('indices') as $data) {
            $handle = $this->string($data, 'handle');
            $id = (new \craft\db\Query())->select(['id'])->from('{{%searchmanager_indices}}')->where(['handle' => $handle])->scalar();
            $attributes = [
                'name' => preg_replace('/(?<!^)[A-Z]/', ' $0', $handle),
                'handle' => $handle,
                'elementType' => $this->string($data, 'elementType'),
                'siteId' => null,
                'criteria' => '{}',
                'transformerClass' => '',
                'headingLevels' => ($data['split'] ?? false) ? '[2,3]' : null,
                'language' => null,
                'enabled' => 1,
                'enableAnalytics' => ($data['analytics'] ?? false) ? 1 : 0,
                'disableStopWords' => 0,
                'skipEntriesWithoutUrl' => 0,
                'splitSections' => ($data['split'] ?? false) ? 1 : 0,
                'retrievableFields' => '["*"]',
                'source' => 'database',
                'backend' => $backendHandle,
                'lastIndexed' => null,
                'documentCount' => 0,
                'dateUpdated' => $now,
            ];
            if ($id === false) {
                $attributes['dateCreated'] = $now;
                $attributes['uid'] = $this->string($data, 'uid');
                $db->createCommand()->insert('{{%searchmanager_indices}}', $attributes)->execute();
                $id = $db->getLastInsertID();
            } else {
                $db->createCommand()->update('{{%searchmanager_indices}}', $attributes, ['id' => $id])->execute();
            }
            foreach ($sites as $site) {
                $exists = (new \craft\db\Query())->from('{{%searchmanager_index_sites}}')->where([
                    'indexId' => $id,
                    'siteId' => $site->id,
                ])->exists();
                if (!$exists) {
                    $db->createCommand()->insert('{{%searchmanager_index_sites}}', [
                        'indexId' => $id,
                        'siteId' => $site->id,
                    ])->execute();
                }
            }
            $this->resolved['search.indices.' . $handle] = (int)$id;
        }
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultBackendHandle = $backendHandle;
        $settings->enableAnalytics = true;
        $this->save(
            $settings->saveToDatabase(['defaultBackendHandle', 'enableAnalytics']),
            $settings,
            'Search Manager fixture settings',
        );

        $config = $this->mapManifest('searchManagerConfig');
        $indexPrefix = $this->string($config, 'indexPrefix');
        if ($settings->indexPrefix !== $indexPrefix) {
            throw new \RuntimeException('Search Manager did not load the deterministic fixture index prefix.');
        }
        $this->resolved['search.config.indexPrefix'] = $indexPrefix;
        foreach ($this->listFromMap($config, 'indices') as $definition) {
            $handle = $this->string($definition, 'handle');
            $index = SearchIndex::findByHandle($handle);
            if (!$index instanceof SearchIndex || $index->source !== 'config') {
                throw new \RuntimeException("Search Manager config index was not resolved: {$handle}");
            }
            $this->resolved['search.config.indices.' . $handle] = $handle;
        }
    }

    /** @param list<\craft\base\FieldInterface> $fields */
    private function fieldLayout(string $elementType, array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => $elementType]);
        $elements = array_map(
            static fn(\craft\base\FieldInterface $field): CustomField => new CustomField($field),
            $fields,
        );
        if ($elementType === Entry::class) {
            array_unshift($elements, new EntryTitleField(['required' => true]));
        }
        if ($elements !== []) {
            $tab = new FieldLayoutTab(['name' => 'Content']);
            $tab->setLayout($layout);
            $tab->setElements($elements);
            $layout->setTabs([$tab]);
        }

        return $layout;
    }

    private function forceUid(string $table, int $id, string $uid): void
    {
        Craft::$app->getDb()->createCommand()->update($table, ['uid' => $uid], ['id' => $id])->execute();
    }

    /** @param list<Site> $sites */
    private function supportedFixtureSiteId(ElementInterface $element, array $sites, string $label): int
    {
        $supportedSiteIds = array_map(
            static fn(array $site): int => (int)$site['siteId'],
            ElementHelper::supportedSitesForElement($element),
        );
        foreach ($sites as $site) {
            $siteId = (int)$site->id;
            if (in_array($siteId, $supportedSiteIds, true)) {
                return $siteId;
            }
        }

        throw new \RuntimeException(
            "No deterministic fixture site is supported by {$label}; supported sites: "
            . implode(', ', $supportedSiteIds),
        );
    }

    private function save(bool $success, object $model, string $label): void
    {
        if ($success) {
            return;
        }
        $errors = method_exists($model, 'getErrorSummary') ? $model->getErrorSummary(true) : [];
        throw new \RuntimeException("Unable to save {$label}: " . implode('; ', $errors));
    }

    /** @return array<string, mixed> */
    private function mapManifest(string $key): array
    {
        $value = $this->manifest[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("Fixture manifest {$key} must be a map.");
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function listManifest(string $key): array
    {
        $value = $this->manifest[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("Fixture manifest {$key} must be a list.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("Fixture manifest {$key} contains an invalid row.");
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $map
     * @return list<array<string, mixed>>
     */
    private function listFromMap(array $map, string $key): array
    {
        $value = $map[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("Fixture manifest map value {$key} must be a list.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("Fixture manifest map value {$key} contains an invalid row.");
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function firstManifest(string $key): array
    {
        $rows = $this->listManifest($key);
        if ($rows === []) {
            throw new \RuntimeException("Fixture manifest {$key} is empty.");
        }

        return $rows[0];
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Fixture manifest value {$key} must be a non-empty string.");
        }

        return $value;
    }
}
