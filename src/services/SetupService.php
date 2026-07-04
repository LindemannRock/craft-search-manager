<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use craft\base\Component;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;

/**
 * Computes setup readiness for Search Manager.
 *
 * @since 5.53.0
 */
class SetupService extends Component
{
    /**
     * @return array{complete: bool, missing: list<string>, setupUrl: string, ipSaltConfigured: bool}
     */
    public function getStatus(?Settings $settings = null): array
    {
        $settings ??= SearchManager::$plugin->getSettings();
        $ipSaltConfigured = $this->isIpSaltConfigured($settings);
        $missing = $ipSaltConfigured ? [] : ['ipSalt'];

        return [
            'complete' => $missing === [],
            'missing' => $missing,
            'setupUrl' => 'search-manager/setup',
            'ipSaltConfigured' => $ipSaltConfigured,
        ];
    }

    public function isIpSaltConfigured(Settings $settings): bool
    {
        $salt = trim((string) ($settings->ipHashSalt ?? ''));

        return $salt !== '' && $salt !== '$SEARCH_MANAGER_IP_SALT';
    }
}
