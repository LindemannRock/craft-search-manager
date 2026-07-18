<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\testing\SqlDialectLinter;
use lindemannrock\searchmanager\services\analytics\AnalyticsQueryTrait;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins PostgreSQL dialect safety for hand-written SQL.
 *
 * CI runs on MySQL, which is case-insensitive for identifiers and never
 * surfaces these bugs. PostgreSQL folds unquoted identifiers to lowercase and
 * rejects bare column references inside ON CONFLICT DO UPDATE expressions, so
 * these tests assert the source shape (via the shared SqlDialectLinter and
 * call-site pins) instead of needing a live PostgreSQL install.
 */
final class PostgresDialectSafetyTest extends TestCase
{
    public function testActionIdentityExpressionBracketsSessionId(): void
    {
        $subject = new class {
            use AnalyticsQueryTrait;
        };

        $expr = $subject->actionIdentityExpression();

        self::assertStringContainsString('[[sessionId]]', $expr);
        self::assertStringNotContainsString('COALESCE(sessionId', $expr);
    }

    /**
     * Boolean columns of the analytics tables. PostgreSQL has no MAX()/MIN()
     * over boolean, so aggregating one must go through DbHelper::boolToInt() —
     * the linter needs the names since column types aren't visible in source.
     * Keep in sync with Install.php ($this->boolean() columns used in analytics
     * aggregates).
     */
    private const BOOLEAN_ANALYTICS_COLUMNS = [
        'isHit',
        'wasRedirected',
        'isRobot',
        'isSystemAgent',
        'synonymsExpanded',
    ];

    /**
     * Every camelCase column or alias inside a raw SQL string literal must be
     * wrapped in [[...]], and boolean columns must not be aggregated bare.
     * MySqlStorage is excluded — its raw SQL intentionally targets MySQL and
     * never runs on PostgreSQL.
     */
    public function testRawSqlLiteralsBracketCamelCaseColumnsAndAliases(): void
    {
        $violations = SqlDialectLinter::scanDirectory(
            dirname(__DIR__, 2) . '/src',
            ['src/search/storage/MySqlStorage.php'],
            self::BOOLEAN_ANALYTICS_COLUMNS,
        );

        self::assertSame([], $violations, "PostgreSQL-unsafe raw SQL found:\n" . implode("\n", $violations));
    }

    /**
     * Pins the Pattern-C fix: boolean flags are CASE-projected to 0/1 before
     * aggregation, so the zero-outcome HAVING works on PostgreSQL.
     */
    public function testZeroOutcomeHavingProjectsBooleansToInt(): void
    {
        $subject = new class {
            use AnalyticsQueryTrait;

            public function exposeZeroOutcomeHaving(): string
            {
                return $this->zeroOutcomeHaving();
            }
        };

        $having = $subject->exposeZeroOutcomeHaving();

        self::assertStringContainsString('MAX(CASE WHEN [[isHit]] THEN 1 ELSE 0 END) = 0', $having);
        self::assertStringContainsString('MAX(CASE WHEN [[wasRedirected]] THEN 1 ELSE 0 END) = 0', $having);
        self::assertStringContainsString('MAX([[promotionsShown]]) = 0', $having);
        self::assertStringNotContainsString('MAX([[isHit]])', $having);
    }

    /**
     * Upserts with Expression update values must reference existing-row
     * columns via DbHelper::existingColumn() — a bare [[column]] there is
     * ambiguous on PostgreSQL (SQLSTATE 42702, target row vs EXCLUDED).
     */
    public function testSearchTermsFrequencyUpsertQualifiesExistingRowColumn(): void
    {
        $source = $this->readPluginFile('src/search/storage/PostgreSqlStorage.php');

        self::assertStringContainsString(
            "DbHelper::existingColumn('searchmanager_search_terms', 'frequency')",
            $source
        );
        self::assertStringNotContainsString('GREATEST([[frequency]]', $source);
    }

    public function testPendingSyncUpsertQualifiesExistingRowColumns(): void
    {
        $source = $this->readPluginFile('src/services/sync/PendingSyncRepository.php');

        self::assertStringContainsString(
            "DbHelper::existingColumn('searchmanager_pending_syncs', \$column)",
            $source
        );

        foreach (['status', 'attemptCount', 'nextAttemptAt', 'claimedAt', 'claimToken', 'lastError', 'lastProcessedAt'] as $column) {
            self::assertStringNotContainsString("WHEN [[{$column}]]", $source);
            self::assertStringNotContainsString("THEN [[{$column}]]", $source);
        }
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
