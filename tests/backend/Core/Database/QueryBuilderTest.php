<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database\QueryBuilder class.
 *
 * Tests fluent interface for building SQL queries, including
 * SELECT, INSERT, UPDATE, DELETE operations with various clauses.
 */
class QueryBuilderTest extends TestCase
{
    private static bool $dbConnected = false;

    public static function setUpBeforeClass(): void
    {
        self::$dbConnected = defined('LWT_TEST_DB_AVAILABLE') && LWT_TEST_DB_AVAILABLE;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test data
        $prefix = '';
        Connection::query("DELETE FROM {$prefix}tags WHERE TgText LIKE 'test_qb_%'");
        Connection::query("DELETE FROM {$prefix}settings WHERE StKey LIKE 'test_qb_%'");
    }

    // ===== table() and constructor tests =====

    public function testTableReturnsQueryBuilder(): void
    {
        $qb = QueryBuilder::table('tags');
        $this->assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function testConstructorAddsPrefix(): void
    {

        $qb = new QueryBuilder('tags');
        $sql = $qb->toSql();

        // Should contain table prefix
        $prefix = '';
        $this->assertStringContainsString("{$prefix}tags", $sql);
    }

    // ===== select() tests =====

    public function testSelectDefaultsToAsterisk(): void
    {
        $sql = QueryBuilder::table('tags')->toSql();
        $this->assertStringContainsString('SELECT *', $sql);
    }

    public function testSelectWithSingleColumn(): void
    {
        $sql = QueryBuilder::table('tags')->select('TgText')->toSql();
        $this->assertStringContainsString('SELECT TgText', $sql);
    }

    public function testSelectWithMultipleColumns(): void
    {
        $sql = QueryBuilder::table('tags')->select(['TgID', 'TgText'])->toSql();
        $this->assertStringContainsString('SELECT TgID, TgText', $sql);
    }

    // ===== distinct() tests =====

    public function testDistinct(): void
    {
        $sql = QueryBuilder::table('tags')->select('TgText')->distinct()->toSql();
        $this->assertStringContainsString('SELECT DISTINCT TgText', $sql);
    }

    // ===== where() tests =====

    public function testWhereWithEquality(): void
    {
        $sql = QueryBuilder::table('tags')->where('TgID', '=', 1)->toSql();
        $this->assertStringContainsString("WHERE TgID = 1", $sql);
    }

    public function testWhereWithImplicitEquality(): void
    {
        $sql = QueryBuilder::table('tags')->where('TgID', 1)->toSql();
        $this->assertStringContainsString("WHERE TgID = 1", $sql);
    }

    public function testWhereWithNotEquals(): void
    {
        $sql = QueryBuilder::table('tags')->where('TgID', '!=', 1)->toSql();
        $this->assertStringContainsString("WHERE TgID != 1", $sql);
    }

    public function testWhereWithGreaterThan(): void
    {
        $sql = QueryBuilder::table('tags')->where('TgID', '>', 5)->toSql();
        $this->assertStringContainsString("WHERE TgID > 5", $sql);
    }

    public function testWhereWithLessThan(): void
    {
        $sql = QueryBuilder::table('tags')->where('TgID', '<', 10)->toSql();
        $this->assertStringContainsString("WHERE TgID < 10", $sql);
    }

    public function testWhereWithLike(): void
    {
        $sql = QueryBuilder::table('tags')->where('TgText', 'LIKE', 'test%')->toSql();
        $this->assertStringContainsString("WHERE TgText LIKE 'test%'", $sql);
    }

    public function testMultipleWheres(): void
    {
        $sql = QueryBuilder::table('tags')
            ->where('TgID', '>', 1)
            ->where('TgText', 'LIKE', 'test%')
            ->toSql();

        $this->assertStringContainsString("WHERE TgID > 1 AND TgText LIKE 'test%'", $sql);
    }

    public function testWhereWithBooleanTrue(): void
    {

        $sql = QueryBuilder::table('tags')->where('TgID', '=', true)->toSql();
        $this->assertStringContainsString("WHERE TgID = 1", $sql);
    }

    public function testWhereWithBooleanFalse(): void
    {

        $sql = QueryBuilder::table('tags')->where('TgID', '=', false)->toSql();
        $this->assertStringContainsString("WHERE TgID = 0", $sql);
    }

    public function testWhereWithFloat(): void
    {

        $sql = QueryBuilder::table('tags')->where('TgID', '>', 3.14)->toSql();
        $this->assertStringContainsString("WHERE TgID > 3.14", $sql);
    }

    public function testWhereWithNull(): void
    {

        // When using null with =, it should produce IS NULL
        $sql = QueryBuilder::table('tags')->whereNull('TgComment')->toSql();
        $this->assertStringContainsString("WHERE TgComment IS NULL", $sql);
    }

    // ===== orWhere() tests =====

    public function testOrWhere(): void
    {
        $sql = QueryBuilder::table('tags')
            ->where('TgID', 1)
            ->orWhere('TgID', 2)
            ->toSql();

        $this->assertStringContainsString("WHERE TgID = 1 OR TgID = 2", $sql);
    }

    // ===== whereIn() tests =====

    public function testWhereIn(): void
    {
        $sql = QueryBuilder::table('tags')->whereIn('TgID', [1, 2, 3])->toSql();
        $this->assertStringContainsString("WHERE TgID IN (1, 2, 3)", $sql);
    }

    public function testWhereNotIn(): void
    {
        $sql = QueryBuilder::table('tags')->whereNotIn('TgID', [1, 2])->toSql();
        $this->assertStringContainsString("WHERE TgID NOT IN (1, 2)", $sql);
    }

    // ===== whereNull() tests =====

    public function testWhereNull(): void
    {
        $sql = QueryBuilder::table('tags')->whereNull('TgComment')->toSql();
        $this->assertStringContainsString("WHERE TgComment IS NULL", $sql);
    }

    public function testWhereNotNull(): void
    {
        $sql = QueryBuilder::table('tags')->whereNotNull('TgComment')->toSql();
        $this->assertStringContainsString("WHERE TgComment IS NOT NULL", $sql);
    }

    // ===== whereRaw() tests =====

    public function testWhereRaw(): void
    {
        $sql = QueryBuilder::table('tags')->whereRaw("TgID > 5 AND TgText != ''")->toSql();
        $this->assertStringContainsString("WHERE TgID > 5 AND TgText != ''", $sql);
    }

    public function testWhereRawBindsItsOwnPlaceholdersInClauseOrder(): void
    {
        $query = QueryBuilder::table('tags')
            ->where('TgID', '>', 5)
            ->whereRaw('(TgText LIKE ? OR TgText LIKE ?)', ['%ab%', '%cd%'])
            ->where('TgComment', '=', 'note');

        $sql = $query->toSqlPrepared();

        $this->assertStringContainsString('(TgText LIKE ? OR TgText LIKE ?)', $sql);
        $this->assertSame([5, '%ab%', '%cd%', 'note'], $query->getBindings());
    }

    public function testWhereRawWithoutBindingsAddsNone(): void
    {
        $query = QueryBuilder::table('tags')
            ->whereRaw('TgID > 5')
            ->where('TgText', '=', 'x');
        $query->toSqlPrepared();

        $this->assertSame(['x'], $query->getBindings());
    }

    // ===== join() tests =====

    public function testJoinWithExplicitOperator(): void
    {
        $prefix = '';
        $sql = QueryBuilder::table('tags')
            ->join('word_tag_map', 'tags.TgID', '=', 'word_tag_map.WtTgID')
            ->toSql();

        $this->assertStringContainsString("INNER JOIN {$prefix}word_tag_map", $sql);
        $this->assertStringContainsString("ON tags.TgID = word_tag_map.WtTgID", $sql);
    }

    public function testJoinWithImplicitEquality(): void
    {
        $prefix = '';
        $sql = QueryBuilder::table('tags')
            ->join('word_tag_map', 'tags.TgID', 'word_tag_map.WtTgID')
            ->toSql();

        $this->assertStringContainsString("INNER JOIN {$prefix}word_tag_map", $sql);
        $this->assertStringContainsString("ON tags.TgID = word_tag_map.WtTgID", $sql);
    }

    public function testLeftJoin(): void
    {
        $prefix = '';
        $sql = QueryBuilder::table('tags')
            ->leftJoin('word_tag_map', 'tags.TgID', 'word_tag_map.WtTgID')
            ->toSql();

        $this->assertStringContainsString("LEFT JOIN {$prefix}word_tag_map", $sql);
    }

    public function testRightJoin(): void
    {
        $prefix = '';
        $sql = QueryBuilder::table('tags')
            ->rightJoin('word_tag_map', 'tags.TgID', 'word_tag_map.WtTgID')
            ->toSql();

        $this->assertStringContainsString("RIGHT JOIN {$prefix}word_tag_map", $sql);
    }

    public function testJoinWithNonEqualityOperator(): void
    {
        $prefix = '';
        $sql = QueryBuilder::table('tags')
            ->join('word_tag_map', 'tags.TgID', '!=', 'word_tag_map.WtTgID')
            ->toSql();

        $this->assertStringContainsString("INNER JOIN {$prefix}word_tag_map", $sql);
        $this->assertStringContainsString("ON tags.TgID != word_tag_map.WtTgID", $sql);
    }

    // ===== orderBy() tests =====

    public function testOrderByAscending(): void
    {
        $sql = QueryBuilder::table('tags')->orderBy('TgText')->toSql();
        $this->assertStringContainsString("ORDER BY TgText ASC", $sql);
    }

    public function testOrderByDescending(): void
    {
        $sql = QueryBuilder::table('tags')->orderBy('TgText', 'DESC')->toSql();
        $this->assertStringContainsString("ORDER BY TgText DESC", $sql);
    }

    public function testOrderByDesc(): void
    {
        $sql = QueryBuilder::table('tags')->orderByDesc('TgText')->toSql();
        $this->assertStringContainsString("ORDER BY TgText DESC", $sql);
    }

    public function testMultipleOrderBys(): void
    {
        $sql = QueryBuilder::table('tags')
            ->orderBy('TgText')
            ->orderBy('TgID', 'DESC')
            ->toSql();

        $this->assertStringContainsString("ORDER BY TgText ASC, TgID DESC", $sql);
    }

    // ===== groupBy() tests =====

    public function testGroupBySingleColumn(): void
    {
        $sql = QueryBuilder::table('tags')->groupBy('TgText')->toSql();
        $this->assertStringContainsString("GROUP BY TgText", $sql);
    }

    public function testGroupByMultipleColumns(): void
    {
        $sql = QueryBuilder::table('tags')->groupBy(['TgText', 'TgComment'])->toSql();
        $this->assertStringContainsString("GROUP BY TgText, TgComment", $sql);
    }

    // ===== limit() and offset() tests =====

    public function testLimit(): void
    {
        $sql = QueryBuilder::table('tags')->limit(10)->toSql();
        $this->assertStringContainsString("LIMIT 10", $sql);
    }

    public function testOffset(): void
    {
        $sql = QueryBuilder::table('tags')->offset(5)->toSql();
        $this->assertStringContainsString("OFFSET 5", $sql);
    }

    public function testLimitAndOffset(): void
    {
        $sql = QueryBuilder::table('tags')->limit(10)->offset(20)->toSql();
        $this->assertStringContainsString("LIMIT 10 OFFSET 20", $sql);
    }

    // ===== get() tests =====

    public function testGetReturnsArray(): void
    {

        $results = QueryBuilder::table('tags')->limit(5)->get();
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(5, count($results));
    }

    public function testGetWithWhere(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_get']);

        $results = QueryBuilder::table('tags')->where('TgText', 'test_qb_get')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('test_qb_get', $results[0]['TgText']);
    }

    // ===== first() tests =====

    public function testFirstReturnsRow(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_first']);

        $row = QueryBuilder::table('tags')->where('TgText', 'test_qb_first')->first();

        $this->assertIsArray($row);
        $this->assertEquals('test_qb_first', $row['TgText']);
    }

    public function testFirstReturnsNull(): void
    {

        $row = QueryBuilder::table('tags')->where('TgText', 'nonexistent_xyz')->first();
        $this->assertNull($row);
    }

    // ===== value() tests =====

    public function testValueReturnsValue(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_value']);

        $value = QueryBuilder::table('tags')->where('TgText', 'test_qb_value')->value('TgText');

        $this->assertEquals('test_qb_value', $value);
    }

    public function testValueReturnsNullWhenNoMatch(): void
    {

        $value = QueryBuilder::table('tags')->where('TgText', 'nonexistent_xyz_123')->value('TgText');

        $this->assertNull($value);
    }

    // ===== count() tests =====

    public function testCountReturnsInteger(): void
    {

        $count = QueryBuilder::table('tags')->count();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountWithWhere(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_count1']);
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_count2']);

        $count = QueryBuilder::table('tags')->where('TgText', 'LIKE', 'test_qb_count%')->count();

        $this->assertEquals(2, $count);
    }

    // ===== exists() tests =====

    public function testExistsReturnsTrue(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_exists']);

        $exists = QueryBuilder::table('tags')->where('TgText', 'test_qb_exists')->exists();

        $this->assertTrue($exists);
    }

    public function testExistsReturnsFalse(): void
    {

        $exists = QueryBuilder::table('tags')->where('TgText', 'nonexistent_xyz')->exists();
        $this->assertFalse($exists);
    }

    // ===== insert() tests =====

    public function testInsertReturnsId(): void
    {

        $id = QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_insert']);

        $this->assertGreaterThan(0, $id);

        // Verify inserted
        $row = QueryBuilder::table('tags')->where('TgID', $id)->first();
        $this->assertEquals('test_qb_insert', $row['TgText']);
    }

    public function testInsertWithMultipleColumns(): void
    {

        $id = QueryBuilder::table('tags')->insert([
            'TgText' => 'test_qb_multi',
            'TgComment' => 'test comment'
        ]);

        $row = QueryBuilder::table('tags')->where('TgID', $id)->first();
        $this->assertEquals('test_qb_multi', $row['TgText']);
        $this->assertEquals('test comment', $row['TgComment']);
    }

    // ===== insertMany() tests =====

    public function testInsertManyReturnsAffectedRows(): void
    {

        $affected = QueryBuilder::table('tags')->insertMany([
            ['TgText' => 'test_qb_many1'],
            ['TgText' => 'test_qb_many2'],
            ['TgText' => 'test_qb_many3']
        ]);

        $this->assertEquals(3, $affected);

        // Verify all inserted
        $count = QueryBuilder::table('tags')->where('TgText', 'LIKE', 'test_qb_many%')->count();
        $this->assertEquals(3, $count);
    }

    public function testInsertManyWithEmptyArray(): void
    {

        $affected = QueryBuilder::table('tags')->insertMany([]);
        $this->assertEquals(0, $affected);
    }

    // ===== update() tests =====

    public function testUpdateReturnsAffectedRows(): void
    {

        // Insert test data
        $id = QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_update_old']);

        // Update
        $affected = QueryBuilder::table('tags')
            ->where('TgID', $id)
            ->update(['TgText' => 'test_qb_update_new']);

        $this->assertEquals(1, $affected);

        // Verify updated
        $row = QueryBuilder::table('tags')->where('TgID', $id)->first();
        $this->assertEquals('test_qb_update_new', $row['TgText']);
    }

    public function testUpdateWithMultipleColumns(): void
    {

        // Insert test data with unique suffix
        $suffix = substr(uniqid(), -6);
        $id = QueryBuilder::table('tags')->insert(['TgText' => "test_qb_upd_m_{$suffix}"]);

        // Update
        QueryBuilder::table('tags')->where('TgID', $id)->update([
            'TgText' => "upd_txt_{$suffix}",
            'TgComment' => 'updated_comment'
        ]);

        // Verify
        $row = QueryBuilder::table('tags')->where('TgID', $id)->first();
        $this->assertEquals("upd_txt_{$suffix}", $row['TgText']);
        $this->assertEquals('updated_comment', $row['TgComment']);
    }

    // ===== delete() tests =====

    public function testDeleteReturnsAffectedRows(): void
    {

        // Insert test data
        $id = QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_delete']);

        // Delete
        $affected = QueryBuilder::table('tags')->where('TgID', $id)->delete();

        $this->assertEquals(1, $affected);

        // Verify deleted
        $row = QueryBuilder::table('tags')->where('TgID', $id)->first();
        $this->assertNull($row);
    }

    public function testDeleteMultipleRows(): void
    {

        // Use random suffix to avoid conflicts (TgText is varchar(20))
        $rand = substr(uniqid(), -6);
        $tag1 = "qb_{$rand}_1";
        $tag2 = "qb_{$rand}_2";
        $tag3 = "qb_{$rand}_3";

        // Clean up any potential leftovers
        try {
            QueryBuilder::table('tags')->where('TgText', 'LIKE', "qb_{$rand}%")->delete();
        } catch (\Exception $e) {
            // Ignore if nothing to delete
        }

        // Insert test data
        QueryBuilder::table('tags')->insertMany([
            ['TgText' => $tag1],
            ['TgText' => $tag2],
            ['TgText' => $tag3]
        ]);

        // Delete
        $affected = QueryBuilder::table('tags')->where('TgText', 'LIKE', "qb_{$rand}%")->delete();

        $this->assertEquals(3, $affected);
    }

    // ===== truncate() tests =====

    public function testTruncateEmptiesTable(): void
    {

        // Create a temporary test table
        $prefix = '';
        Connection::query(
            "CREATE TEMPORARY TABLE {$prefix}test_truncate " .
            "(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))"
        );

        // Insert data
        QueryBuilder::table('test_truncate')->insert(['name' => 'test1']);
        QueryBuilder::table('test_truncate')->insert(['name' => 'test2']);

        // Verify data exists
        $count = QueryBuilder::table('test_truncate')->count();
        $this->assertEquals(2, $count);

        // Truncate
        QueryBuilder::table('test_truncate')->truncate();

        // Verify empty
        $count = QueryBuilder::table('test_truncate')->count();
        $this->assertEquals(0, $count);
    }

    // ===== Complex query tests =====

    public function testComplexQueryWithMultipleClauses(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insertMany([
            ['TgText' => 'test_qb_complex_a'],
            ['TgText' => 'test_qb_complex_b'],
            ['TgText' => 'test_qb_complex_c'],
            ['TgText' => 'test_qb_other']
        ]);

        // Complex query
        $results = QueryBuilder::table('tags')
            ->select(['TgID', 'TgText'])
            ->where('TgText', 'LIKE', 'test_qb_complex%')
            ->orderBy('TgText')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('test_qb_complex_a', $results[0]['TgText']);
        $this->assertEquals('test_qb_complex_b', $results[1]['TgText']);
    }

    public function testFluentInterfaceChaining(): void
    {

        // Insert test data
        QueryBuilder::table('tags')->insert(['TgText' => 'test_qb_fluent']);

        // Test fluent chaining
        $result = QueryBuilder::table('tags')
            ->select('TgText')
            ->where('TgText', 'test_qb_fluent')
            ->orderBy('TgID')
            ->limit(1)
            ->first();

        $this->assertIsArray($result);
        $this->assertEquals('test_qb_fluent', $result['TgText']);
    }
}
