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
use lindemannrock\base\helpers\SlugHandleHelper;
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

    private const ALLOWED_HIGHLIGHT_TAGS = ['mark', 'em', 'strong', 'b', 'i', 'span'];

    public const TYPE_MODAL = 'modal';

    public const TYPE_PAGE = 'page';

    public const TYPE_INLINE = 'inline';

    public const WIDGET_TYPES = [
        self::TYPE_MODAL,
        self::TYPE_PAGE,
        self::TYPE_INLINE,
    ];

    public const WIDGET_TYPE_LABELS = [
        self::TYPE_MODAL => 'Modal',
        self::TYPE_PAGE => 'Search Page',
        self::TYPE_INLINE => 'Inline Search',
    ];

    public ?int $id = null;

    public string $handle = '';

    public string $name = '';

    public string $type = 'modal';

    public bool $enabled = true;

    /**
     * @var array|string|null Styles stored as JSON in database
     */
    public array|string|null $styles = null;

    public ?\DateTime $dateCreated = null;

    public ?\DateTime $dateUpdated = null;

    public ?string $uid = null;

    /** @inheritdoc */
    public function rules(): array
    {
        return [
            [['handle', 'name'], 'required'],
            [['handle'], 'string', 'max' => 64],
            [['name'], 'string', 'max' => 255],
            [['type'], 'in', 'range' => self::WIDGET_TYPES],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => Craft::t('search-manager', 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.')],
            [['handle'], 'validateUniqueHandle'],
            [['enabled'], 'boolean'],
            [['styles'], 'validateStyles'],
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
            'styles' => Craft::t('search-manager', 'Styles'),
        ];
    }

    /**
     * Validate handle is unique among database-backed widget styles.
     */
    public function validateUniqueHandle(string $attribute): void
    {
        if (SlugHandleHelper::exists('{{%searchmanager_widget_styles}}', 'handle', $this->handle, [
            'excludeId' => $this->id,
        ])) {
            $this->addError($attribute, Craft::t('search-manager', 'Handle must be unique.'));
        }
    }

    /**
     * Validate style dimension values
     */
    public function validateStyles(): void
    {
        $s = $this->getStyles();

        // Modal dimensions
        $this->validateStyleInt($s, 'modalMaxWidth', 300, 1200, Craft::t('search-manager', 'Modal Max Width'));
        $this->validateStyleInt($s, 'modalMaxHeight', 30, 95, Craft::t('search-manager', 'Modal Max Height'));
        $this->validateStyleInt($s, 'modalBorderRadius', 0, 50, Craft::t('search-manager', 'Modal Border Radius'));
        $this->validateStyleInt($s, 'modalBorderWidth', 0, 10, Craft::t('search-manager', 'Modal Border Width'));
        $this->validateStyleInt($s, 'modalPaddingX', 0, 64, Craft::t('search-manager', 'Modal Padding X'));
        $this->validateStyleInt($s, 'modalPaddingY', 0, 64, Craft::t('search-manager', 'Modal Padding Y'));

        // Backdrop
        $this->validateStyleInt($s, 'backdropOpacity', 0, 100, Craft::t('search-manager', 'Backdrop Opacity'));

        // Header
        $this->validateStyleInt($s, 'headerBorderRadius', 0, 20, Craft::t('search-manager', 'Header Border Radius'));
        $this->validateStyleInt($s, 'headerBorderWidth', 0, 10, Craft::t('search-manager', 'Header Border Width'));
        $this->validateStyleInt($s, 'headerPaddingX', 0, 40, Craft::t('search-manager', 'Header Padding X'));
        $this->validateStyleInt($s, 'headerPaddingY', 0, 40, Craft::t('search-manager', 'Header Padding Y'));

        // Input
        $this->validateStyleInt($s, 'inputFontSize', 12, 24, Craft::t('search-manager', 'Input Font Size'));
        $this->validateStyleInt($s, 'inputBorderRadius', 0, 20, Craft::t('search-manager', 'Input Border Radius'));
        $this->validateStyleInt($s, 'inputBorderWidth', 0, 10, Craft::t('search-manager', 'Input Border Width'));
        $this->validateStyleInt($s, 'inputPaddingX', 0, 40, Craft::t('search-manager', 'Input Padding X'));
        $this->validateStyleInt($s, 'inputPaddingY', 0, 40, Craft::t('search-manager', 'Input Padding Y'));

        // Results
        $this->validateStyleInt($s, 'resultGap', 0, 20, Craft::t('search-manager', 'Result Gap'));
        $this->validateStyleInt($s, 'resultBorderRadius', 0, 20, Craft::t('search-manager', 'Result Border Radius'));
        $this->validateStyleInt($s, 'resultBorderWidth', 0, 10, Craft::t('search-manager', 'Result Border Width'));
        $this->validateStyleInt($s, 'resultPaddingX', 0, 32, Craft::t('search-manager', 'Result Padding X'));
        $this->validateStyleInt($s, 'resultPaddingY', 0, 32, Craft::t('search-manager', 'Result Padding Y'));

        // Trigger
        $this->validateStyleInt($s, 'triggerBorderRadius', 0, 20, Craft::t('search-manager', 'Trigger Border Radius'));
        $this->validateStyleInt($s, 'triggerBorderWidth', 0, 5, Craft::t('search-manager', 'Trigger Border Width'));
        $this->validateStyleInt($s, 'triggerPaddingX', 0, 40, Craft::t('search-manager', 'Trigger Padding X'));
        $this->validateStyleInt($s, 'triggerPaddingY', 0, 40, Craft::t('search-manager', 'Trigger Padding Y'));
        $this->validateStyleInt($s, 'triggerFontSize', 10, 24, Craft::t('search-manager', 'Trigger Font Size'));

        // Keyboard badge
        $this->validateStyleInt($s, 'kbdBorderRadius', 0, 20, Craft::t('search-manager', 'Keyboard Badge Border Radius'));

        $this->validateHighlightTag($s);
        $this->validateHighlightClass($s);
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
            $this->addError("styles.{$key}", Craft::t('search-manager', '{label} must be between {min} and {max}.', [
                'label' => $label,
                'min' => $min,
                'max' => $max,
            ]));
        }
    }

    /**
     * Validate highlight markup tag names against the same safe subset as the PHP highlighter.
     */
    private function validateHighlightTag(array $styles): void
    {
        $value = strtolower(trim((string)($styles['highlightTag'] ?? '')));
        if ($value === '') {
            return;
        }

        if (!in_array($value, self::ALLOWED_HIGHLIGHT_TAGS, true)) {
            $this->addError('styles.highlightTag', Craft::t('search-manager', '{label} must be one of: {values}.', [
                'label' => Craft::t('search-manager', 'HTML Tag'),
                'values' => implode(', ', self::ALLOWED_HIGHLIGHT_TAGS),
            ]));
        }
    }

    /**
     * Validate highlight class lists as plain CSS class tokens.
     */
    private function validateHighlightClass(array $styles): void
    {
        $value = trim((string)($styles['highlightClass'] ?? ''));
        if ($value === '') {
            return;
        }

        if (!$this->isValidClassTokenList($value)) {
            $this->addError('styles.highlightClass', Craft::t('yii', '{attribute} is invalid.', [
                'attribute' => Craft::t('search-manager', 'CSS Class'),
            ]));
        }
    }

    private function isValidClassTokenList(string $value): bool
    {
        foreach (preg_split('/\s+/', $value) ?: [] as $token) {
            if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

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
