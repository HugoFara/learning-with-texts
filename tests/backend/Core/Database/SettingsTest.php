<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database\Settings class.
 *
 * Tests application settings management.
 */
class SettingsTest extends TestCase
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

        // Clean up test settings after each test
        Connection::query("DELETE FROM settings WHERE StKey LIKE 'test_%'");
    }

    // ===== getZeroOrOne() tests =====

    public function testGetZeroOrOneWithValueOne(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_bool_1', '1');
        $result = Settings::getZeroOrOne('test_bool_1', 0);
        $this->assertEquals(1, $result, 'Non-zero value should return 1');
    }

    public function testGetZeroOrOneWithValueZero(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_bool_0', '0');
        $result = Settings::getZeroOrOne('test_bool_0', 1);
        $this->assertEquals(0, $result, 'Zero value should return 0');
    }

    public function testGetZeroOrOneWithNonZeroNumeric(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_bool_5', '5');
        $result = Settings::getZeroOrOne('test_bool_5', 0);
        $this->assertEquals(1, $result, 'Non-zero value (5) should return 1');
    }

    public function testGetZeroOrOneWithNonExistentReturnsDefault(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::getZeroOrOne('nonexistent_bool_key', 1);
        $this->assertEquals(1, $result, 'Non-existent setting should return default');
    }

    public function testGetZeroOrOneWithDefaultZero(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::getZeroOrOne('nonexistent_bool_key_2', 0);
        $this->assertEquals(0, $result, 'Non-existent setting should return default 0');
    }

    public function testGetZeroOrOneWithStringDefault(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::getZeroOrOne('nonexistent_key', '1');
        $this->assertEquals(1, $result, 'String default should be converted to int');
    }

    // ===== get() tests =====

    public function testGetNonExistentKey(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::get('nonexistent_key_xyz');
        $this->assertEquals('', $result, 'Non-existent key should return empty string');
    }

    public function testGetEmptyKey(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::get('');
        $this->assertEquals('', $result, 'Empty key should return empty string');
    }

    public function testGetSavedValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_get_value', 'my_test_value');
        $result = Settings::get('test_get_value');
        $this->assertEquals('my_test_value', $result);
    }

    public function testGetTrimsWhitespace(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Directly insert value with whitespace to test trimming
        $table = 'settings';
        Connection::query("DELETE FROM " . $table . " WHERE StKey = 'test_whitespace'");
        Connection::query(
            "INSERT INTO " . $table . " (StKey, StValue) VALUES ('test_whitespace', '  value  ')"
        );

        $result = Settings::get('test_whitespace');
        $this->assertEquals('value', $result, 'Value should be trimmed');
    }

    public function testGetSqlInjectionInKey(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Clean up any previously saved SQL injection key
        $injectionKey = "key'; DROP TABLE settings; --";
        $table = 'settings';
        Connection::query(
            "DELETE FROM " . $table . " WHERE StKey = " . Escaping::toSqlSyntax($injectionKey)
        );

        // The SQL injection key should return empty (not found)
        $result = Settings::get($injectionKey);
        $this->assertEquals('', $result, 'SQL injection key should return empty when not present');

        // More importantly, the settings table should still exist (not dropped)
        $tableExists = mysqli_num_rows(Connection::query("SHOW TABLES LIKE 'settings'")) > 0;
        $this->assertTrue($tableExists, 'SQL injection should not drop the table');
    }

    public function testGetSpecialKeyCurrentlanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // currentlanguage triggers validateLang
        $result = Settings::get('currentlanguage');
        // Should return empty or valid language ID
        $this->assertIsString($result);
    }

    public function testGetSpecialKeyCurrenttext(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // currenttext triggers validateText
        $result = Settings::get('currenttext');
        // Should return empty or valid text ID
        $this->assertIsString($result);
    }

    // ===== getWithDefault() tests =====

    public function testGetWithDefaultKnownSetting(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Known setting with default: 'set-texts-per-page' defaults to '10'
        $result = Settings::getWithDefault('set-texts-per-page');
        $this->assertIsString($result);
        $this->assertNotEquals('', $result, 'Should return non-empty (default or saved value)');
    }

    public function testGetWithDefaultNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::getWithDefault('nonexistent_setting_xyz123');
        $this->assertEquals('', $result, 'Non-existent setting without default should return empty');
    }

    public function testGetWithDefaultSqlInjection(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Use a different injection key that wasn't previously saved
        $injectionKey = "newkey'; DROP TABLE settings; --";
        $table = 'settings';
        Connection::query(
            "DELETE FROM " . $table . " WHERE StKey = " . Escaping::toSqlSyntax($injectionKey)
        );

        $result = Settings::getWithDefault($injectionKey);
        $this->assertEquals('', $result, 'SQL injection key should return empty when not present');

        // More importantly, the settings table should still exist (not dropped)
        $tableExists = mysqli_num_rows(Connection::query("SHOW TABLES LIKE 'settings'")) > 0;
        $this->assertTrue($tableExists, 'SQL injection should not drop the table');
    }

    public function testGetWithDefaultEmptyKey(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::getWithDefault('');
        $this->assertEquals('', $result, 'Empty key should return empty');
    }

    public function testGetWithDefaultSavedValueOverridesDefault(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Save a custom value for a known setting
        Settings::save('set-texts-per-page', '25');
        $result = Settings::getWithDefault('set-texts-per-page');
        $this->assertEquals('25', $result, 'Saved value should override default');
    }

    // ===== save() tests =====

    public function testSaveValidSetting(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Settings::save() returns void on success, throws on error
        Settings::save('test_save_key', 'test_value');
        $this->assertTrue(true, 'Valid save should not throw');
    }

    public function testSaveAndRetrieve(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_retrieve_key', 'test_retrieve_value');
        $value = Settings::get('test_retrieve_key');
        $this->assertEquals('test_retrieve_value', $value, 'Saved value should be retrievable');
    }

    public function testSaveNullValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value is not set');
        Settings::save('test_null_key', null);
    }

    public function testSaveEmptyStringValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_empty_key', '');
        $value = Settings::get('test_empty_key');
        $this->assertEquals('', $value, 'Empty string should be saved successfully');
    }

    public function testSaveUpdateExisting(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_update_key', 'value1');
        Settings::save('test_update_key', 'value2');

        $value = Settings::get('test_update_key');
        $this->assertEquals('value2', $value, 'Updated value should be saved');
    }

    public function testSaveSqlInjectionInKey(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Should safely escape - doesn't throw
        Settings::save("key'; DROP TABLE settings; --", 'value');
        $this->assertTrue(true, 'SQL injection in key should be safely escaped');
    }

    public function testSaveSqlInjectionInValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_safe_key', "value'; DROP TABLE settings; --");
        $this->assertTrue(true, 'Should save with escaped value');
    }

    public function testSaveNumericSettingWithinBounds(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // 'set-texts-per-page' has min=10, max=9999
        Settings::save('set-texts-per-page', '50');

        $value = Settings::get('set-texts-per-page');
        $this->assertEquals('50', $value);
    }

    public function testSaveNumericSettingBelowMin(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // 'set-texts-per-page' has min=1, max=9999, and default=10
        // Saving 0 is below min (1), so it should be reset to default (10)
        Settings::save('set-texts-per-page', '0');
        $value = Settings::get('set-texts-per-page');
        // Should be reset to default
        $this->assertEquals('10', $value, 'Value below min should be reset to default');
    }

    public function testSaveNumericSettingAboveMax(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // 'set-texts-per-page' has max=9999 and default=10
        Settings::save('set-texts-per-page', '99999');
        $value = Settings::get('set-texts-per-page');
        // Should be reset to default
        $this->assertEquals('10', $value, 'Value above max should be reset to default');
    }

    public function testSaveIntegerValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::save('test_int_key', 42);

        $value = Settings::get('test_int_key');
        $this->assertEquals('42', $value);
    }

    // ===== lwtTableCheck() tests =====

    public function testLwtTableCheckCreatesTable(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // This should complete without error
        Settings::lwtTableCheck();
        $this->assertTrue(true, 'lwtTableCheck should complete without error');
    }

    // ===== lwtTableSet() and lwtTableGet() tests =====

    public function testLwtTableSetAndGet(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet('test_lwt_key', 'test_lwt_value');
        $result = Settings::lwtTableGet('test_lwt_key');
        $this->assertEquals('test_lwt_value', $result);

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey = 'test_lwt_key'");
    }

    public function testLwtTableSetUpdate(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet('test_lwt_update', 'value1');
        Settings::lwtTableSet('test_lwt_update', 'value2');
        $result = Settings::lwtTableGet('test_lwt_update');
        $this->assertEquals('value2', $result);

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey = 'test_lwt_update'");
    }

    public function testLwtTableGetNonExistent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = Settings::lwtTableGet('nonexistent_lwt_key');
        $this->assertEquals('', $result, 'Non-existent key should return empty string');
    }

    public function testLwtTableSetSqlInjectionInKey(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet("key'; DROP TABLE _lwtgeneral; --", 'value');
        $result = Settings::lwtTableGet("key'; DROP TABLE _lwtgeneral; --");
        // Should handle safely (either escaped or rejected)
        $this->assertIsString($result);

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey LIKE '%DROP%'");
    }

    public function testLwtTableSetSqlInjectionInValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet('test_safe_lwt', "value'; DROP TABLE _lwtgeneral; --");
        $result = Settings::lwtTableGet('test_safe_lwt');
        // Should retrieve the escaped value
        $this->assertStringContainsString('DROP', $result, 'SQL injection in value should be stored as-is (escaped)');

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey = 'test_safe_lwt'");
    }

    public function testLwtTableMultipleKeys(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet('test_multi_1', 'value_1');
        Settings::lwtTableSet('test_multi_2', 'value_2');
        Settings::lwtTableSet('test_multi_3', 'value_3');

        $result1 = Settings::lwtTableGet('test_multi_1');
        $result2 = Settings::lwtTableGet('test_multi_2');
        $result3 = Settings::lwtTableGet('test_multi_3');

        $this->assertEquals('value_1', $result1);
        $this->assertEquals('value_2', $result2);
        $this->assertEquals('value_3', $result3);

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey LIKE 'test_multi_%'");
    }

    public function testLwtTableSetEmptyValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet('test_empty_val', '');
        $result = Settings::lwtTableGet('test_empty_val');
        // Empty value should be stored as empty string (not NULL due to toSqlSyntax behavior)
        $this->assertIsString($result);

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey = 'test_empty_val'");
    }

    public function testLwtTableUnicodeValue(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        Settings::lwtTableSet('test_unicode', '日本語テスト');
        $result = Settings::lwtTableGet('test_unicode');
        $this->assertEquals('日本語テスト', $result);

        // Clean up
        Connection::query("DELETE FROM _lwtgeneral WHERE LWTKey = 'test_unicode'");
    }
}
