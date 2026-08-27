<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Infrastructure;

use Lwt\Modules\Review\Domain\Scheduling\LegacyStatusSeed;
use Lwt\Modules\Review\Infrastructure\ScheduleSql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the review queue's due-date expression.
 *
 * The expression is only ever executed by MySQL, so what is worth asserting
 * here is that it keeps agreeing with the seed table it mirrors — a term with
 * no schedule row has to fall due exactly when LegacyStatusSeed would have
 * placed it, or the queue changes under people on upgrade.
 */
#[CoversClass(ScheduleSql::class)]
class ScheduleSqlTest extends TestCase
{
    /**
     * The expression asks the schema whether `term_schedule` is there. Force
     * the answer so these stay unit tests and never reach for a connection.
     */
    protected function setUp(): void
    {
        parent::setUp();
        ScheduleSql::setHasScheduleTable(true);
    }

    protected function tearDown(): void
    {
        ScheduleSql::setHasScheduleTable(null);
        parent::tearDown();
    }

    public function testFallsBackToTheStatusSeedForAnUngradedTerm(): void
    {
        $sql = ScheduleSql::effectiveDue();

        $this->assertStringContainsString('WoStatusChanged', $sql);
        $this->assertStringContainsString('term_schedule', $sql);
        $this->assertStringContainsString('COALESCE', $sql);
    }

    public function testEveryScheduledStatusCarriesItsSeedInterval(): void
    {
        $sql = ScheduleSql::effectiveDue();

        foreach (LegacyStatusSeed::stabilityByStatus() as $status => $stability) {
            $this->assertStringContainsString(
                'WHEN ' . $status . ' THEN ' . (int) round($stability),
                $sql,
                "Status $status lost its seed interval"
            );
        }
    }

    public function testUnschedulableStatusesGetNoInterval(): void
    {
        $sql = ScheduleSql::effectiveDue();

        // 98 (ignored) and 99 (well known) were never in the review queue
        $this->assertStringNotContainsString('WHEN 98', $sql);
        $this->assertStringNotContainsString('WHEN 99', $sql);
    }

    public function testDuePredicateComparesAgainstNow(): void
    {
        $this->assertSame(
            ScheduleSql::effectiveDue() . ' <= NOW()',
            ScheduleSql::isDue()
        );
    }

    public function testTomorrowPredicateLooksOneDayAhead(): void
    {
        $this->assertStringContainsString('INTERVAL 1 DAY', ScheduleSql::isDueTomorrow());
        $this->assertStringStartsWith(ScheduleSql::effectiveDue(), ScheduleSql::isDueTomorrow());
    }

    public function testSchemaWithoutTheScheduleTableNeverNamesIt(): void
    {
        // An install where the phase-2a migration did not run (#275). Naming a
        // missing table fails the statement at prepare time, which took the
        // review page and the vocabulary list to a 500 rather than degrading.
        ScheduleSql::setHasScheduleTable(false);

        $sql = ScheduleSql::effectiveDue();

        $this->assertStringNotContainsString('term_schedule', $sql);
        $this->assertStringContainsString('WoStatusChanged', $sql);
    }

    public function testDegradedExpressionKeepsEverySeedInterval(): void
    {
        // Every term on such a schema is ungraded by definition, so the seed
        // expression alone is not an approximation — it is the same answer.
        ScheduleSql::setHasScheduleTable(false);

        $sql = ScheduleSql::effectiveDue();

        foreach (LegacyStatusSeed::stabilityByStatus() as $status => $stability) {
            $this->assertStringContainsString(
                'WHEN ' . $status . ' THEN ' . (int) round($stability),
                $sql,
                "Status $status lost its seed interval without term_schedule"
            );
        }
    }

    public function testDegradedExpressionIsStillSelfContained(): void
    {
        ScheduleSql::setHasScheduleTable(false);

        $sql = ScheduleSql::effectiveDue();

        $this->assertStringStartsWith('(', $sql);
        $this->assertStringEndsWith(')', $sql);
        $this->assertSame(substr_count($sql, '('), substr_count($sql, ')'));
    }

    public function testPredicatesFollowTheDegradedExpression(): void
    {
        ScheduleSql::setHasScheduleTable(false);

        $this->assertSame(ScheduleSql::effectiveDue() . ' <= NOW()', ScheduleSql::isDue());
        $this->assertStringStartsWith(ScheduleSql::effectiveDue(), ScheduleSql::isDueTomorrow());
    }

    public function testExpressionIsSelfContained(): void
    {
        // Wrapped in parentheses so it can be dropped into a WHERE, an ORDER BY
        // or a comparison without changing precedence
        $sql = ScheduleSql::effectiveDue();

        $this->assertStringStartsWith('(', $sql);
        $this->assertStringEndsWith(')', $sql);
        $this->assertSame(
            substr_count($sql, '('),
            substr_count($sql, ')'),
            'Unbalanced parentheses in the due expression'
        );
    }
}
