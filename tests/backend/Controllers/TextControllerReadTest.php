<?php

declare(strict_types=1);

namespace Lwt\Tests\Controllers;

use Lwt\Modules\Text\Http\TextController;
use Lwt\Modules\Text\Http\TextReadController;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Text\Application\TextFacade;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TextController::read() method and related functionality.
 *
 * Tests controller initialization, service integration,
 * and verifies the MVC pattern implementation for text reading.
 */
class TextControllerReadTest extends TestCase
{
    private static bool $dbConnected = false;
    private static int $testLangId = 0;
    private static int $testTextId = 0;
    private static int $testText2Id = 0;
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
            // Create a test language
            $existingLang = Connection::fetchValue(
                "SELECT LgID AS value FROM languages" .
                " WHERE LgName = 'ReadControllerTestLang' LIMIT 1"
            );

            if ($existingLang) {
                self::$testLangId = (int)$existingLang;
            } else {
                $sql = "INSERT INTO languages" .
                    " (LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI, " .
                    "LgTextSize, LgCharacterSubstitutions, LgRegexpSplitSentences, " .
                    "LgExceptionsSplitSentences, LgRegexpWordCharacters, LgRemoveSpaces, " .
                    "LgSplitEachChar, LgRightToLeft, LgShowRomanization, LgTTSVoiceAPI) " .
                    "VALUES ('ReadControllerTestLang', 'http://dict1.test/###', " .
                    "'http://dict2.test/###', 'http://translate.test/?sl=en&tl=fr&text=###', " .
                    "120, '', '.!?', '', 'a-zA-Z', 0, 0, 0, 1, 'Google')";
                Connection::query($sql);
                self::$testLangId = (int)Connection::fetchValue(
                    "SELECT LAST_INSERT_ID() AS value"
                );
            }

            // Create first test text
            $annotatedText = "-1\t.\n0\tTest\t\t*\n0\ttext\t\ttranslation";
            Connection::query(
                "INSERT INTO texts (TxLgID, TxTitle, TxText, TxAnnotatedText, TxAudioURI, " .
                "TxSourceURI, TxAudioPosition, TxPosition) " .
                "VALUES (" . self::$testLangId . ", 'ReadControllerTestText', 'Test text.', " .
                "'" . mysqli_real_escape_string(Globals::getDbConnection(), $annotatedText) . "', " .
                "'http://audio.test/audio.mp3', 'http://source.test/article', 15, 50)"
            );
            self::$testTextId = (int)Connection::fetchValue(
                "SELECT LAST_INSERT_ID() AS value"
            );

            // Create second test text (for navigation tests)
            Connection::query(
                "INSERT INTO texts (TxLgID, TxTitle, TxText, TxAnnotatedText) " .
                "VALUES (" . self::$testLangId . ", 'ReadControllerTestText2', 'Second test.', '')"
            );
            self::$testText2Id = (int)Connection::fetchValue(
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
        $textIds = self::$testTextId . ", " . self::$testText2Id;
        Connection::query(
            "DELETE FROM word_occurrences" .
            " WHERE Ti2TxID IN (" . $textIds . ")"
        );
        Connection::query(
            "DELETE FROM sentences" .
            " WHERE SeTxID IN (" . $textIds . ")"
        );
        Connection::query(
            "DELETE FROM texts" .
            " WHERE TxID IN (" . $textIds . ")"
        );
        Connection::query(
            "DELETE FROM languages" .
            " WHERE LgName = 'ReadControllerTestLang'"
        );
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

        $controller = new TextController(
            new \Lwt\Modules\Text\Application\TextFacade(),
            new \Lwt\Modules\Language\Application\LanguageFacade()
        );

        $this->assertInstanceOf(TextController::class, $controller);
    }

    // ===== Method existence tests =====

    public function testControllerHasReadMethod(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller = new TextController(
            new \Lwt\Modules\Text\Application\TextFacade(),
            new \Lwt\Modules\Language\Application\LanguageFacade()
        );

        $this->assertTrue(method_exists($controller, 'read'));
    }

    // ===== Service integration tests =====

    public function testTextServiceCanRetrieveTestTextData(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new TextFacade();

        // Test getTextForReading
        $headerData = $service->getTextForReading(self::$testTextId);
        $this->assertIsArray($headerData);
        $this->assertEquals('ReadControllerTestText', $headerData['TxTitle']);
        $this->assertEquals(self::$testLangId, (int)$headerData['TxLgID']);
        $this->assertEquals('http://audio.test/audio.mp3', $headerData['TxAudioURI']);
        $this->assertEquals(15, (int)$headerData['TxAudioPosition']);

        // Test getTextDataForContent
        $contentData = $service->getTextDataForContent(self::$testTextId);
        $this->assertIsArray($contentData);
        $this->assertEquals(50, (int)$contentData['TxPosition']);
        $this->assertStringContainsString('Test', $contentData['TxAnnotatedText']);

        // Test getLanguageSettingsForReading
        $langSettings = $service->getLanguageSettingsForReading(self::$testLangId);
        $this->assertIsArray($langSettings);
        $this->assertEquals(120, (int)$langSettings['LgTextSize']);
        $this->assertEquals('http://dict1.test/###', $langSettings['LgDict1URI']);

        // Test getTtsVoiceApi
        $voiceApi = $service->getTtsVoiceApi(self::$testLangId);
        $this->assertEquals('Google', $voiceApi);
    }

    // ===== Request parameter extraction tests =====

    public function testGetTextIdFromRequestWithTextParam(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = (string)self::$testTextId;

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        $this->assertEquals(self::$testTextId, $result);
    }

    public function testGetTextIdFromRequestWithStartParam(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['start'] = (string)self::$testTextId;

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        $this->assertEquals(self::$testTextId, $result);
    }

    public function testGetTextIdFromRequestPrefersTextOverStart(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = (string)self::$testTextId;
        $_REQUEST['start'] = (string)self::$testText2Id;

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        // Should prefer 'text' over 'start'
        $this->assertEquals(self::$testTextId, $result);
    }

    public function testGetTextIdFromRequestReturnsNullForMissingParams(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // No text or start parameter set

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        $this->assertNull($result);
    }

    public function testGetTextIdFromRequestReturnsNullForNonNumeric(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = 'not-a-number';

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        $this->assertNull($result);
    }

    public function testGetTextIdFromRequestHandlesZero(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '0';

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        // '0' is numeric, so it should return 0
        $this->assertEquals(0, $result);
    }

    // ===== Settings tests =====

    public function testSettingsAreRetrievedCorrectly(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Test that Settings class can retrieve values
        $showAllWords = Settings::getZeroOrOne('showallwords', 1);
        $this->assertContains($showAllWords, [0, 1]);

        $showLearningTrans = Settings::getZeroOrOne('showlearningtranslations', 1);
        $this->assertContains($showLearningTrans, [0, 1]);
    }

    // ===== Integration tests =====

    public function testFullReadingDataRetrieval(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new TextFacade();

        // Simulate what the controller does
        $textId = self::$testTextId;

        // Step 1: Get header data
        $headerData = $service->getTextForReading($textId);
        $this->assertNotNull($headerData);

        $title = (string) $headerData['TxTitle'];
        $langId = (int) $headerData['TxLgID'];
        $media = isset($headerData['TxAudioURI']) ? trim((string) $headerData['TxAudioURI']) : '';
        $audioPosition = (int) ($headerData['TxAudioPosition'] ?? 0);
        $sourceUri = (string) ($headerData['TxSourceURI'] ?? '');
        $text = (string) $headerData['TxText'];
        $languageName = (string) $headerData['LgName'];

        $this->assertEquals('ReadControllerTestText', $title);
        $this->assertEquals(self::$testLangId, $langId);
        $this->assertEquals('http://audio.test/audio.mp3', $media);
        $this->assertEquals(15, $audioPosition);
        $this->assertEquals('http://source.test/article', $sourceUri);
        $this->assertEquals('Test text.', $text);
        $this->assertEquals('ReadControllerTestLang', $languageName);

        // Step 2: Get text content data
        $textData = $service->getTextDataForContent($textId);
        $this->assertNotNull($textData);

        $annotatedText = (string) ($textData['TxAnnotatedText'] ?? '');
        $textPosition = (int) ($textData['TxPosition'] ?? 0);

        $this->assertStringContainsString('Test', $annotatedText);
        $this->assertEquals(50, $textPosition);

        // Step 3: Get language settings
        $langSettings = $service->getLanguageSettingsForReading($langId);
        $this->assertNotNull($langSettings);

        $dictLink1 = $langSettings['LgDict1URI'] ?? '';
        $dictLink2 = $langSettings['LgDict2URI'] ?? '';
        $translatorLink = $langSettings['LgGoogleTranslateURI'] ?? '';
        $textSize = (int) $langSettings['LgTextSize'];
        $regexpWordChars = $langSettings['LgRegexpWordCharacters'] ?? '';
        $removeSpaces = (int) $langSettings['LgRemoveSpaces'];
        $rtlScript = (bool) $langSettings['LgRightToLeft'];

        $this->assertEquals('http://dict1.test/###', $dictLink1);
        $this->assertEquals('http://dict2.test/###', $dictLink2);
        $this->assertStringContainsString('translate.test', $translatorLink);
        $this->assertEquals(120, $textSize);
        $this->assertEquals('a-zA-Z', $regexpWordChars);
        $this->assertEquals(0, $removeSpaces);
        $this->assertFalse($rtlScript);

        // Step 4: Get TTS voice API
        $voiceApi = $service->getTtsVoiceApi($langId);
        $this->assertEquals('Google', $voiceApi);
    }

    public function testReadingDataRetrievalForNonExistentText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new TextFacade();

        $headerData = $service->getTextForReading(999999);

        $this->assertNull($headerData);
    }

    public function testReadingDataRetrievalForTextWithoutAudio(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new TextFacade();

        // Use the second test text which has no audio
        $headerData = $service->getTextForReading(self::$testText2Id);

        $this->assertIsArray($headerData);
        $this->assertEquals('ReadControllerTestText2', $headerData['TxTitle']);
        $this->assertTrue(
            $headerData['TxAudioURI'] === null || $headerData['TxAudioURI'] === '',
            'Text without audio should have empty or null TxAudioURI'
        );
    }

    // ===== RTL language tests =====

    public function testReadingDataForRtlLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $service = new TextFacade();

        // Create RTL language
        Connection::query(
            "INSERT INTO languages (LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI, " .
            "LgTextSize, LgCharacterSubstitutions, LgRegexpSplitSentences, LgExceptionsSplitSentences, " .
            "LgRegexpWordCharacters, LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization) " .
            "VALUES ('ReadTestRTLLang', 'http://rtl.test/###', '', '', " .
            "100, '', '.!?', '', 'a-zA-Z', 0, 0, 1, 0)"
        );
        $rtlLangId = (int)Connection::fetchValue("SELECT LAST_INSERT_ID() AS value");

        // Create text with RTL language
        Connection::query(
            "INSERT INTO texts (TxLgID, TxTitle, TxText, TxAnnotatedText) " .
            "VALUES (" . $rtlLangId . ", 'RTL Test', 'RTL content.', '')"
        );
        $rtlTextId = (int)Connection::fetchValue("SELECT LAST_INSERT_ID() AS value");

        // Get language settings
        $langSettings = $service->getLanguageSettingsForReading($rtlLangId);

        $this->assertIsArray($langSettings);
        $this->assertEquals(1, (int)$langSettings['LgRightToLeft']);

        // Cleanup
        Connection::query("DELETE FROM texts WHERE TxID = " . $rtlTextId);
        Connection::query("DELETE FROM languages WHERE LgID = " . $rtlLangId);
    }

    // ===== Edge case tests =====

    public function testGetTextIdWithLargeNumbers(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '2147483647'; // Max 32-bit int

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        $this->assertEquals(2147483647, $result);
    }

    public function testGetTextIdWithNegativeNumber(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '-1';

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        // is_numeric returns true for negative numbers
        $this->assertEquals(-1, $result);
    }

    public function testGetTextIdWithDecimalNumber(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '123.45';

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        // is_numeric returns true for decimals, will be cast to int
        $this->assertEquals(123, $result);
    }

    public function testGetTextIdWithWhitespace(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $_REQUEST['text'] = '  123  ';

        $controller = new TextReadController(
            new \Lwt\Modules\Text\Application\TextFacade()
        );

        $result = $controller->getTextIdFromRequest();

        // Trimmed string is numeric, cast to int
        // Note: is_numeric may behave differently with leading/trailing spaces
        // This test documents the actual behavior
        if (is_numeric($_REQUEST['text'])) {
            $this->assertEquals(123, $result);
        } else {
            $this->assertNull($result);
        }
    }

    // ===== Controller instantiation consistency =====

    public function testMultipleControllerInstantiationsAreFunctional(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $controller1 = new TextController(
            new \Lwt\Modules\Text\Application\TextFacade(),
            new \Lwt\Modules\Language\Application\LanguageFacade()
        );
        $controller2 = new TextController(
            new \Lwt\Modules\Text\Application\TextFacade(),
            new \Lwt\Modules\Language\Application\LanguageFacade()
        );

        // Both controllers should be functional
        $this->assertInstanceOf(TextController::class, $controller1);
        $this->assertInstanceOf(TextController::class, $controller2);

        // Both should have the read method
        $this->assertTrue(method_exists($controller1, 'read'));
        $this->assertTrue(method_exists($controller2, 'read'));
    }
}
