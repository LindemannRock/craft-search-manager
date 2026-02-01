<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use craft\base\Component;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\traits\DeviceDetectionTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Device Detection Service
 *
 * Uses Matomo DeviceDetector library for accurate device, browser, and OS detection
 */
class DeviceDetectionService extends Component
{
    use LoggingTrait;
    use DeviceDetectionTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Detect device information from user agent
     *
     * @param string|null $userAgent
     * @return array Device information array
     */
    public function detectDevice(?string $userAgent = null): array
    {
        return $this->detectDeviceInfo($userAgent);
    }

    /**
     * Check if device is mobile (phone or tablet)
     *
     * @param array $deviceInfo
     * @return bool
     */
    public function isMobileDevice(array $deviceInfo): bool
    {
        return in_array($deviceInfo['deviceType'] ?? '', ['mobile', 'tablet', 'smartphone', 'phablet']);
    }

    /**
     * Check if device is a tablet
     *
     * @param array $deviceInfo
     * @return bool
     */
    public function isTablet(array $deviceInfo): bool
    {
        return ($deviceInfo['deviceType'] ?? '') === 'tablet';
    }

    /**
     * Check if device is desktop
     *
     * @param array $deviceInfo
     * @return bool
     */
    public function isDesktop(array $deviceInfo): bool
    {
        return ($deviceInfo['deviceType'] ?? 'desktop') === 'desktop';
    }

    /**
     * @inheritdoc
     */
    protected function getDeviceDetectionConfig(): array
    {
        $settings = SearchManager::$plugin->getSettings();

        return [
            'cacheEnabled' => (bool) $settings->cacheDeviceDetection,
            'cacheStorageMethod' => $settings->cacheStorageMethod,
            'cacheDuration' => (int) $settings->deviceDetectionCacheDuration,
            'cachePath' => PluginHelper::getCachePath(SearchManager::$plugin, 'device'),
            'cacheKeyPrefix' => PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'device'),
            'cacheKeySet' => PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'device'),
            'includeLanguage' => true,
            'includePlatform' => false,
        ];
    }
}
