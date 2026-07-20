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
     * @return array{
     *   complete: bool,
     *   missing: list<string>,
     *   setupUrl: string,
     *   ipSaltConfigured: bool,
     *   configIndicesValid: bool,
     *   configIndicesClean: bool,
     *   configIndexFindings: list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>,
     *   configIndexFindingGroups: list<array{
     *     handle: string|null,
     *     severity: string,
     *     findings: list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>
     *   }>,
     *   configIndexRuntimeOnlyCriteria: list<string>
     * }
     */
    public function getStatus(?Settings $settings = null): array
    {
        $settings ??= SearchManager::$plugin->getSettings();
        $ipSaltConfigured = $this->isIpSaltConfigured($settings);
        $missing = $ipSaltConfigured ? [] : ['ipSalt'];
        $configValidation = SearchManager::$plugin->configIndexValidator->validate();
        $configIndexFindings = $configValidation->getFindings();
        $configIndicesValid = !$configValidation->hasErrors();
        $configIndicesClean = $configIndexFindings === [];
        if (!$configIndicesValid) {
            $missing[] = 'configIndices';
        }

        return [
            'complete' => $missing === [],
            'missing' => $missing,
            'setupUrl' => 'search-manager/setup',
            'ipSaltConfigured' => $ipSaltConfigured,
            'configIndicesValid' => $configIndicesValid,
            'configIndicesClean' => $configIndicesClean,
            'configIndexFindings' => $configIndexFindings,
            'configIndexFindingGroups' => $configValidation->getFindingGroups(),
            'configIndexRuntimeOnlyCriteria' => $configValidation->getRuntimeOnlyCriteriaHandles(),
        ];
    }

    public function isIpSaltConfigured(Settings $settings): bool
    {
        $salt = trim((string) ($settings->ipHashSalt ?? ''));

        return $salt !== '' && $salt !== '$SEARCH_MANAGER_IP_SALT';
    }
}
