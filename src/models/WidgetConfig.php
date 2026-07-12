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
                'preventBodyScroll' => true,
                'debounce' => 200,
                'minChars' => 2,
                'maxResults' => 10,
                'maxHeadingsPerResult' => 3,
                'showRecent' => true,
                'maxRecentSearches' => 5,
                'groupResults' => true,
                'hotkey' => 'k',
                'hideResultsWithoutUrl' => false,
                'resultLayout' => 'default',
                'hierarchyGroupBy' => '',
                'hierarchyDisplay' => 'individual',
                'showCodeSnippets' => SnippetOptionsHelper::DEFAULT_SHOW_CODE,
                'snippetMode' => SnippetOptionsHelper::DEFAULT_MODE,
                'resultTitleLines' => 1,
                'resultDescLines' => 1,
                'snippetLength' => SnippetOptionsHelper::DEFAULT_LENGTH,
                'parseMarkdownSnippets' => SnippetOptionsHelper::DEFAULT_PARSE_MARKDOWN,
                'showLoadingIndicator' => true,
                'highlightDestinationPage' => true,
                'persistQueryInUrl' => true,
                'queryParamName' => 'smq',
                'destinationHighlightSelector' => 'main, article, [data-search-content]',
            ],
            'trigger' => [
                'showTrigger' => true,
                'triggerText' => 'Search',
            ],
            'analytics' => [
                'source' => '',           // Custom source identifier (e.g., 'header-search', 'mobile-nav')
                'idleTimeout' => 1500,    // Track search after idle timeout in ms (0 = disabled)
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
        // Handle legacy single indexHandle
        if (empty($handles)) {
            $legacy = $this->getSetting('search.indexHandle', '');
            if (!empty($legacy)) {
                return [$legacy];
            }
        }
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

    /**
     * @deprecated Use getIndexHandles() instead
     */
    public function getIndexHandle(): string
    {
        $handles = $this->getIndexHandles();
        return $handles[0] ?? '';
    }

    // Behavior
    public function isPreventBodyScrollEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.preventBodyScroll', true);
    }

    public function getDebounce(): int
    {
        return (int) $this->getSetting('behavior.debounce', 200);
    }

    public function getMinChars(): int
    {
        return (int) $this->getSetting('behavior.minChars', 2);
    }

    public function getMaxResults(): int
    {
        return (int) $this->getSetting('behavior.maxResults', 10);
    }

    /**
     * Maximum heading children to display per result
     *
     * @since 5.39.0
     */
    public function getMaxHeadingsPerResult(): int
    {
        return (int) $this->getSetting('behavior.maxHeadingsPerResult', 3);
    }

    public function isShowRecentEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.showRecent', true);
    }

    public function getMaxRecentSearches(): int
    {
        return (int) $this->getSetting('behavior.maxRecentSearches', 5);
    }

    public function isGroupResultsEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.groupResults', true);
    }

    public function getHotkey(): string
    {
        return $this->getSetting('behavior.hotkey', 'k');
    }

    public function isHideResultsWithoutUrlEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.hideResultsWithoutUrl', false);
    }

    /**
     * Result layout mode: default | hierarchical
     *
     * @since 5.39.0
     */
    public function getResultLayout(): string
    {
        $layout = (string) $this->getSetting('behavior.resultLayout', 'default');
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
    public function isShowCodeSnippetsEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.showCodeSnippets', SnippetOptionsHelper::DEFAULT_SHOW_CODE);
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
    public function getResultTitleLines(): int
    {
        return (int) $this->getSetting('behavior.resultTitleLines', 1);
    }

    /**
     * Result description line clamp count
     *
     * @since 5.39.0
     */
    public function getResultDescLines(): int
    {
        return (int) $this->getSetting('behavior.resultDescLines', 1);
    }

    /**
     * Snippet max length
     *
     * @since 5.39.0
     */
    public function getSnippetLength(): int
    {
        return SnippetOptionsHelper::normalizeLength($this->getSetting('behavior.snippetLength', SnippetOptionsHelper::DEFAULT_LENGTH));
    }

    /**
     * Clean Markdown markers from snippet display text.
     *
     * @since 5.39.0
     */
    public function isParseMarkdownSnippetsEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.parseMarkdownSnippets', SnippetOptionsHelper::DEFAULT_PARSE_MARKDOWN);
    }

    /**
     * @return array{showCodeSnippets: bool, snippetMode: string, snippetLength: int, parseMarkdownSnippets: bool, minSnippetLength: int, maxSnippetLength: int, snippetModes: list<string>}
     * @since 5.53.0
     */
    public function getSnippetDefaults(): array
    {
        return SnippetOptionsHelper::widgetDefaults();
    }

    public function isShowLoadingIndicatorEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.showLoadingIndicator', true);
    }

    /**
     * Enable destination page highlighting after navigating from a search result.
     *
     * @since 5.39.0
     */
    public function isHighlightDestinationPageEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.highlightDestinationPage', true);
    }

    /**
     * Append search query to destination URLs for page highlighting.
     *
     * @since 5.39.0
     */
    public function isPersistQueryInUrlEnabled(): bool
    {
        return $this->getBooleanSetting('behavior.persistQueryInUrl', true);
    }

    /**
     * URL parameter name for the persisted search query.
     *
     * @since 5.39.0
     */
    public function getQueryParamName(): string
    {
        return $this->getSetting('behavior.queryParamName', 'smq');
    }

    /**
     * CSS selector for destination page content areas to highlight.
     *
     * @since 5.39.0
     */
    public function getDestinationHighlightSelector(): string
    {
        return $this->getSetting('behavior.destinationHighlightSelector', 'main, article, [data-search-content]');
    }

    // Trigger
    public function isShowTriggerEnabled(): bool
    {
        return $this->getBooleanSetting('trigger.showTrigger', true);
    }

    public function getTriggerText(): string
    {
        return $this->getSetting('trigger.triggerText', 'Search');
    }

    // Analytics
    public function getAnalyticsSource(): string
    {
        return $this->getSetting('analytics.source', '');
    }

    public function getApiKeyId(): ?int
    {
        $value = $this->getSetting('apiKeyId');
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
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
        $key = SearchManager::$plugin->apiKeys->findWidgetUsablePublicKeyByHandle($this->getApiKeyHandle());
        if ($key !== null) {
            return $key;
        }

        return SearchManager::$plugin->apiKeys->findWidgetUsablePublicKeyById($this->getApiKeyId());
    }

    /**
     * The public API key this widget sends as the `X-Search-Manager-Key`
     * header. Saved/config references should use `apiKeyHandle` to point at a
     * CP-managed public API key by handle. Direct `apiKey` values are raw
     * public keys intended for render-time overrides or config-only widgets
     * that intentionally provide the actual key value. Numeric IDs are still
     * accepted when reading older database settings from pre-release test
     * installs, but CP saves write handles.
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

    public function getIdleTimeout(): int
    {
        return (int) $this->getSetting('analytics.idleTimeout', 1500);
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
        $this->validateIntField($s, 'behavior', 'debounce', Craft::t('search-manager', 'Debounce'), 0, 2000);
        $this->validateIntField($s, 'behavior', 'minChars', Craft::t('search-manager', 'Minimum Characters'), 1, 10);
        $this->validateIntField($s, 'behavior', 'maxResults', Craft::t('search-manager', 'Maximum Results'), 1, 100);
        $this->validateIntField($s, 'behavior', 'maxHeadingsPerResult', Craft::t('search-manager', 'Max Headings per Result'), 1, 50);
        if (BooleanHelper::normalize($s['behavior']['showRecent'] ?? true, true)) {
            $this->validateIntField($s, 'behavior', 'maxRecentSearches', Craft::t('search-manager', 'Max Recent Searches'), 1, 50);
        }
        $this->validateIntField($s, 'behavior', 'resultTitleLines', Craft::t('search-manager', 'Result Title Lines'), 1, 5);
        $this->validateIntField($s, 'behavior', 'resultDescLines', Craft::t('search-manager', 'Result Description Lines'), 1, 5);
        $this->validateIntField($s, 'behavior', 'snippetLength', Craft::t('search-manager', 'Snippet Length'), SnippetOptionsHelper::MIN_LENGTH, SnippetOptionsHelper::MAX_LENGTH);

        // Behavior settings — enums
        $this->validateEnumField($s, 'behavior', 'resultLayout', Craft::t('search-manager', 'Result Layout'), ['default', 'hierarchical']);
        $this->validateEnumField($s, 'behavior', 'hierarchyDisplay', Craft::t('search-manager', 'Hierarchy Display'), ['individual', 'unified']);
        $this->validateEnumField($s, 'behavior', 'snippetMode', Craft::t('search-manager', 'Snippet Mode'), SnippetOptionsHelper::MODES);

        // Behavior settings — booleans
        $this->validateBooleanField($s, 'behavior', 'preventBodyScroll', Craft::t('search-manager', 'Prevent Body Scroll'));
        $this->validateBooleanField($s, 'behavior', 'showRecent', Craft::t('search-manager', 'Show Recent Searches'));
        $this->validateBooleanField($s, 'behavior', 'groupResults', Craft::t('search-manager', 'Group Results'));
        $this->validateBooleanField($s, 'behavior', 'hideResultsWithoutUrl', Craft::t('search-manager', 'Hide Results Without URL'));
        $this->validateBooleanField($s, 'behavior', 'showCodeSnippets', Craft::t('search-manager', 'Show Code Snippets'));
        $this->validateBooleanField($s, 'behavior', 'parseMarkdownSnippets', Craft::t('search-manager', 'Parse Markdown Snippets'));
        $this->validateBooleanField($s, 'behavior', 'showLoadingIndicator', Craft::t('search-manager', 'Show Loading Indicator'));
        $this->validateBooleanField($s, 'behavior', 'highlightDestinationPage', Craft::t('search-manager', 'Highlight Destination Page'));
        $this->validateBooleanField($s, 'behavior', 'persistQueryInUrl', Craft::t('search-manager', 'Persist Query in URL'));

        // Behavior settings — strings
        $this->validateStringField($s, 'behavior', 'hotkey', Craft::t('search-manager', 'Hotkey'), 1);
        $this->validateStringField($s, 'behavior', 'hierarchyGroupBy', Craft::t('search-manager', 'Group By Field'), 64);
        $this->validateStringField($s, 'behavior', 'queryParamName', Craft::t('search-manager', 'Query Parameter Name'), 32);
        $this->validateStringField($s, 'behavior', 'destinationHighlightSelector', Craft::t('search-manager', 'Content Selector'), 255);
        $this->validateQueryParamName($s);
        $this->validateCssSelector('settings.behavior.destinationHighlightSelector', (string)($s['behavior']['destinationHighlightSelector'] ?? ''));

        // Trigger settings
        $this->validateStringField($s, 'trigger', 'triggerText', Craft::t('search-manager', 'Trigger Text'), 255);
        $this->validateBooleanField($s, 'trigger', 'showTrigger', Craft::t('search-manager', 'Show Trigger Button'));

        // Analytics settings
        $this->validateIntField($s, 'analytics', 'idleTimeout', Craft::t('search-manager', 'Idle Timeout'), 0, 10000);
        $this->validateStringField($s, 'analytics', 'source', Craft::t('search-manager', 'Source Identifier'), 64);
        $this->validateSourceIdentifier($s);
    }

    /**
     * Validate the widget's selected API key is usable for rendering.
     */
    private function validateApiKeySelection(array $settings): ?ApiKey
    {
        $raw = $settings['apiKeyHandle'] ?? null;
        if ($raw === null || $raw === '') {
            $idFallback = $settings['apiKeyId'] ?? null;
            if ($idFallback === null || $idFallback === '') {
                return null;
            }
            $key = is_numeric($idFallback)
                ? SearchManager::$plugin->apiKeys->findWidgetUsablePublicKeyById((int)$idFallback)
                : null;
            if ($key === null) {
                $this->addError('settings.apiKeyHandle', Craft::t('search-manager', 'Select a valid widget API key.'));
            }
            return $key;
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
        $value = trim((string)($settings['behavior']['queryParamName'] ?? ''));
        if ($value === '') {
            return;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $value) !== 1) {
            $this->addError(
                'settings.behavior.queryParamName',
                Craft::t('search-manager', 'Query Parameter Name must start with a letter and contain only letters, numbers, hyphens, and underscores.')
            );
        }
    }

    /**
     * Validate analytics source identifier format.
     */
    private function validateSourceIdentifier(array $settings): void
    {
        $value = trim((string)($settings['analytics']['source'] ?? ''));
        if ($value === '') {
            return;
        }

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $value) !== 1) {
            $this->addError(
                'settings.analytics.source',
                Craft::t('search-manager', 'Source Identifier must start with a lowercase letter and contain only lowercase letters, numbers, hyphens, and underscores.')
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
