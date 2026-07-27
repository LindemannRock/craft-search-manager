<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shipped Postman collection to Search Manager's public REST contract.
 *
 * @since 5.54.0
 */
final class PostmanArtifactContractTest extends TestCase
{
    private const COLLECTION_FILE = 'postman/Search-Manager.postman_collection.json';
    private const ENVIRONMENT_FILE = 'postman/Search-Manager.postman_environment.json';
    private const README_FILE = 'postman/README.md';
    private const FIXTURE_FILE = 'tests/Support/PostmanFixture.php';

    /**
     * @var array<string, array{status: int|string, auth: string}>
     */
    private const REQUEST_MATRIX = [
        'Search API > Search - anonymous (enforcement disabled)' => ['status' => 200, 'auth' => 'noauth'],
        'Search API > Search - public key (enforcement enabled)' => ['status' => 200, 'auth' => 'inherited'],
        'Search API > Search - public key with Origin fallback' => ['status' => 200, 'auth' => 'inherited'],
        'Search API > Search - canonical widget-style response' => ['status' => 200, 'auth' => 'inherited'],
        'Search API > Search - no indices uses public-key scope' => ['status' => 200, 'auth' => 'inherited'],
        'Search API > Search - scoped site' => ['status' => 200, 'auth' => 'inherited'],
        'Autocomplete API > Autocomplete - anonymous (enforcement disabled)' => ['status' => 200, 'auth' => 'noauth'],
        'Autocomplete API > Autocomplete - public key (enforcement enabled)' => ['status' => 200, 'auth' => 'inherited'],
        'Autocomplete API > Autocomplete - public key with Origin fallback' => ['status' => 200, 'auth' => 'inherited'],
        'Autocomplete API > Autocomplete - only suggestions' => ['status' => 200, 'auth' => 'inherited'],
        'Autocomplete API > Autocomplete - only results' => ['status' => 200, 'auth' => 'inherited'],
        'Analytics Tracking > Track search - Standard pre-parse no-op' => ['status' => 204, 'auth' => 'noauth'],
        'Analytics Tracking > Track click - Standard pre-parse no-op' => ['status' => 204, 'auth' => 'noauth'],
        'Analytics Tracking > Track search - Pro public key' => ['status' => 200, 'auth' => 'inherited'],
        'Analytics Tracking > Track search - Pro same-origin Origin' => ['status' => 200, 'auth' => 'inherited'],
        'Analytics Tracking > Track click - Pro public key' => ['status' => 200, 'auth' => 'inherited'],
        'Analytics Tracking > Track search - Pro disallowed Origin' => ['status' => 403, 'auth' => 'inherited'],
        'Analytics Tracking > Track search preflight - Pro same-origin Origin' => ['status' => 204, 'auth' => 'noauth'],
        'Enforcement Checks > Missing public key - exact 401' => ['status' => 401, 'auth' => 'noauth'],
        'Enforcement Checks > Invalid public key - exact 401' => ['status' => 401, 'auth' => 'invalid_api_key'],
        'Enforcement Checks > Public key with disallowed Referer - exact 403' => ['status' => 403, 'auth' => 'inherited'],
        'Enforcement Checks > Public key with disallowed Origin - exact 403' => ['status' => 403, 'auth' => 'inherited'],
        'Enforcement Checks > Public key with out-of-scope index - exact 403' => ['status' => 403, 'auth' => 'inherited'],
        'Enforcement Checks > Public key with unknown site - exact 400' => ['status' => 400, 'auth' => 'inherited'],
        'Enforcement Checks > Rate-limit Runner - deterministic 200 then 429' => ['status' => 'rate', 'auth' => 'rate_limit_api_key'],
    ];

    /**
     * @var list<string>
     */
    private const ENVIRONMENT_VARIABLES = [
        'api_mode',
        'edition_mode',
        'base_url',
        'api_key',
        'rate_limit_api_key',
        'invalid_api_key',
        'referrer',
        'blocked_referrer',
        'origin',
        'blocked_origin',
        'query',
        'index_handles',
        'index_handle',
        'blocked_index_handle',
        'results_limit',
        'site_id',
        'unknown_site_id',
        'element_id',
        'results_count',
        'trigger',
        'analytics_source',
        'rate_limit_allowed_requests',
        'rate_limit_runner_iterations',
    ];

    public function testCollectionAndEnvironmentAreValidVersion21Artifacts(): void
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
        self::assertSame('{{api_key}}', $this->authValue($collection['auth'], 'value'));
        self::assertSame('environment', $environment['_postman_variable_scope'] ?? null);
        self::assertSame('Search Manager API', $environment['name'] ?? null);
        self::assertSame(
            ['Search API', 'Autocomplete API', 'Analytics Tracking', 'Enforcement Checks'],
            array_column($collection['item'] ?? [], 'name'),
        );
        self::assertSame(['request_contracts'], array_column($collection['variable'] ?? [], 'key'));
        self::assertSame(['prerequest'], array_column($collection['event'] ?? [], 'listen'));
    }

    public function testRequestMatrixUsesExactStatusesAndOneCanonicalPrerequisiteAuthority(): void
    {
        $requests = $this->requestsByPath();
        $contracts = $this->requestContracts();
        $guard = $this->collectionScript('prerequest');

        self::assertSame(array_keys(self::REQUEST_MATRIX), array_keys($requests));
        self::assertSame(
            array_map(static fn(string $path): string => substr($path, strpos($path, ' > ') + 3), array_keys($requests)),
            array_keys($contracts),
        );
        self::assertSame(1, substr_count($this->file(self::COLLECTION_FILE), 'pm.execution.skipRequest();'));
        self::assertStringContainsString("pm.environment.get('api_mode')", $guard);
        self::assertStringContainsString("pm.environment.get('edition_mode')", $guard);
        self::assertStringContainsString("pm.collectionVariables.get('request_contracts')", $guard);
        self::assertStringContainsString('!supportedApiModes.includes(apiMode)', $guard);
        self::assertStringContainsString('!supportedEditionModes.includes(editionMode)', $guard);
        self::assertStringContainsString("apiMode || '<unset>'", $guard);
        self::assertStringContainsString("editionMode || '<unset>'", $guard);
        self::assertStringContainsString('contract.apiModes.includes(apiMode)', $guard);
        self::assertStringContainsString('contract.editionModes.includes(editionMode)', $guard);
        self::assertStringContainsString('contract.requiredVariables.filter', $guard);
        self::assertStringContainsString('SKIP:', $guard);
        self::assertStringContainsString('pm.execution.skipRequest();', $guard);

        foreach (self::REQUEST_MATRIX as $path => $contract) {
            $item = $requests[$path];
            $name = (string)$item['name'];
            $canonical = $contracts[$name];
            $description = (string)($item['request']['description'] ?? '');
            $test = $this->script($item, 'test');

            self::assertSame([], array_values(array_filter(
                $item['event'] ?? [],
                static fn(array $event): bool => ($event['listen'] ?? null) === 'prerequest',
            )), $path);
            self::assertSame($contract['auth'], $canonical['auth'] ?? null, $path);
            self::assertSame($contract['status'], $canonical['expectedStatus'] ?? null, $path);
            self::assertSame($contract['auth'], $this->authenticationMode($item), $path);
            self::assertStringContainsString(
                'API modes: ' . $this->descriptionValues($canonical['apiModes'] ?? []),
                $description,
                $path,
            );
            self::assertStringContainsString(
                'Editions: ' . $this->descriptionValues($canonical['editionModes'] ?? []),
                $description,
                $path,
            );
            self::assertStringContainsString(
                'Required variables: ' . $this->descriptionValues($canonical['requiredVariables'] ?? []),
                $description,
                $path,
            );

            if ($contract['status'] === 'rate') {
                self::assertStringContainsString('pm.info.iteration < allowedRequests ? 200 : 429', $test, $path);
                self::assertStringContainsString('pm.response.to.have.status(expectedStatus)', $test, $path);
                self::assertTrue((bool)($canonical['rateRunner'] ?? false), $path);
                self::assertStringContainsString('Expected: exact `200` before the declared cap, then exact `429`.', $description, $path);
                continue;
            }

            self::assertStringContainsString("Expected: exact `{$contract['status']}`.", $description, $path);
            self::assertStringContainsString("pm.response.to.have.status({$contract['status']})", $test, $path);
            self::assertDoesNotMatchRegularExpression('/\[(?:200|204|400|401|403|429)[^\]]*,/', $test, $path);
        }
    }

    public function testAuthenticationAndEditionAxesAreIndependent(): void
    {
        $contracts = $this->requestContracts();

        foreach ([
            'Search - public key (enforcement enabled)',
            'Search - public key with Origin fallback',
            'Search - canonical widget-style response',
            'Search - no indices uses public-key scope',
            'Search - scoped site',
            'Autocomplete - public key (enforcement enabled)',
            'Autocomplete - public key with Origin fallback',
            'Autocomplete - only suggestions',
            'Autocomplete - only results',
            'Missing public key - exact 401',
            'Invalid public key - exact 401',
            'Public key with disallowed Referer - exact 403',
            'Public key with disallowed Origin - exact 403',
            'Public key with out-of-scope index - exact 403',
            'Public key with unknown site - exact 400',
        ] as $name) {
            self::assertSame(['keyed'], $contracts[$name]['apiModes'] ?? null, $name);
            self::assertSame(['standard', 'pro'], $contracts[$name]['editionModes'] ?? null, $name);
        }

        foreach ([
            'Search - anonymous (enforcement disabled)',
            'Autocomplete - anonymous (enforcement disabled)',
        ] as $name) {
            self::assertSame(['anonymous'], $contracts[$name]['apiModes'] ?? null, $name);
            self::assertSame(['standard', 'pro'], $contracts[$name]['editionModes'] ?? null, $name);
        }

        foreach ([
            'Track search - Standard pre-parse no-op',
            'Track click - Standard pre-parse no-op',
        ] as $name) {
            self::assertSame(['anonymous', 'keyed', 'rate-limit'], $contracts[$name]['apiModes'] ?? null, $name);
            self::assertSame(['standard'], $contracts[$name]['editionModes'] ?? null, $name);
            self::assertSame(204, $contracts[$name]['expectedStatus'] ?? null, $name);
        }

        foreach ([
            'Track search - Pro public key',
            'Track search - Pro same-origin Origin',
            'Track click - Pro public key',
            'Track search - Pro disallowed Origin',
            'Track search preflight - Pro same-origin Origin',
        ] as $name) {
            self::assertSame(['keyed'], $contracts[$name]['apiModes'] ?? null, $name);
            self::assertSame(['pro'], $contracts[$name]['editionModes'] ?? null, $name);
        }

        $rate = $contracts['Rate-limit Runner - deterministic 200 then 429'];
        self::assertSame(['rate-limit'], $rate['apiModes'] ?? null);
        self::assertSame(['standard', 'pro'], $rate['editionModes'] ?? null);
        self::assertTrue((bool)($rate['rateRunner'] ?? false));
    }

    public function testPositiveExamplesUseOnlyPublicKeysAndServerKeyTeachingIsAbsent(): void
    {
        $combined = implode("\n", [
            $this->file(self::COLLECTION_FILE),
            $this->file(self::ENVIRONMENT_FILE),
            $this->file(self::README_FILE),
            $this->file(self::FIXTURE_FILE),
        ]);

        self::assertStringNotContainsString('server_api_key', $combined);
        self::assertStringNotContainsString('server key example', strtolower($combined));
        self::assertStringNotContainsString('server keys skip', strtolower($combined));
        self::assertStringNotContainsString('sm_srv_', $combined);
        self::assertStringNotContainsString('fixture' . '_mode', $combined);
        self::assertStringNotContainsString('keyed' . '-pro', $combined);

        foreach ($this->requestsByPath() as $path => $item) {
            $auth = $item['request']['auth'] ?? null;
            if (is_array($auth) && ($auth['type'] ?? null) === 'apikey') {
                $value = $this->authValue($auth, 'value');
                self::assertContains($value, ['{{invalid_api_key}}', '{{rate_limit_api_key}}'], $path);
            }
        }
    }

    public function testEnvironmentHasOneConsumedHarmlessContractForAllModes(): void
    {
        $environment = $this->environment();
        $values = [];
        foreach ($environment['values'] ?? [] as $variable) {
            $key = (string)($variable['key'] ?? '');
            self::assertArrayNotHasKey($key, $values, "Duplicate environment variable: {$key}");
            self::assertTrue((bool)($variable['enabled'] ?? false), $key);
            $values[$key] = $variable;
        }

        self::assertSame(self::ENVIRONMENT_VARIABLES, array_keys($values));
        self::assertSame('', $values['api_mode']['value']);
        self::assertSame('', $values['edition_mode']['value']);
        self::assertSame('https://yoursite.com', $values['base_url']['value']);
        self::assertSame('', $values['api_key']['value']);
        self::assertSame('', $values['rate_limit_api_key']['value']);
        self::assertSame('sm_pub_invalidplaceholder', $values['invalid_api_key']['value']);
        self::assertSame('secret', $values['api_key']['type']);
        self::assertSame('secret', $values['rate_limit_api_key']['type']);
        self::assertSame('secret', $values['invalid_api_key']['type']);

        $environmentSource = $this->file(self::ENVIRONMENT_FILE);
        self::assertStringNotContainsString('localhost', $environmentSource);
        self::assertStringNotContainsString('.ddev.site', $environmentSource);
        self::assertStringNotContainsString('.internal', $environmentSource);

        $collectionSource = $this->file(self::COLLECTION_FILE);
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $collectionSource, $templateMatches);
        preg_match_all("/pm\\.environment\\.get\\('([a-zA-Z0-9_]+)'\\)/", $collectionSource, $scriptMatches);
        $contracts = $this->requestContracts();
        $contractVariables = [];
        foreach ($contracts as $contract) {
            $contractVariables = array_merge($contractVariables, $contract['requiredVariables'] ?? []);
        }
        $referenced = array_values(array_unique(array_merge(
            $templateMatches[1],
            $scriptMatches[1],
            $contractVariables,
        )));
        sort($referenced);
        $declared = self::ENVIRONMENT_VARIABLES;
        sort($declared);

        self::assertSame($declared, $referenced, 'Every referenced variable must exist and every shipped variable must have a consumer.');
    }

    public function testRoutesParametersBodiesAndHeadersMatchTheControllers(): void
    {
        $requests = $this->requestsByPath();
        $searchQueryKeys = [];
        $autocompleteQueryKeys = [];

        foreach ($requests as $path => $item) {
            $request = $item['request'];
            $rawUrl = (string)($request['url']['raw'] ?? '');
            self::assertStringStartsWith('{{base_url}}/actions/search-manager/', $rawUrl, $path);

            if (str_contains($rawUrl, '/api/search?')) {
                self::assertSame('GET', $request['method'] ?? null, $path);
                self::assertStringContainsString('/api/search?', $rawUrl, $path);
                self::assertStringContainsString('q={{query}}', $rawUrl, $path);
                $searchQueryKeys = array_merge($searchQueryKeys, array_column($request['url']['query'] ?? [], 'key'));
            }
            if (str_contains($rawUrl, '/api/autocomplete?')) {
                self::assertSame('GET', $request['method'] ?? null, $path);
                self::assertStringContainsString('/api/autocomplete?', $rawUrl, $path);
                self::assertStringContainsString('q={{query}}', $rawUrl, $path);
                $autocompleteQueryKeys = array_merge($autocompleteQueryKeys, array_column($request['url']['query'] ?? [], 'key'));
            }
            if (str_contains($rawUrl, '/search/track-search')) {
                self::assertContains($request['method'] ?? null, ['POST', 'OPTIONS'], $path);
            }
            if (str_contains($rawUrl, '/search/track-click')) {
                self::assertSame('POST', $request['method'] ?? null, $path);
            }
        }

        self::assertSame(
            [
                'indexHandles',
                'q',
                'resultsLimit',
                'resultsRequireUrl',
                'siteId',
                'skipAnalytics',
                'snippetCleanMarkdown',
                'snippetIncludeCodeBlocks',
                'snippetMaxLength',
                'snippetMode',
            ],
            $this->sortedUnique($searchQueryKeys),
        );
        self::assertSame(
            ['indexHandles', 'only', 'q', 'resultsLimit'],
            $this->sortedUnique($autocompleteQueryKeys),
        );

        self::assertSame(
            ['q', 'indexHandles', 'resultsCount', 'trigger', 'analyticsSource', 'siteId', 'cached', 'took'],
            $this->bodyKeys($requests['Analytics Tracking > Track search - Pro public key']),
        );
        self::assertSame(
            ['elementId', 'query', 'index', 'position'],
            $this->bodyKeys($requests['Analytics Tracking > Track click - Pro public key']),
        );
        self::assertSame(
            $this->bodyKeys($requests['Analytics Tracking > Track search - Pro public key']),
            $this->bodyKeys($requests['Analytics Tracking > Track search - Standard pre-parse no-op']),
        );
        self::assertSame(
            $this->bodyKeys($requests['Analytics Tracking > Track search - Pro public key']),
            $this->bodyKeys($requests['Analytics Tracking > Track search - Pro same-origin Origin']),
        );
        self::assertSame(
            $this->bodyKeys($requests['Analytics Tracking > Track search - Pro public key']),
            $this->bodyKeys($requests['Analytics Tracking > Track search - Pro disallowed Origin']),
        );
        self::assertSame(
            $this->bodyKeys($requests['Analytics Tracking > Track click - Pro public key']),
            $this->bodyKeys($requests['Analytics Tracking > Track click - Standard pre-parse no-op']),
        );
        self::assertSame(
            [],
            $this->bodyKeys($requests['Analytics Tracking > Track search preflight - Pro same-origin Origin']),
        );
        self::assertSame(
            [],
            $this->bodyKeys($requests['Enforcement Checks > Public key with disallowed Origin - exact 403']),
        );

        self::assertSame(
            '{{blocked_referrer}}',
            $this->headerValue($requests['Enforcement Checks > Public key with disallowed Referer - exact 403'], 'Referer'),
        );
        self::assertSame(
            '{{blocked_origin}}',
            $this->headerValue($requests['Enforcement Checks > Public key with disallowed Origin - exact 403'], 'Origin'),
        );
        self::assertSame(
            '{{blocked_origin}}',
            $this->headerValue($requests['Analytics Tracking > Track search - Pro disallowed Origin'], 'Origin'),
        );
        self::assertSame(
            '{{origin}}',
            $this->headerValue($requests['Analytics Tracking > Track search - Pro same-origin Origin'], 'Origin'),
        );
        self::assertNull(
            $this->headerValue($requests['Analytics Tracking > Track search - Pro same-origin Origin'], 'Referer'),
        );

        self::assertStringContainsString(
            'indexHandles={{blocked_index_handle}}',
            (string)$requests['Enforcement Checks > Public key with out-of-scope index - exact 403']['request']['url']['raw'],
        );
        self::assertStringContainsString(
            'siteId={{unknown_site_id}}',
            (string)$requests['Enforcement Checks > Public key with unknown site - exact 400']['request']['url']['raw'],
        );
    }

    public function testTrackingAndRateContractsArePinnedWithoutBroadAllowlists(): void
    {
        $requests = $this->requestsByPath();
        $source = $this->file(self::COLLECTION_FILE);

        self::assertStringNotContainsString('to.include(pm.response.code)', $source);
        self::assertDoesNotMatchRegularExpression('/expect\(\[(?:200|204|400|401|403|429)/', $source);

        foreach ([
            'Analytics Tracking > Track search - Standard pre-parse no-op',
            'Analytics Tracking > Track click - Standard pre-parse no-op',
        ] as $path) {
            self::assertSame('noauth', $requests[$path]['request']['auth']['type'] ?? null);
            self::assertStringContainsString('pm.response.to.have.status(204)', $this->script($requests[$path], 'test'));
            self::assertStringContainsString("pm.expect(pm.response.text()).to.eql('')", $this->script($requests[$path], 'test'));
        }

        foreach ([
            'Analytics Tracking > Track search - Pro public key',
            'Analytics Tracking > Track search - Pro same-origin Origin',
            'Analytics Tracking > Track click - Pro public key',
        ] as $path) {
            self::assertStringContainsString('pm.response.to.have.status(200)', $this->script($requests[$path], 'test'));
            self::assertStringContainsString("'success'", $this->script($requests[$path], 'test'));
        }

        self::assertStringContainsString(
            'pm.response.to.have.status(403)',
            $this->script($requests['Analytics Tracking > Track search - Pro disallowed Origin'], 'test'),
        );

        $rate = $requests['Enforcement Checks > Rate-limit Runner - deterministic 200 then 429'];
        $guard = $this->collectionScript('prerequest');
        self::assertSame('{{rate_limit_api_key}}', $this->authValue($rate['request']['auth'], 'value'));
        self::assertStringContainsString('rate_limit_allowed_requests', $this->script($rate, 'test'));
        self::assertStringContainsString('rate_limit_runner_iterations', $guard);
        self::assertStringContainsString('pm.info.iterationCount !== requiredIterations', $guard);
        self::assertStringContainsString('skipAnalytics=1', (string)$rate['request']['url']['raw']);
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
    private function requestContracts(): array
    {
        foreach ($this->collection()['variable'] ?? [] as $variable) {
            if (($variable['key'] ?? null) !== 'request_contracts') {
                continue;
            }

            $contracts = json_decode((string)($variable['value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($contracts);

            return $contracts;
        }

        self::fail('Missing collection request_contracts authority.');
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

    /**
     * @param array<string, mixed> $item
     */
    private function script(array $item, string $listen): string
    {
        foreach ($item['event'] ?? [] as $event) {
            if (($event['listen'] ?? null) === $listen) {
                return implode("\n", $event['script']['exec'] ?? []);
            }
        }

        self::fail("Missing {$listen} script for request " . ($item['name'] ?? '<unknown>'));
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
     */
    private function authenticationMode(array $item): string
    {
        $auth = $item['request']['auth'] ?? null;
        if (!is_array($auth)) {
            return 'inherited';
        }

        if (($auth['type'] ?? null) === 'noauth') {
            return 'noauth';
        }

        if (($auth['type'] ?? null) === 'apikey') {
            return match ($this->authValue($auth, 'value')) {
                '{{invalid_api_key}}' => 'invalid_api_key',
                '{{rate_limit_api_key}}' => 'rate_limit_api_key',
                default => 'unexpected_apikey',
            };
        }

        return 'unexpected_auth';
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param list<string> $values
     */
    private function descriptionValues(array $values): string
    {
        return implode(', ', array_map(static fn(string $value): string => "`{$value}`", $values)) . '.';
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
