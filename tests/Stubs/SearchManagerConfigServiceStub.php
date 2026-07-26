<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Stubs;

use craft\config\BaseConfig;
use craft\services\Config;

/**
 * Test-only config service that controls the two default-handle overrides
 * while delegating every other configuration read to the real service.
 *
 * @since 5.54.0
 */
final class SearchManagerConfigServiceStub extends Config
{
    /**
     * @param array<string, string> $defaultOverrides
     */
    public function __construct(
        private readonly Config $original,
        private readonly array $defaultOverrides = [],
    ) {
        parent::__construct();
    }

    public function getConfigSettings(string $category): object
    {
        return $this->original->getConfigSettings($category);
    }

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        $config = $this->original->getConfigFromFile($filename);
        if ($filename !== 'search-manager' || !is_array($config)) {
            return $config;
        }

        unset($config['defaultBackendHandle'], $config['defaultWidgetHandle']);

        return array_replace($config, $this->defaultOverrides);
    }
}
