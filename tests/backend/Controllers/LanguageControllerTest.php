<?php

declare(strict_types=1);

namespace Lwt\Tests\Controllers;

use Lwt\Modules\Language\Http\LanguageController;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Dictionary\Application\DictionaryFacade;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

// LanguageController loaded via autoloader

/**
 * Unit tests for the LanguageController class.
 *
 * Tests controller initialization and service integration.
 * Note: Full integration tests for output rendering should use E2E tests.
 */
class LanguageControllerTest extends TestCase
{
    private static bool $dbConnected = false;
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
            // Reset auto_increment to prevent overflow (LgID is tinyint max 255)
            $languagesTable = 'languages';
            $maxId = Connection::fetchValue("SELECT COALESCE(MAX(LgID), 0) AS value FROM {$languagesTable}");
            Connection::query("ALTER TABLE {$languagesTable} AUTO_INCREMENT = " . ((int)$maxId + 1));
        }
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

        if (!self::$dbConnected) {
            return;
        }

        // Clean up test languages
        $languagesTable = 'languages';
        Connection::query("DELETE FROM {$languagesTable} WHERE LgName LIKE 'Test_%'");
        Connection::query("DELETE FROM {$languagesTable} WHERE LgName LIKE 'TestLang%'");

        // Reset auto_increment to prevent overflow (LgID is tinyint max 255)
        $maxId = Connection::fetchValue("SELECT COALESCE(MAX(LgID), 0) AS value FROM {$languagesTable}");
        Connection::query("ALTER TABLE {$languagesTable} AUTO_INCREMENT = " . ((int)$maxId + 1));
    }

    /**
     * Helper to create a test language directly in the database.
     *
     * @param string $name Language name
     *
     * @return int The created language ID
     */
    private function createTestLanguage(string $name): int
    {
        $languagesTable = 'languages';
        Connection::query(
            "INSERT INTO {$languagesTable} (
                LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization
            ) VALUES (
                '$name', 'https://dict.test/lwt_term', '', 'https://translate.test/lwt_term',
                100, '.!?', 'a-zA-Z',
                0, 0, 0, 1
            )"
        );
        return (int) mysqli_insert_id(Globals::getDbConnection());
    }

    /**
     * Helper method to create a LanguageController with its dependencies.
     *
     * @return LanguageController
     */
    private function createController(): LanguageController
    {
        $dictFacade = $this->createMock(DictionaryFacade::class);
        return new LanguageController(new LanguageFacade(), $dictFacade);
    }

    // ===== Constructor tests =====

    public function testControllerCanBeInstantiated(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        $this->assertInstanceOf(LanguageController::class, $controller);
    }

    public function testControllerHasLanguageFacade(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        // Use reflection to check private property
        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('languageFacade');
        $service = $property->getValue($controller);

        $this->assertInstanceOf(LanguageFacade::class, $service);
    }

    // ===== Route parameter tests =====

    public function testIndexMethodExists(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        $this->assertTrue(method_exists($controller, 'index'));
    }

    // ===== Service delegation tests =====

    public function testControllerUsesLanguageFacadeForGetAll(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Create a test language to ensure there's data
        $this->createTestLanguage('TestLang_ServiceCheck');

        $controller = $this->createController();

        // Get the service from the controller
        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('languageFacade');
        $service = $property->getValue($controller);

        $languages = $service->getAllLanguages();

        $this->assertArrayHasKey('TestLang_ServiceCheck', $languages);
    }

    // ===== Request handling tests =====

    public function testIndexAcceptsArrayParams(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = $this->createController();

        // Test that index() accepts an array parameter
        $reflection = new \ReflectionMethod($controller, 'index');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('params', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());
    }

    // ===== Service method delegation tests =====

    public function testServiceCreateMethodIsCalled(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new LanguageFacade();

        $_REQUEST = [
            'LgName' => 'TestLang_ControllerCreate',
            'LgDict1URI' => 'https://dict.test/lwt_term',
            'LgDict2URI' => '',
            'LgGoogleTranslateURI' => '',
            'LgExportTemplate' => '',
            'LgTextSize' => '100',
            'LgCharacterSubstitutions' => '',
            'LgRegexpSplitSentences' => '.!?',
            'LgExceptionsSplitSentences' => '',
            'LgRegexpWordCharacters' => 'a-zA-Z',
            'LgTTSVoiceAPI' => '',
        ];

        $result = $service->create();

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('TestLang_ControllerCreate', $service->getAllLanguages());
    }

    public function testServiceUpdateMethodIsCalled(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguage('TestLang_ControllerUpdate');
        $service = new LanguageFacade();

        $_REQUEST = [
            'LgName' => 'TestLang_ControllerUpdated',
            'LgDict1URI' => 'https://newdict.test/lwt_term',
            'LgDict2URI' => '',
            'LgGoogleTranslateURI' => '',
            'LgExportTemplate' => '',
            'LgTextSize' => '150',
            'LgCharacterSubstitutions' => '',
            'LgRegexpSplitSentences' => '.!?',
            'LgExceptionsSplitSentences' => '',
            'LgRegexpWordCharacters' => 'a-zA-Z',
            'LgTTSVoiceAPI' => '',
        ];

        $result = $service->update($id);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $lang = $service->getById($id);
        $this->assertEquals('TestLang_ControllerUpdated', $lang->name());
    }

    public function testServiceDeleteMethodIsCalled(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguage('TestLang_ControllerDelete');

        // Clean up any related data that might exist for this language
        Connection::query("DELETE FROM texts WHERE TxLgID = $id");
        Connection::query("DELETE FROM words WHERE WoLgID = $id");
        Connection::query("DELETE FROM news_feeds WHERE NfLgID = $id");

        $service = new LanguageFacade();

        $result = $service->delete($id);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertFalse($service->exists($id));
    }

    public function testServiceRefreshMethodIsCalled(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguage('TestLang_ControllerRefresh');
        $service = new LanguageFacade();

        $result = $service->refresh($id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('sentencesDeleted', $result);
        $this->assertArrayHasKey('textItemsDeleted', $result);
    }

    // ===== Integration tests with service =====

    public function testControllerServiceGetByIdWorks(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $id = $this->createTestLanguage('TestLang_ControllerGetById');

        $controller = $this->createController();
        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('languageFacade');
        $service = $property->getValue($controller);

        $lang = $service->getById($id);

        $this->assertEquals('TestLang_ControllerGetById', $lang->name());
        $this->assertEquals($id, $lang->id()->toInt());
    }

    public function testControllerServiceGetLanguagesWithStatsWorks(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->createTestLanguage('TestLang_ControllerStats');

        $controller = $this->createController();
        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('languageFacade');
        $service = $property->getValue($controller);

        $stats = $service->getLanguagesWithStats();

        $found = false;
        foreach ($stats as $lang) {
            if ($lang['name'] === 'TestLang_ControllerStats') {
                $found = true;
                $this->assertArrayHasKey('textCount', $lang);
                $this->assertArrayHasKey('wordCount', $lang);
                break;
            }
        }

        $this->assertTrue($found, 'Test language should be in stats');
    }

    // ===== Edge case tests =====

    public function testServiceRejectsEmptyLanguageName(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new LanguageFacade();

        // Empty name should throw an exception (database constraint)
        $_REQUEST = [
            'LgName' => '',
            'LgDict1URI' => 'https://dict.test/lwt_term',
            'LgDict2URI' => '',
            'LgGoogleTranslateURI' => '',
            'LgExportTemplate' => '',
            'LgTextSize' => '100',
            'LgCharacterSubstitutions' => '',
            'LgRegexpSplitSentences' => '.!?',
            'LgExceptionsSplitSentences' => '',
            'LgRegexpWordCharacters' => 'a-zA-Z',
            'LgTTSVoiceAPI' => '',
        ];

        $this->expectException(\RuntimeException::class);
        $service->create();
    }

    public function testServiceHandlesSpecialCharactersInName(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new LanguageFacade();

        $_REQUEST = [
            'LgName' => "TestLang_Special'Chars\"",
            'LgDict1URI' => 'https://dict.test/lwt_term',
            'LgDict2URI' => '',
            'LgGoogleTranslateURI' => '',
            'LgExportTemplate' => '',
            'LgTextSize' => '100',
            'LgCharacterSubstitutions' => '',
            'LgRegexpSplitSentences' => '.!?',
            'LgExceptionsSplitSentences' => '',
            'LgRegexpWordCharacters' => 'a-zA-Z',
            'LgTTSVoiceAPI' => '',
        ];

        $result = $service->create();

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testServiceHandlesUnicodeInName(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new LanguageFacade();

        $_REQUEST = [
            'LgName' => 'TestLang_日本語',
            'LgDict1URI' => 'https://dict.test/lwt_term',
            'LgDict2URI' => '',
            'LgGoogleTranslateURI' => '',
            'LgExportTemplate' => '',
            'LgTextSize' => '100',
            'LgCharacterSubstitutions' => '',
            'LgRegexpSplitSentences' => '.!?',
            'LgExceptionsSplitSentences' => '',
            'LgRegexpWordCharacters' => 'a-zA-Z',
            'LgTTSVoiceAPI' => '',
        ];

        $result = $service->create();

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        $langs = $service->getAllLanguages();
        $this->assertArrayHasKey('TestLang_日本語', $langs);
    }
}
