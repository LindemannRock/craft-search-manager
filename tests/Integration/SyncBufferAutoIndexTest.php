<?php

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\events\ElementEvent;
use craft\services\Elements;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Event;

/**
 * Verifies the auto-indexing wiring at the Craft event-system level:
 *
 *   - The `autoIndex` setting (true in the test install) attaches class-level
 *     listeners on `Elements::EVENT_AFTER_SAVE_ELEMENT` and
 *     `Elements::EVENT_AFTER_DELETE_ELEMENT`.
 *   - Saving an element via the normal Craft API queues a pending-sync row
 *     for every matching (index, site) pair — no manual `queueForElement()`
 *     call required.
 *
 * The `autoIndex = false` branch isn't covered here: that branch *skips
 * listener install* during the plugin's `init()`, which runs once at
 * bootstrap. Toggling the setting mid-test does not detach already-attached
 * listeners. Coverage for that branch needs a separate test process with a
 * pre-bootstrap settings override, not a per-test toggle.
 *
 * @since 5.45.0
 */
final class SyncBufferAutoIndexTest extends TestCase
{
    public function testAutoIndexAttachesSaveAndDeleteListeners(): void
    {
        $this->assertTrue(
            SearchManager::$plugin->getSettings()->autoIndex,
            'Test install must have autoIndex enabled — that is the wiring this test verifies.',
        );

        $this->assertTrue(
            Event::hasHandlers(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT),
            'autoIndex must attach a class-level handler on Elements::EVENT_AFTER_SAVE_ELEMENT.',
        );
        $this->assertTrue(
            Event::hasHandlers(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT),
            'autoIndex must attach a class-level handler on Elements::EVENT_AFTER_DELETE_ELEMENT.',
        );
    }

    public function testElementSaveEventQueuesARow(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;

        $this->assertSame(
            0,
            $this->countPendingRows([
                'indexHandle' => $index->handle,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ]),
            'Buffer must be empty before the save event fires (setUp truncates).',
        );

        // Fire the listener directly. We are asserting that the autoIndex
        // wiring → queueForElement → buffer pipeline is intact. Going through
        // `Craft::$app->getElements()->saveElement()` adds field-validation
        // work (Link field touches $request->getIsPost() which doesn't exist
        // on console requests) that is unrelated to what this test verifies.
        Event::trigger(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            new ElementEvent(['element' => $element]),
        );

        $this->assertGreaterThanOrEqual(
            1,
            $this->countPendingRows([
                'indexHandle' => $index->handle,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ]),
            'EVENT_AFTER_SAVE_ELEMENT listener must queue a row for the saved (element, site).',
        );
    }
}
