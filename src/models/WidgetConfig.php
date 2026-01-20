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
    public bool $enabled = true;

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
     */
    public static function defaultSettings(): array
    {
        return [
            'search' => [
                'indexHandles' => [], // Empty = search all indices
            ],
            'highlighting' => [
                'enabled' => true,
                'tag' => 'mark',
                'class' => null,
                'bgLight' => '#fef08a',
                'colorLight' => '#854d0e',
                'bgDark' => '#854d0e',
                'colorDark' => '#fef08a',
            ],
            'backdrop' => [
                'opacity' => 50,
                'blur' => true,
            ],
            'behavior' => [
                'preventBodyScroll' => true,
                'debounce' => 200,
                'minChars' => 2,
                'maxResults' => 10,
                'showRecent' => true,
                'maxRecentSearches' => 5,
                'groupResults' => true,
                'hotkey' => 'k',
                'hideResultsWithoutUrl' => false,
                'showLoadingIndicator' => true,
            ],
            'trigger' => [
                'showTrigger' => true,
                'triggerText' => 'Search',
            ],
            // Note: 'styles' defaults are handled by JavaScript (StyleConfig.js)
            // PHP only passes explicitly configured styles to avoid duplication
            'styles' => [],
        ];
    }

    // =========================================================================
    // SETTINGS ACCESSORS
    // =========================================================================

    /**
     * Get settings as array (parses JSON if needed)
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
     * @param string $key Dot notation key (e.g., 'highlighting.bgLight')
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
     * @deprecated Use getIndexHandles() instead
     */
    public function getIndexHandle(): string
    {
        $handles = $this->getIndexHandles();
        return $handles[0] ?? '';
    }

    // Highlighting
    public function isHighlightingEnabled(): bool
    {
        return (bool) $this->getSetting('highlighting.enabled', true);
    }

    public function getHighlightTag(): string
    {
        return $this->getSetting('highlighting.tag', 'mark');
    }

    public function getHighlightClass(): ?string
    {
        return $this->getSetting('highlighting.class');
    }

    public function getHighlightBgLight(): string
    {
        return $this->getSetting('highlighting.bgLight', '#fef08a');
    }

    public function getHighlightColorLight(): string
    {
        return $this->getSetting('highlighting.colorLight', '#854d0e');
    }

    public function getHighlightBgDark(): string
    {
        return $this->getSetting('highlighting.bgDark', '#854d0e');
    }

    public function getHighlightColorDark(): string
    {
        return $this->getSetting('highlighting.colorDark', '#fef08a');
    }

    // Backdrop
    public function getBackdropOpacity(): int
    {
        return (int) $this->getSetting('backdrop.opacity', 50);
    }

    public function isBackdropBlurEnabled(): bool
    {
        return (bool) $this->getSetting('backdrop.blur', true);
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

    // Styles - returns only explicitly configured styles (JS handles defaults)
    public function getStyles(): array
    {
        $styles = $this->getSetting('styles', []);
        return is_array($styles) ? $styles : [];
    }

    /**
     * Get styles with defaults for CP preview
     * Merges configured styles with defaults so preview renders correctly
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

    /**
     * Get a specific style value
     */
    public function getStyle(string $key, string $default = ''): string
    {
        return (string) $this->getSetting('styles.' . $key, $default);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get raw config display for showing in tooltip (config widgets only)
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

        // Indicate styles exist without showing all values
        if (!empty($settings['styles'])) {
            $config['styles'] = '[ ... ' . count($settings['styles']) . ' properties ]';
        }

        // Indicate highlighting exists without showing all values
        if (!empty($settings['highlighting'])) {
            $config['highlighting'] = '[ ... ]';
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
            [['enabled'], 'boolean'],
        ];
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    /**
     * Prepare for database save
     */
    public function prepareForDb(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'settings' => is_array($this->settings) ? Json::encode($this->settings) : $this->settings,
            'enabled' => $this->enabled ? 1 : 0,
        ];
    }
}
