<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Exception\DatabaseException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database\Connection class.
 *
 * Tests database connection wrapper, query execution, result fetching,
 * escaping, and transaction handling.
 */
class ConnectionTest extends TestCase
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

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test data after each test
        Connection::query("DELETE FROM settings WHERE StKey LIKE 'test_conn_%'");
        Connection::query("DELETE FROM tags WHERE TgText LIKE 'test_conn_%'");
    }

    // ===== getInstance() tests =====

    public function testGetInstanceReturnsConnection(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $connection = Connection::getInstance();
        $this->assertInstanceOf(\mysqli::class, $connection);
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $connection1 = Connection::getInstance();
        $connection2 = Connection::getInstance();
        $this->assertSame($connection1, $connection2);
    }

    // ===== setInstance() tests =====

    public function testSetInstanceChangesConnection(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $oldConnection = Connection::getInstance();

        // Set it back (this is just to test the setter works)
        Connection::setInstance($oldConnection);

        $newConnection = Connection::getInstance();
        $this->assertInstanceOf(\mysqli::class, $newConnection);
    }

    // ===== query() tests =====

    public function testQueryWithSelectStatement(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::query("SELECT COUNT(*) as cnt FROM settings");

        $this->assertInstanceOf(\mysqli_result::class, $result);
        mysqli_free_result($result);
    }

    public function testQueryWithInsertStatement(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::query("INSERT INTO tags (TgText) VALUES ('test_conn_tag')");

        $this->assertTrue($result);

        // Clean up
        Connection::query("DELETE FROM tags WHERE TgText = 'test_conn_tag'");
    }

    public function testQueryWithInvalidSqlThrowsException(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Query failed:/');

        Connection::query("SELECT * FROM nonexistent_table_xyz");
    }

    // ===== fetchAll() tests =====

    public function testFetchAllReturnsArray(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $rows = Connection::fetchAll("SELECT * FROM settings LIMIT 5");

        $this->assertIsArray($rows);
        $this->assertLessThanOrEqual(5, count($rows));

        if (count($rows) > 0) {
            $this->assertIsArray($rows[0]);
            $this->assertArrayHasKey('StKey', $rows[0]);
        }
    }

    public function testFetchAllWithNoResults(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $rows = Connection::fetchAll("SELECT * FROM settings WHERE StKey = 'nonexistent_key_xyz'");

        $this->assertIsArray($rows);
        $this->assertEmpty($rows);
    }

    public function testFetchAllWithNonSelectQuery(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // INSERT returns true, not a result set
        $rows = Connection::fetchAll("INSERT INTO tags (TgText) VALUES ('test_conn_fetchall')");

        $this->assertIsArray($rows);
        $this->assertEmpty($rows);

        // Clean up
        Connection::query("DELETE FROM tags WHERE TgText = 'test_conn_fetchall'");
    }

    // ===== fetchOne() tests =====

    public function testFetchOneReturnsFirstRow(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Insert a test setting
        Connection::query("INSERT INTO settings (StKey, StValue) VALUES ('test_conn_one', 'value1')");

        $row = Connection::fetchOne("SELECT * FROM settings WHERE StKey = 'test_conn_one'");

        $this->assertIsArray($row);
        $this->assertEquals('test_conn_one', $row['StKey']);
        $this->assertEquals('value1', $row['StValue']);

        // Clean up
        Connection::query("DELETE FROM settings WHERE StKey = 'test_conn_one'");
    }

    public function testFetchOneReturnsNullWhenNoResults(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $row = Connection::fetchOne("SELECT * FROM settings WHERE StKey = 'nonexistent_key_xyz'");

        $this->assertNull($row);
    }

    public function testFetchOneWithMultipleRowsReturnsFirst(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $row = Connection::fetchOne("SELECT * FROM settings LIMIT 1");

        $this->assertIsArray($row);
    }

    public function testFetchOneWithNonSelectQuery(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Test with a non-SELECT query (returns true from mysqli_query)
        // Use DO statement which doesn't return rows
        $result = Connection::fetchOne("DO 1");

        $this->assertNull($result);
    }

    // ===== fetchValue() tests =====

    public function testFetchValueReturnsValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Connection::query("INSERT INTO settings (StKey, StValue) VALUES ('test_conn_value', 'myvalue')");

        $value = Connection::fetchValue("SELECT StValue as value FROM settings WHERE StKey = 'test_conn_value'");

        $this->assertEquals('myvalue', $value);

        // Clean up
        Connection::query("DELETE FROM settings WHERE StKey = 'test_conn_value'");
    }

    public function testFetchValueWithCustomColumn(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Connection::query("INSERT INTO settings (StKey, StValue) VALUES ('test_conn_custom', 'customvalue')");

        $value = Connection::fetchValue("SELECT StValue FROM settings WHERE StKey = 'test_conn_custom'", 'StValue');

        $this->assertEquals('customvalue', $value);

        // Clean up
        Connection::query("DELETE FROM settings WHERE StKey = 'test_conn_custom'");
    }

    public function testFetchValueReturnsNullWhenNoResults(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $value = Connection::fetchValue("SELECT StValue as value FROM settings WHERE StKey = 'nonexistent_xyz'");

        $this->assertNull($value);
    }

    public function testFetchValueReturnsNullWhenColumnNotFound(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $value = Connection::fetchValue("SELECT StKey FROM settings LIMIT 1", 'nonexistent_column');

        $this->assertNull($value);
    }

    // ===== execute() tests =====

    public function testExecuteReturnsAffectedRows(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Connection::execute("INSERT INTO tags (TgText) VALUES ('test_conn_exec')");

        $affected = Connection::execute("DELETE FROM tags WHERE TgText = 'test_conn_exec'");

        $this->assertGreaterThanOrEqual(1, $affected);
    }

    public function testExecuteWithUpdate(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Connection::execute("INSERT INTO settings (StKey, StValue) VALUES ('test_conn_update', 'old')");

        $affected = Connection::execute("UPDATE settings SET StValue = 'new' WHERE StKey = 'test_conn_update'");

        $this->assertEquals(1, $affected);

        // Clean up
        Connection::query("DELETE FROM settings WHERE StKey = 'test_conn_update'");
    }

    // ===== lastInsertId() tests =====

    public function testLastInsertIdReturnsId(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Connection::execute("INSERT INTO tags (TgText) VALUES ('test_conn_lastid')");

        $lastId = Connection::lastInsertId();

        $this->assertGreaterThan(0, $lastId);

        // Clean up
        Connection::query("DELETE FROM tags WHERE TgID = $lastId");
    }

    // ===== escape() tests =====

    public function testEscapeEscapesSpecialCharacters(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $escaped = Connection::escape("test's value");
        $this->assertStringContainsString("\\'", $escaped);
    }

    public function testEscapeHandlesQuotes(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $escaped = Connection::escape("test \"quoted\" value");
        $this->assertStringContainsString('\\"', $escaped);
    }

    public function testEscapeHandlesBackslash(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $escaped = Connection::escape("test\\value");
        $this->assertStringContainsString('\\\\', $escaped);
    }

    // ===== escapeOrNull() tests =====

    public function testEscapeOrNullWithValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeOrNull("test value");
        $this->assertEquals("'test value'", $result);
    }

    public function testEscapeOrNullWithEmptyString(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeOrNull("");
        $this->assertEquals('NULL', $result);
    }

    public function testEscapeOrNullTrimsWhitespace(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeOrNull("  test  ");
        $this->assertEquals("'test'", $result);
    }

    public function testEscapeOrNullWithWhitespaceOnlyReturnsNull(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeOrNull("   ");
        $this->assertEquals('NULL', $result);
    }

    public function testEscapeOrNullConvertsCarriageReturn(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeOrNull("line1\r\nline2");
        // Result is quoted and escaped - the newline will be escaped by mysqli_real_escape_string
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringNotContainsString("\r", $result);
        // Check that it contains line1 and line2
        $this->assertStringContainsString("line1", $result);
        $this->assertStringContainsString("line2", $result);
    }

    // ===== escapeString() tests =====

    public function testEscapeStringWithValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeString("test value");
        $this->assertEquals("'test value'", $result);
    }

    public function testEscapeStringWithEmptyString(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeString("");
        $this->assertEquals("''", $result);
    }

    public function testEscapeStringTrimsWhitespace(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeString("  test  ");
        $this->assertEquals("'test'", $result);
    }

    public function testEscapeStringWithSpecialChars(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Connection::escapeString("test's \"value\"");
        // Check that quotes are escaped
        $this->assertStringContainsString("'", $result);
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
    }

    // ===== Integration tests =====

    public function testInsertAndRetrieveWithEscaping(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $testValue = "test's \"special\" \\ value";

        $escaped = Connection::escapeString($testValue);
        Connection::execute("INSERT INTO settings (StKey, StValue) VALUES ('test_conn_escape', $escaped)");

        $retrieved = Connection::fetchValue("SELECT StValue as value FROM settings WHERE StKey = 'test_conn_escape'");

        // The value should be retrieved exactly as it was inserted (after unescaping by MySQL)
        $this->assertEquals($testValue, $retrieved);

        // Clean up
        Connection::query("DELETE FROM settings WHERE StKey = 'test_conn_escape'");
    }

    public function testQueryChaining(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Insert
        Connection::execute("INSERT INTO settings (StKey, StValue) VALUES ('test_conn_chain', 'chain_val')");

        // Fetch
        $value = Connection::fetchValue("SELECT StValue as value FROM settings WHERE StKey = 'test_conn_chain'");
        $this->assertEquals('chain_val', $value);

        // Update
        Connection::execute("UPDATE settings SET StValue = 'chain_val_2' WHERE StKey = 'test_conn_chain'");

        // Fetch again
        $value = Connection::fetchValue("SELECT StValue as value FROM settings WHERE StKey = 'test_conn_chain'");
        $this->assertEquals('chain_val_2', $value);

        // Delete
        $affected = Connection::execute("DELETE FROM settings WHERE StKey = 'test_conn_chain'");
        $this->assertEquals(1, $affected);
    }

    // ===== lastInsertId() tests =====

    public function testLastInsertIdReturnsId2(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Use tags table which has AUTO_INCREMENT
        Connection::execute("INSERT INTO tags (TgText) VALUES ('test_last_insert_id')");
        $lastId = Connection::lastInsertId();

        $this->assertGreaterThan(0, $lastId);

        // Clean up
        Connection::execute("DELETE FROM tags WHERE TgText = 'test_last_insert_id'");
    }

    // ===== reset() tests =====

    public function testResetClearsInstance(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $instance = Connection::getInstance();

        Connection::reset();

        // Connection owns the handle outright, so a reset really does drop it
        // rather than leaving a copy behind for the next getInstance() to find.
        try {
            Connection::getInstance();
            $this->fail('getInstance() should throw after reset()');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not initialized', $e->getMessage());
        } finally {
            // Restore it: the rest of the suite shares this connection.
            Connection::setInstance($instance);
        }
    }

    // ===== setInstance() tests =====

    public function testSetInstanceSetsConnection(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $originalInstance = Connection::getInstance();

        // Set a new instance (same connection for testing purposes)
        Connection::setInstance($originalInstance);

        $newInstance = Connection::getInstance();

        $this->assertSame($originalInstance, $newInstance);
    }
}
