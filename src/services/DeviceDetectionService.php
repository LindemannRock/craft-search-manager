<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use DeviceDetector\DeviceDetector;
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

    /**
     * @var DeviceDetector|null
     */
    private ?DeviceDetector $_detector = null;

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
        $settings = SearchManager::$plugin->getSettings();

        // Try to get from cache if enabled
        if ($settings->cacheDeviceDetection && $userAgent) {
            $cached = $this->_getCachedDeviceInfo($userAgent);
            if ($cached !== null) {
                return $cached;
            }
        }

        $detector = $this->_getDetector();

        if ($userAgent) {
            $detector->setUserAgent($userAgent);
        } else {
            $userAgent = Craft::$app->getRequest()->getUserAgent() ?? '';
            $detector->setUserAgent($userAgent);
        }

        $detector->parse();

        $deviceInfo = [
            'userAgent' => $userAgent,
            'deviceType' => null,
            'deviceBrand' => null,
            'deviceModel' => null,
            'osName' => null,
            'osVersion' => null,
            'browser' => null,
            'browserVersion' => null,
            'browserEngine' => null,
            'clientType' => null,
            'isRobot' => false,
            'isMobileApp' => false,
            'botName' => null,
        ];

        // Check if it's a bot
        if ($detector->isBot()) {
            $deviceInfo['isRobot'] = true;
            $botInfo = $detector->getBot();
            $deviceInfo['botName'] = $botInfo['name'] ?? null;
            return $deviceInfo;
        }

        // Get device type
        $deviceType = $detector->getDeviceName();
        $deviceInfo['deviceType'] = strtolower($deviceType ?: 'desktop');

        // Get brand and model
        $deviceInfo['deviceBrand'] = $detector->getBrandName() ?: null;
        $deviceInfo['deviceModel'] = $detector->getModel() ?: null;

        // Get OS information
        $osInfo = $detector->getOs();
        if ($osInfo) {
            $deviceInfo['osName'] = $osInfo['name'] ?? null;
            $deviceInfo['osVersion'] = $osInfo['version'] ?? null;
        }

        // Get client/browser information
        $clientInfo = $detector->getClient();
        if ($clientInfo) {
            $deviceInfo['clientType'] = $clientInfo['type'] ?? null;
            $deviceInfo['browser'] = $clientInfo['name'] ?? null;
            $deviceInfo['browserVersion'] = $clientInfo['version'] ?? null;
            $deviceInfo['browserEngine'] = $clientInfo['engine'] ?? null;
        }

        // Check if it's a mobile app
        $deviceInfo['isMobileApp'] = $detector->isMobileApp();

        // Get language from browser headers with fallback
        $deviceInfo['language'] = $this->detectLanguage();

        // Cache the result if enabled
        if ($settings->cacheDeviceDetection && $userAgent) {
            $this->_cacheDeviceInfo($userAgent, $deviceInfo, $settings->deviceDetectionCacheDuration);
        }

        return $deviceInfo;
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
     * Get DeviceDetector instance
     *
     * @return DeviceDetector
     */
    private function _getDetector(): DeviceDetector
    {
        if ($this->_detector === null) {
            $this->_detector = new DeviceDetector();
        }

        return $this->_detector;
    }

    /**
     * Get cached device info from file storage
     *
     * @param string $userAgent
     * @return array|null
     */
    private function _getCachedDeviceInfo(string $userAgent): ?array
    {
        $settings = SearchManager::$plugin->getSettings();
        $cacheKey = 'searchmanager:device:' . md5($userAgent);

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cached = Craft::$app->cache->get($cacheKey);
            return $cached !== false ? $cached : null;
        }

        // Use file-based cache (default)
        $cachePath = Craft::$app->path->getRuntimePath() . '/search-manager/cache/device/';
        $cacheFile = $cachePath . md5($userAgent) . '.cache';

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is expired
        $mtime = filemtime($cacheFile);
        if (time() - $mtime > $settings->deviceDetectionCacheDuration) {
            @unlink($cacheFile);
            return null;
        }

        $data = file_get_contents($cacheFile);
        return unserialize($data);
    }

    /**
     * Cache device info to storage (file or Redis)
     *
     * @param string $userAgent
     * @param array $data
     * @param int $duration
     * @return void
     */
    private function _cacheDeviceInfo(string $userAgent, array $data, int $duration): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $cacheKey = 'searchmanager:device:' . md5($userAgent);

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cache = Craft::$app->cache;
            $cache->set($cacheKey, $data, $duration);

            // Track key in set for selective deletion
            if ($cache instanceof \yii\redis\Cache) {
                $redis = $cache->redis;
                $redis->executeCommand('SADD', ['searchmanager-device-keys', $cacheKey]);
            }

            return;
        }

        // Use file-based cache (default)
        $cachePath = Craft::$app->path->getRuntimePath() . '/search-manager/cache/device/';

        // Create directory if it doesn't exist
        if (!is_dir($cachePath)) {
            \craft\helpers\FileHelper::createDirectory($cachePath);
        }

        $cacheFile = $cachePath . md5($userAgent) . '.cache';
        file_put_contents($cacheFile, serialize($data));
    }

    /**
     * Detect language from browser or IP
     *
     * @return string Language code (e.g., 'en', 'ar', 'fr')
     */
    public function detectLanguage(): string
    {
        $settings = SearchManager::$plugin->getSettings();
        $request = Craft::$app->getRequest();
        $detectedLang = null;

        // Always check URL parameter first (highest priority)
        $langParam = $request->getQueryParam('lang') ?? $request->getQueryParam('locale');
        if ($langParam) {
            $detectedLang = substr($langParam, 0, 2);
        }

        // Try to detect from browser
        if (!$detectedLang) {
            $detectedLang = $this->_detectFromBrowser();
        }

        // Default to site language if nothing detected
        if (!$detectedLang) {
            $detectedLang = substr(Craft::$app->language, 0, 2);
        }

        // Validate against site languages
        $supportedLanguages = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $supportedLanguages[] = substr($site->language, 0, 2);
        }
        $supportedLanguages = array_unique($supportedLanguages);

        if (!in_array($detectedLang, $supportedLanguages)) {
            // Default to primary site language
            $detectedLang = substr(Craft::$app->getSites()->getPrimarySite()->language, 0, 2);
        }

        return $detectedLang;
    }

    /**
     * Detect language from browser headers
     *
     * @return string|null Language code or null
     */
    private function _detectFromBrowser(): ?string
    {
        $acceptLanguage = Craft::$app->getRequest()->getHeaders()->get('Accept-Language');
        if ($acceptLanguage) {
            // Parse Accept-Language header
            $languages = [];
            $parts = explode(',', $acceptLanguage);
            foreach ($parts as $part) {
                $lang = explode(';', $part);
                $code = substr(trim($lang[0]), 0, 2);
                $quality = isset($lang[1]) ? (float) str_replace('q=', '', $lang[1]) : 1.0;
                $languages[$code] = $quality;
            }

            // Sort by quality
            arsort($languages);
            return array_key_first($languages);
        }

        return null;
    }
}
