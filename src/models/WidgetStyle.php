<?php

namespace lindemannrock\searchmanager\models;

use craft\base\Model;
use craft\helpers\Json;
use lindemannrock\searchmanager\traits\ConfigSourceTrait;

/**
 * WidgetStyle model
 *
 * Stores reusable appearance presets for widgets.
 *
 * @since 5.39.0
 */
class WidgetStyle extends Model
{
    use ConfigSourceTrait;

    /** @since 5.39.0 */
    public const TYPE_MODAL = 'modal';

    /** @since 5.39.0 */
    public const TYPE_PAGE = 'page';

    /** @since 5.39.0 */
    public const TYPE_INLINE = 'inline';

    /**
     * @since 5.39.0
     */
    public const WIDGET_TYPES = [
        self::TYPE_MODAL,
        self::TYPE_PAGE,
        self::TYPE_INLINE,
    ];

    /**
     * @since 5.39.0
     */
    public const WIDGET_TYPE_LABELS = [
        self::TYPE_MODAL => 'Modal',
        self::TYPE_PAGE => 'Search Page',
        self::TYPE_INLINE => 'Inline Search',
    ];

    /** @since 5.39.0 */
    public ?int $id = null;

    /** @since 5.39.0 */
    public string $handle = '';

    /** @since 5.39.0 */
    public string $name = '';

    /** @since 5.39.0 */
    public string $type = 'modal';

    /** @since 5.39.0 */
    public bool $enabled = true;

    /**
     * @var array|string|null Styles stored as JSON in database
     * @since 5.39.0
     */
    public array|string|null $styles = null;

    /** @since 5.39.0 */
    public ?\DateTime $dateCreated = null;

    /** @since 5.39.0 */
    public ?\DateTime $dateUpdated = null;

    /** @since 5.39.0 */
    public ?string $uid = null;

    /** @inheritdoc */
    public function rules(): array
    {
        return [
            [['handle', 'name'], 'required'],
            [['handle'], 'string', 'max' => 64],
            [['name'], 'string', 'max' => 255],
            [['type'], 'in', 'range' => self::WIDGET_TYPES],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.'],
            [['enabled'], 'boolean'],
            [['styles'], 'validateStyles'],
        ];
    }

    /**
     * Validate style dimension values
     *
     * @since 5.39.0
     */
    public function validateStyles(): void
    {
        $s = $this->getStyles();

        // Modal dimensions
        $this->validateStyleInt($s, 'modalMaxWidth', 300, 1200, 'Modal Max Width');
        $this->validateStyleInt($s, 'modalMaxHeight', 30, 95, 'Modal Max Height');
        $this->validateStyleInt($s, 'modalBorderRadius', 0, 50, 'Modal Border Radius');
        $this->validateStyleInt($s, 'modalBorderWidth', 0, 10, 'Modal Border Width');
        $this->validateStyleInt($s, 'modalPaddingX', 0, 64, 'Modal Padding X');
        $this->validateStyleInt($s, 'modalPaddingY', 0, 64, 'Modal Padding Y');

        // Backdrop
        $this->validateStyleInt($s, 'backdropOpacity', 0, 100, 'Backdrop Opacity');

        // Header
        $this->validateStyleInt($s, 'headerBorderRadius', 0, 20, 'Header Border Radius');
        $this->validateStyleInt($s, 'headerBorderWidth', 0, 10, 'Header Border Width');
        $this->validateStyleInt($s, 'headerPaddingX', 0, 40, 'Header Padding X');
        $this->validateStyleInt($s, 'headerPaddingY', 0, 40, 'Header Padding Y');

        // Input
        $this->validateStyleInt($s, 'inputFontSize', 12, 24, 'Input Font Size');
        $this->validateStyleInt($s, 'inputBorderRadius', 0, 20, 'Input Border Radius');
        $this->validateStyleInt($s, 'inputBorderWidth', 0, 10, 'Input Border Width');
        $this->validateStyleInt($s, 'inputPaddingX', 0, 40, 'Input Padding X');
        $this->validateStyleInt($s, 'inputPaddingY', 0, 40, 'Input Padding Y');

        // Results
        $this->validateStyleInt($s, 'resultGap', 0, 20, 'Result Gap');
        $this->validateStyleInt($s, 'resultBorderRadius', 0, 20, 'Result Border Radius');
        $this->validateStyleInt($s, 'resultBorderWidth', 0, 10, 'Result Border Width');
        $this->validateStyleInt($s, 'resultPaddingX', 0, 32, 'Result Padding X');
        $this->validateStyleInt($s, 'resultPaddingY', 0, 32, 'Result Padding Y');

        // Trigger
        $this->validateStyleInt($s, 'triggerBorderRadius', 0, 20, 'Trigger Border Radius');
        $this->validateStyleInt($s, 'triggerBorderWidth', 0, 5, 'Trigger Border Width');
        $this->validateStyleInt($s, 'triggerPaddingX', 0, 40, 'Trigger Padding X');
        $this->validateStyleInt($s, 'triggerPaddingY', 0, 40, 'Trigger Padding Y');
        $this->validateStyleInt($s, 'triggerFontSize', 10, 24, 'Trigger Font Size');

        // Keyboard badge
        $this->validateStyleInt($s, 'kbdBorderRadius', 0, 20, 'Keyboard Badge Border Radius');
    }

    /**
     * Validate an integer style value is within range
     */
    private function validateStyleInt(array $styles, string $key, int $min, int $max, string $label): void
    {
        if (!isset($styles[$key]) || $styles[$key] === '') {
            return;
        }

        $value = (int) $styles[$key];
        if ($value < $min || $value > $max) {
            $this->addError("styles.{$key}", "{$label} must be between {$min} and {$max}.");
        }
    }

    /** @since 5.39.0 */
    public function getStyles(): array
    {
        if (is_string($this->styles)) {
            $decoded = Json::decodeIfJson($this->styles) ?: [];
        } else {
            $decoded = is_array($this->styles) ? $this->styles : [];
        }

        // Normalize hex color values — Craft's colorField strips '#' on save
        // Only apply to color keys (containing 'Bg' or 'Color') to avoid
        // corrupting numeric dimension values like '640' that match hex patterns
        foreach ($decoded as $key => $value) {
            if (is_string($value) && (str_contains($key, 'Bg') || str_contains($key, 'Color'))
                && preg_match('/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value)) {
                $decoded[$key] = '#' . $value;
            }
        }

        return $decoded;
    }

    /**
     * Get raw config display for tooltip on index page
     *
     * @since 5.39.0
     */
    public function getRawConfigDisplay(): string
    {
        if (!$this->isFromConfig()) {
            return '';
        }

        $config = [
            'name' => $this->name,
            'type' => $this->type,
            'enabled' => $this->enabled,
        ];

        $styles = $this->getStyles();
        if (!empty($styles)) {
            $count = count($styles);
            $config['styles'] = "[{$count} style properties]";
        }

        return $this->formatConfigDisplay($config, $this->handle, []);
    }

    /** @since 5.39.0 */
    public function prepareForDb(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'type' => $this->type,
            'styles' => is_array($this->styles) ? Json::encode($this->styles) : $this->styles,
            'enabled' => $this->enabled ? 1 : 0,
        ];
    }
}
