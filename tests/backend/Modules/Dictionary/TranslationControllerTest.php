<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary;

use Lwt\Modules\Dictionary\Http\TranslationController;
use Lwt\Modules\Dictionary\Application\TranslationService;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TranslationController class.
 *
 * Tests controller initialization, service integration,
 * and verifies the MVC pattern implementation for translation APIs.
 */
class TranslationControllerTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $testLangId = 0;
    private array $originalRequest;
    private array $originalServer;
    private array $originalGet;
    private array $originalPost;

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

        if (self::$dbConnected) {
            self::setupTestData();
        }
    }

    private static function setupTestData(): void
    {
        // Reset auto_increment to prevent overflow (LgID is tinyint max 255)
        $maxId = Connection::fetchValue("SELECT COALESCE(MAX(LgID), 0) AS value FROM " . 'languages');
        Connection::query("ALTER TABLE languages AUTO_INCREMENT = " . ((int)$maxId + 1));

        // Create a test language
        $existingLang = Connection::fetchValue(
            "SELECT LgID AS value FROM languages" .
            " WHERE LgName = 'TranslationControllerTestLang' LIMIT 1"
        );

        if ($existingLang) {
            self::$testLangId = (int)$existingLang;
        } else {
            $table = 'languages';
            Connection::query(
                "INSERT INTO " . $table . " (LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI, " .
                "LgTextSize, LgCharacterSubstitutions, LgRegexpSplitSentences, " .
                "LgExceptionsSplitSentences, LgRegexpWordCharacters, LgRemoveSpaces, " .
                "LgSplitEachChar, LgRightToLeft, LgShowRomanization) " .
                "VALUES ('TranslationControllerTestLang', 'http://dict1.test/lwt_term', " .
                "'http://dict2.test/###', 'ggl.php?text=lwt_term&sl=es&tl=en', " .
                "100, '', '.!?', '', 'a-zA-Z', 0, 0, 0, 1)"
            );
            self::$testLangId = (int)Connection::fetchValue(
                "SELECT LAST_INSERT_ID() AS value"
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test data
        $wordsTable = 'words';
        Connection::query("DELETE FROM " . $wordsTable . " WHERE WoLgID = " . self::$testLangId);
        $langTable = 'languages';
        Connection::query(
            "DELETE FROM " . $langTable . " WHERE LgName = 'TranslationControllerTestLang'"
        );

        // Reset auto_increment to prevent overflow (LgID is tinyint max 255)
        $maxId = Connection::fetchValue("SELECT COALESCE(MAX(LgID), 0) AS value FROM " . 'languages');
        Connection::query("ALTER TABLE languages AUTO_INCREMENT = " . ((int)$maxId + 1));
    }

    protected function setUp(): void
    {
        // Save original superglobals
        $this->originalRequest = $_REQUEST;
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;

        // Reset superglobals
        $_REQUEST = [];
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        // Restore superglobals
        $_REQUEST = $this->originalRequest;
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
    }

    // ===== Constructor tests =====

    public function testControllerCanBeInstantiated(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $this->assertInstanceOf(TranslationController::class, $controller);
    }

    public function testControllerHasTranslationService(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());
        $service = $controller->getTranslationService();

        $this->assertInstanceOf(TranslationService::class, $service);
    }

    // ===== Method existence tests =====

    public function testControllerHasRequiredMethods(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $this->assertTrue(method_exists($controller, 'google'));
        $this->assertTrue(method_exists($controller, 'glosbe'));
        $this->assertTrue(method_exists($controller, 'translate'));
        $this->assertTrue(method_exists($controller, 'getTranslationService'));
    }

    // ===== google() action tests =====

    public function testGoogleActionReturnsEarlyWithoutTextParam(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        // Without 'text' GET parameter, should return early
        $_GET = [];

        ob_start();
        $controller->google([]);
        $output = ob_get_clean();

        // Should produce no output (early return)
        $this->assertEmpty($output);
    }

    public function testGoogleActionWithEmptyTextShowsMessage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_GET = ['text' => ''];

        ob_start();
        $controller->google([]);
        $output = ob_get_clean();

        // Should show "Term is not set!" message
        $this->assertStringContainsString('Term is not set!', $output);
    }

    public function testGoogleActionWithWhitespaceOnlyTextShowsMessage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_GET = ['text' => '   '];

        ob_start();
        $controller->google([]);
        $output = ob_get_clean();

        // Should show "Term is not set!" message
        $this->assertStringContainsString('Term is not set!', $output);
    }

    // ===== glosbe() action tests =====

    public function testGlosbeActionWithValidParams(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'from' => 'en',
            'dest' => 'es',
            'phrase' => 'hello'
        ];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Should contain Glosbe dictionary link
        $this->assertStringContainsString('glosbe.com', $output);
        $this->assertStringContainsString('hello', $output);
    }

    public function testGlosbeActionWithEmptyLanguagesShowsError(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'from' => '',
            'dest' => '',
            'phrase' => 'hello'
        ];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Should contain error state in JSON config for JavaScript handling
        $this->assertStringContainsString('"error":"api_error"', $output);
    }

    public function testGlosbeActionWithEmptyPhraseShowsMessage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'from' => 'en',
            'dest' => 'es',
            'phrase' => ''
        ];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Should contain error state in JSON config for JavaScript handling
        $this->assertStringContainsString('"error":"empty_term"', $output);
    }

    public function testGlosbeActionWithMissingParams(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Should still render a page (with error state)
        $this->assertNotEmpty($output);
    }

    // ===== translate() action tests =====

    public function testTranslateActionReturnsEarlyWithoutXParam(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [];

        ob_start();
        $controller->translate([]);
        $output = ob_get_clean();

        // Should produce no output (early return)
        $this->assertEmpty($output);
    }

    public function testTranslateActionReturnsEarlyWithNonNumericX(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = ['x' => 'invalid'];

        ob_start();
        $controller->translate([]);
        $output = ob_get_clean();

        // Should produce no output (early return)
        $this->assertEmpty($output);
    }

    public function testTranslateActionWithInvalidTypeReturnsEarly(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'x' => '3', // Invalid type (not 1 or 2)
            't' => '1',
            'i' => '1'
        ];

        ob_start();
        $controller->translate([]);
        $output = ob_get_clean();

        // Invalid type should produce no output
        $this->assertEmpty($output);
    }

    // ===== Service integration tests =====

    public function testServiceMethodsAccessibleThroughController(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());
        $service = $controller->getTranslationService();

        // Test that service methods work correctly
        $url = $service->buildGlosbeUrl('test', 'en', 'es');
        $this->assertEquals('https://glosbe.com/en/es/test', $url);

        $validation = $service->validateGlosbeParams('en', 'es', 'hello');
        $this->assertTrue($validation['valid']);

        $googleUrl = $service->buildGoogleTranslateUrl('hello', 'en', 'es');
        $this->assertStringContainsString('translate.google.com', $googleUrl);
    }

    public function testServiceValidationThroughController(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());
        $service = $controller->getTranslationService();

        // Test empty language validation
        $result = $service->validateGlosbeParams('', 'es', 'hello');
        $this->assertFalse($result['valid']);
        $this->assertEquals('Language codes are required', $result['error']);

        // Test empty phrase validation
        $result = $service->validateGlosbeParams('en', 'es', '');
        $this->assertFalse($result['valid']);
        $this->assertEquals('Term is not set', $result['error']);
    }

    // ===== Output structure tests =====

    public function testGlosbeOutputContainsExpectedElements(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'from' => 'en',
            'dest' => 'es',
            'phrase' => 'test'
        ];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Check for expected HTML elements
        $this->assertStringContainsString('glosbe.com', $output);
        $this->assertStringContainsString('form', $output);
        $this->assertStringContainsString('Translate via Glosbe', $output);
        $this->assertStringContainsString('del_translation', $output);
        $this->assertStringContainsString('translations', $output);
    }

    public function testGoogleOutputContainsExpectedElementsWhenTextEmpty(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_GET = ['text' => ''];

        ob_start();
        $controller->google([]);
        $output = ob_get_clean();

        // Should contain the "term not set" notification class
        $this->assertStringContainsString('notification is-warning', $output);
    }

    // ===== Edge case tests =====

    public function testGlosbeWithSpecialCharactersInPhrase(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'from' => 'de',
            'dest' => 'en',
            'phrase' => 'über'
        ];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Should handle special characters
        $this->assertStringContainsString('glosbe.com', $output);
    }

    public function testGlosbeWithUnicodePhrase(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'from' => 'ja',
            'dest' => 'en',
            'phrase' => 'こんにちは'
        ];

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        // Should handle Unicode characters
        $this->assertNotEmpty($output);
    }

    public function testControllerInheritsFromBaseController(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        // TranslationController should have BaseController methods
        $this->assertTrue(method_exists($controller, 'param'));
        $this->assertTrue(method_exists($controller, 'get'));
        $this->assertTrue(method_exists($controller, 'redirect'));
    }

    // ===== Parameter handling tests =====

    public function testGlosbeUsesRequestParameter(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        // Test with POST data in REQUEST
        $_REQUEST = [
            'from' => 'fr',
            'dest' => 'de',
            'phrase' => 'bonjour'
        ];
        $_POST = $_REQUEST;

        ob_start();
        $controller->glosbe([]);
        $output = ob_get_clean();

        $this->assertStringContainsString('bonjour', $output);
        $this->assertStringContainsString('fr', $output);
        $this->assertStringContainsString('de', $output);
    }

    public function testTranslateWithZeroParams(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TranslationController(new TranslationService());

        $_REQUEST = [
            'x' => '1',
            't' => '0',
            'i' => '0'
        ];

        ob_start();
        $controller->translate([]);
        $output = ob_get_clean();

        // With zero text/order, should produce no redirect (invalid query)
        $this->assertEmpty($output);
    }
}
