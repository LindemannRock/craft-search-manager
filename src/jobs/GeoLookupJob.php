<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Geo Lookup Job
 *
 * Queue job for asynchronously resolving geographic location from IP address.
 * This prevents the geo-lookup API call from blocking search requests.
 */
class GeoLookupJob extends BaseJob
{
    use LoggingTrait;

    /**
     * @var int The analytics record ID to update
     */
    public int $analyticsId;

    /**
     * @var string The IP address to look up (already anonymized if applicable)
     */
    public string $ip;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function execute($queue): void
    {
        // Perform the geo-lookup
        $geoData = SearchManager::$plugin->analytics->getLocationFromIp($this->ip);

        if (!$geoData) {
            $this->logDebug('Geo-lookup returned no data', [
                'analyticsId' => $this->analyticsId,
                'ip' => $this->ip,
            ]);
            return;
        }

        // Update the analytics record with geo data
        try {
            Craft::$app->getDb()->createCommand()
                ->update('{{%searchmanager_analytics}}', [
                    'country' => $geoData['countryCode'] ?? null,
                    'city' => $geoData['city'] ?? null,
                    'region' => $geoData['region'] ?? null,
                    'latitude' => $geoData['lat'] ?? null,
                    'longitude' => $geoData['lon'] ?? null,
                ], ['id' => $this->analyticsId])
                ->execute();

            $this->logDebug('Updated analytics with geo data', [
                'analyticsId' => $this->analyticsId,
                'country' => $geoData['countryCode'] ?? null,
                'city' => $geoData['city'] ?? null,
            ]);
        } catch (\Exception $e) {
            $this->logError('Failed to update analytics with geo data', [
                'analyticsId' => $this->analyticsId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return Craft::t('search-manager', '{pluginName}: Resolving geo-location {id}', [
            'pluginName' => $settings->getDisplayName(),
            'id' => $this->analyticsId,
        ]);
    }
}
