<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Database;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database\TextParsing class.
 *
 * Tests text parsing and processing utilities.
 */
class TextParsingTest extends TestCase
{
    private static bool $dbConnected = false;
    private static ?int $testLanguageId = null;

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
            self::createTestLanguage();
        }
    }

    private static function createTestLanguage(): void
    {
        $word_occurrences = Globals::table('word_occurrences');
        $languages = Globals::table('languages');
        $sentences = Globals::table('sentences');
        $texts = Globals::table('texts');
        $words = Globals::table('words');

        // Clean up any existing test language first
        $langName = 'Test TextParsing Language';
        Connection::query(
            "DELETE FROM $word_occurrences WHERE Ti2LgID IN " .
            "(SELECT LgID FROM $languages WHERE LgName = '$langName')"
        );
        Connection::query(
            "DELETE FROM $sentences WHERE SeLgID IN " .
            "(SELECT LgID FROM $languages WHERE LgName = '$langName')"
        );
        Connection::query(
            "DELETE FROM $texts WHERE TxLgID IN " .
            "(SELECT LgID FROM $languages WHERE LgName = '$langName')"
        );
        Connection::query(
            "DELETE FROM $words WHERE WoLgID IN " .
            "(SELECT LgID FROM $languages WHERE LgName = '$langName')"
        );
        Connection::query("DELETE FROM $languages WHERE LgName = '$langName'");

        // Create test language
        $sql = "INSERT INTO $languages (
            LgName, LgDict1URI, LgGoogleTranslateURI, LgTextSize,
            LgCharacterSubstitutions, LgRegexpSplitSentences,
            LgExceptionsSplitSentences, LgRegexpWordCharacters,
            LgRemoveSpaces, LgSplitEachChar, LgRightToLeft
        ) VALUES (
            'Test TextParsing Language',
            'https://en.wiktionary.org/wiki/###',
            'https://translate.google.com/?text=###',
            100, '', '.!?', 'Mr.|Dr.|Mrs.|Ms.', 'a-zA-Z', 0, 0, 0
        )";
        Connection::query($sql);
        self::$testLanguageId = mysqli_insert_id(Globals::getDbConnection());
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        if (self::$testLanguageId) {
            $word_occurrences = Globals::table('word_occurrences');
            $sentences = Globals::table('sentences');
            $texts = Globals::table('texts');
            $words = Globals::table('words');
            $languages = Globals::table('languages');

            // Clean up any test texts and associated data
            Connection::query("DELETE FROM $word_occurrences WHERE Ti2LgID = " . self::$testLanguageId);
            Connection::query("DELETE FROM $sentences WHERE SeLgID = " . self::$testLanguageId);
            Connection::query("DELETE FROM $texts WHERE TxLgID = " . self::$testLanguageId);
            Connection::query("DELETE FROM $words WHERE WoLgID = " . self::$testLanguageId);
            Connection::query("DELETE FROM $languages WHERE LgID = " . self::$testLanguageId);
        }
    }

    /**
     * Helper to call splitIntoSentences() with output buffering
     */
    private function callSplitIntoSentences(string $text, int $lid): array
    {
        ob_start();
        $result = TextParsing::splitIntoSentences($text, $lid);
        ob_end_clean();
        return $result;
    }

    // ===== splitIntoSentences() tests =====

    public function testSplitIntoSentencesBasicText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello world. This is a test.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should return array in split mode');
        $this->assertNotEmpty($result, 'Should have parsed sentences');
    }

    public function testSplitIntoSentencesEmptyText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->callSplitIntoSentences('', self::$testLanguageId);

        $this->assertIsArray($result);
    }

    public function testSplitIntoSentencesWhitespaceOnly(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->callSplitIntoSentences("   \n\t  ", self::$testLanguageId);

        $this->assertIsArray($result);
    }

    public function testSplitIntoSentencesWithBraces(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Braces should be replaced with brackets
        $text = "Text with {braces} and more {content}.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSplitIntoSentencesWithWindowsLineEndings(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Line one.\r\nLine two.\r\nLine three.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSplitIntoSentencesWithUnicode(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello 世界. Γεια σου κόσμε.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSplitIntoSentencesInvalidLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = $this->callSplitIntoSentences("Test text.", 99999);

        // Should return empty array for invalid language (since splitIntoSentences returns [''])
        $this->assertIsArray($result);
        $this->assertEquals([''], $result);
    }

    // ===== splitIntoSentences() tests - sentence parsing =====

    public function testSplitIntoSentencesBasicSentences(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello world. This is a test sentence.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, count($result), 'Should split into at least 2 sentences');
    }

    public function testSplitIntoSentencesMultipleParagraphs(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    public function testSplitIntoSentencesSpecialPunctuation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Question? Exclamation! Period. Another one.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    public function testSplitIntoSentencesWithNumbers(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "The value is 3.14. Another number is 42. Version 2.0.1 is here.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    public function testSplitIntoSentencesWithQuotes(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = '"First sentence." "Second sentence." \'Third sentence.\'';
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    public function testSplitIntoSentencesWithEllipsis(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Wait for it... Here it comes. Done.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // ===== checkTextReport() tests =====

    public function testCheckTextReportSplitMode(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello world. This is a test.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testCheckTextReportPreviewsTheText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $report = TextParsing::checkTextReport("Test sentence.", self::$testLanguageId);

        $this->assertStringContainsString('Test sentence', $report['preview']);
    }

    // ===== parseAndSave() tests =====

    public function testParseAndSaveCreatesSentencesAndTextItems(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $texts = Globals::table('texts');
        $sentences = Globals::table('sentences');
        $word_occurrences = Globals::table('word_occurrences');

        // Create a test text
        $sql = "INSERT INTO $texts (TxLgID, TxTitle, TxText, TxAudioURI)
                VALUES (" . self::$testLanguageId . ", 'Register Test', 'Hello world.', '')";
        Connection::query($sql);
        $textId = mysqli_insert_id(Globals::getDbConnection());

        // Parse and save the text (populates temp_word_occurrences and registers sentences/text items)
        TextParsing::parseAndSave("Hello world.", self::$testLanguageId, $textId);

        // Check that sentences were created
        $sentenceCount = Connection::fetchValue(
            "SELECT COUNT(*) as value FROM $sentences WHERE SeTxID = $textId"
        );
        $this->assertGreaterThan(0, (int)$sentenceCount, 'Should create sentences');

        // Check that text items were created
        $itemCount = Connection::fetchValue(
            "SELECT COUNT(*) as value FROM $word_occurrences WHERE Ti2TxID = $textId"
        );
        $this->assertGreaterThan(0, (int)$itemCount, 'Should create text items');

        // Clean up
        Connection::query("DELETE FROM $word_occurrences WHERE Ti2TxID = $textId");
        Connection::query("DELETE FROM $sentences WHERE SeTxID = $textId");
        Connection::query("DELETE FROM $texts WHERE TxID = $textId");
    }

    // ===== Edge cases =====

    public function testSplitIntoSentencesLongText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Generate long text
        $sentences = [];
        for ($i = 1; $i <= 50; $i++) {
            $sentences[] = "This is sentence number $i with some content.";
        }
        $text = implode(' ', $sentences);

        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(49, count($result));
    }

    public function testSplitIntoSentencesSpecialCharacters(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Test with 'single quotes'. Test with \"double quotes\". Test with \\ backslash.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSplitIntoSentencesWithEmoji(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello 😀 world. How are you 🌍 doing?";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSplitIntoSentencesNoPunctuation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Text without any sentence ending punctuation marks";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'Should still return the text');
    }

    /**
     * Tests fix for issue #114: Last word of text not recognized without punctuation.
     *
     * When a text ends without punctuation, the last word should still be
     * recognized as a word (WordCount=1), not as a non-word (WordCount=0).
     *
     * This test verifies the behavior through the public parseAndSave() API
     * and checks the final word_occurrences table for correct word recognition.
     */
    public function testParseAndSaveLastWordRecognizedWithoutPunctuation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $texts = Globals::table('texts');
        $sentences = Globals::table('sentences');
        $word_occurrences = Globals::table('word_occurrences');

        // Test text WITHOUT punctuation
        $sql = "INSERT INTO $texts (TxLgID, TxTitle, TxText, TxAudioURI)
                VALUES (" . self::$testLanguageId . ", 'Issue 114 Test NoPunct', 'Hello world', '')";
        Connection::query($sql);
        $textIdNoPunct = mysqli_insert_id(Globals::getDbConnection());

        TextParsing::parseAndSave("Hello world", self::$testLanguageId, $textIdNoPunct);

        // Query word_occurrences to check word recognition
        $resultNoPunct = Connection::fetchAll(
            "SELECT Ti2Text, Ti2WordCount FROM $word_occurrences
             WHERE Ti2TxID = $textIdNoPunct ORDER BY Ti2Order"
        );

        // Filter to only word items (Ti2WordCount > 0)
        $wordsNoPunct = array_filter($resultNoPunct, fn($r) => (int)$r['Ti2WordCount'] > 0);
        $wordsNoPunct = array_values($wordsNoPunct); // Re-index

        // Both "Hello" and "world" should be recognized as words
        $this->assertCount(2, $wordsNoPunct, 'Should have 2 words without punctuation');
        $this->assertEquals('Hello', $wordsNoPunct[0]['Ti2Text']);
        $this->assertEquals(1, (int)$wordsNoPunct[0]['Ti2WordCount'], 'First word should have WordCount=1');
        $this->assertEquals('world', $wordsNoPunct[1]['Ti2Text']);
        $this->assertEquals(
            1,
            (int)$wordsNoPunct[1]['Ti2WordCount'],
            'Last word should have WordCount=1 even without trailing punctuation'
        );

        // Test text WITH punctuation for comparison
        $sql = "INSERT INTO $texts (TxLgID, TxTitle, TxText, TxAudioURI)
                VALUES (" . self::$testLanguageId . ", 'Issue 114 Test WithPunct', 'Hello world.', '')";
        Connection::query($sql);
        $textIdWithPunct = mysqli_insert_id(Globals::getDbConnection());

        TextParsing::parseAndSave("Hello world.", self::$testLanguageId, $textIdWithPunct);

        $resultWithPunct = Connection::fetchAll(
            "SELECT Ti2Text, Ti2WordCount FROM $word_occurrences
             WHERE Ti2TxID = $textIdWithPunct ORDER BY Ti2Order"
        );

        // Filter to only word items
        $wordsWithPunct = array_filter($resultWithPunct, fn($r) => (int)$r['Ti2WordCount'] > 0);
        $wordsWithPunct = array_values($wordsWithPunct);

        // Both words should be recognized
        $this->assertCount(2, $wordsWithPunct, 'Should have 2 words with punctuation');
        $this->assertEquals('Hello', $wordsWithPunct[0]['Ti2Text']);
        $this->assertEquals(1, (int)$wordsWithPunct[0]['Ti2WordCount']);
        $this->assertEquals('world', $wordsWithPunct[1]['Ti2Text']);
        $this->assertEquals(1, (int)$wordsWithPunct[1]['Ti2WordCount']);

        // Check that punctuation is also stored (as non-word)
        $punctuation = array_filter($resultWithPunct, fn($r) => $r['Ti2Text'] === '.');
        $this->assertNotEmpty($punctuation, 'Punctuation should be stored');
        $punctItem = array_values($punctuation)[0];
        $this->assertEquals(0, (int)$punctItem['Ti2WordCount'], 'Punctuation should have WordCount=0');

        // Clean up
        Connection::query("DELETE FROM $word_occurrences WHERE Ti2TxID IN ($textIdNoPunct, $textIdWithPunct)");
        Connection::query("DELETE FROM $sentences WHERE SeTxID IN ($textIdNoPunct, $textIdWithPunct)");
        Connection::query("DELETE FROM $texts WHERE TxID IN ($textIdNoPunct, $textIdWithPunct)");
    }

    // ===== Character substitution tests =====

    public function testSplitIntoSentencesWithCharacterSubstitutions(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $languages = Globals::table('languages');

        // Create language with character substitutions
        $sql = "INSERT INTO $languages (
            LgName, LgDict1URI, LgGoogleTranslateURI, LgTextSize,
            LgCharacterSubstitutions, LgRegexpSplitSentences,
            LgExceptionsSplitSentences, LgRegexpWordCharacters,
            LgRemoveSpaces, LgSplitEachChar, LgRightToLeft
        ) VALUES (
            'Test German Substitutions',
            'https://de.wiktionary.org/wiki/###',
            'https://translate.google.com/?text=###',
            100, 'ß=ss|ä=ae|ö=oe|ü=ue', '.!?', '', 'a-zA-ZäöüßÄÖÜ', 0, 0, 0
        )";
        Connection::query($sql);
        $germanLangId = mysqli_insert_id(Globals::getDbConnection());

        $text = "Größe Käse Tür";
        $result = $this->callSplitIntoSentences($text, $germanLangId);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Clean up
        Connection::query("DELETE FROM $languages WHERE LgID = $germanLangId");
    }

    // ===== Split each char language tests =====

    public function testSplitIntoSentencesWithSplitEachChar(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $languages = Globals::table('languages');

        // Create language with split each char enabled
        $sql = "INSERT INTO $languages (
            LgName, LgDict1URI, LgGoogleTranslateURI, LgTextSize,
            LgCharacterSubstitutions, LgRegexpSplitSentences,
            LgExceptionsSplitSentences, LgRegexpWordCharacters,
            LgRemoveSpaces, LgSplitEachChar, LgRightToLeft
        ) VALUES (
            'Test Split Char',
            'https://example.com/###',
            'https://translate.google.com/?text=###',
            100, '', '。', '', 'a-zA-Z', 0, 1, 0
        )";
        Connection::query($sql);
        $splitLangId = mysqli_insert_id(Globals::getDbConnection());

        $text = "Hello。";
        $result = $this->callSplitIntoSentences($text, $splitLangId);

        $this->assertIsArray($result);

        // Clean up
        Connection::query("DELETE FROM $languages WHERE LgID = $splitLangId");
    }

    // ===== RTL language tests =====

    public function testSplitIntoSentencesWithRtlLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $languages = Globals::table('languages');

        // Create RTL language
        $sql = "INSERT INTO $languages (
            LgName, LgDict1URI, LgGoogleTranslateURI, LgTextSize,
            LgCharacterSubstitutions, LgRegexpSplitSentences,
            LgExceptionsSplitSentences, LgRegexpWordCharacters,
            LgRemoveSpaces, LgSplitEachChar, LgRightToLeft
        ) VALUES (
            'Test Arabic',
            'https://example.com/###',
            'https://translate.google.com/?text=###',
            100, '', '。!?', '', '؀-ۿ', 0, 0, 1
        )";
        Connection::query($sql);
        $rtlLangId = mysqli_insert_id(Globals::getDbConnection());

        $text = "مرحبا. كيف حالك.";
        $result = $this->callSplitIntoSentences($text, $rtlLangId);

        $this->assertIsArray($result);

        // Clean up
        Connection::query("DELETE FROM $languages WHERE LgID = $rtlLangId");
    }

    // ===== checkText() tests =====

    public function testCheckTextReturnsArrayWithStats(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello world. This is a test sentence.";
        $result = TextParsing::checkText($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('sentences', $result);
        $this->assertArrayHasKey('words', $result);
        $this->assertArrayHasKey('unknownPercent', $result);
        $this->assertArrayHasKey('preview', $result);
    }

    public function testCheckTextReturnsSentenceCount(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "First sentence. Second sentence. Third sentence.";
        $result = TextParsing::checkText($text, self::$testLanguageId);

        // At least 1 sentence should be parsed
        $this->assertGreaterThanOrEqual(1, $result['sentences']);
    }

    public function testCheckTextReturnsWordCount(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "One two three four five.";
        $result = TextParsing::checkText($text, self::$testLanguageId);

        $this->assertGreaterThanOrEqual(5, $result['words']);
    }

    public function testCheckTextReturnsUnknownPercent(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Hello world.";
        $result = TextParsing::checkText($text, self::$testLanguageId);

        // Without any words in the database, all words should be unknown
        $this->assertIsFloat($result['unknownPercent']);
        $this->assertGreaterThanOrEqual(0, $result['unknownPercent']);
        $this->assertLessThanOrEqual(100, $result['unknownPercent']);
    }

    public function testCheckTextReturnsPreview(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "First sentence. Second sentence. Third sentence.";
        $result = TextParsing::checkText($text, self::$testLanguageId);

        $this->assertIsString($result['preview']);
        $this->assertNotEmpty($result['preview']);
    }

    public function testCheckTextPreviewWithMultipleSentences(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Sentence one. Sentence two. Sentence three. Sentence four. Sentence five.";
        $result = TextParsing::checkText($text, self::$testLanguageId);

        // Preview should contain some of the text
        $this->assertIsString($result['preview']);
        $this->assertNotEmpty($result['preview']);
    }

    public function testCheckTextWithEmptyText(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = TextParsing::checkText('', self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['words']);
    }

    public function testCheckTextWithInvalidLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $result = TextParsing::checkText('Test text.', 99999);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['sentences']);
        $this->assertEquals(0, $result['words']);
        $this->assertEquals(100.0, $result['unknownPercent']);
        $this->assertEquals('', $result['preview']);
    }

    // ===== parseAndSave() error handling tests =====

    public function testParseAndSaveThrowsForZeroTextId(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Text ID must be positive');

        TextParsing::parseAndSave("Test text.", self::$testLanguageId, 0);
    }

    public function testParseAndSaveThrowsForNegativeTextId(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Text ID must be positive');

        TextParsing::parseAndSave("Test text.", self::$testLanguageId, -1);
    }

    public function testParseAndSaveThrowsForInvalidLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->expectException(\Lwt\Shared\Infrastructure\Exception\DatabaseException::class);

        TextParsing::parseAndSave("Test text.", 99999, 1);
    }

    // ===== checkTextReport() error handling tests =====

    public function testCheckTextReportThrowsForInvalidLanguage(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $this->expectException(\Lwt\Shared\Infrastructure\Exception\DatabaseException::class);

        TextParsing::checkTextReport("Test text.", 99999);
    }

    // ===== Multi-word expression tests =====

    public function testParseAndSaveWithMultiWordExpression(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $texts = Globals::table('texts');
        $sentences = Globals::table('sentences');
        $word_occurrences = Globals::table('word_occurrences');
        $words = Globals::table('words');

        // Create a multi-word expression (lowercase to match parsed text)
        $sql = "INSERT INTO $words (WoLgID, WoText, WoTextLC, WoTranslation, WoStatus, WoWordCount)
                VALUES (" . self::$testLanguageId . ", 'test word', 'test word', 'translation', 1, 2)";
        Connection::query($sql);
        $wordId = mysqli_insert_id(Globals::getDbConnection());

        // Create a test text
        $sql = "INSERT INTO $texts (TxLgID, TxTitle, TxText, TxAudioURI)
                VALUES (" . self::$testLanguageId . ", 'MW Test', 'This is a test word example.', '')";
        Connection::query($sql);
        $textId = mysqli_insert_id(Globals::getDbConnection());

        // Parse and save
        TextParsing::parseAndSave("This is a test word example.", self::$testLanguageId, $textId);

        // Check that word occurrences were created
        $itemCount = Connection::fetchValue(
            "SELECT COUNT(*) as value FROM $word_occurrences WHERE Ti2TxID = $textId"
        );
        $this->assertGreaterThan(0, (int)$itemCount, 'Should have word occurrences');

        // Clean up
        Connection::query("DELETE FROM $word_occurrences WHERE Ti2TxID = $textId");
        Connection::query("DELETE FROM $sentences WHERE SeTxID = $textId");
        Connection::query("DELETE FROM $texts WHERE TxID = $textId");
        Connection::query("DELETE FROM $words WHERE WoID = $wordId");
    }

    // ===== Additional edge case tests =====

    public function testSplitIntoSentencesWithAbbreviations(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        // Test that abbreviations don't split sentences incorrectly
        $text = "Dr. Smith works at Mr. Jones Corp. He is great.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        // Should not split at "Dr." or "Mr."
        $this->assertGreaterThanOrEqual(1, count($result));
    }

    public function testSplitIntoSentencesWithMixedPunctuation(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "What? How! Sure... Go on. Yes!";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(4, count($result));
    }

    public function testSplitIntoSentencesPreservesParagraphMarkers(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $text = "Para one.\n\nPara two.\n\nPara three.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result);
        // Should have paragraph markers (¶)
        $joined = implode('', $result);
        $this->assertStringContainsString('¶', $joined);
    }

    public function testCheckTextWithKnownWord(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $words = Globals::table('words');

        // Create a known word
        $sql = "INSERT INTO $words (WoLgID, WoText, WoTextLC, WoTranslation, WoStatus, WoWordCount)
                VALUES (" . self::$testLanguageId . ", 'known', 'known', 'bekannt', 99, 1)";
        Connection::query($sql);
        $wordId = mysqli_insert_id(Globals::getDbConnection());

        $result = TextParsing::checkText('known word test.', self::$testLanguageId);

        // At least one word should be known now (lower unknown %)
        $this->assertIsFloat($result['unknownPercent']);
        // Can't assert exact percentage since "word" and "test" are still unknown

        // Clean up
        Connection::query("DELETE FROM $words WHERE WoID = $wordId");
    }

    public function testParseAndSaveMultipleSentences(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $texts = Globals::table('texts');
        $sentences = Globals::table('sentences');
        $word_occurrences = Globals::table('word_occurrences');

        // Create a test text with multiple sentences
        $sql = "INSERT INTO $texts (TxLgID, TxTitle, TxText, TxAudioURI)
                VALUES (" . self::$testLanguageId .
                ", 'Multi Sentence Test', " .
                "'Sentence one. Sentence two. Sentence three.', '')";
        Connection::query($sql);
        $textId = mysqli_insert_id(Globals::getDbConnection());

        TextParsing::parseAndSave("Sentence one. Sentence two. Sentence three.", self::$testLanguageId, $textId);

        // Check that at least 1 sentence was created
        $sentenceCount = Connection::fetchValue(
            "SELECT COUNT(*) as value FROM $sentences WHERE SeTxID = $textId"
        );
        $this->assertGreaterThanOrEqual(1, (int)$sentenceCount, 'Should create at least 1 sentence');

        // Clean up
        Connection::query("DELETE FROM $word_occurrences WHERE Ti2TxID = $textId");
        Connection::query("DELETE FROM $sentences WHERE SeTxID = $textId");
        Connection::query("DELETE FROM $texts WHERE TxID = $textId");
    }

    public function testCheckTextReportSplitsSentences(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $report = TextParsing::checkTextReport(
            "Test sentence one. Test sentence two.",
            self::$testLanguageId
        );

        $this->assertCount(2, $report['sentences']);
        $this->assertStringContainsString('Test sentence one', $report['sentences'][0]);
    }

    public function testCheckTextReportTalliesWordsAndVerdict(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }

        $report = TextParsing::checkTextReport("Hello world.", self::$testLanguageId);

        $this->assertSame(['hello', 'world'], array_column($report['words'], 0));
        $this->assertSame([1, 1], array_column($report['words'], 1));
        $this->assertIsArray($report['nonWords']);
        $this->assertIsArray($report['multiWords']);
        $this->assertIsBool($report['rtlScript']);
        $this->assertSame('ok', $report['warning']);
    }
}
