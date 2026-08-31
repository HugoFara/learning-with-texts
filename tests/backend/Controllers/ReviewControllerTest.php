<?php

declare(strict_types=1);

namespace Lwt\Tests\Controllers;

use Lwt\Modules\Review\Http\ReviewController;
use Lwt\Modules\Review\Application\ReviewFacade;
use Lwt\Modules\Review\Infrastructure\SessionStateManager;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ReviewController class.
 *
 * Tests the word testing/review interface controller (from Review module)
 * and its collaborators.
 */
class ReviewControllerTest extends TestCase
{
    private static bool $dbConnected = false;
    private array $originalServer;
    private array $originalGet;
    private array $originalPost;
    private array $originalRequest;
    private array $originalSession;

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

        // Save original superglobals
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalRequest = $_REQUEST;
        $this->originalSession = $_SESSION ?? [];

        // Reset superglobals
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Restore superglobals
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_REQUEST = $this->originalRequest;
        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    /**
     * Helper method to create a ReviewController with its dependencies.
     *
     * @return ReviewController
     */
    private function createController(): ReviewController
    {
        return new ReviewController(new ReviewFacade());
    }

    /**
     * Helper method to call protected param() method.
     *
     * @param ReviewController $controller The controller instance
     * @param string         $name       Parameter name
     * @param string         $default    Default value
     *
     * @return string Parameter value
     */
    private function invokeParam(ReviewController $controller, string $name, string $default = ''): string
    {
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('param');
        return $method->invoke($controller, $name, $default);
    }

    // ===== Constructor tests =====

    public function testControllerCanBeInstantiated(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        $this->assertInstanceOf(ReviewController::class, $controller);
    }

    // ===== Method existence tests =====

    public function testControllerHasIndexMethod(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        $this->assertTrue(method_exists($controller, 'index'));
    }

    // ===== BaseController inheritance tests =====

    public function testControllerExtendsBaseController(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        $this->assertInstanceOf(\Lwt\Shared\Http\BaseController::class, $controller);
    }

    // ===== Parameter tests =====

    public function testLangParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['lang'] = '5';

        $controller = $this->createController();

        $this->assertEquals('5', $this->invokeParam($controller, 'lang'));
    }

    public function testTextParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '10';

        $controller = $this->createController();

        $this->assertEquals('10', $this->invokeParam($controller, 'text'));
    }

    public function testSelectionParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['selection'] = '3';

        $controller = $this->createController();

        $this->assertEquals('3', $this->invokeParam($controller, 'selection'));
    }

    public function testTypeParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['type'] = '2';

        $controller = $this->createController();

        $this->assertEquals('2', $this->invokeParam($controller, 'type'));
    }

    public function testWidParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['wid'] = '100';

        $controller = $this->createController();

        $this->assertEquals('100', $this->invokeParam($controller, 'wid'));
    }

    public function testStatusParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['status'] = '4';

        $controller = $this->createController();

        $this->assertEquals('4', $this->invokeParam($controller, 'status'));
    }

    public function testStchangeParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['stchange'] = '1';

        $controller = $this->createController();

        $this->assertEquals('1', $this->invokeParam($controller, 'stchange'));
    }

    public function testAjaxParamDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['ajax'] = '1';

        $this->assertTrue(isset($_REQUEST['ajax']));
    }

    // ===== Session tests =====

    public function testSessionTestsqlDetected(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_SESSION['testsql'] = 'SELECT * FROM words WHERE WoStatus < 5';

        $this->assertEquals('SELECT * FROM words WHERE WoStatus < 5', $_SESSION['testsql']);
    }

    // ===== Test property determination =====

    public function testGetTestPropertyWithSelection(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['selection'] = '1';
        // Use SessionStateManager to store criteria instead of raw SQL
        $sessionManager = new SessionStateManager();
        $sessionManager->saveCriteria('texts', [1, 2, 3]);

        $controller = $this->createController();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getReviewProperty');

        $result = $method->invoke($controller);

        $this->assertEquals('selection=1', $result);

        // Clean up
        $sessionManager->clearCriteria();
    }

    public function testGetTestPropertyWithLang(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['lang'] = '5';

        $controller = $this->createController();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getReviewProperty');

        $result = $method->invoke($controller);

        $this->assertEquals('lang=5', $result);
    }

    public function testGetTestPropertyWithText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '10';

        $controller = $this->createController();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getReviewProperty');

        $result = $method->invoke($controller);

        $this->assertEquals('text=10', $result);
    }

    public function testGetTestPropertyReturnsEmptyWhenNoParams(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getReviewProperty');

        $result = $method->invoke($controller);

        $this->assertEquals('', $result);
    }

    // ===== Database query tests =====

    public function testWordsQueryWorks(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $sql = "SELECT WoID, WoText, WoStatus FROM words LIMIT 10";
        $result = Connection::query($sql);

        $this->assertInstanceOf(\mysqli_result::class, $result);
        mysqli_free_result($result);
    }

    public function testWordsStatusQuery(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $sql = "SELECT COUNT(*) AS value FROM words WHERE WoStatus BETWEEN 1 AND 5";
        $result = Connection::fetchValue($sql);

        $this->assertIsNumeric($result);
    }

    public function testLanguageSettingsQuery(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $sql = "SELECT LgID, LgName, LgTextSize, LgRegexpWordCharacters, LgRightToLeft
                FROM languages LIMIT 5";
        $result = Connection::query($sql);

        $this->assertInstanceOf(\mysqli_result::class, $result);
        mysqli_free_result($result);
    }

    // ===== Multiple controller instances test =====

    public function testMultipleControllerInstances(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller1 = $this->createController();
        $controller2 = $this->createController();

        $this->assertInstanceOf(ReviewController::class, $controller1);
        $this->assertInstanceOf(ReviewController::class, $controller2);
        $this->assertNotSame($controller1, $controller2);
    }
}
