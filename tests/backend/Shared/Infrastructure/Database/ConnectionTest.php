<?php

/**
 * Unit tests for Connection helper methods.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Shared\Infrastructure\Database
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for Connection static helper methods.
 *
 * @since  3.0.0
 */
#[CoversClass(Connection::class)]
class ConnectionTest extends TestCase
{
    // =========================================================================
    // buildPreparedInClause
    // =========================================================================

    #[Test]
    public function buildPreparedInClauseWithEmptyArrayReturnsNull(): void
    {
        $bindings = [];
        $result = Connection::buildPreparedInClause([], $bindings);

        $this->assertSame('(NULL)', $result);
        $this->assertSame([], $bindings);
    }

    #[Test]
    public function buildPreparedInClauseWithSingleId(): void
    {
        $bindings = [];
        $result = Connection::buildPreparedInClause([42], $bindings);

        $this->assertSame('(?)', $result);
        $this->assertSame([42], $bindings);
    }

    #[Test]
    public function buildPreparedInClauseWithMultipleIds(): void
    {
        $bindings = [];
        $result = Connection::buildPreparedInClause([1, 2, 3], $bindings);

        $this->assertSame('(?,?,?)', $result);
        $this->assertSame([1, 2, 3], $bindings);
    }

    #[Test]
    public function buildPreparedInClauseAppendsToExistingBindings(): void
    {
        $bindings = ['existing_value'];
        $result = Connection::buildPreparedInClause([10, 20], $bindings);

        $this->assertSame('(?,?)', $result);
        $this->assertSame(['existing_value', 10, 20], $bindings);
    }

    #[Test]
    public function buildPreparedInClauseCastsToInt(): void
    {
        $bindings = [];
        /** @psalm-suppress InvalidArgument - Testing runtime cast behavior */
        $result = Connection::buildPreparedInClause([1, 2, 3], $bindings);

        $this->assertSame('(?,?,?)', $result);
        foreach ($bindings as $val) {
            $this->assertIsInt($val);
        }
    }

    #[Test]
    public function buildPreparedInClauseWithLargeArray(): void
    {
        $ids = range(1, 100);
        $bindings = [];
        $result = Connection::buildPreparedInClause($ids, $bindings);

        $expectedPlaceholders = '(' . implode(',', array_fill(0, 100, '?')) . ')';
        $this->assertSame($expectedPlaceholders, $result);
        $this->assertCount(100, $bindings);
    }

    #[Test]
    public function buildPreparedInClauseDoesNotMutateInput(): void
    {
        $ids = [5, 10, 15];
        $bindings = [];
        Connection::buildPreparedInClause($ids, $bindings);

        $this->assertSame([5, 10, 15], $ids);
    }

    #[Test]
    public function buildPreparedInClauseWithDuplicateIds(): void
    {
        $bindings = [];
        $result = Connection::buildPreparedInClause([1, 1, 2], $bindings);

        $this->assertSame('(?,?,?)', $result);
        $this->assertSame([1, 1, 2], $bindings);
    }

    // =========================================================================
    // buildIntInClause (existing method, add coverage)
    // =========================================================================

    #[Test]
    public function buildIntInClauseWithEmptyArray(): void
    {
        $this->assertSame('()', Connection::buildIntInClause([]));
    }

    #[Test]
    public function buildIntInClauseWithSingleId(): void
    {
        $this->assertSame('(42)', Connection::buildIntInClause([42]));
    }

    #[Test]
    public function buildIntInClauseWithMultipleIds(): void
    {
        $this->assertSame('(1,2,3)', Connection::buildIntInClause([1, 2, 3]));
    }
}
