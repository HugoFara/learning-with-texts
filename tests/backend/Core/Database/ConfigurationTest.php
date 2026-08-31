<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database\Configuration class.
 *
 * Tests database configuration and connection utilities.
 */
class ConfigurationTest extends TestCase
{
    private static bool $dbConnected = false;
    private static array $dbConfig = [];

    public static function setUpBeforeClass(): void
    {
        self::$dbConnected = defined('LWT_TEST_DB_AVAILABLE') && LWT_TEST_DB_AVAILABLE;

        // Ensure EnvLoader has loaded the .env file (may have been reset by other tests)
        EnvLoader::load(__DIR__ . '/../../../../.env');
        self::$dbConfig = EnvLoader::getDatabaseConfig();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
    }

    // ===== loadFromEnv() tests =====

    public function testLoadFromEnvWithValidPath(): void
    {
        $envPath = __DIR__ . '/../../../../.env';

        if (!file_exists($envPath)) {
            $this->markTestSkipped('.env file not found');
        }

        $config = Configuration::loadFromEnv($envPath);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('server', $config);
        $this->assertArrayHasKey('userid', $config);
        $this->assertArrayHasKey('passwd', $config);
        $this->assertArrayHasKey('dbname', $config);
        $this->assertArrayHasKey('socket', $config);
    }

    public function testLoadFromEnvWithInvalidPath(): void
    {
        $config = Configuration::loadFromEnv('/nonexistent/path/.env');

        // Should return defaults
        $this->assertIsArray($config);
        $this->assertEquals('localhost', $config['server']);
        $this->assertEquals('root', $config['userid']);
        $this->assertEquals('', $config['passwd']);
        $this->assertEquals('learning-with-texts', $config['dbname']);
        $this->assertEquals('', $config['socket']);
    }

    public function testLoadFromEnvReturnsCompleteConfig(): void
    {
        $envPath = __DIR__ . '/../../../../.env';

        if (!file_exists($envPath)) {
            $this->markTestSkipped('.env file not found');
        }

        $config = Configuration::loadFromEnv($envPath);

        // All required keys should be present
        $requiredKeys = ['server', 'userid', 'passwd', 'dbname', 'socket'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $config, "Config should contain key: $key");
        }
    }

    // ===== connect() tests =====

    public function testConnectWithValidCredentials(): void
    {
        $testDbname = "test_" . self::$dbConfig['dbname'];
        $connection = Configuration::connect(
            self::$dbConfig['server'],
            self::$dbConfig['userid'],
            self::$dbConfig['passwd'],
            $testDbname,
            self::$dbConfig['socket'] ?? ''
        );

        $this->assertInstanceOf(\mysqli::class, $connection);
        $this->assertEquals(0, mysqli_connect_errno(), 'Should connect successfully');
    }

    public function testConnectReturnsWorkingConnection(): void
    {
        $testDbname = "test_" . self::$dbConfig['dbname'];
        $connection = Configuration::connect(
            self::$dbConfig['server'],
            self::$dbConfig['userid'],
            self::$dbConfig['passwd'],
            $testDbname,
            self::$dbConfig['socket'] ?? ''
        );

        // Test that connection can execute queries
        $result = mysqli_query($connection, "SELECT 1 as test");
        $this->assertNotFalse($result, 'Connection should be able to execute queries');

        if ($result instanceof \mysqli_result) {
            mysqli_free_result($result);
        }
    }

    public function testConnectSetsUtf8Charset(): void
    {
        $testDbname = "test_" . self::$dbConfig['dbname'];
        $connection = Configuration::connect(
            self::$dbConfig['server'],
            self::$dbConfig['userid'],
            self::$dbConfig['passwd'],
            $testDbname,
            self::$dbConfig['socket'] ?? ''
        );

        // SET NAMES updates server-side variables but not the client library charset,
        // so we check character_set_client instead of mysqli_character_set_name().
        $result = mysqli_query($connection, "SHOW VARIABLES LIKE 'character_set_client'");
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        $this->assertContains(
            $row['Value'],
            ['utf8', 'utf8mb3', 'utf8mb4'],
            'Connection should use UTF-8 charset'
        );
    }

    public function testConnectWithSocket(): void
    {
        // Test that socket parameter is accepted (even if empty)
        $testDbname = "test_" . self::$dbConfig['dbname'];
        $connection = Configuration::connect(
            self::$dbConfig['server'],
            self::$dbConfig['userid'],
            self::$dbConfig['passwd'],
            $testDbname,
            '' // Empty socket
        );

        $this->assertInstanceOf(\mysqli::class, $connection);
    }

    // ===== Integration tests =====

    public function testLoadFromEnvAndConnect(): void
    {
        $envPath = __DIR__ . '/../../../../.env';

        if (!file_exists($envPath)) {
            $this->markTestSkipped('.env file not found');
        }

        $config = Configuration::loadFromEnv($envPath);
        $testDbname = "test_" . $config['dbname'];

        $connection = Configuration::connect(
            $config['server'],
            $config['userid'],
            $config['passwd'],
            $testDbname,
            $config['socket']
        );

        $this->assertInstanceOf(\mysqli::class, $connection);
    }

    // ===== Edge cases =====

    public function testConnectSetsSqlMode(): void
    {
        $testDbname = "test_" . self::$dbConfig['dbname'];
        $connection = Configuration::connect(
            self::$dbConfig['server'],
            self::$dbConfig['userid'],
            self::$dbConfig['passwd'],
            $testDbname,
            self::$dbConfig['socket'] ?? ''
        );

        // Check SQL mode was set
        $result = mysqli_query($connection, "SELECT @@SESSION.sql_mode as mode");
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        // The function sets sql_mode to STRICT_ALL_TABLES
        $this->assertEquals('STRICT_ALL_TABLES', $row['mode'], 'SQL mode should be STRICT_ALL_TABLES');
    }
}
