<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit findings #429 and #431-#437.
 *
 * @since 5.54.0
 */
final class IndexPersistenceContractTest extends TestCase
{
    public function testNullTransformerClassPersistsAsEmptyString(): void
    {
        $handle = 'sm_audit_431_transformer';
        Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $handle])->execute();

        $index = new SearchIndex([
            'name' => 'Audit 431 Transformer',
            'handle' => $handle,
            'elementType' => Entry::class,
            'criteria' => [],
            'transformerClass' => null,
            'enabled' => false,
        ]);

        try {
            self::assertTrue($index->save(), print_r($index->getErrors(), true));
            self::assertSame('', (new Query())
                ->select(['transformerClass'])
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handle])
                ->scalar());
        } finally {
            Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $handle])->execute();
            SearchIndex::clearCache();
        }
    }
}
