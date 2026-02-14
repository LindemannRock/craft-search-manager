<?php

namespace lindemannrock\searchmanager\models;

use craft\base\Model;
use craft\helpers\Json;
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
     * @since 5.30.0
     */
    public static function defaultSettings(): array
    {
        return [
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
                'allowCodeSnippets' => false,
                'snippetMode' => 'balanced',
                'resultTitleLines' => 1,
                'resultDescLines' => 1,
                'snippetLength' => 150,
                'parseMarkdownSnippets' => false,
                'showLoadingIndicator' => true,
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
     * @since 5.30.0
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
     * @since 5.30.0
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
     * @since 5.30.0
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
     * @since 5.30.0
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
     * @since 5.30.0
     */
    public function getIndexHandle(): string
    {
        $handles = $this->getIndexHandles();
        return $handles[0] ?? '';
    }

    // Behavior
    public function isPreventBodyScrollEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.preventBodyScroll', true);
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
        return (bool) $this->getSetting('behavior.showRecent', true);
    }

    public function getMaxRecentSearches(): int
    {
        return (int) $this->getSetting('behavior.maxRecentSearches', 5);
    }

    public function isGroupResultsEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.groupResults', true);
    }

    public function getHotkey(): string
    {
        return $this->getSetting('behavior.hotkey', 'k');
    }

    public function isHideResultsWithoutUrlEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.hideResultsWithoutUrl', false);
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
     * Field to group hierarchical results by (e.g., 'section', 'category')
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
    public function isAllowCodeSnippetsEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.allowCodeSnippets', false);
    }

    /**
     * Snippet mode: early | balanced | deep
     *
     * @since 5.39.0
     */
    public function getSnippetMode(): string
    {
        $mode = (string) $this->getSetting('behavior.snippetMode', 'balanced');
        $mode = strtolower(trim($mode));
        return in_array($mode, ['early', 'balanced', 'deep'], true) ? $mode : 'balanced';
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
        return (int) $this->getSetting('behavior.snippetLength', 150);
    }

    /**
     * Parse markdown before building snippets
     *
     * @since 5.39.0
     */
    public function isParseMarkdownSnippetsEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.parseMarkdownSnippets', false);
    }

    public function isShowLoadingIndicatorEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.showLoadingIndicator', true);
    }

    // Trigger
    public function isShowTriggerEnabled(): bool
    {
        return (bool) $this->getSetting('trigger.showTrigger', true);
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
     * @since 5.30.0
     */
    public function getStylesForPreview(): array
    {
        return array_merge(self::defaultStyleValues(), $this->getStyles());
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
     * @since 5.30.0
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
     * @since 5.30.0
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

    public function rules(): array
    {
        return [
            [['handle', 'name'], 'required'],
            [['handle'], 'string', 'max' => 64],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.'],
            [['type'], 'in', 'range' => WidgetStyle::WIDGET_TYPES],
            [['enabled'], 'boolean'],
            [['settings'], 'validateSettings'],
        ];
    }

    /**
     * Validate nested settings values
     *
     * @since 5.39.0
     */
    public function validateSettings(): void
    {
        $s = $this->getSettingsArray();

        // Search settings
        $this->validateStringField($s, 'search', 'placeholder', 'Placeholder', 255);

        // Behavior settings — integers with ranges
        $this->validateIntField($s, 'behavior', 'debounce', 'Debounce', 0, 2000);
        $this->validateIntField($s, 'behavior', 'minChars', 'Minimum Characters', 1, 10);
        $this->validateIntField($s, 'behavior', 'maxResults', 'Maximum Results', 1, 100);
        $this->validateIntField($s, 'behavior', 'maxHeadingsPerResult', 'Max Headings per Result', 1, 50);
        $this->validateIntField($s, 'behavior', 'maxRecentSearches', 'Max Recent Searches', 1, 50);
        $this->validateIntField($s, 'behavior', 'resultTitleLines', 'Result Title Lines', 1, 5);
        $this->validateIntField($s, 'behavior', 'resultDescLines', 'Result Description Lines', 1, 5);
        $this->validateIntField($s, 'behavior', 'snippetLength', 'Snippet Length', 50, 500);

        // Behavior settings — enums
        $this->validateEnumField($s, 'behavior', 'resultLayout', 'Result Layout', ['default', 'hierarchical']);
        $this->validateEnumField($s, 'behavior', 'hierarchyDisplay', 'Hierarchy Display', ['individual', 'unified']);
        $this->validateEnumField($s, 'behavior', 'snippetMode', 'Snippet Mode', ['early', 'balanced', 'deep']);

        // Behavior settings — strings
        $this->validateStringField($s, 'behavior', 'hotkey', 'Hotkey', 1);
        $this->validateStringField($s, 'behavior', 'hierarchyGroupBy', 'Group By Field', 64);

        // Trigger settings
        $this->validateStringField($s, 'trigger', 'triggerText', 'Trigger Text', 255);

        // Analytics settings
        $this->validateIntField($s, 'analytics', 'idleTimeout', 'Idle Timeout', 0, 10000);
        $this->validateStringField($s, 'analytics', 'source', 'Source Identifier', 64);
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
        $intVal = (int) $value;
        if ($intVal < $min || $intVal > $max) {
            $this->addError("settings.{$group}.{$key}", "{$label} must be between {$min} and {$max}.");
        }
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
            $this->addError("settings.{$group}.{$key}", "{$label} must be {$maxLength} characters or fewer.");
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
            $this->addError("settings.{$group}.{$key}", "{$label} must be one of: " . implode(', ', $allowed) . '.');
        }
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Prepare for database save
     *
     * @return array
     * @since 5.30.0
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
