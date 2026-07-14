<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use lindemannrock\base\helpers\BooleanHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\searchmanager\helpers\SnippetOptionsHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\traits\ConfigSourceTrait;

/**
 * WidgetConfig model
 *
 * Stores configuration for search widget instances.
 * Allows multiple named configurations with different appearance/behavior settings.
 *
 * @since 5.30.0
 */
class WidgetConfig extends Model
{
    use ConfigSourceTrait;

    private const SAFE_HIGHLIGHT_TAGS = ['mark', 'em', 'strong', 'b', 'i', 'span'];
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;

    public string $handle = '';

    public string $name = '';

    public string $type = 'modal';

    public bool $enabled = true;

    public ?string $styleHandle = null;

    /**
     * @var array|string|null Settings stored as JSON in database
     */
    public array|string|null $settings = null;

    public ?\DateTime $dateCreated = null;

    public ?\DateTime $dateUpdated = null;

    public ?string $uid = null;

    // =========================================================================
    // DEFAULT SETTINGS
    // =========================================================================

    /**
     * Default settings structure for new widget configs
     *
     * @return array
     */
    public static function defaultSettings(): array
    {
        return [
            'apiKeyHandle' => '',
            'search' => [
                'indexHandles' => [], // Empty = search all indices
                'placeholder' => 'Search...',
            ],
            'behavior' => [
                'modalPreventBodyScroll' => true,
                'searchDebounceMs' => 200,
                'searchMinChars' => 2,
                'resultsLimit' => 10,
                'hierarchyMaxHeadings' => 3,
                'recentSearchesEnabled' => true,
                'recentSearchesLimit' => 5,
                'resultsGroupingEnabled' => true,
                'triggerHotkey' => 'k',
                'resultsRequireUrl' => false,
                'resultsLayout' => 'default',
                'hierarchyGroupBy' => '',
                'hierarchyStyle' => 'tree',
                'hierarchyDisplay' => 'individual',
                'snippetIncludeCodeBlocks' => SnippetOptionsHelper::DEFAULT_SHOW_CODE,
                'snippetMode' => SnippetOptionsHelper::DEFAULT_MODE,
                'resultsTitleLines' => 1,
                'resultsDescriptionLines' => 1,
                'snippetMaxLength' => SnippetOptionsHelper::DEFAULT_LENGTH,
                'snippetCleanMarkdown' => SnippetOptionsHelper::DEFAULT_PARSE_MARKDOWN,
                'loadingIndicatorEnabled' => true,
                'highlightDestinationEnabled' => true,
                'highlightDestinationPersistQuery' => true,
                'highlightDestinationQueryParam' => 'smq',
                'highlightDestinationContentSelector' => 'main, article, [data-search-content]',
            ],
            'trigger' => [
                'triggerEnabled' => true,
                'triggerLabel' => 'Search',
            ],
            'analytics' => [
                'analyticsSource' => '',        // Custom source identifier (e.g., 'header-search', 'mobile-nav')
                'analyticsIdleTimeoutMs' => 1500, // Track search after idle timeout in ms (0 = disabled)
            ],
        ];
    }

    // =========================================================================
    // SETTINGS ACCESSORS
    // =========================================================================

    /**
     * Get settings as array (parses JSON if needed)
     *
     * @return array
     */
    public function getSettingsArray(): array
    {
        if (is_string($this->settings)) {
            return Json::decodeIfJson($this->settings) ?: self::defaultSettings();
        }

        return $this->settings ?: self::defaultSettings();
    }

    /**
     * Get a specific setting with dot notation support
     *
     * @param string $key Dot notation key (e.g., 'behavior.debounce')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->getSettingsArray();
        $keys = explode('.', $key);

        $value = $settings;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set a specific setting with dot notation support
     *
     * @param string $key
     * @param mixed $value
     */
    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->getSettingsArray();
        $keys = explode('.', $key);
        $lastKey = array_pop($keys);

        $current = &$settings;
        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }
        $current[$lastKey] = $value;

        $this->settings = $settings;
    }

    // =========================================================================
    // CONVENIENCE ACCESSORS
    // =========================================================================

    // Search
    /**
     * Get selected index handles (empty array = search all)
     *
     * @return string[]
     */
    public function getIndexHandles(): array
    {
        $handles = $this->getSetting('search.indexHandles', []);
        return is_array($handles) ? $handles : [];
    }

    /**
     * Get placeholder text for the search input
     *
     * @since 5.39.0
     */
    public function getPlaceholder(): string
    {
        return $this->getSetting('search.placeholder', 'Search...');
    }

    // Behavior
    public function isModalPreventBodyScrollEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.modalPreventBodyScroll', true);
    }

    public function getSearchDebounceMs(): int
    {
        return (int) $this->getSetting('behavior.searchDebounceMs', 200);
    }

    public function getSearchMinChars(): int
    {
        return (int) $this->getSetting('behavior.searchMinChars', 2);
    }

    public function getResultsLimit(): int
    {
        return (int) $this->getSetting('behavior.resultsLimit', 10);
    }

    /**
     * Maximum heading children to display per page block
     *
     * @since 5.39.0
     */
    public function getHierarchyMaxHeadings(): int
    {
        return (int) $this->getSetting('behavior.hierarchyMaxHeadings', 3);
    }

    public function isRecentSearchesEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.recentSearchesEnabled', true);
    }

    public function getRecentSearchesLimit(): int
    {
        return (int) $this->getSetting('behavior.recentSearchesLimit', 5);
    }

    public function isResultsGroupingEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.resultsGroupingEnabled', true);
    }

    public function getTriggerHotkey(): string
    {
        return $this->getSetting('behavior.triggerHotkey', 'k');
    }

    public function isResultsRequireUrlEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.resultsRequireUrl', false);
    }

    /**
     * Result layout mode: default | hierarchical
     *
     * @since 5.39.0
     */
    public function getResultsLayout(): string
    {
        $layout = (string) $this->getSetting('behavior.resultsLayout', 'default');
        $layout = strtolower(trim($layout));
        return in_array($layout, ['default', 'hierarchical'], true) ? $layout : 'default';
    }

    /**
     * Field to group hierarchical results by (e.g., 'source', 'entrySection', 'docCategory', 'categoryGroup')
     *
     * @since 5.39.0
     */
    public function getHierarchyGroupBy(): string
    {
        return (string) $this->getSetting('behavior.hierarchyGroupBy', '');
    }

    /**
     * Hierarchy display style: 'tree' (indented + connectors), 'flat' (no indentation + connectors), 'none' (no indentation, no connectors)
     *
     * @since 5.39.0
     */
    public function getHierarchyStyle(): string
    {
        $style = (string) $this->getSetting('behavior.hierarchyStyle', 'tree');
        $style = strtolower(trim($style));
        return in_array($style, ['tree', 'flat', 'none'], true) ? $style : 'tree';
    }

    /**
     * Hierarchy display mode: 'individual' (each result as own card) or 'unified' (page + headings in one card)
     *
     * @since 5.39.0
     */
    public function getHierarchyDisplay(): string
    {
        $display = (string) $this->getSetting('behavior.hierarchyDisplay', 'individual');
        $display = strtolower(trim($display));
        return in_array($display, ['individual', 'unified'], true) ? $display : 'individual';
    }

    /**
     * Allow code snippets in descriptions
     *
     * @since 5.39.0
     */
    public function isSnippetIncludeCodeBlocksEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.snippetIncludeCodeBlocks', SnippetOptionsHelper::DEFAULT_SHOW_CODE);
    }

    /**
     * Snippet mode: early | balanced | deep
     *
     * @since 5.39.0
     */
    public function getSnippetMode(): string
    {
        return SnippetOptionsHelper::normalizeMode($this->getSetting('behavior.snippetMode', SnippetOptionsHelper::DEFAULT_MODE));
    }

    /**
     * Result title line clamp count
     *
     * @since 5.39.0
     */
    public function getResultsTitleLines(): int
    {
        return (int) $this->getSetting('behavior.resultsTitleLines', 1);
    }

    /**
     * Result description line clamp count
     *
     * @since 5.39.0
     */
    public function getResultsDescriptionLines(): int
    {
        return (int) $this->getSetting('behavior.resultsDescriptionLines', 1);
    }

    /**
     * Snippet max length
     *
     * @since 5.39.0
     */
    public function getSnippetMaxLength(): int
    {
        return SnippetOptionsHelper::normalizeLength($this->getSetting('behavior.snippetMaxLength', SnippetOptionsHelper::DEFAULT_LENGTH));
    }

    /**
     * Clean Markdown markers from snippet display text.
     *
     * @since 5.39.0
     */
    public function isSnippetCleanMarkdownEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.snippetCleanMarkdown', SnippetOptionsHelper::DEFAULT_PARSE_MARKDOWN);
    }

    /**
     * @return array{snippetIncludeCodeBlocks: bool, snippetMode: string, snippetMaxLength: int, snippetCleanMarkdown: bool, minSnippetLength: int, maxSnippetLength: int, snippetModes: list<string>}
     * @since 5.53.0
     */
    public function getSnippetDefaults(): array
    {
        return SnippetOptionsHelper::widgetDefaults();
    }

    public function isLoadingIndicatorEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.loadingIndicatorEnabled', true);
    }

    /**
     * Enable destination page highlighting after navigating from a search result.
     *
     * @since 5.39.0
     */
    public function isHighlightDestinationEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.highlightDestinationEnabled', true);
    }

    /**
     * Append search query to destination URLs for page highlighting.
     *
     * @since 5.39.0
     */
    public function isHighlightDestinationPersistQueryEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.highlightDestinationPersistQuery', true);
    }

    /**
     * URL parameter name for the persisted search query.
     *
     * @since 5.39.0
     */
    public function getHighlightDestinationQueryParam(): string
    {
        return $this->getSetting('behavior.highlightDestinationQueryParam', 'smq');
    }

    /**
     * CSS selector for destination page content areas to highlight.
     *
     * @since 5.39.0
     */
    public function getHighlightDestinationContentSelector(): string
    {
        return $this->getSetting('behavior.highlightDestinationContentSelector', 'main, article, [data-search-content]');
    }

    // Trigger
    public function isTriggerEnabled(): bool
    {
        return $this->getBooleanSetting('trigger.triggerEnabled', true);
    }

    public function getTriggerLabel(): string
    {
        return $this->getSetting('trigger.triggerLabel', 'Search');
    }

    // Analytics
    public function getAnalyticsSource(): string
    {
        return $this->getSetting('analytics.analyticsSource', '');
    }

    public function getApiKeyHandle(): string
    {
        $value = $this->getSetting('apiKeyHandle', '');
        if (is_string($value)) {
            return trim($value);
        }

        return '';
    }

    public function getSelectedApiKey(): ?ApiKey
    {
        return SearchManager::$plugin->apiKeys->findWidgetUsablePublicKeyByHandle($this->getApiKeyHandle());
    }

    /**
     * The public API key this widget sends as the `X-Search-Manager-Key`
     * header. Saved/config references should use `apiKeyHandle` to point at a
     * CP-managed public API key by handle. Direct `apiKey` values are raw
     * public keys intended for render-time overrides or config-only widgets
     * that intentionally provide the actual key value.
     *
     * @since 5.47.0
     */
    public function getApiKey(): string
    {
        $selectedKey = $this->getSelectedApiKey();
        if ($selectedKey !== null) {
            return SearchManager::$plugin->apiKeys->decryptPlaintextKey($selectedKey) ?? '';
        }

        return (string)$this->getSetting('apiKey', '');
    }

    public function getAnalyticsIdleTimeoutMs(): int
    {
        return (int) $this->getSetting('analytics.analyticsIdleTimeoutMs', 1500);
    }

    // Styles - returns styles from style preset or inline config
    public function getStyles(): array
    {
        // Style preset takes priority
        if ($this->styleHandle) {
            $preset = \lindemannrock\searchmanager\SearchManager::$plugin->widgetStyles->getByHandle($this->styleHandle);
            if ($preset && $preset->enabled) {
                return $preset->getStyles();
            }
        }

        // Fall back to inline styles (config-file widgets)
        $inlineStyles = $this->getSetting('styles', []);

        return is_array($inlineStyles) ? $inlineStyles : [];
    }

    /**
     * Get styles with defaults for CP preview
     * Merges configured styles with defaults so preview renders correctly
     *
     * @return array
     */
    public function getStylesForPreview(): array
    {
        return $this->getStylesForRender();
    }

    /**
     * Get normalized styles for public widget rendering.
     *
     * @param array<string, mixed> $overrides Render-time style overrides
     * @return array
     */
    public function getStylesForRender(array $overrides = []): array
    {
        $styles = array_merge(self::defaultStyleValues(), $this->getStyles(), $overrides);
        $styles['highlightTag'] = $this->normalizeHighlightTag((string)($styles['highlightTag'] ?? ''));
        $styles['highlightClass'] = $this->normalizeHighlightClass((string)($styles['highlightClass'] ?? ''));

        return $styles;
    }

    private function normalizeHighlightTag(string $tag): string
    {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            return '';
        }

        return in_array($tag, self::SAFE_HIGHLIGHT_TAGS, true) ? $tag : 'mark';
    }

    private function normalizeHighlightClass(string $class): string
    {
        $tokens = [];
        foreach (preg_split('/\s+/', trim($class)) ?: [] as $token) {
            if ($token !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1) {
                $tokens[] = $token;
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * @var array|null Cached style defaults from JSON file
     */
    private static ?array $_styleDefaults = null;

    /**
     * Default style values loaded from shared JSON config
     * Single source of truth: src/config/style-defaults.json
     * This is also imported by JavaScript StyleConfig.js
     *
     * @return array
     */
    public static function defaultStyleValues(): array
    {
        if (self::$_styleDefaults !== null) {
            return self::$_styleDefaults;
        }

        $jsonPath = __DIR__ . '/../config/style-defaults.json';
        if (file_exists($jsonPath)) {
            $json = file_get_contents($jsonPath);
            self::$_styleDefaults = json_decode($json, true) ?: [];
        } else {
            self::$_styleDefaults = [];
        }

        return self::$_styleDefaults;
    }


    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get raw config display for showing in tooltip (config widgets only)
     *
     * @return string
     */
    public function getRawConfigDisplay(): string
    {
        if (!$this->isFromConfig()) {
            return '';
        }

        $settings = $this->getSettingsArray();

        // Build a summary config (exclude verbose style arrays for tooltip)
        $config = [
            'name' => $this->name,
            'enabled' => $this->enabled,
        ];

        // Add index handles if set
        $indexHandles = $settings['search']['indexHandles'] ?? [];
        if (!empty($indexHandles)) {
            $config['search']['indexHandles'] = $indexHandles;
        }

        // Add behavior settings if customized
        if (!empty($settings['behavior'])) {
            $config['behavior'] = $settings['behavior'];
        }

        // Add trigger settings if customized
        if (!empty($settings['trigger'])) {
            $config['trigger'] = $settings['trigger'];
        }

        // Show style handle if set
        if ($this->styleHandle) {
            $config['styleHandle'] = $this->styleHandle;
        }

        return $this->formatConfigDisplay($config, $this->handle, []);
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /** @inheritdoc */
    public function rules(): array
    {
        return [
            [['handle', 'name'], 'required'],
            [['handle'], 'string', 'max' => 64],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => Craft::t('search-manager', 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.')],
            [['handle'], 'validateUniqueHandle'],
            [['type'], 'in', 'range' => WidgetStyle::WIDGET_TYPES],
            [['type'], 'validateImplementedType'],
            [['enabled'], 'boolean'],
            [['settings'], 'validateSettings'],
        ];
    }

    /** @inheritdoc */
    public function attributeLabels(): array
    {
        return [
            'handle' => Craft::t('search-manager', 'Handle'),
            'name' => Craft::t('search-manager', 'Name'),
            'type' => Craft::t('search-manager', 'Widget Type'),
            'enabled' => Craft::t('search-manager', 'Enabled'),
            'settings' => Craft::t('search-manager', 'Settings'),
        ];
    }

    /**
     * Validate handle is unique among database-backed widget configs.
     */
    public function validateUniqueHandle(string $attribute): void
    {
        if (SlugHandleHelper::exists('{{%searchmanager_widget_configs}}', 'handle', $this->handle, [
            'excludeId' => $this->id,
        ])) {
            $this->addError($attribute, Craft::t('search-manager', 'Handle must be unique.'));
        }
    }

    /**
     * Validate that the selected widget type is implemented in this release.
     */
    public function validateImplementedType(string $attribute): void
    {
        if ($this->type !== WidgetStyle::TYPE_MODAL) {
            $this->addError($attribute, Craft::t('search-manager', 'Only modal widgets are available in this version.'));
        }
    }

    /**
     * Validate nested settings values
     *
     * @since 5.39.0
     */
    public function validateSettings(): void
    {
        $s = $this->getSettingsArray();

        $selectedApiKey = $this->validateApiKeySelection($s);
        $this->validateDirectApiKey($s);

        // Search settings
        $this->validateStringField($s, 'search', 'placeholder', Craft::t('search-manager', 'Placeholder'), 255);
        $this->validateIndexHandles($s, $selectedApiKey);

        // Behavior settings — integers with ranges
        $this->validateIntField($s, 'behavior', 'searchDebounceMs', Craft::t('search-manager', 'Search Debounce'), 0, 2000);
        $this->validateIntField($s, 'behavior', 'searchMinChars', Craft::t('search-manager', 'Search Minimum Characters'), 1, 10);
        $this->validateIntField($s, 'behavior', 'resultsLimit', Craft::t('search-manager', 'Results Limit'), 1, 100);
        $this->validateIntField($s, 'behavior', 'hierarchyMaxHeadings', Craft::t('search-manager', 'Hierarchy Max Headings'), 1, 50);
        if (BooleanHelper::normalize($s['behavior']['recentSearchesEnabled'] ?? true, true)) {
            $this->validateIntField($s, 'behavior', 'recentSearchesLimit', Craft::t('search-manager', 'Recent Searches Limit'), 1, 50);
        }
        $this->validateIntField($s, 'behavior', 'resultsTitleLines', Craft::t('search-manager', 'Results Title Lines'), 1, 5);
        $this->validateIntField($s, 'behavior', 'resultsDescriptionLines', Craft::t('search-manager', 'Results Description Lines'), 1, 5);
        $this->validateIntField($s, 'behavior', 'snippetMaxLength', Craft::t('search-manager', 'Snippet Max Length'), SnippetOptionsHelper::MIN_LENGTH, SnippetOptionsHelper::MAX_LENGTH);

        // Behavior settings — enums
        $this->validateEnumField($s, 'behavior', 'resultsLayout', Craft::t('search-manager', 'Results Layout'), ['default', 'hierarchical']);
        $this->validateEnumField($s, 'behavior', 'hierarchyStyle', Craft::t('search-manager', 'Hierarchy Style'), ['tree', 'flat', 'none']);
        $this->validateEnumField($s, 'behavior', 'hierarchyDisplay', Craft::t('search-manager', 'Hierarchy Display'), ['individual', 'unified']);
        $this->validateEnumField($s, 'behavior', 'snippetMode', Craft::t('search-manager', 'Snippet Mode'), SnippetOptionsHelper::MODES);

        // Behavior settings — booleans
        $this->validateBooleanField($s, 'behavior', 'modalPreventBodyScroll', Craft::t('search-manager', 'Prevent Body Scroll'));
        $this->validateBooleanField($s, 'behavior', 'recentSearchesEnabled', Craft::t('search-manager', 'Enable Recent Searches'));
        $this->validateBooleanField($s, 'behavior', 'resultsGroupingEnabled', Craft::t('search-manager', 'Enable Result Grouping'));
        $this->validateBooleanField($s, 'behavior', 'resultsRequireUrl', Craft::t('search-manager', 'Require URL for Results'));
        $this->validateBooleanField($s, 'behavior', 'snippetIncludeCodeBlocks', Craft::t('search-manager', 'Include Code Blocks'));
        $this->validateBooleanField($s, 'behavior', 'snippetCleanMarkdown', Craft::t('search-manager', 'Clean Markdown'));
        $this->validateBooleanField($s, 'behavior', 'loadingIndicatorEnabled', Craft::t('search-manager', 'Loading Indicator Enabled'));
        $this->validateBooleanField($s, 'behavior', 'highlightDestinationEnabled', Craft::t('search-manager', 'Enable Destination Highlighting'));
        $this->validateBooleanField($s, 'behavior', 'highlightDestinationPersistQuery', Craft::t('search-manager', 'Persist Query in URL'));

        // Behavior settings — strings
        $this->validateStringField($s, 'behavior', 'triggerHotkey', Craft::t('search-manager', 'Trigger Hotkey'), 1);
        $this->validateStringField($s, 'behavior', 'hierarchyGroupBy', Craft::t('search-manager', 'Hierarchy Group By Field'), 64);
        $this->validateStringField($s, 'behavior', 'highlightDestinationQueryParam', Craft::t('search-manager', 'Destination Highlighting Query Parameter'), 32);
        $this->validateStringField($s, 'behavior', 'highlightDestinationContentSelector', Craft::t('search-manager', 'Destination Highlighting Content Selector'), 255);
        $this->validateQueryParamName($s);
        $this->validateCssSelector('settings.behavior.highlightDestinationContentSelector', (string)($s['behavior']['highlightDestinationContentSelector'] ?? ''));

        // Trigger settings
        $this->validateStringField($s, 'trigger', 'triggerLabel', Craft::t('search-manager', 'Trigger Label'), 255);
        $this->validateBooleanField($s, 'trigger', 'triggerEnabled', Craft::t('search-manager', 'Trigger Enabled'));

        // Analytics settings
        $this->validateIntField($s, 'analytics', 'analyticsIdleTimeoutMs', Craft::t('search-manager', 'Analytics Idle Timeout'), 0, 10000);
        $this->validateStringField($s, 'analytics', 'analyticsSource', Craft::t('search-manager', 'Analytics Source'), 64);
        $this->validateSourceIdentifier($s);
    }

    /**
     * Validate the widget's selected API key is usable for rendering.
     */
    private function validateApiKeySelection(array $settings): ?ApiKey
    {
        $raw = $settings['apiKeyHandle'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_string($raw)) {
            $this->addError('settings.apiKeyHandle', Craft::t('search-manager', 'Select a valid widget API key.'));
            return null;
        }

        $key = SearchManager::$plugin->apiKeys->findWidgetUsablePublicKeyByHandle($raw);
        if ($key === null) {
            $this->addError('settings.apiKeyHandle', Craft::t('search-manager', 'Select a valid widget API key.'));
        }

        return $key;
    }

    /**
     * Validate raw public API key values remain browser-safe.
     */
    private function validateDirectApiKey(array $settings): void
    {
        $value = trim((string)($settings['apiKey'] ?? ''));
        if ($value === '') {
            return;
        }

        if (!str_starts_with($value, 'sm_pub_')) {
            $this->addError(
                'settings.apiKey',
                Craft::t('search-manager', 'Select a valid search API key.')
            );
        }
    }

    /**
     * Validate an integer field within a range
     */
    private function validateIntField(array $settings, string $group, string $key, string $label, int $min, int $max): void
    {
        $value = $settings[$group][$key] ?? null;
        if ($value === null || $value === '') {
            return;
        }
        if (!is_numeric($value) || preg_match('/^-?\d+$/', (string)$value) !== 1) {
            $this->addError("settings.{$group}.{$key}", Craft::t('search-manager', '{label} must be a whole number.', [
                'label' => $label,
            ]));
            return;
        }
        $intVal = (int) $value;
        if ($intVal < $min || $intVal > $max) {
            $this->addError("settings.{$group}.{$key}", Craft::t('search-manager', '{label} must be between {min} and {max}.', [
                'label' => $label,
                'min' => $min,
                'max' => $max,
            ]));
        }
    }

    /**
     * Validate a boolean-like field.
     */
    private function validateBooleanField(array $settings, string $group, string $key, string $label): void
    {
        $value = $settings[$group][$key] ?? null;
        if ($value === null) {
            return;
        }
        if (!BooleanHelper::isBooleanLike($value)) {
            $this->addError("settings.{$group}.{$key}", Craft::t('search-manager', '{label} must be true or false.', [
                'label' => $label,
            ]));
        }
    }

    private function getBooleanSetting(string $key, bool $default): bool
    {
        return BooleanHelper::normalize($this->getSetting($key, $default), $default);
    }

    /**
     * Validate a string field with max length
     */
    private function validateStringField(array $settings, string $group, string $key, string $label, int $maxLength): void
    {
        $value = $settings[$group][$key] ?? null;
        if ($value === null || $value === '') {
            return;
        }
        if (mb_strlen((string) $value) > $maxLength) {
            $this->addError("settings.{$group}.{$key}", Craft::t('search-manager', '{label} must be {maxLength} characters or fewer.', [
                'label' => $label,
                'maxLength' => $maxLength,
            ]));
        }
    }

    /**
     * Validate an enum field against allowed values
     */
    private function validateEnumField(array $settings, string $group, string $key, string $label, array $allowed): void
    {
        $value = $settings[$group][$key] ?? null;
        if ($value === null || $value === '') {
            return;
        }
        if (!in_array(strtolower(trim((string) $value)), $allowed, true)) {
            $this->addError("settings.{$group}.{$key}", Craft::t('search-manager', '{label} must be one of: {values}.', [
                'label' => $label,
                'values' => implode(', ', $allowed),
            ]));
        }
    }

    /**
     * Validate selected search index handles exist.
     */
    private function validateIndexHandles(array $settings, ?ApiKey $selectedApiKey = null): void
    {
        $handles = $settings['search']['indexHandles'] ?? [];
        if ($handles === '' || $handles === []) {
            return;
        }

        if (!is_array($handles)) {
            $this->addError('settings.search.indexHandles', Craft::t('search-manager', 'Search Indices must be an array of index handles.'));
            return;
        }

        $validHandles = array_map(
            static fn(SearchIndex $index): string => $index->handle,
            SearchIndex::findAll(),
        );

        foreach ($handles as $handle) {
            if (!is_string($handle) || $handle === '' || !in_array($handle, $validHandles, true)) {
                $this->addError('settings.search.indexHandles', Craft::t('search-manager', 'One or more selected search indices are invalid.'));
                return;
            }
        }

        if ($selectedApiKey !== null && !$selectedApiKey->allowsAllIndices()) {
            foreach ($handles as $handle) {
                if (!$selectedApiKey->allowsIndex((string)$handle)) {
                    $this->addError('settings.search.indexHandles', Craft::t('search-manager', 'Selected indices must be allowed by the selected API key.'));
                    return;
                }
            }
        }
    }

    /**
     * Validate query parameter name format.
     */
    private function validateQueryParamName(array $settings): void
    {
        $value = trim((string)($settings['behavior']['highlightDestinationQueryParam'] ?? ''));
        if ($value === '') {
            return;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $value) !== 1) {
            $this->addError(
                'settings.behavior.highlightDestinationQueryParam',
                Craft::t('search-manager', 'Destination Highlighting Query Parameter must start with a letter and contain only letters, numbers, hyphens, and underscores.')
            );
        }
    }

    /**
     * Validate analytics source identifier format.
     */
    private function validateSourceIdentifier(array $settings): void
    {
        $value = trim((string)($settings['analytics']['analyticsSource'] ?? ''));
        if ($value === '') {
            return;
        }

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $value) !== 1) {
            $this->addError(
                'settings.analytics.analyticsSource',
                Craft::t('search-manager', 'Analytics Source must start with a lowercase letter and contain only lowercase letters, numbers, hyphens, and underscores.')
            );
        }
    }

    /**
     * Validate CSS selector field is syntactically safe.
     */
    private function validateCssSelector(string $attribute, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        if (preg_match('/[<>"\'`{};]/', $value) === 1 || stripos($value, 'javascript:') !== false) {
            $this->addError($attribute, Craft::t('search-manager', 'Content Selector contains unsafe characters.'));
            return;
        }

        if (preg_match('/[^a-zA-Z0-9\s\-\_\.\#\[\]\:\,\>\+\~\*\(\)=]/', $value) === 1) {
            $this->addError(
                $attribute,
                Craft::t('search-manager', 'Content Selector contains invalid characters. Use CSS selectors only (for example: main, article, [data-search-content]).')
            );
        }
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Prepare for database save
     *
     * @return array
     */
    public function prepareForDb(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'type' => $this->type,
            'settings' => is_array($this->settings) ? Json::encode($this->settings) : $this->settings,
            'enabled' => $this->enabled ? 1 : 0,
            'styleHandle' => $this->styleHandle,
        ];
    }
}
