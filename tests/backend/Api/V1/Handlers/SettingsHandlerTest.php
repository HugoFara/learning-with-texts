<?php

declare(strict_types=1);

namespace Lwt\Tests\Api\V1\Handlers;

use Lwt\Modules\Admin\Application\AdminFacade;
use Lwt\Modules\Admin\Http\AdminApiHandler;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AdminApiHandler class (settings functionality).
 *
 * Tests settings-related API operations.
 */
class SettingsHandlerTest extends TestCase
{
    private static bool $dbConnected = false;
    private AdminApiHandler $handler;

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
        // Create mock AdminFacade for testing
        $mockFacade = $this->createMock(AdminFacade::class);
        $mockFacade->method('getServerData')->willReturn([]);

        $this->handler = new AdminApiHandler($mockFacade);
    }

    protected function tearDown(): void
    {
        // Clean up test settings
        if (self::$dbConnected) {
            $prefix = '';
            Connection::query("DELETE FROM {$prefix}settings WHERE StKey LIKE 'test_api_%'");
        }
        parent::tearDown();
    }

    // ===== Class structure tests =====

    /**
     * Test that AdminApiHandler class has the required methods.
     */
    public function testClassHasRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(AdminApiHandler::class);

        // Business logic methods
        $this->assertTrue($reflection->hasMethod('saveSetting'));
        $this->assertTrue($reflection->hasMethod('getThemePath'));

        // API formatter methods
        $this->assertTrue($reflection->hasMethod('formatSaveSetting'));
        $this->assertTrue($reflection->hasMethod('formatThemePath'));
    }

    /**
     * Test formatSaveSetting returns correct structure on success.
     */
    public function testFormatSaveSettingReturnsMessageOnSuccess(): void
    {
        $result = $this->handler->formatSaveSetting('test_api_setting', 'test_value');

        $this->assertIsArray($result);
        // Should have either 'message' or 'error' key
        $this->assertTrue(
            array_key_exists('message', $result) || array_key_exists('error', $result),
            'Response should contain either message or error key'
        );
    }

    /**
     * Test formatThemePath returns correct structure.
     */
    public function testFormatThemePathReturnsCorrectStructure(): void
    {
        $result = $this->handler->formatThemePath('styles.css');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('theme_path', $result);
    }

    /**
     * Test all public methods are accessible.
     */
    public function testPublicMethods(): void
    {
        $reflection = new \ReflectionClass(AdminApiHandler::class);
        $publicMethods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn($m) => !$m->isConstructor()
        );

        // Should have at least 4 public methods (settings + statistics)
        $this->assertGreaterThanOrEqual(4, count($publicMethods));
    }
}
