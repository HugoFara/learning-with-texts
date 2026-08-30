<?php

/**
 * Unit tests for GetServerData use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Admin\Application\UseCases\ServerData
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Admin\Application\UseCases\ServerData;

use Lwt\Modules\Admin\Application\UseCases\ServerData\GetServerData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetServerData use case.
 *
 * Note: execute() requires a database connection for several calls.
 * These tests verify the class structure and helper methods via
 * reflection where the methods are private.
 *
 * @since 3.0.0
 */
class GetServerDataTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $useCase = new GetServerData();
        $this->assertInstanceOf(GetServerData::class, $useCase);
    }

    #[Test]
    public function parseApacheVersionExtractsVersion(): void
    {
        $useCase = new GetServerData();
        $method = new \ReflectionMethod($useCase, 'parseApacheVersion');

        $result = $method->invoke($useCase, 'Apache/2.4.52 (Ubuntu)');
        $this->assertSame('Apache/2.4.52', $result);
    }

    #[Test]
    public function parseApacheVersionHandlesVersionOnly(): void
    {
        $useCase = new GetServerData();
        $method = new \ReflectionMethod($useCase, 'parseApacheVersion');

        $result = $method->invoke($useCase, 'Apache/2.4.52');
        $this->assertSame('Apache/2.4.52', $result);
    }

    #[Test]
    public function parseApacheVersionReturnsQuestionMarkForNonApache(): void
    {
        $useCase = new GetServerData();
        $method = new \ReflectionMethod($useCase, 'parseApacheVersion');

        $result = $method->invoke($useCase, 'nginx/1.18.0');
        $this->assertSame('Apache/?', $result);
    }

    #[Test]
    public function parseApacheVersionHandlesEmptyString(): void
    {
        $useCase = new GetServerData();
        $method = new \ReflectionMethod($useCase, 'parseApacheVersion');

        $result = $method->invoke($useCase, '');
        $this->assertSame('Apache/?', $result);
    }

    #[Test]
    public function parseApacheVersionHandlesPhpBuiltInServer(): void
    {
        $useCase = new GetServerData();
        $method = new \ReflectionMethod($useCase, 'parseApacheVersion');

        $result = $method->invoke($useCase, 'PHP 8.2.0 Development Server');
        $this->assertSame('Apache/?', $result);
    }

    #[Test]
    public function parseApacheVersionHandlesMultipleModules(): void
    {
        $useCase = new GetServerData();
        $method = new \ReflectionMethod($useCase, 'parseApacheVersion');

        $result = $method->invoke(
            $useCase,
            'Apache/2.4.52 (Ubuntu) OpenSSL/1.1.1n PHP/8.1.2'
        );
        $this->assertSame('Apache/2.4.52', $result);
    }

    /**
     * Test that execute() returns expected array keys when DB is available.
     * This is an integration-style test that requires a database connection.
     */
    #[Test]
    public function executeReturnsExpectedKeysWhenDbAvailable(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database not available');
        }

        $useCase = new GetServerData();
        $result = $useCase->execute();

        $expectedKeys = [
            'db_name', 'db_size', 'server_soft', 'apache',
            'php', 'mysql', 'lwt_version', 'server_location'
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    #[Test]
    public function executeReturnsCorrectTypesWhenDbAvailable(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database not available');
        }

        $useCase = new GetServerData();
        $result = $useCase->execute();

        $this->assertIsString($result['db_name']);
        $this->assertIsFloat($result['db_size']);
        $this->assertIsString($result['server_soft']);
        $this->assertIsString($result['apache']);
        $this->assertIsString($result['mysql']);
        $this->assertIsString($result['lwt_version']);
        $this->assertIsString($result['server_location']);
    }

    #[Test]
    public function executeServerLocationUsesUrlUtilities(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database not available');
        }

        $useCase = new GetServerData();
        $result = $useCase->execute();

        // server_location should come from UrlUtilities::getAppOrigin()
        // which returns a protocol+host string, not just a hostname
        $this->assertIsString($result['server_location']);
        // Should not contain raw user input from HTTP_HOST
        $this->assertStringNotContainsString('<script>', $result['server_location']);
    }
}
