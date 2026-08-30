<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\PreparedStatement;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;

/**
 * Tests for PreparedStatement class and prepared statement functionality.
 */
#[Group('integration')]
class PreparedStatementTest extends TestCase
{
    private static bool $dbConnected = false;

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        $testDbname = "test_" . $config['dbname'];

        if (!Globals::getDbConnection()) {
            try {
                $connection = Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    $testDbname,
                    $config['socket'] ?? ''
                );
                Globals::setDbConnection($connection);
                self::$dbConnected = true;
            } catch (\Exception $e) {
                self::$dbConnected = false;
            }
        } else {
            self::$dbConnected = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection not available');
        }
    }

    public function testPrepareCreatesStatement(): void
    {
        $table = 'languages';
        $stmt = Connection::prepare(
            "SELECT LgID FROM {$table} WHERE LgID = ?"
        );

        $this->assertInstanceOf(PreparedStatement::class, $stmt);
    }

    public function testBindAndFetchOne(): void
    {
        $table = 'languages';

        // First, get an existing language ID
        $row = Connection::fetchOne(
            "SELECT LgID FROM {$table} LIMIT 1"
        );

        if ($row === null) {
            $this->markTestSkipped('No languages in database');
        }

        $langId = (int) $row['LgID'];

        // Test prepared statement fetch
        $result = Connection::preparedFetchOne(
            "SELECT LgID, LgName FROM {$table} WHERE LgID = ?",
            [$langId]
        );

        $this->assertNotNull($result);
        $this->assertEquals($langId, (int) $result['LgID']);
    }

    public function testPreparedFetchAll(): void
    {
        $table = 'languages';
        $results = Connection::preparedFetchAll(
            "SELECT LgID FROM {$table} WHERE LgID > ?",
            [0]
        );

        $this->assertIsArray($results);
    }

    public function testPreparedFetchValue(): void
    {
        $table = 'languages';
        $count = Connection::preparedFetchValue(
            "SELECT COUNT(*) AS value FROM {$table} WHERE LgID > ?",
            [0]
        );

        $this->assertIsNumeric($count);
    }

    public function testBindWithMultipleTypes(): void
    {
        $table = 'languages';
        // Test binding string and int types
        $stmt = Connection::prepare(
            "SELECT LgID FROM {$table} WHERE LgID = ? AND LgName != ?"
        );

        $stmt->bind('is', 1, 'nonexistent');
        $result = $stmt->fetchAll();

        $this->assertIsArray($result);
    }

    public function testBindValues(): void
    {
        $table = 'languages';
        $stmt = Connection::prepare(
            "SELECT LgID FROM {$table} WHERE LgID = ? AND LgName != ?"
        );

        // bindValues auto-detects types
        $stmt->bindValues([1, 'nonexistent']);
        $result = $stmt->fetchAll();

        $this->assertIsArray($result);
    }

    public function testQueryBuilderPreparedSelect(): void
    {
        $results = QueryBuilder::table('languages')
            ->select(['LgID', 'LgName'])
            ->where('LgID', '>', 0)
            ->limit(5)
            ->getPrepared();

        $this->assertIsArray($results);
    }

    public function testQueryBuilderPreparedFirst(): void
    {
        $result = QueryBuilder::table('languages')
            ->select(['LgID', 'LgName'])
            ->where('LgID', '>', 0)
            ->firstPrepared();

        // Could be null if no languages exist, or an array with expected keys
        if ($result !== null) {
            $this->assertArrayHasKey('LgID', $result);
        } else {
            $this->assertNull($result);
        }
    }

    public function testQueryBuilderPreparedCount(): void
    {
        $count = QueryBuilder::table('languages')
            ->where('LgID', '>', 0)
            ->countPrepared();

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testQueryBuilderPreparedExists(): void
    {
        $exists = QueryBuilder::table('languages')
            ->where('LgID', '>', 0)
            ->existsPrepared();

        $this->assertIsBool($exists);
    }

    public function testQueryBuilderWhereInPrepared(): void
    {
        $results = QueryBuilder::table('languages')
            ->select(['LgID'])
            ->whereIn('LgID', [1, 2, 3])
            ->getPrepared();

        $this->assertIsArray($results);
    }

    public function testPreparedStatementWithNullValue(): void
    {
        $table = 'languages';
        // Test that null values are handled correctly
        $stmt = Connection::prepare(
            "SELECT LgID FROM {$table} WHERE LgID = ? OR ? IS NULL"
        );

        $stmt->bindValues([1, null]);
        $result = $stmt->fetchAll();

        $this->assertIsArray($result);
    }

    public function testPreparedStatementTypeInference(): void
    {
        $table = 'languages';
        $stmt = Connection::prepare(
            "SELECT LgID FROM {$table} WHERE LgID > ?"
        );

        // Test that float is properly handled
        $stmt->bindValues([0.5]);
        $result = $stmt->fetchAll();

        $this->assertIsArray($result);
    }
}
