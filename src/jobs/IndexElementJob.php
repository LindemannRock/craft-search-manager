<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Index Element Job
 *
 * Queue job for indexing a single element
 *
 * @since 5.0.0
 */
class IndexElementJob extends BaseJob
{
    use LoggingTrait;

    public int $elementId;
    public string $elementType;
    public ?int $siteId = null;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function execute($queue): void
    {
        // Get element
        $element = Craft::$app->elements->getElementById(
            $this->elementId,
            $this->elementType,
            $this->siteId
        );

        if (!$element) {
            $this->logWarning('Element not found for indexing', [
                'elementId' => $this->elementId,
                'elementType' => $this->elementType,
            ]);
            return;
        }

        // Index element (skip queue since we're already in a job)
        SearchManager::$plugin->indexing->indexElementNow($element);
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return Craft::t('search-manager', '{pluginName}: Indexing element {id}', [
            'pluginName' => $settings->getDisplayName(),
            'id' => $this->elementId,
        ]);
    }
}
