<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Stubs;

use craft\db\Command;

/**
 * Execution-boundary fault/barrier hook owned by the pending worker fixtures.
 *
 * @since 5.57.0
 */
final class PendingSyncFaultCommand extends Command
{
    public static ?string $pendingTable = null;

    /** @var \Closure(self): void|null */
    public static ?\Closure $beforeExecute = null;

    /** @inheritdoc */
    public function setSql($sql): static
    {
        parent::setSql($sql);
        if (self::$pendingTable !== null && is_string($sql)) {
            parent::setSql(str_replace('`searchmanager_pending_syncs`', '`' . self::$pendingTable . '`', $this->getSql()));
        }
        return $this;
    }

    /** @inheritdoc */
    protected function internalExecute($rawSql): void
    {
        if (self::$beforeExecute !== null) {
            (self::$beforeExecute)($this);
        }
        parent::internalExecute($rawSql);
    }
}
