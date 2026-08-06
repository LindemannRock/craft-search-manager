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
use craft\web\Response as WebResponse;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\SearchManager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins the shipped Postman resources to the customer-first public REST contract.
 *
 * @since 5.54.0
 */
final class PostmanArtifactContractTest extends TestCase
{
    private const COLLECTION_FILE = 'resources/postman/Search-Manager.postman_collection.json';
    private const ENVIRONMENT_FILE = 'resources/postman/Search-Manager.postman_environment.json';
    private const README_FILE = 'resources/postman/README.md';
    private const FIXTURE_FILE = 'tests/Support/PostmanFixture.php';

    /**
     * @var array<string, list<string>>
     */
    private const FOLDER_REQUESTS = [
        'Start Here' => [
            'Search all allowed indices',
            'Autocomplete across all allowed indices',
        ],
        'Search Examples' => [
            'Search one index',
            'Search multiple indices',
            'Search one site',
            'Search multiple indices within one site',
            'Canonical widget-style response',
        ],
        'Autocomplete Examples' => [
            'Autocomplete one index',
            'Autocomplete multiple indices',
            'Suggestions only',
            'Results only',
        ],
        'Analytics' => [
            'Track search',
            'Track click',
        ],
        'Developer Validation — Optional' => [
            'Missing public API key',
            'Invalid public API key',
            'Disallowed Referer',
            'Disallowed Origin',
            'Out-of-scope index',
            'Unknown site',
            'Deterministic rate-limit validation',
        ],
    ];

    /**
     * @var list<string>
     */
    private const ENVIRONMENT_VARIABLES = [
        'base_url',
        'query',
        'public_api_key',
        'index_handle',
        'index_handles',
        'site_id',
        'element_id',
        'developer_api_key_enforcement_enabled',
        'developer_public_api_key',
        'developer_blocked_index_handle',
        'developer_rate_limit_api_key',
        'developer_rate_limit_allowed_requests',
        'developer_rate_limit_runner_iterations',
    ];

    public function testDownloadUsesCanonicalResourceDirectoryAndStableArchiveContract(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $canonicalDirectory = $packageRoot . '/resources/postman';
        $legacyDirectory = $packageRoot . '/postman';
        $expectedFiles = [
            'search-manager.postman_collection.json' => 'Search-Manager.postman_collection.json',
            'search-manager.postman_environment.json' => 'Search-Manager.postman_environment.json',
            'readme.md' => 'README.md',
        ];

        self::assertDirectoryExists($canonicalDirectory);
        self::assertDirectoryDoesNotExist($legacyDirectory);
        foreach ($expectedFiles as $sourceFilename) {
            self::assertFileExists($canonicalDirectory . '/' . $sourceFilename);
            self::assertFileDoesNotExist($legacyDirectory . '/' . $sourceFilename);
        }

        $controller = new class('settings', SearchManager::$plugin) extends SettingsController {
            public function requirePermission(string $permissionName): void
            {
            }
        };
        $originalResponse = Craft::$app->getResponse();
        Craft::$app->set('response', new WebResponse());

        try {
            $response = $controller->actionDownloadPostmanCollection();
        } finally {
            Craft::$app->set('response', $originalResponse);
        }

        self::assertSame('application/zip; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame(
            'attachment; filename="search-manager-postman.zip"',
            $response->headers->get('Content-Disposition'),
        );

        $tempFile = tempnam(sys_get_temp_dir(), 'search-manager-postman-');
        self::assertIsString($tempFile);
        $archive = new ZipArchive();
        $archiveIsOpen = false;

        try {
            $content = (string)$response->content;
            self::assertSame(strlen($content), file_put_contents($tempFile, $content));
            self::assertTrue($archive->open($tempFile));
            $archiveIsOpen = true;

            $archiveFiles = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $filename = $archive->getNameIndex($index);
                self::assertIsString($filename);
                $archiveFiles[] = $filename;
            }

            self::assertSame(array_keys($expectedFiles), $archiveFiles);
            foreach ($expectedFiles as $archiveFilename => $sourceFilename) {
                self::assertSame(
                    file_get_contents($canonicalDirectory . '/' . $sourceFilename),
                    $archive->getFromName($archiveFilename),
                    $archiveFilename,
                );
            }
        } finally {
            if ($archiveIsOpen) {
                $archive->close();
            }
            unlink($tempFile);
        }

        self::assertStringContainsString(
            "'search-manager/settings/download-postman-collection' => 'search-manager/settings/download-postman-collection'",
            $this->file('src/SearchManager.php'),
        );
        foreach ([
            'src/templates/settings/test/_partials/search.twig',
            'src/templates/utilities/index.twig',
        ] as $template) {
            self::assertStringContainsString(
                "actionUrl('search-manager/settings/download-postman-collection')",
                $this->file($template),
                $template,
            );
        }
    }

    public function testCollectionAndEnvironmentUseTheCustomerFirstStructure(): void
    {
        $collection = $this->collection();
        $environment = $this->environment();

        self::assertSame(
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            $collection['info']['schema'] ?? null,
        );
        self::assertSame('Search Manager API', $collection['info']['name'] ?? null);
        self::assertSame('apikey', $collection['auth']['type'] ?? null);
        self::assertSame('X-Search-Manager-Key', $this->authValue($collection['auth'], 'key'));
        self::assertSame('{{public_api_key}}', $this->authValue($collection['auth'], 'value'));
        self::assertSame('environment', $environment['_postman_variable_scope'] ?? null);
        self::assertSame('Search Manager API', $environment['name'] ?? null);
        self::assertSame(array_keys(self::FOLDER_REQUESTS), array_column($collection['item'] ?? [], 'name'));
        self::assertSame([], $collection['variable'] ?? []);

        foreach ($collection['item'] ?? [] as $folder) {
            $folderName = (string)$folder['name'];
            self::assertSame(self::FOLDER_REQUESTS[$folderName], array_column($folder['item'] ?? [], 'name'));
        }

        self::assertSame(['prerequest'], array_column($collection['event'] ?? [], 'listen'));
        $collectionGuard = $this->collectionScript('prerequest');
        self::assertStringContainsString("pm.environment.get('base_url')", $collectionGuard);
        self::assertStringContainsString("pm.request.headers.upsert({key: 'Referer'", $collectionGuard);
        self::assertStringNotContainsString('skipRequest', $collectionGuard);
        self::assertStringNotContainsString('request_contracts', $collectionGuard);
    }

    public function testShippedEnvironmentIsSimpleOrderedAndContainsNoFixtureDefaults(): void
    {
        $environment = $this->environment();
        $values = [];

        foreach ($environment['values'] ?? [] as $variable) {
            $key = (string)($variable['key'] ?? '');
            self::assertArrayNotHasKey($key, $values, "Duplicate environment variable: {$key}");
            self::assertTrue((bool)($variable['enabled'] ?? false), $key);
            self::assertNotSame('', trim((string)($variable['description'] ?? '')), $key);
            $values[$key] = $variable;
        }

        self::assertSame(self::ENVIRONMENT_VARIABLES, array_keys($values));
        self::assertSame('https://yoursite.com', $values['base_url']['value']);
        self::assertSame('test', $values['query']['value']);
        self::assertSame('', $values['public_api_key']['value']);
        self::assertSame('secret', $values['public_api_key']['type']);
        foreach (['index_handle', 'index_handles', 'site_id', 'element_id'] as $optional) {
            self::assertSame('', $values[$optional]['value'], $optional);
        }
        foreach (array_slice(self::ENVIRONMENT_VARIABLES, 7) as $developerVariable) {
            self::assertStringContainsString(
                'Developer Validation only',
                (string)$values[$developerVariable]['description'],
                $developerVariable,
            );
        }

        $shipped = $this->shippedSource();
        foreach (['postman-fixture', 'api_mode', 'edition_mode', 'request_contracts'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $shipped, $forbidden);
        }
        self::assertStringNotContainsString('localhost', $this->file(self::ENVIRONMENT_FILE));
        self::assertStringNotContainsString('.ddev.site', $this->file(self::ENVIRONMENT_FILE));
        self::assertStringNotContainsString('.internal', $this->file(self::ENVIRONMENT_FILE));
    }

    public function testEveryReferencedEnvironmentVariableIsDeclaredAndConsumed(): void
    {
        $collectionSource = $this->file(self::COLLECTION_FILE);
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $collectionSource, $templateMatches);
        preg_match_all("/pm\\.environment\\.get\\('([a-zA-Z0-9_]+)'\\)/", $collectionSource, $scriptMatches);
        $referenced = array_values(array_unique(array_merge($templateMatches[1], $scriptMatches[1])));
        sort($referenced);
        $declared = self::ENVIRONMENT_VARIABLES;
        sort($declared);

        self::assertSame($declared, $referenced);
    }

    public function testEveryRequestHasASelfDescribingExecutableContract(): void
    {
        foreach ($this->requestsByPath() as $path => $item) {
            $request = $item['request'] ?? [];
            $requestSource = json_encode($request, JSON_THROW_ON_ERROR);
            $testEvents = $this->events($item, 'test');

            self::assertNotSame('', trim((string)($item['name'] ?? '')), $path);
            self::assertNotSame('', trim((string)($request['description'] ?? '')), $path);
            self::assertContains($request['method'] ?? null, ['GET', 'POST'], $path);
            self::assertStringStartsWith('{{base_url}}/actions/search-manager/', $this->rawUrl($item), $path);
            self::assertIsArray($request['header'] ?? null, $path);
            self::assertSame([], $item['response'] ?? null, $path);
            self::assertCount(1, $testEvents, $path);
            self::assertNotSame('', trim(implode("\n", $testEvents[0]['script']['exec'] ?? [])), $path);
            self::assertStringNotContainsString('api_mode', $requestSource, $path);
            self::assertStringNotContainsString('edition_mode', $requestSource, $path);
            self::assertStringNotContainsString('postman-fixture', $requestSource, $path);
            self::assertStringNotContainsString('server_api_key', $requestSource, $path);
            self::assertStringNotContainsString('to.include(pm.response.code)', $this->script($item, 'test'), $path);
        }
    }

    public function testStartHereNeedsNoModesOrIndexScopeAndExplainsValidEmptyResults(): void
    {
        $requests = $this->requestsByPath();

        foreach ([
            'Start Here > Search all allowed indices',
            'Start Here > Autocomplete across all allowed indices',
        ] as $path) {
            $item = $requests[$path];
            $url = $this->rawUrl($item);
            self::assertStringContainsString('{{base_url}}', $url, $path);
            self::assertStringContainsString('q={{query}}', $url, $path);
            self::assertStringNotContainsString('indexHandles=', $url, $path);
            self::assertSame([], $this->events($item, 'prerequest'), $path);
            self::assertStringContainsString('zero', strtolower((string)$item['request']['description']), $path);
            $test = $this->script($item, 'test');
            self::assertStringContainsString('pm.response.code === 200', $test, $path);
            self::assertStringContainsString('pm.response.code === 401', $test, $path);
            self::assertStringContainsString('public_api_key', $test, $path);
            self::assertStringContainsString('zero', strtolower($test), $path);
        }

        self::assertStringContainsString(
            'skipAnalytics=1',
            $this->rawUrl($requests['Start Here > Search all allowed indices']),
        );
        self::assertStringNotContainsString(
            'skipAnalytics',
            $this->rawUrl($requests['Start Here > Autocomplete across all allowed indices']),
        );
    }

    public function testSearchAndAutocompleteRepresentOmittedSingularAndMultipleScopesConsistently(): void
    {
        $requests = $this->requestsByPath();

        self::assertStringContainsString(
            'indexHandles={{index_handle}}',
            $this->rawUrl($requests['Search Examples > Search one index']),
        );
        self::assertStringContainsString(
            'indexHandles={{index_handles}}',
            $this->rawUrl($requests['Search Examples > Search multiple indices']),
        );
        self::assertStringNotContainsString(
            'indexHandles=',
            $this->rawUrl($requests['Search Examples > Search one site']),
        );
        self::assertStringContainsString(
            'siteId={{site_id}}',
            $this->rawUrl($requests['Search Examples > Search one site']),
        );
        $multiSite = $this->rawUrl($requests['Search Examples > Search multiple indices within one site']);
        self::assertStringContainsString('indexHandles={{index_handles}}', $multiSite);
        self::assertStringContainsString('siteId={{site_id}}', $multiSite);
        self::assertStringContainsString(
            'indexHandles={{index_handles}}',
            $this->rawUrl($requests['Search Examples > Canonical widget-style response']),
        );

        self::assertStringContainsString(
            'indexHandles={{index_handle}}',
            $this->rawUrl($requests['Autocomplete Examples > Autocomplete one index']),
        );
        self::assertStringContainsString(
            'indexHandles={{index_handles}}',
            $this->rawUrl($requests['Autocomplete Examples > Autocomplete multiple indices']),
        );
        foreach (['Suggestions only', 'Results only'] as $name) {
            self::assertStringNotContainsString(
                'indexHandles=',
                $this->rawUrl($requests["Autocomplete Examples > {$name}"]),
                $name,
            );
        }

        foreach ($requests as $path => $item) {
            $url = $this->rawUrl($item);
            if (str_contains($url, '/api/search?') && !str_contains($path, 'Developer Validation')) {
                self::assertStringContainsString('skipAnalytics=1', $url, $path);
            }
            self::assertStringNotContainsString('indices=', $url, $path);
        }

        self::assertStringContainsString('maximum five', strtolower((string)$this->folder('Search Examples')['description']));
        self::assertStringContainsString('same omitted, singular', strtolower((string)$this->folder('Autocomplete Examples')['description']));
    }

    public function testAnalyticsUsesServerResponsesWithoutAnEditionVariable(): void
    {
        $requests = $this->requestsByPath();
        $trackSearch = $requests['Analytics > Track search'];
        $trackClick = $requests['Analytics > Track click'];

        self::assertSame(
            ['q', 'resultsCount', 'trigger', 'analyticsSource'],
            $this->bodyKeys($trackSearch),
        );
        self::assertSame(
            ['elementId', 'query', 'index', 'position'],
            $this->bodyKeys($trackClick),
        );
        self::assertSame([], $this->events($trackSearch, 'prerequest'));
        self::assertCount(1, $this->events($trackClick, 'prerequest'));
        self::assertStringContainsString(
            'const required = ["element_id","index_handle"]',
            $this->script($trackClick, 'prerequest'),
        );
        self::assertStringContainsString(
            'Set element_id to a real result element ID and index_handle to the enabled index that returned it',
            $this->script($trackClick, 'prerequest'),
        );

        $searchTest = $this->script($trackSearch, 'test');
        self::assertStringContainsString('pm.response.code === 200', $searchTest);
        self::assertStringContainsString("property('success', true)", $searchTest);
        self::assertStringContainsString('body.tracked === true', $searchTest);
        self::assertStringContainsString('body.tracked === false', $searchTest);
        self::assertStringContainsString('accepted but not recorded under the current Analytics settings', $searchTest);
        self::assertStringContainsString('pm.response.code === 204', $searchTest);
        self::assertStringContainsString("pm.expect(pm.response.text()).to.eql('')", $searchTest);
        self::assertStringContainsString('pm.response.code === 401', $searchTest);
        self::assertStringContainsString('Set public_api_key to a permitted Search Manager public key', $searchTest);
        self::assertStringContainsString('pm.expect.fail', $searchTest);

        $clickTest = $this->script($trackClick, 'test');
        self::assertStringContainsString('pm.response.code === 200', $clickTest);
        self::assertStringContainsString("property('success', true)", $clickTest);
        self::assertStringContainsString('pm.response.code === 204', $clickTest);
        self::assertStringContainsString('pm.response.code === 401', $clickTest);
        self::assertStringContainsString('Set public_api_key to a permitted Search Manager public key', $clickTest);
        self::assertStringContainsString('pm.expect.fail', $clickTest);
        self::assertStringContainsString(
            'A 200 proves acceptance only, not persistence',
            (string)$trackClick['request']['description'],
        );

        foreach ([
            self::README_FILE,
            'docs/resources/testing-tools.md',
            'docs/feature-tour/utilities.md',
        ] as $documentation) {
            $source = $this->file($documentation);
            self::assertStringContainsString('accepted but not recorded', $source, $documentation);
            self::assertStringContainsString('acceptance only', $source, $documentation);
            self::assertStringContainsString('public_api_key', $source, $documentation);
        }
    }

    public function testDeveloperValidationIsIsolatedAndUsesExactDisposableContracts(): void
    {
        $requests = $this->requestsByPath();
        $developerFolder = $this->folder('Developer Validation — Optional');
        self::assertStringContainsString('disposable local', strtolower((string)$developerFolder['description']));
        self::assertStringContainsString('never use production', strtolower((string)$developerFolder['description']));

        $statuses = [
            'Missing public API key' => 401,
            'Invalid public API key' => 401,
            'Disallowed Referer' => 403,
            'Disallowed Origin' => 403,
            'Out-of-scope index' => 403,
            'Unknown site' => 400,
        ];
        foreach ($statuses as $name => $status) {
            $path = "Developer Validation — Optional > {$name}";
            self::assertStringContainsString(
                "pm.response.to.have.status({$status})",
                $this->script($requests[$path], 'test'),
                $path,
            );
            self::assertCount(1, $this->events($requests[$path], 'prerequest'), $path);
            self::assertStringContainsString('SKIP:', $this->script($requests[$path], 'prerequest'), $path);
            self::assertStringContainsString(
                "pm.environment.get('developer_api_key_enforcement_enabled')",
                $this->script($requests[$path], 'prerequest'),
                $path,
            );
            self::assertStringContainsString(
                "missing.push('developer_api_key_enforcement_enabled=yes')",
                $this->script($requests[$path], 'prerequest'),
                $path,
            );
        }

        self::assertSame('noauth', $requests['Developer Validation — Optional > Missing public API key']['request']['auth']['type'] ?? null);
        self::assertSame(
            'sm_pub_invalid_example',
            $this->authValue(
                $requests['Developer Validation — Optional > Invalid public API key']['request']['auth'],
                'value',
            ),
        );
        self::assertSame(
            'https://blocked.invalid/',
            $this->headerValue($requests['Developer Validation — Optional > Disallowed Referer'], 'Referer'),
        );
        self::assertSame(
            'https://blocked.invalid',
            $this->headerValue($requests['Developer Validation — Optional > Disallowed Origin'], 'Origin'),
        );
        self::assertStringContainsString(
            'indexHandles={{developer_blocked_index_handle}}',
            $this->rawUrl($requests['Developer Validation — Optional > Out-of-scope index']),
        );
        self::assertStringContainsString(
            'siteId=999999999',
            $this->rawUrl($requests['Developer Validation — Optional > Unknown site']),
        );

        $rate = $requests['Developer Validation — Optional > Deterministic rate-limit validation'];
        self::assertSame(
            '{{developer_rate_limit_api_key}}',
            $this->authValue($rate['request']['auth'], 'value'),
        );
        self::assertStringContainsString('pm.info.iterationCount !== iterations', $this->script($rate, 'prerequest'));
        self::assertStringContainsString('pm.info.iteration < allowed ? 200 : 429', $this->script($rate, 'test'));
        self::assertStringContainsString('Runner-only', (string)$rate['request']['description']);
        self::assertStringContainsString('skipAnalytics=1', $this->rawUrl($rate));

        foreach (['Start Here', 'Search Examples', 'Autocomplete Examples', 'Analytics'] as $customerFolder) {
            self::assertStringNotContainsString(
                'developer_',
                json_encode($this->folder($customerFolder), JSON_THROW_ON_ERROR),
                $customerFolder,
            );
        }
    }

    public function testRoutesBodiesAndAuthenticationMatchTheControllers(): void
    {
        foreach ($this->requestsByPath() as $path => $item) {
            $request = $item['request'];
            $url = $this->rawUrl($item);
            self::assertStringStartsWith('{{base_url}}/actions/search-manager/', $url, $path);

            if (str_contains($url, '/api/search?')) {
                self::assertSame('GET', $request['method'] ?? null, $path);
                self::assertStringContainsString('q={{query}}', $url, $path);
            }
            if (str_contains($url, '/api/autocomplete?')) {
                self::assertSame('GET', $request['method'] ?? null, $path);
                self::assertStringContainsString('q={{query}}', $url, $path);
            }
            if (str_contains($url, '/search/track-')) {
                self::assertSame('POST', $request['method'] ?? null, $path);
            }
        }

        self::assertSame('apikey', $this->collection()['auth']['type'] ?? null);
        foreach ([
            'Developer Validation — Optional > Invalid public API key',
            'Developer Validation — Optional > Disallowed Referer',
            'Developer Validation — Optional > Disallowed Origin',
            'Developer Validation — Optional > Out-of-scope index',
            'Developer Validation — Optional > Unknown site',
            'Developer Validation — Optional > Deterministic rate-limit validation',
        ] as $path) {
            self::assertSame('apikey', $this->requestsByPath()[$path]['request']['auth']['type'] ?? null, $path);
        }
    }

    public function testInternalFixtureKeepsDeterministicModesWithoutLeakingThemToShippedFiles(): void
    {
        $fixture = $this->file(self::FIXTURE_FILE);

        foreach ([
            'POSTMAN_FIXTURE_INDEX',
            'POSTMAN_FIXTURE_SECOND_INDEX',
            'POSTMAN_FIXTURE_BLOCKED_INDEX',
            'POSTMAN_FIXTURE_RATE_LIMIT = 3',
            'POSTMAN_FIXTURE_ITERATIONS = 5',
            "['anonymous', 'keyed', 'rate-limit']",
            '[SearchManager::EDITION_STANDARD, SearchManager::EDITION_PRO]',
            "['analytics-enabled', 'analytics-disabled']",
            "applyApiModeSettings('anonymous'",
            'applyApiModeSettings($apiMode',
            "'enableAnalytics' => \$analyticsEnabled ? 1 : 0",
            'applyAnalyticsConfigMode($analyticsEnabled',
            'restoreAnalyticsConfig($state)',
            "'analyticsConfigRestored' => true",
            'restoreManagedSettings($state)',
            'array_sum($counts) !== 0',
        ] as $contract) {
            self::assertStringContainsString($contract, $fixture, $contract);
        }

        self::assertStringContainsString(
            "'index_handles' => POSTMAN_FIXTURE_INDEX . ',' . POSTMAN_FIXTURE_SECOND_INDEX",
            $fixture,
        );
        self::assertStringContainsString(
            "'public_api_key' => \$apiMode === 'anonymous' ? '' : (string)\$state['publicPlaintext']",
            $fixture,
        );
        self::assertStringContainsString(
            "'developer_blocked_index_handle' => POSTMAN_FIXTURE_BLOCKED_INDEX",
            $fixture,
        );
        self::assertStringContainsString(
            "'developer_rate_limit_api_key' => (string)\$state['ratePlaintext']",
            $fixture,
        );
        self::assertStringNotContainsString('POSTMAN_FIXTURE', $this->shippedSource());
    }

    /**
     * @return array<string, mixed>
     */
    private function collection(): array
    {
        return $this->decode(self::COLLECTION_FILE);
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        return $this->decode(self::ENVIRONMENT_FILE);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function requestsByPath(): array
    {
        $requests = [];
        foreach ($this->collection()['item'] ?? [] as $folder) {
            foreach ($folder['item'] ?? [] as $item) {
                $requests[(string)$folder['name'] . ' > ' . (string)$item['name']] = $item;
            }
        }

        return $requests;
    }

    /**
     * @return array<string, mixed>
     */
    private function folder(string $name): array
    {
        foreach ($this->collection()['item'] ?? [] as $folder) {
            if (($folder['name'] ?? null) === $name) {
                return $folder;
            }
        }

        self::fail("Missing collection folder {$name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $relativePath): array
    {
        $decoded = json_decode($this->file($relativePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function file(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }

    private function shippedSource(): string
    {
        return implode("\n", [
            $this->file(self::COLLECTION_FILE),
            $this->file(self::ENVIRONMENT_FILE),
            $this->file(self::README_FILE),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array<string, mixed>>
     */
    private function events(array $item, string $listen): array
    {
        return array_values(array_filter(
            $item['event'] ?? [],
            static fn(array $event): bool => ($event['listen'] ?? null) === $listen,
        ));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function script(array $item, string $listen): string
    {
        $events = $this->events($item, $listen);
        if ($events === []) {
            self::fail("Missing {$listen} script for request " . ($item['name'] ?? '<unknown>'));
        }

        return implode("\n", $events[0]['script']['exec'] ?? []);
    }

    private function collectionScript(string $listen): string
    {
        foreach ($this->collection()['event'] ?? [] as $event) {
            if (($event['listen'] ?? null) === $listen) {
                return implode("\n", $event['script']['exec'] ?? []);
            }
        }

        self::fail("Missing collection {$listen} script.");
    }

    /**
     * @param array<string, mixed> $item
     */
    private function rawUrl(array $item): string
    {
        return (string)($item['request']['url'] ?? '');
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function authValue(array $auth, string $key): ?string
    {
        foreach ($auth['apikey'] ?? [] as $entry) {
            if (($entry['key'] ?? null) === $key) {
                return is_string($entry['value'] ?? null) ? $entry['value'] : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function bodyKeys(array $item): array
    {
        return array_column($item['request']['body']['urlencoded'] ?? [], 'key');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function headerValue(array $item, string $key): ?string
    {
        foreach ($item['request']['header'] ?? [] as $header) {
            if (strcasecmp((string)($header['key'] ?? ''), $key) === 0) {
                return is_string($header['value'] ?? null) ? $header['value'] : null;
            }
        }

        return null;
    }
}
