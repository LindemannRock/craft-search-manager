<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
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
     *   configIndexRuntimeOnlyCriteria: list<string>,
     *   backendReadinessValid: bool,
     *   backendReadinessClean: bool,
     *   backendReadinessFindings: list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>,
     *   backendReadinessFindingGroups: list<array{
     *     handle: string|null,
     *     severity: string,
     *     findings: list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>
     *   }>
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
        $backendReadiness = $this->getBackendReadiness($settings);
        $backendReadinessFindings = $backendReadiness->getFindings();
        $backendReadinessValid = !$backendReadiness->hasErrors();
        if (!$backendReadinessValid) {
            $missing[] = 'backendReadiness';
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
            'backendReadinessValid' => $backendReadinessValid,
            'backendReadinessClean' => $backendReadinessFindings === [],
            'backendReadinessFindings' => $backendReadinessFindings,
            'backendReadinessFindingGroups' => $backendReadiness->getFindingGroups(),
        ];
    }

    public function isIpSaltConfigured(Settings $settings): bool
    {
        $salt = trim((string) ($settings->ipHashSalt ?? ''));

        return $salt !== '' && $salt !== '$SEARCH_MANAGER_IP_SALT';
    }

    private function getBackendReadiness(Settings $settings): ConfigIndexValidationResult
    {
        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        if (!App::isEphemeral()) {
            return $result;
        }

        $defaultHandle = trim((string)$settings->defaultBackendHandle);
        $defaultBackend = $defaultHandle !== '' ? $this->findBackend($defaultHandle) : null;
        if ($this->isEnabledFileBackend($defaultBackend)) {
            $message = Craft::t('search-manager', 'Backend is not available. Check your settings.') . ' '
                . Craft::t('search-manager', 'Select a valid default backend or index backend before using this action.');
            if ($settings->isOverriddenByConfig('defaultBackendHandle')) {
                $message .= ' ' . Craft::t('search-manager', 'Default backend is set via config file and cannot be changed here.');
            }
            $result->addFinding(
                null,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'defaultBackend',
                $message,
                $defaultHandle,
            );
        }

        foreach ($this->findIndices() as $index) {
            if (!$index->enabled) {
                continue;
            }

            $effectiveBackendHandle = trim((string)(
                $index->backend ?: $settings->defaultBackendHandle
            ));
            $backend = $effectiveBackendHandle !== '' ? $this->findBackend($effectiveBackendHandle) : null;
            if (!$this->isEnabledFileBackend($backend)) {
                continue;
            }

            $message = Craft::t('search-manager', 'Backend is not available. Check your settings.') . ' '
                . Craft::t('search-manager', 'Select a valid default backend or index backend before using this action.');
            if ($index->isFromConfig()) {
                $message .= ' ' . Craft::t('search-manager', 'Review this index in config/search-manager.php before rebuilding it.');
            }
            $result->addFinding(
                $index->handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'backend',
                $message,
                $effectiveBackendHandle,
            );
        }

        return $result;
    }

    protected function findBackend(string $handle): ?ConfiguredBackend
    {
        return ConfiguredBackend::findByHandle($handle);
    }

    /** @return list<SearchIndex> */
    protected function findIndices(): array
    {
        return SearchIndex::findAll();
    }

    private function isEnabledFileBackend(?ConfiguredBackend $backend): bool
    {
        return $backend?->enabled === true && $backend->backendType === 'file';
    }
}
