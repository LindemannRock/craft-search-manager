<?php

namespace lindemannrock\searchmanager\traits;

/**
 * Config Source Trait
 *
 * Provides common functionality for models that can be sourced from
 * either the config file or the database.
 *
 * Used by: ConfiguredBackend, SearchIndex, WidgetConfig
 *
 * @since 5.0.0
 */
trait ConfigSourceTrait
{
    /**
     * @var string Source of this record ('config' or 'database')
     */
    public string $source = 'database';

    /**
     * Check if this record can be edited (only database records can be edited)
     *
     * @since 5.0.0
     */
    public function canEdit(): bool
    {
        return $this->source !== 'config';
    }

    /**
     * Check if this record is from the config file
     *
     * @since 5.0.0
     */
    public function isFromConfig(): bool
    {
        return $this->source === 'config';
    }

    /**
     * Check if this record is from the database
     *
     * @since 5.0.0
     */
    public function isFromDatabase(): bool
    {
        return $this->source === 'database';
    }

    /**
     * Get raw config display for showing in tooltip (config records only)
     *
     * Override this method in the model to customize the output.
     *
     * @param array $config The config array to format
     * @param string $handle The handle for this record
     * @param array $sensitiveKeys Keys to mask (e.g., ['apiKey', 'password'])
     * @return string Formatted PHP-like config display
     */
    protected function formatConfigDisplay(array $config, string $handle, array $sensitiveKeys = []): string
    {
        // Mask sensitive values
        $maskedConfig = $this->maskSensitiveValues($config, $sensitiveKeys);

        $lines = ["'{$handle}' => ["];
        foreach ($maskedConfig as $key => $value) {
            if (is_array($value)) {
                $lines[] = "    '{$key}' => [";
                foreach ($value as $k => $v) {
                    $lines[] = "        '{$k}' => " . var_export($v, true) . ",";
                }
                $lines[] = "    ],";
            } elseif (is_bool($value)) {
                $lines[] = "    '{$key}' => " . ($value ? 'true' : 'false') . ",";
            } else {
                $lines[] = "    '{$key}' => " . var_export($value, true) . ",";
            }
        }
        $lines[] = "],";

        return implode("\n", $lines);
    }

    /**
     * Mask sensitive values in a config array
     *
     * @param array $config The config array
     * @param array $sensitiveKeys Keys to mask
     * @return array Config with sensitive values masked
     */
    protected function maskSensitiveValues(array $config, array $sensitiveKeys): array
    {
        $masked = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveValues($value, $sensitiveKeys);
            } elseif (in_array($key, $sensitiveKeys, true) && !empty($value)) {
                $masked[$key] = '********';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
