<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\console\controllers;

use lindemannrock\base\console\controllers\AbstractHelpController;

/**
 * Console help for Search Manager commands.
 *
 * @since 5.47.0
 */
final class HelpController extends AbstractHelpController
{
    /**
     * @inheritdoc
     */
    protected function helpManifest(): array
    {
        return [
            'title' => 'Search Manager',
            'pluginHandle' => 'search-manager',
            'commandPrefix' => 'ddev craft',
            'summary' => 'Use these commands to inspect indices, queue rebuilds, clear storage, generate the IP hash salt, and provision API keys for scripted installs.',
            'common' => [
                'index/list',
                'index/rebuild',
                'maintenance/status',
                'security/generate-salt',
                'api-keys/create',
            ],
            'groups' => [
                [
                    'name' => 'index',
                    'label' => 'Indices',
                    'description' => 'List, rebuild, and clear search indices.',
                    'commands' => [
                        [
                            'path' => 'index/list',
                            'summary' => 'List configured indices.',
                            'description' => 'Show each configured index with its handle, element type, document count, last indexed date, and enabled status.',
                            'examples' => [
                                'search-manager/index/list',
                            ],
                        ],
                        [
                            'path' => 'index/rebuild',
                            'summary' => 'Queue rebuild jobs for all or one index.',
                            'description' => 'Clear indexed data and queue rebuild jobs. Omit --handle to rebuild every configured index.',
                            'usageOptions' => '[--handle=<index>]',
                            'options' => [
                                [
                                    'name' => '--handle',
                                    'description' => 'Optional index handle. Omit to rebuild all indices.',
                                ],
                            ],
                            'examples' => [
                                'search-manager/index/rebuild',
                                'search-manager/index/rebuild --handle=entries-en',
                            ],
                            'notes' => [
                                'Large rebuilds run through Craft queue jobs. Make sure your queue worker is running.',
                            ],
                        ],
                        [
                            'path' => 'index/clear',
                            'summary' => 'Clear indexed data without rebuilding.',
                            'description' => 'Remove indexed data while keeping the index configuration. Omit --handle to clear every configured index.',
                            'usageOptions' => '[--handle=<index>]',
                            'options' => [
                                [
                                    'name' => '--handle',
                                    'description' => 'Optional index handle. Omit to clear all indices.',
                                ],
                            ],
                            'examples' => [
                                'search-manager/index/clear',
                                'search-manager/index/clear --handle=entries-en',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'maintenance',
                    'label' => 'Maintenance',
                    'description' => 'Inspect and clear backend storage.',
                    'commands' => [
                        [
                            'path' => 'maintenance/status',
                            'summary' => 'Show backend storage status.',
                            'description' => 'Inspect database, Redis, file, and external backend storage counts and configuration state.',
                            'usageOptions' => '[--verbose]',
                            'options' => [
                                [
                                    'name' => '--verbose',
                                    'description' => 'Show additional backend details such as configured backends and index handles.',
                                ],
                            ],
                            'examples' => [
                                'search-manager/maintenance/status',
                                'search-manager/maintenance/status --verbose',
                            ],
                        ],
                        [
                            'path' => 'maintenance/clear-storage',
                            'summary' => 'Clear all data from one backend storage type.',
                            'description' => 'Delete all indexed data stored in one backend storage type. Use this for troubleshooting orphaned data after backend changes.',
                            'usageOptions' => '--type=<database|redis|file>',
                            'options' => [
                                [
                                    'name' => '--type',
                                    'description' => 'database, redis, or file.',
                                    'required' => true,
                                ],
                            ],
                            'examples' => [
                                'search-manager/maintenance/clear-storage --type=database',
                                'search-manager/maintenance/clear-storage --type=redis',
                                'search-manager/maintenance/clear-storage --type=file',
                            ],
                            'notes' => [
                                'This is destructive. Rebuild affected indices afterward to restore search results.',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'security',
                    'label' => 'Security',
                    'description' => 'Generate required privacy/security secrets.',
                    'commands' => [
                        [
                            'path' => 'security/generate-salt',
                            'summary' => 'Generate the IP hash salt.',
                            'description' => 'Generate a secure SEARCH_MANAGER_IP_SALT value and offer to add it to the project .env file.',
                            'examples' => [
                                'search-manager/security/generate-salt',
                            ],
                            'notes' => [
                                'Use the same salt across environments if you need analytics continuity.',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'api-keys',
                    'label' => 'API Keys',
                    'description' => 'Provision search API keys from scripts.',
                    'commands' => [
                        [
                            'path' => 'api-keys/create',
                            'summary' => 'Create a search API key.',
                            'description' => 'Create a public or server API key for scripted provisioning. The plaintext key is printed once and cannot be recovered later.',
                            'usageOptions' => '--name=<label> [--type=<public|server>] [--indices=<handles|*>] [--referrers=<patterns>] [--max-hits=<count>] [--rate-limit=<rpm>] [--valid-until=<date>] [--disabled]',
                            'options' => [
                                [
                                    'name' => '--name',
                                    'description' => 'Human-readable label shown in the Control Panel.',
                                    'required' => true,
                                ],
                                [
                                    'name' => '--type',
                                    'description' => 'public or server. Default: public.',
                                ],
                                [
                                    'name' => '--indices',
                                    'description' => 'Comma-separated index handles, or * for all indices.',
                                ],
                                [
                                    'name' => '--referrers',
                                    'description' => 'Comma-separated allowed referrer host patterns.',
                                ],
                                [
                                    'name' => '--max-hits',
                                    'description' => 'Clamp on the hitsPerPage request parameter.',
                                ],
                                [
                                    'name' => '--rate-limit',
                                    'description' => 'Per-key requests-per-minute value. Stored for enforcement support.',
                                ],
                                [
                                    'name' => '--valid-until',
                                    'description' => 'Optional expiry datetime.',
                                ],
                                [
                                    'name' => '--disabled',
                                    'description' => 'Create the key disabled.',
                                ],
                            ],
                            'examples' => [
                                'search-manager/api-keys/create --name="Primary widget key"',
                                'search-manager/api-keys/create --name="Docs widget" --type=public --indices=docs-en --referrers=example.com,*.example.com --max-hits=50',
                            ],
                            'notes' => [
                                'The plaintext key is written to stdout exactly once. Treat redirected output as a secret.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
