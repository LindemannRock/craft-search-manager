<?php

namespace lindemannrock\searchmanager\models;

use craft\base\Model;
use craft\helpers\Json;

/**
 * WidgetConfig model
 *
 * Stores configuration for search widget instances.
 * Allows multiple named configurations with different appearance/behavior settings.
 */
class WidgetConfig extends Model
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;
    public string $handle = '';
    public string $name = '';
    public bool $isDefault = false;
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
                'groupResults' => true,
                'hotkey' => 'k',
            ],
            'trigger' => [
                'showTrigger' => true,
                'triggerText' => 'Search',
            ],
            'styles' => [
                // Modal
                'modalBg' => '#ffffff',
                'modalBgDark' => '#1f2937',
                'modalBorderRadius' => '12',
                'modalBorderWidth' => '0',
                'modalBorderColor' => '#e5e7eb',
                'modalBorderColorDark' => '#374151',
                'modalShadow' => '0 25px 50px -12px rgba(0,0,0,0.25)',
                'modalMaxWidth' => '640',
                // Input
                'inputBg' => '#ffffff',
                'inputBgDark' => '#1f2937',
                'inputTextColor' => '#111827',
                'inputTextColorDark' => '#f9fafb',
                'inputPlaceholderColor' => '#9ca3af',
                'inputPlaceholderColorDark' => '#6b7280',
                'inputBorderColor' => '#e5e7eb',
                'inputBorderColorDark' => '#374151',
                'inputFontSize' => '16',
                // Results
                'resultBg' => 'transparent',
                'resultBgDark' => 'transparent',
                'resultHoverBg' => '#f3f4f6',
                'resultHoverBgDark' => '#374151',
                'resultActiveBg' => '#e5e7eb',
                'resultActiveBgDark' => '#4b5563',
                'resultTextColor' => '#111827',
                'resultTextColorDark' => '#f9fafb',
                'resultDescColor' => '#6b7280',
                'resultDescColorDark' => '#9ca3af',
                'resultBorderRadius' => '8',
                // Trigger button
                'triggerBg' => '#ffffff',
                'triggerBgDark' => '#374151',
                'triggerTextColor' => '#374151',
                'triggerTextColorDark' => '#d1d5db',
                'triggerBorderRadius' => '8',
                'triggerBorderWidth' => '1',
                'triggerBorderColor' => '#d1d5db',
                'triggerBorderColorDark' => '#4b5563',
                'triggerPaddingX' => '12',
                'triggerPaddingY' => '8',
                'triggerFontSize' => '14',
                // Kbd (keyboard shortcut badge)
                'kbdBg' => '#f3f4f6',
                'kbdBgDark' => '#4b5563',
                'kbdTextColor' => '#6b7280',
                'kbdTextColorDark' => '#9ca3af',
                'kbdBorderRadius' => '4',
            ],
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

    public function isGroupResultsEnabled(): bool
    {
        return (bool) $this->getSetting('behavior.groupResults', true);
    }

    public function getHotkey(): string
    {
        return $this->getSetting('behavior.hotkey', 'k');
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

    // Styles - returns all styles as an array for passing to JS
    public function getStyles(): array
    {
        $defaults = self::defaultSettings()['styles'];
        $styles = $this->getSetting('styles', []);
        return array_merge($defaults, is_array($styles) ? $styles : []);
    }

    /**
     * Get a specific style value
     */
    public function getStyle(string $key, string $default = ''): string
    {
        return (string) $this->getSetting('styles.' . $key, $default);
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
            [['isDefault', 'enabled'], 'boolean'],
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
            'isDefault' => $this->isDefault ? 1 : 0,
            'enabled' => $this->enabled ? 1 : 0,
        ];
    }
}
