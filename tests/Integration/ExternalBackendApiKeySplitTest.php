<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Algolia\AlgoliaSearch\Api\SearchClient;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\tests\TestCase;
use Typesense\Client;
use Typesense\Lib\Configuration;

/**
 * Regression coverage for audit #373 and #374.
 *
 * @since 5.53.0
 */
final class ExternalBackendApiKeySplitTest extends TestCase
{
    public function testAlgoliaSearchClientUsesSearchKeyWhenConfigured(): void
    {
        $backend = new AlgoliaBackend();
        $backend->setConfiguredSettings([
            'applicationId' => 'test-app',
            'adminApiKey' => 'admin-key',
            'searchApiKey' => 'search-key',
        ]);

        $client = $this->invokeAlgoliaClient($backend, 'getSearchClient');

        self::assertSame('search-key', $client->getClientConfig()->getAlgoliaApiKey());
    }

    public function testAlgoliaSearchClientFallsBackToAdminKey(): void
    {
        $backend = new AlgoliaBackend();
        $backend->setConfiguredSettings([
            'applicationId' => 'test-app',
            'adminApiKey' => 'admin-key',
        ]);

        $client = $this->invokeAlgoliaClient($backend, 'getSearchClient');

        self::assertSame('admin-key', $client->getClientConfig()->getAlgoliaApiKey());
    }

    public function testTypesenseSearchClientUsesSearchKeyWhenConfigured(): void
    {
        $backend = new TypesenseBackend();
        $backend->setConfiguredSettings($this->typesenseSettings([
            'searchApiKey' => 'search-key',
        ]));

        $client = $this->invokeTypesenseClient($backend, 'getSearchClient');

        self::assertSame('search-key', $this->typesenseApiKey($client));
    }

    public function testTypesenseSearchClientFallsBackToAdminKey(): void
    {
        $backend = new TypesenseBackend();
        $backend->setConfiguredSettings($this->typesenseSettings());

        $client = $this->invokeTypesenseClient($backend, 'getSearchClient');

        self::assertSame('admin-key', $this->typesenseApiKey($client));
    }

    public function testTypesenseAdminClientUsesRenamedAdminApiKey(): void
    {
        $backend = new TypesenseBackend();
        $backend->setConfiguredSettings($this->typesenseSettings([
            'searchApiKey' => 'search-key',
        ]));

        $client = $this->invokeTypesenseClient($backend, 'getClient');

        self::assertSame('admin-key', $this->typesenseApiKey($client));
    }

    public function testSearchPathsUseSearchClientsAndIndexingUsesAdminClients(): void
    {
        $algoliaSource = $this->readPluginSource('src/backends/AlgoliaBackend.php');
        self::assertStringContainsString('getSearchClient()', $this->methodBody($algoliaSource, 'search'));
        self::assertStringContainsString('getSearchClient()', $this->methodBody($algoliaSource, 'multipleQueries'));
        self::assertStringContainsString('getClient()', $this->methodBody($algoliaSource, 'indexWithResult'));
        self::assertStringContainsString('getClient()', $this->methodBody($algoliaSource, 'batchIndex'));

        $typesenseSource = $this->readPluginSource('src/backends/TypesenseBackend.php');
        self::assertStringContainsString('getSearchClient()', $this->methodBody($typesenseSource, 'search'));
        self::assertStringContainsString('getSearchClient()', $this->methodBody($typesenseSource, 'multipleQueries'));
        self::assertStringContainsString('getClient()', $this->methodBody($typesenseSource, 'indexWithResult'));
        self::assertStringContainsString('getClient()', $this->methodBody($typesenseSource, 'batchIndex'));
    }

    public function testTypesenseAndMeilisearchDoNotReadLegacyBackendApiKeySetting(): void
    {
        self::assertStringNotContainsString(
            "\$settings['apiKey']",
            $this->readPluginSource('src/backends/TypesenseBackend.php'),
        );
        self::assertStringNotContainsString(
            "\$settings['apiKey']",
            $this->readPluginSource('src/backends/MeilisearchBackend.php'),
        );
    }

    private function invokeAlgoliaClient(AlgoliaBackend $backend, string $method): SearchClient
    {
        $reflection = new \ReflectionMethod($backend, $method);
        $reflection->setAccessible(true);
        $client = $reflection->invoke($backend);
        self::assertInstanceOf(SearchClient::class, $client);

        return $client;
    }

    private function invokeTypesenseClient(TypesenseBackend $backend, string $method): Client
    {
        $reflection = new \ReflectionMethod($backend, $method);
        $reflection->setAccessible(true);
        $client = $reflection->invoke($backend);
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function typesenseSettings(array $overrides = []): array
    {
        return array_merge([
            'host' => 'localhost',
            'port' => '8108',
            'protocol' => 'http',
            'adminApiKey' => 'admin-key',
            'connectionTimeout' => 1,
        ], $overrides);
    }

    private function typesenseApiKey(Client $client): string
    {
        $property = new \ReflectionProperty($client, 'config');
        $property->setAccessible(true);
        $config = $property->getValue($client);
        self::assertInstanceOf(Configuration::class, $config);

        return (string)$config->getApiKey();
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        $start = strpos($source, $needle);
        self::assertIsInt($start, sprintf('Method %s was not found.', $method));

        $brace = strpos($source, '{', $start);
        self::assertIsInt($brace, sprintf('Method %s body was not found.', $method));

        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        self::fail(sprintf('Method %s body was not closed.', $method));
    }
}
