<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\helpers\SearchIndexCriteriaHelper;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\traits\ElementTypeGuardTrait;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\DocsManagerTransformer;
use yii\base\Component;

/**
 * Validates config-defined indices without mutating config, models, or storage.
 *
 * @since 5.54.0
 */
class ConfigIndexValidator extends Component
{
    use ElementTypeGuardTrait;

    private const PLUGIN_HANDLE = 'search-manager';

    private const ALLOWED_KEYS = [
        'name',
        'elementType',
        'siteId',
        'criteria',
        'transformer',
        'language',
        'headingLevels',
        'backend',
        'enabled',
        'enableAnalytics',
        'disableStopWords',
        'skipEntriesWithoutUrl',
        'splitSections',
        'retrievableFields',
    ];

    private const BOOLEAN_KEYS = [
        'enabled',
        'enableAnalytics',
        'disableStopWords',
        'skipEntriesWithoutUrl',
        'splitSections',
    ];

    public function validate(): ConfigIndexValidationResult
    {
        try {
            return $this->validateConfig($this->readConfigFile());
        } catch (\Throwable $e) {
            $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_LOAD_FAILURE);
            $result->addFinding(
                null,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'indices',
                Craft::t(self::PLUGIN_HANDLE, 'Could not load config/search-manager.php: {error}', [
                    'error' => $e->getMessage(),
                ]),
            );

            return $result;
        }
    }

    public function validateConfig(mixed $config): ConfigIndexValidationResult
    {
        if (!is_array($config)) {
            $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_INVALID_ROOT);
            $result->addFinding(
                null,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'config',
                Craft::t(self::PLUGIN_HANDLE, 'config/search-manager.php must return an array.'),
            );

            return $result;
        }

        if (!array_key_exists('indices', $config)) {
            return new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_ABSENT);
        }

        if (!is_array($config['indices'])) {
            $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_INVALID_SECTION);
            $result->addFinding(
                null,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'indices',
                Craft::t(self::PLUGIN_HANDLE, 'The indices section must be an array.'),
            );

            return $result;
        }

        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        foreach ($config['indices'] as $rawHandle => $indexConfig) {
            $handle = (string)$rawHandle;
            $validHandle = is_string($rawHandle)
                && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $rawHandle) === 1;

            if (!$validHandle) {
                $result->addFinding(
                    $handle,
                    ConfigIndexValidationResult::SEVERITY_ERROR,
                    'handle',
                    Craft::t(self::PLUGIN_HANDLE, 'The index handle is invalid.'),
                );
            }

            if (!is_array($indexConfig)) {
                $result->addFinding(
                    $handle,
                    ConfigIndexValidationResult::SEVERITY_ERROR,
                    'index',
                    Craft::t(self::PLUGIN_HANDLE, 'Index configuration must be an array.'),
                );
                continue;
            }

            $this->validateIndex($result, $handle, $indexConfig);
        }

        return $result;
    }

    protected function readConfigFile(): mixed
    {
        $configService = Craft::$app->getConfig();
        $path = $configService->getConfigFilePath(self::PLUGIN_HANDLE);
        if (!is_file($path)) {
            return [];
        }

        $config = (static fn(string $configPath): mixed => include $configPath)($path);
        if (!is_array($config) || !array_key_exists('*', $config)) {
            return $config;
        }

        if ($configService->env === null) {
            return $config['*'];
        }

        $mergedConfig = [];
        foreach ($config as $env => $envConfig) {
            if ($env === '*' || (is_string($env) && StringHelper::contains($configService->env, $env))) {
                $mergedConfig = ArrayHelper::merge($mergedConfig, $envConfig);
            }
        }

        return $mergedConfig;
    }

    /** @param array<string, mixed> $config */
    private function validateIndex(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_KEYS, true)) {
                $result->addFinding(
                    $handle,
                    ConfigIndexValidationResult::SEVERITY_ERROR,
                    (string)$key,
                    Craft::t(self::PLUGIN_HANDLE, 'Unknown key {key}.', [
                        'key' => (string)$key,
                    ]),
                    (string)$key,
                );
            }
        }

        $this->validateName($result, $handle, $config);
        $elementType = $this->validateElementType($result, $handle, $config);
        $this->validateSiteId($result, $handle, $config);
        $this->validateCriteria($result, $handle, $config, $elementType);
        $transformer = $this->validateTransformer($result, $handle, $config);
        $this->validateLanguage($result, $handle, $config);
        $this->validateHeadingLevels($result, $handle, $config);
        $this->validateBackend($result, $handle, $config);
        $this->validateBooleans($result, $handle, $config);
        $this->validateRetrievableFields($result, $handle, $config);
        $this->validateSplitSectionsSupport($result, $handle, $config, $elementType, $transformer);
    }

    /** @param array<string, mixed> $config */
    private function validateName(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        if (!array_key_exists('name', $config) || $config['name'] === null) {
            return;
        }

        if (!is_string($config['name'])) {
            $this->addInvalidValue($result, $handle, 'name');
            return;
        }

        if (trim($config['name']) === '') {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_WARNING,
                'name',
                Craft::t(self::PLUGIN_HANDLE, 'The index name is empty.'),
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateElementType(ConfigIndexValidationResult $result, string $handle, array $config): ?string
    {
        $value = $config['elementType'] ?? Entry::class;
        if (!is_string($value) || trim($value) === '') {
            $this->addInvalidValue($result, $handle, 'elementType');
            return null;
        }

        $value = trim($value);
        if (!class_exists($value)) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'elementType',
                Craft::t(self::PLUGIN_HANDLE, 'Element type "{value}" is unavailable.', [
                    'value' => $value,
                ]),
            );
            return null;
        }

        if (!$this->hasValidElementTypeContract($value)) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'elementType',
                Craft::t(self::PLUGIN_HANDLE, 'Element type "{value}" must implement ElementInterface.', [
                    'value' => $value,
                ]),
            );
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private function validateSiteId(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        if (!array_key_exists('siteId', $config) || $config['siteId'] === null) {
            return;
        }

        $value = $config['siteId'];
        $siteIds = [];
        if (is_int($value) && $value > 0) {
            $siteIds = [$value];
        } elseif (is_array($value) && array_is_list($value) && $value !== []) {
            foreach ($value as $siteId) {
                if (!is_int($siteId) || $siteId <= 0) {
                    $this->addInvalidValue($result, $handle, 'siteId');
                    return;
                }
                $siteIds[] = $siteId;
            }

            if (count(array_unique($siteIds)) !== count($siteIds)) {
                $this->addInvalidValue($result, $handle, 'siteId');
                return;
            }
        } else {
            $this->addInvalidValue($result, $handle, 'siteId');
            return;
        }

        foreach ($siteIds as $siteId) {
            if (Craft::$app->getSites()->getSiteById($siteId) === null) {
                $result->addFinding(
                    $handle,
                    ConfigIndexValidationResult::SEVERITY_ERROR,
                    'siteId',
                    Craft::t(self::PLUGIN_HANDLE, 'Site ID {value} does not exist.', [
                        'value' => $siteId,
                    ]),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateCriteria(
        ConfigIndexValidationResult $result,
        string $handle,
        array $config,
        ?string $elementType,
    ): void {
        if (!array_key_exists('criteria', $config)) {
            return;
        }

        $criteria = $config['criteria'];
        if ($criteria instanceof \Closure) {
            $result->markCriteriaRuntimeOnly($handle);
            return;
        }

        if (!is_array($criteria)) {
            $this->addInvalidValue($result, $handle, 'criteria');
            return;
        }

        if (!$this->isJsonEncodable($criteria)) {
            $this->addInvalidValue($result, $handle, 'criteria');
            return;
        }

        $selector = $elementType === null ? null : SearchIndexCriteriaHelper::selectorForElementType($elementType);
        foreach ($criteria as $key => $values) {
            if (!is_string($key) || $selector === null || $key !== $selector) {
                $result->addFinding(
                    $handle,
                    ConfigIndexValidationResult::SEVERITY_ERROR,
                    'criteria.' . (string)$key,
                    Craft::t(self::PLUGIN_HANDLE, 'Unsupported criteria key "{key}".', [
                        'key' => (string)$key,
                    ]),
                );
                continue;
            }

            if (!is_array($values) || !array_is_list($values) || $values === []) {
                $this->addInvalidValue($result, $handle, 'criteria.' . $key);
                continue;
            }

            foreach ($values as $value) {
                if (!is_scalar($value) || is_bool($value) || trim((string)$value) === '') {
                    $this->addInvalidValue($result, $handle, 'criteria.' . $key);
                    continue 2;
                }

                $value = trim((string)$value);
                if (!$this->selectorHandleExists($key, $value)) {
                    $result->addFinding(
                        $handle,
                        ConfigIndexValidationResult::SEVERITY_ERROR,
                        'criteria.' . $key,
                        Craft::t(self::PLUGIN_HANDLE, 'Criteria "{key}" references missing handle "{value}".', [
                            'key' => $key,
                            'value' => $value,
                        ]),
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateTransformer(ConfigIndexValidationResult $result, string $handle, array $config): ?string
    {
        if (!array_key_exists('transformer', $config) || $config['transformer'] === null || $config['transformer'] === '') {
            return null;
        }

        if (!is_string($config['transformer'])) {
            $this->addInvalidValue($result, $handle, 'transformer');
            return null;
        }

        $transformer = trim($config['transformer']);
        $error = SearchIndex::transformerClassValidationError($transformer);
        if ($error !== null) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'transformer',
                $error,
            );
            return null;
        }

        return $transformer;
    }

    /** @param array<string, mixed> $config */
    private function validateLanguage(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        if (!array_key_exists('language', $config) || $config['language'] === null || $config['language'] === '') {
            return;
        }

        if (!is_string($config['language']) || LanguageNormalizer::normalizeOrNull($config['language']) === null) {
            $this->addInvalidValue($result, $handle, 'language');
        }
    }

    /** @param array<string, mixed> $config */
    private function validateHeadingLevels(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        if (!array_key_exists('headingLevels', $config) || $config['headingLevels'] === null) {
            return;
        }

        $levels = $config['headingLevels'];
        if (!is_array($levels) || !array_is_list($levels) || $levels === [] || !$this->isJsonEncodable($levels)) {
            $this->addInvalidValue($result, $handle, 'headingLevels');
            return;
        }

        foreach ($levels as $level) {
            if (!is_int($level) || $level < 1 || $level > 6) {
                $this->addInvalidValue($result, $handle, 'headingLevels');
                return;
            }
        }

        if (count(array_unique($levels)) !== count($levels)) {
            $this->addInvalidValue($result, $handle, 'headingLevels');
        }
    }

    /** @param array<string, mixed> $config */
    private function validateBackend(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        if (!array_key_exists('backend', $config) || $config['backend'] === null || $config['backend'] === '') {
            return;
        }

        if (!is_string($config['backend'])) {
            $this->addInvalidValue($result, $handle, 'backend');
            return;
        }

        $backend = $this->findBackendByHandle(trim($config['backend']));
        if ($backend === null) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'backend',
                Craft::t(self::PLUGIN_HANDLE, 'Selected backend does not exist.'),
            );
        } elseif (!$backend->enabled) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'backend',
                Craft::t(self::PLUGIN_HANDLE, 'Selected backend is disabled.'),
            );
        }
    }

    /** @param array<string, mixed> $config */
    private function validateBooleans(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        foreach (self::BOOLEAN_KEYS as $key) {
            if (array_key_exists($key, $config) && $config[$key] !== null && !is_bool($config[$key])) {
                $this->addInvalidValue($result, $handle, $key);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function validateRetrievableFields(ConfigIndexValidationResult $result, string $handle, array $config): void
    {
        if (!array_key_exists('retrievableFields', $config) || $config['retrievableFields'] === null) {
            return;
        }

        $value = $config['retrievableFields'];
        if (!is_string($value) && !is_array($value)) {
            $this->addInvalidValue($result, $handle, 'retrievableFields');
            return;
        }

        if (is_array($value)) {
            if (!array_is_list($value) || !$this->isJsonEncodable($value)) {
                $this->addInvalidValue($result, $handle, 'retrievableFields');
                return;
            }

            foreach ($value as $member) {
                if (!is_scalar($member) || is_bool($member)) {
                    $this->addInvalidValue($result, $handle, 'retrievableFields');
                    return;
                }
            }
        }

        $normalized = SearchIndex::normalizeRetrievableFields($value);
        $sourceMembers = is_string($value)
            ? preg_split('/[\r\n,]+/', $value) ?: []
            : $value;
        $nonEmptyMembers = array_values(array_filter(
            array_map(static fn(mixed $member): string => trim((string)$member), $sourceMembers),
            static fn(string $member): bool => $member !== '',
        ));

        if (count($normalized) !== count(array_unique($nonEmptyMembers))) {
            $this->addInvalidValue($result, $handle, 'retrievableFields');
            return;
        }

        $hasWildcard = in_array('*', $normalized, true);
        $hasExclusion = array_filter($normalized, static fn(string $field): bool => str_starts_with($field, '-')) !== [];
        if ($hasExclusion && !$hasWildcard) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'retrievableFields',
                Craft::t(self::PLUGIN_HANDLE, 'Retrievable field exclusions (for example -wysiwyg) can only be used with *.'),
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateSplitSectionsSupport(
        ConfigIndexValidationResult $result,
        string $handle,
        array $config,
        ?string $elementType,
        ?string $transformer,
    ): void {
        if (($config['splitSections'] ?? false) !== true || $elementType === null) {
            return;
        }

        if (array_key_exists('transformer', $config)
            && $config['transformer'] !== null
            && $config['transformer'] !== ''
            && $transformer === null) {
            return;
        }

        $resolvedTransformer = SearchManager::$plugin->transformers
            ->resolveTransformerClassForElementTypeSilently($elementType, $transformer);
        $supported = $resolvedTransformer !== null && (is_a($resolvedTransformer, AutoTransformer::class, true)
            || (SearchIndexCriteriaHelper::selectorForElementType($elementType) === 'sourceHandles'
                && is_a($resolvedTransformer, DocsManagerTransformer::class, true)));
        if (!$supported) {
            $result->addFinding(
                $handle,
                ConfigIndexValidationResult::SEVERITY_ERROR,
                'splitSections',
                Craft::t(self::PLUGIN_HANDLE, 'splitSections is enabled for an unsupported element and transformer combination.'),
            );
        }
    }

    private function selectorHandleExists(string $selector, string $handle): bool
    {
        try {
            return match ($selector) {
                'sections' => Craft::$app->getEntries()->getSectionByHandle($handle) !== null,
                'volumes' => Craft::$app->getVolumes()->getVolumeByHandle($handle) !== null,
                'groups' => Craft::$app->getCategories()->getGroupByHandle($handle) !== null,
                'sourceHandles' => (new Query())
                    ->from('{{%docsmanager_sources}}')
                    ->where(['handle' => $handle])
                    ->exists(),
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private function isJsonEncodable(mixed $value): bool
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    protected function findBackendByHandle(string $handle): ?ConfiguredBackend
    {
        return ConfiguredBackend::findByHandle($handle);
    }

    /**
     * ElementTypeGuardTrait's operational guard logs; validation reports findings instead.
     *
     * @param array<string, mixed> $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
    }

    private function addInvalidValue(ConfigIndexValidationResult $result, string $handle, string $key): void
    {
        $result->addFinding(
            $handle,
            ConfigIndexValidationResult::SEVERITY_ERROR,
            $key,
            Craft::t(self::PLUGIN_HANDLE, 'Invalid value for "{key}".', [
                'key' => $key,
            ]),
        );
    }
}
