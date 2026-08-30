<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Migrations;
use Lwt\Shared\Infrastructure\Database\SchemaConstraints;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the Database\Migrations class.
 *
 * Tests database migrations and initialization utilities.
 */
class MigrationsTest extends TestCase
{
    private static bool $dbConnected = false;

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        $testDbname = "test_" . $config['dbname'];
        // Other test classes overwrite the global database name; the schema
        // queries below (INFORMATION_SCHEMA lookups) need it to be ours.
        Globals::setDatabaseName($testDbname);

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

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Several tests here drop constraints and realign columns on purpose.
        // Leave the schema as declared, so whatever runs next — ForeignKeyTest
        // asserting cascades, or a second run of this suite — starts from a
        // whole database rather than from the wreckage. This is the order
        // update() uses: constraints out of the way, columns aligned, then
        // everything put back.
        $foreignKeys = Migrations::captureForeignKeys();
        Migrations::dropAllForeignKeys();
        Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            Migrations::alignReferenceColumnTypes();
            Migrations::restoreForeignKeys($foreignKeys);
            Migrations::reconcileForeignKeys();
        } finally {
            Connection::execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ===== prefixQuery() tests =====
    #[DataProvider('providerPrefixQueryInsert')]
    public function testPrefixQueryInsert(string $sql, string $prefix, string $expected): void
    {
        $result = Migrations::prefixQuery($sql, $prefix);
        $this->assertEquals($expected, $result);
    }

    public static function providerPrefixQueryInsert(): array
    {
        return [
            'INSERT INTO with prefix' => [
                "INSERT INTO languages (LgName) VALUES ('Test');",
                "prefix_",
                "INSERT INTO prefix_languages (LgName) VALUES ('Test');"
            ],
            'INSERT INTO with empty prefix' => [
                "INSERT INTO languages (LgName) VALUES ('Test');",
                "",
                "INSERT INTO languages (LgName) VALUES ('Test');"
            ],
            'INSERT INTO multiple columns' => [
                "INSERT INTO words (WoLgID, WoText) VALUES (1, 'test');",
                "lwt_",
                "INSERT INTO lwt_words (WoLgID, WoText) VALUES (1, 'test');"
            ],
        ];
    }
    #[DataProvider('providerPrefixQueryCreateTable')]
    public function testPrefixQueryCreateTable(string $sql, string $prefix, string $expected): void
    {
        $result = Migrations::prefixQuery($sql, $prefix);
        $this->assertEquals($expected, $result);
    }

    public static function providerPrefixQueryCreateTable(): array
    {
        return [
            'CREATE TABLE basic' => [
                "CREATE TABLE languages (id INT);",
                "test_",
                "CREATE TABLE test_languages (id INT);"
            ],
            'CREATE TABLE with backticks' => [
                "CREATE TABLE `users` (id INT);",
                "pre_",
                "CREATE TABLE `pre_users` (id INT);"
            ],
            'CREATE TABLE IF NOT EXISTS' => [
                "CREATE TABLE IF NOT EXISTS languages (id INT);",
                "lwt_",
                "CREATE TABLE IF NOT EXISTS lwt_languages (id INT);"
            ],
            'CREATE TABLE IF NOT EXISTS with backticks' => [
                "CREATE TABLE IF NOT EXISTS `texts` (id INT);",
                "app_",
                "CREATE TABLE IF NOT EXISTS `app_texts` (id INT);"
            ],
            'CREATE TABLE with empty prefix' => [
                "CREATE TABLE users (id INT);",
                "",
                "CREATE TABLE users (id INT);"
            ],
        ];
    }
    #[DataProvider('providerPrefixQueryAlterTable')]
    public function testPrefixQueryAlterTable(string $sql, string $prefix, string $expected): void
    {
        $result = Migrations::prefixQuery($sql, $prefix);
        $this->assertEquals($expected, $result);
    }

    public static function providerPrefixQueryAlterTable(): array
    {
        return [
            'ALTER TABLE basic' => [
                "ALTER TABLE languages ADD COLUMN name VARCHAR(255);",
                "pre_",
                "ALTER TABLE pre_languages ADD COLUMN name VARCHAR(255);"
            ],
            'ALTER TABLE with backticks' => [
                "ALTER TABLE `users` DROP COLUMN email;",
                "test_",
                "ALTER TABLE `test_users` DROP COLUMN email;"
            ],
            'ALTER TABLE with empty prefix' => [
                "ALTER TABLE settings MODIFY StValue TEXT;",
                "",
                "ALTER TABLE settings MODIFY StValue TEXT;"
            ],
        ];
    }
    #[DataProvider('providerPrefixQueryDropTable')]
    public function testPrefixQueryDropTable(string $sql, string $prefix, string $expected): void
    {
        $result = Migrations::prefixQuery($sql, $prefix);
        $this->assertEquals($expected, $result);
    }

    public static function providerPrefixQueryDropTable(): array
    {
        return [
            'DROP TABLE basic' => [
                "DROP TABLE temp_data;",
                "pre_",
                "DROP TABLE pre_temp_data;"
            ],
            'DROP TABLE IF EXISTS' => [
                "DROP TABLE IF EXISTS temp_data;",
                "lwt_",
                "DROP TABLE IF EXISTS lwt_temp_data;"
            ],
            'DROP TABLE with backticks' => [
                "DROP TABLE `old_table`;",
                "test_",
                "DROP TABLE `test_old_table`;"
            ],
        ];
    }

    public function testPrefixQueryNonMatchingStatement(): void
    {
        // SELECT statements should not be modified
        $sql = "SELECT * FROM languages;";
        $result = Migrations::prefixQuery($sql, "prefix_");
        $this->assertEquals($sql, $result, 'Non-matching statements should be unchanged');
    }

    public function testPrefixQueryUpdateStatement(): void
    {
        // UPDATE statements should not be modified by prefixQuery
        $sql = "UPDATE languages SET LgName = 'Test';";
        $result = Migrations::prefixQuery($sql, "prefix_");
        $this->assertEquals($sql, $result, 'UPDATE statements should be unchanged');
    }

    public function testPrefixQueryDeleteStatement(): void
    {
        // DELETE statements should not be modified by prefixQuery
        $sql = "DELETE FROM languages WHERE LgID = 1;";
        $result = Migrations::prefixQuery($sql, "prefix_");
        $this->assertEquals($sql, $result, 'DELETE statements should be unchanged');
    }

    public function testPrefixQueryComplexCreateTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `languages` (
            LgID tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
            LgName varchar(40) NOT NULL,
            PRIMARY KEY (LgID)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;";

        $result = Migrations::prefixQuery($sql, "test_");

        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS `test_languages`", $result);
    }

    // ===== reparseAllTexts() tests =====

    public function testReparseAllTextsRuns(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Clean up any texts that reference non-existent languages
        // to avoid "Language data not found" errors
        Connection::query("DELETE FROM word_occurrences WHERE Ti2TxID IN (
            SELECT TxID FROM texts WHERE TxLgID NOT IN (SELECT LgID FROM languages)
        )");
        Connection::query("DELETE FROM sentences WHERE SeTxID IN (
            SELECT TxID FROM texts WHERE TxLgID NOT IN (SELECT LgID FROM languages)
        )");
        Connection::query("DELETE FROM texts WHERE TxLgID NOT IN (SELECT LgID FROM languages)");

        // This function truncates and rebuilds text data
        // Should run without error on empty/minimal database
        Migrations::reparseAllTexts();
        $this->assertTrue(true, 'reparseAllTexts should complete without error');
    }

    // ===== update() tests =====

    public function testUpdateRuns(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // The update function checks and updates database schema
        // Should run without error on a properly initialized database
        Migrations::update();
        $this->assertTrue(true, 'update should complete without error');
    }

    // ===== checkAndUpdate() tests =====

    public function testCheckAndUpdateRuns(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // This is the main entry point for database initialization
        // Should run without error
        Migrations::checkAndUpdate();
        $this->assertTrue(true, 'checkAndUpdate should complete without error');
    }

    public function testCheckAndUpdateEnsuresTablesExist(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::checkAndUpdate();

        // Verify core tables exist
        $tables = [
            'languages', 'texts', 'words', 'sentences',
            'settings', 'tags', 'text_tags', 'word_occurrences'
        ];

        foreach ($tables as $table) {
            $result = Connection::query("SHOW TABLES LIKE '{$table}'");
            $exists = mysqli_num_rows($result) > 0;
            mysqli_free_result($result);
            $this->assertTrue($exists, "Table {$table} should exist after checkAndUpdate");
        }
    }

    public function testCheckAndUpdateMigrationsTable(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::checkAndUpdate();

        // Verify _migrations table exists (without prefix)
        $result = Connection::query("SHOW TABLES LIKE '_migrations'");
        $exists = mysqli_num_rows($result) > 0;
        mysqli_free_result($result);
        $this->assertTrue($exists, "_migrations table should exist");
    }

    // ===== Edge cases and complex scenarios =====

    public function testPrefixQueryWithSpecialCharacters(): void
    {
        // Test with table name that has underscores
        $sql = "CREATE TABLE my_table_name (id INT);";
        $result = Migrations::prefixQuery($sql, "prefix_");
        $this->assertEquals("CREATE TABLE prefix_my_table_name (id INT);", $result);
    }

    public function testPrefixQueryWithNumericPrefix(): void
    {
        // Prefix with numbers
        $sql = "CREATE TABLE users (id INT);";
        $result = Migrations::prefixQuery($sql, "app123_");
        $this->assertEquals("CREATE TABLE app123_users (id INT);", $result);
    }

    public function testPrefixQueryCaseInsensitive(): void
    {
        // prefixQuery should handle SQL keywords case-insensitively
        $sql = "create table users (id INT);";
        $result = Migrations::prefixQuery($sql, "pre_");
        $this->assertStringContainsString("pre_users", $result, 'Lowercase CREATE TABLE should be prefixed');

        $sql = "Create Table users (id INT);";
        $result = Migrations::prefixQuery($sql, "pre_");
        $this->assertStringContainsString("pre_users", $result, 'Mixed case CREATE TABLE should be prefixed');

        $sql = "insert into languages (LgName) VALUES ('Test');";
        $result = Migrations::prefixQuery($sql, "pre_");
        $this->assertStringContainsString("pre_languages", $result, 'Lowercase INSERT INTO should be prefixed');

        $sql = "drop table IF EXISTS temp_data;";
        $result = Migrations::prefixQuery($sql, "pre_");
        $this->assertStringContainsString("pre_temp_data", $result, 'Lowercase DROP TABLE should be prefixed');
    }

    public function testPrefixQueryInsertMultipleValues(): void
    {
        $sql = "INSERT INTO languages (LgID, LgName) VALUES (1, 'English'), (2, 'French');";
        $result = Migrations::prefixQuery($sql, "test_");
        $this->assertEquals(
            "INSERT INTO test_languages (LgID, LgName) VALUES (1, 'English'), (2, 'French');",
            $result
        );
    }

    public function testCheckAndUpdateSetsLastScorecalc(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Clear lastscorecalc to force recalculation
        Connection::query("DELETE FROM settings WHERE StKey = 'lastscorecalc'");

        Migrations::checkAndUpdate();

        // Verify lastscorecalc was set
        $result = Connection::fetchValue(
            "SELECT StValue as value FROM settings WHERE StKey = 'lastscorecalc'"
        );

        $this->assertNotEmpty($result, 'lastscorecalc should be set after checkAndUpdate');
        // Should be today's date
        $this->assertEquals(date('Y-m-d'), $result, 'lastscorecalc should be today');
    }

    public function testPrefixQueryPreservesRestOfStatement(): void
    {
        // Ensure the rest of the SQL statement is preserved correctly
        $sql = "CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;";

        $result = Migrations::prefixQuery($sql, "app_");

        $this->assertStringContainsString("CREATE TABLE app_users", $result);
        $this->assertStringContainsString("AUTO_INCREMENT PRIMARY KEY", $result);
        $this->assertStringContainsString("VARCHAR(100) NOT NULL DEFAULT ''", $result);
        $this->assertStringContainsString("ENGINE=InnoDB", $result);
    }

    public function testUpdateSetsDbversion(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::update();

        // Verify dbversion is set
        $result = Connection::fetchValue(
            "SELECT StValue as value FROM settings WHERE StKey = 'dbversion'"
        );

        $this->assertNotEmpty($result, 'dbversion should be set after update');
        // Should match current version format (vXXXYYYZZZ)
        $this->assertMatchesRegularExpression('/^v\d{9}$/', $result, 'dbversion should match version format');
    }

    // ===== New migration tracking tests =====

    public function testGetMigrationFilesReturnsSortedList(): void
    {
        $files = Migrations::getMigrationFiles();

        $this->assertIsArray($files);
        $this->assertNotEmpty($files, 'Should find migration files in db/migrations/');

        // Verify files are sorted
        $sortedFiles = $files;
        sort($sortedFiles);
        $this->assertEquals($sortedFiles, $files, 'Migration files should be sorted');

        // Verify all entries are SQL files
        foreach ($files as $file) {
            $this->assertStringEndsWith('.sql', $file, 'All migration files should be .sql');
        }
    }

    public function testGetAppliedMigrationsReturnsArray(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $applied = Migrations::getAppliedMigrations();

        $this->assertIsArray($applied);
    }

    public function testRecordMigrationTracksNewMigration(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $testFilename = 'test_migration_' . time() . '.sql';

        // Record a test migration
        Migrations::recordMigration($testFilename);

        // Verify it was recorded
        $applied = Migrations::getAppliedMigrations();
        $this->assertContains($testFilename, $applied, 'Recorded migration should appear in applied list');

        // Clean up
        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testRecordMigrationIsIdempotent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $testFilename = 'test_idempotent_' . time() . '.sql';

        // Record the same migration twice
        Migrations::recordMigration($testFilename);
        Migrations::recordMigration($testFilename);

        // Should not throw an error and should only have one entry
        $count = Connection::preparedFetchValue(
            "SELECT COUNT(*) as value FROM _migrations WHERE filename = ?",
            [$testFilename]
        );
        $this->assertEquals(1, $count, 'Recording the same migration twice should result in one entry');

        // Clean up
        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testUpgradeMigrationsTableAddsAppliedAtColumn(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Run upgrade (should be idempotent)
        Migrations::upgradeMigrationsTable();

        // Verify applied_at column exists
        $dbname = Globals::getDatabaseName();
        $columns = Connection::preparedFetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = '_migrations'",
            [$dbname]
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');

        $this->assertContains('applied_at', $columnNames, '_migrations should have applied_at column');
    }

    public function testRecordMigrationStoresFailureWithError(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::upgradeMigrationsTable();
        $testFilename = 'test_failed_' . time() . '.sql';

        Migrations::recordMigration(
            $testFilename,
            '',
            Migrations::STATUS_FAILED,
            "Can't create table (errno: 150)"
        );

        $failed = Migrations::getFailedMigrations();
        $filenames = array_column($failed, 'filename');
        $this->assertContains($testFilename, $filenames, 'Failed migration should be reported as failed');

        // A failed migration must not count as applied, otherwise the missing
        // schema is frozen in place forever (issue #247).
        $this->assertNotContains(
            $testFilename,
            Migrations::getAppliedMigrations(),
            'Failed migration should not be listed as applied'
        );
        $this->assertContains(
            $testFilename,
            Migrations::getRecordedMigrations(),
            'Failed migration should still be recorded'
        );
        $this->assertContains(
            $testFilename,
            Migrations::getRetryableMigrations(),
            'A first failure should still be retryable'
        );

        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testRetryStopsAfterMaxAttempts(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::upgradeMigrationsTable();
        $testFilename = 'test_exhausted_' . time() . '.sql';

        for ($i = 0; $i < Migrations::MAX_ATTEMPTS; $i++) {
            Migrations::recordMigration($testFilename, '', Migrations::STATUS_FAILED, 'boom');
        }

        $this->assertNotContains(
            $testFilename,
            Migrations::getRetryableMigrations(),
            'A migration should stop being retried after MAX_ATTEMPTS'
        );
        $this->assertContains(
            $testFilename,
            array_column(Migrations::getFailedMigrations(), 'filename'),
            'An exhausted migration should still be reported to the admin'
        );

        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testAnUpgradeRestoresTheRetryBudget(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::upgradeMigrationsTable();
        $testFilename = 'test_budget_' . time() . '.sql';

        for ($i = 0; $i < Migrations::MAX_ATTEMPTS; $i++) {
            Migrations::recordMigration($testFilename, '', Migrations::STATUS_FAILED, 'boom');
        }
        $this->assertNotContains($testFilename, Migrations::getRetryableMigrations());

        // A migration usually fails on a prerequisite rather than on itself, so
        // new migration files are a reason to try an exhausted one again.
        Migrations::restoreRetryBudget();

        $this->assertContains(
            $testFilename,
            Migrations::getRetryableMigrations(),
            'An upgrade should let an exhausted migration run again'
        );

        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testRestoringTheBudgetLeavesAppliedMigrationsAlone(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::upgradeMigrationsTable();
        $testFilename = 'test_budget_applied_' . time() . '.sql';

        Migrations::recordMigration($testFilename, 'abc', Migrations::STATUS_APPLIED);
        Migrations::restoreRetryBudget();

        $this->assertNotContains(
            $testFilename,
            Migrations::getRetryableMigrations(),
            'An applied migration must never be queued for a retry'
        );
        $this->assertContains($testFilename, Migrations::getAppliedMigrations());

        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testRecordMigrationPromotesFailureToApplied(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Migrations::upgradeMigrationsTable();
        $testFilename = 'test_recovered_' . time() . '.sql';

        Migrations::recordMigration($testFilename, '', Migrations::STATUS_FAILED, 'boom');
        Migrations::recordMigration($testFilename, 'abc', Migrations::STATUS_APPLIED);

        $this->assertContains(
            $testFilename,
            Migrations::getAppliedMigrations(),
            'A migration that succeeds on retry should end up applied'
        );
        $this->assertNotContains(
            $testFilename,
            array_column(Migrations::getFailedMigrations(), 'filename'),
            'A recovered migration should no longer be reported as failed'
        );

        Connection::preparedExecute("DELETE FROM _migrations WHERE filename = ?", [$testFilename]);
    }

    public function testAConstraintIsRestoredEvenWhenItsRowsViolateIt(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // The premise of repairing an install that lost its keys is that rows
        // the constraint would have prevented are already there. InnoDB
        // validates existing rows when a constraint is added, so with FK checks
        // on it refuses exactly the keys most worth restoring (errno 1452) and
        // addMissingForeignKeys() only logs it — the constraint is lost.
        $before = Migrations::captureForeignKeys();
        $target = null;
        foreach ($before as $key) {
            if ($key['table'] === 'texttags' && count($key['columns']) === 1) {
                $target = $key;
                break;
            }
        }
        if ($target === null) {
            $this->markTestSkipped('No single-column texttags constraint to exercise');
        }

        Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
        Connection::preparedExecute(
            'INSERT IGNORE INTO texttags (TtTxID, TtT2ID) VALUES (?, ?)',
            [999123, 999123]
        );
        Connection::execute('SET FOREIGN_KEY_CHECKS = 1');

        try {
            Migrations::dropAllForeignKeys();
            Migrations::restoreForeignKeys($before);

            $names = array_map(
                static fn(array $k): string => $k['table'] . '.' . $k['name'],
                Migrations::captureForeignKeys()
            );
            $this->assertContains(
                $target['table'] . '.' . $target['name'],
                $names,
                'A constraint its own rows violate must still be restored'
            );
        } finally {
            Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
            Connection::preparedExecute(
                'DELETE FROM texttags WHERE TtTxID = ? AND TtT2ID = ?',
                [999123, 999123]
            );
            Connection::execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function testForeignKeysSurviveDropAndRestore(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $before = Migrations::captureForeignKeys();
        if ($before === []) {
            $this->markTestSkipped('Test database has no foreign keys to preserve');
        }

        // What update() does around a migration run: drop everything, then put
        // back whatever the run did not recreate. Losing keys here means every
        // upgrade silently strips the database of its cascade deletes and
        // orphan protection.
        Migrations::dropAllForeignKeys();
        $this->assertSame([], Migrations::captureForeignKeys(), 'FKs should be gone after the drop');

        Migrations::restoreForeignKeys($before);
        $after = Migrations::captureForeignKeys();

        $names = static fn(array $keys): array => array_map(
            static fn(array $key): string => $key['table'] . '.' . $key['name'],
            $keys
        );
        $expected = $names($before);
        $actual = $names($after);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, 'Every foreign key should be back');

        // The definition has to survive too, not just the name.
        $byName = [];
        foreach ($after as $key) {
            $byName[$key['table'] . '.' . $key['name']] = $key;
        }
        foreach ($before as $key) {
            $restored = $byName[$key['table'] . '.' . $key['name']];
            $this->assertSame($key['columns'], $restored['columns']);
            $this->assertSame($key['refTable'], $restored['refTable']);
            $this->assertSame($key['refColumns'], $restored['refColumns']);
            $this->assertSame($key['onDelete'], $restored['onDelete']);
        }
    }

    public function testReconcileAddsBackAConstraintTheDatabaseLost(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $declared = SchemaConstraints::FOREIGN_KEYS;
        $target = null;
        foreach ($declared as $key) {
            // Pick one the test database actually carries.
            foreach (Migrations::captureForeignKeys() as $present) {
                if ($present['name'] === $key['name'] && $present['table'] === $key['table']) {
                    $target = $key;
                    break 2;
                }
            }
        }
        if ($target === null) {
            $this->markTestSkipped('No declared constraint present to drop');
        }

        // Losing one is what every upgrade before 3.4.0 did; nothing replays
        // the migration that created it, so only the declared set can bring it
        // back (#273).
        Connection::execute(
            'ALTER TABLE `' . $target['table'] . '` DROP FOREIGN KEY `' . $target['name'] . '`'
        );
        $this->assertContains(
            $target['name'],
            array_column(Migrations::getMissingForeignKeys(), 'name'),
            'A dropped constraint should be reported as missing'
        );

        Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $added = Migrations::reconcileForeignKeys();
        } finally {
            Connection::execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->assertGreaterThanOrEqual(1, $added);
        $this->assertNotContains(
            $target['name'],
            array_column(Migrations::getMissingForeignKeys(), 'name'),
            'Reconciling should have recreated it'
        );

        $restored = null;
        foreach (Migrations::captureForeignKeys() as $key) {
            if ($key['name'] === $target['name']) {
                $restored = $key;
            }
        }
        $this->assertNotNull($restored);
        $this->assertSame($target['columns'], $restored['columns']);
        $this->assertSame($target['refTable'], $restored['refTable']);
        // InnoDB treats RESTRICT and NO ACTION as the same rule and reports it
        // back as NO ACTION, so compare the behaviour, not the spelling.
        $normalise = static fn(string $action): string
            => $action === 'NO ACTION' ? 'RESTRICT' : $action;
        $this->assertSame($normalise($target['onDelete']), $normalise($restored['onDelete']));
    }

    public function testReconcileIsANoOpWhenNothingIsMissing(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            Migrations::reconcileForeignKeys();
            $second = Migrations::reconcileForeignKeys();
        } finally {
            Connection::execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->assertSame(0, $second, 'A healthy database should need no repair');
    }

    public function testRestoringSkipsKeysWhoseColumnIsGone(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // A migration that renamed the table or column owns the new shape, so a
        // stale captured key must be skipped rather than fail the upgrade.
        $stale = [[
            'name' => 'fk_test_vanished',
            'table' => 'texts',
            'columns' => ['TxColumnThatNeverExisted'],
            'refTable' => 'languages',
            'refColumns' => ['LgID'],
            'onUpdate' => 'RESTRICT',
            'onDelete' => 'CASCADE',
        ]];

        $this->assertSame(0, Migrations::restoreForeignKeys($stale));
    }

    #[DataProvider('providerReferenceKeySuffixes')]
    public function testReferenceColumnsShareTheSameType(string $suffix): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // ALTER TABLE MODIFY is refused on a column a foreign key points at,
        // which is why update() drops them first. Do the same here, or the
        // alignment silently does nothing and the assertion below turns into a
        // test of whichever constraints happened to exist.
        $foreignKeys = Migrations::captureForeignKeys();
        Migrations::dropAllForeignKeys();
        try {
            Migrations::alignReferenceColumnTypes();
        } finally {
            Connection::execute('SET FOREIGN_KEY_CHECKS = 0');
            Migrations::restoreForeignKeys($foreignKeys);
            Connection::execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        $columns = Connection::preparedFetchAll(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE ?",
            [Globals::getDatabaseName(), '%' . $suffix]
        );

        $this->assertNotEmpty($columns, "Expected to find $suffix columns in the test schema");
        $expected = (string) $columns[0]['DATA_TYPE'];
        foreach ($columns as $column) {
            // A mismatch here makes any CREATE TABLE carrying a foreign key on
            // that column fail with errno 150, so the table is never created
            // (issue #247).
            $this->assertEqualsIgnoringCase(
                $expected,
                $column['DATA_TYPE'],
                "{$column['TABLE_NAME']}.{$column['COLUMN_NAME']} should match the $suffix key"
            );
        }
    }

    public static function providerReferenceKeySuffixes(): array
    {
        return [
            'languages' => ['LgID'],
            'texts' => ['TxID'],
            'words' => ['WoID'],
            'sentences' => ['SeID'],
            'users' => ['UsID'],
        ];
    }

    /**
     * A term whose word count was never computed is repaired after a run.
     *
     * Dictionary imports left every term they created at WoWordCount = 0
     * (issue #283), which is "not counted yet" rather than a count — matched
     * by neither the reader's WoWordCount = 1 lookup nor expression matching's
     * > 1. The import fixes its own rows now; this covers the installs that
     * already ran it.
     */
    public function testAMigrationRunComputesMissingWordCounts(): void
    {
        $method = new \ReflectionMethod(Migrations::class, 'computeMissingWordCounts');
        $this->assertTrue(
            $method->isPrivate(),
            'the repair is an internal step of the run, not a public entry point'
        );

        // It must survive a tokenizer that is not installed: an upgrade cannot
        // be allowed to fail because MeCab is missing from the machine.
        $source = file_get_contents(
            __DIR__ . '/../../../../src/Shared/Infrastructure/Database/Migrations.php'
        );
        $this->assertIsString($source);
        $body = substr($source, (int) strpos($source, 'function computeMissingWordCounts'));
        $this->assertStringContainsString('catch (\Throwable $e)', $body);
        $this->assertStringContainsString('Maintenance::initWordCount()', $body);
    }

    public function testMigrationsOnlyRunOnce(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Get currently applied migrations
        $appliedBefore = Migrations::getAppliedMigrations();

        // Run update again
        Migrations::update();

        // Applied migrations should be the same (no new runs)
        $appliedAfter = Migrations::getAppliedMigrations();

        $this->assertEquals(
            count($appliedBefore),
            count($appliedAfter),
            'Running update twice should not add new migration entries'
        );
    }
}
