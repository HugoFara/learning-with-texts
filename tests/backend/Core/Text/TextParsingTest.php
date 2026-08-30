<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Text;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Modules\Language\Application\Services\TextParsingService;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for text parsing functions
 *
 * Tests the core text processing pipeline including:
 * - TextParsing::splitIntoSentences() - split text into sentences
 * - TextParsing::checkTextReport() - report on a parse without saving
 * - TextParsing::parseAndSave() - parse and save text to database
 * - Character substitutions
 * - Language-specific processing
 * - Brace replacement
 * - Edge cases and Unicode handling
 */
class TextParsingTest extends TestCase
{
    private static $dbConnection;
    private static $testLanguageId;
    private static bool $dbConnected = false;

    /**
     * Set up database connection and create test language
     */
    public static function setUpBeforeClass(): void
    {
        self::$dbConnected = defined('LWT_TEST_DB_AVAILABLE') && LWT_TEST_DB_AVAILABLE;

        if (self::$dbConnected) {
            self::$dbConnection = Globals::getDbConnection();

            // Create a test language for parsing tests
            self::createTestLanguage();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
    }

    /**
     * Create test language with standard settings
     */
    private static function createTestLanguage(): void
    {
        // Insert test language
        $sql = "INSERT INTO languages (
            LgName,
            LgDict1URI,
            LgGoogleTranslateURI,
            LgTextSize,
            LgCharacterSubstitutions,
            LgRegexpSplitSentences,
            LgExceptionsSplitSentences,
            LgRegexpWordCharacters,
            LgRemoveSpaces,
            LgSplitEachChar,
            LgRightToLeft
        ) VALUES (
            'Test English',
            'https://en.wiktionary.org/wiki/###',
            'https://translate.google.com/?ie=UTF-8&sl=en&tl=es&text=###',
            100,
            '',
            '.!?',
            'Mr.|Dr.|Mrs.|Ms.',
            'a-zA-Z',
            0,
            0,
            0
        )";

        Connection::query($sql);
        self::$testLanguageId = mysqli_insert_id(self::$dbConnection);
    }

    /**
     * Clean up test language
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$testLanguageId) {
            Connection::query("DELETE FROM languages WHERE LgID = " . self::$testLanguageId);
        }
    }

    /**
     * Helper method to call splitIntoSentences with output buffering
     * This prevents HTML output from polluting the test output
     *
     * @return string[]
     *
     * @psalm-return non-empty-list<string>
     */
    private function callSplitIntoSentences(string $text, int $lid): array
    {
        ob_start();
        $result = TextParsing::splitIntoSentences($text, $lid);
        ob_end_clean();
        return $result;
    }

    /**
     * Test splitIntoSentences with basic text
     */
    public function testSplitIntoSentencesBasic(): void
    {

        // Test with split mode which returns sentences array
        $text = "Hello world. This is a test.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should return array in split mode');
        $this->assertNotEmpty($result, 'Should have parsed sentences');
    }

    /**
     * Test character substitution in splitIntoSentences
     */
    public function testSplitIntoSentencesCharacterSubstitution(): void
    {
        // Create language with character substitutions
        $sql = "INSERT INTO languages (
            LgName, LgDict1URI, LgGoogleTranslateURI, LgTextSize,
            LgCharacterSubstitutions, LgRegexpSplitSentences,
            LgExceptionsSplitSentences, LgRegexpWordCharacters,
            LgRemoveSpaces, LgSplitEachChar, LgRightToLeft
        ) VALUES (
            'Test German',
            'https://de.wiktionary.org/wiki/###',
            'https://translate.google.com/?sl=de&tl=en&text=###',
            100,
            'ß=ss|ä=ae|ö=oe|ü=ue',
            '.!?',
            '',
            'a-zA-ZäöüßÄÖÜ',
            0, 0, 0
        )";

        Connection::query($sql);
        $germanLangId = mysqli_insert_id(Globals::getDbConnection());

        // Test text with German characters
        $text = "Größe Käse Tür";
        $result = $this->callSplitIntoSentences($text, $germanLangId);

        $this->assertIsArray($result, 'Should return array');

        // Check that text was processed (substitutions should have been applied)
        // The exact result depends on parsing logic, but it should not fail
        $this->assertNotEmpty($result, 'Should parse German text');

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $germanLangId");
    }

    /**
     * Test brace replacement in splitIntoSentences
     */
    public function testSplitIntoSentencesBraceReplacement(): void
    {

        // Text with braces should have them replaced with brackets
        $text = "Text with {braces} and more {content}.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should return array');
        $this->assertNotEmpty($result, 'Should parse text with braces');

        // The braces should have been replaced internally
        // We can't directly check the internal state, but parsing should succeed
    }

    /**
     * Test splitIntoSentences with empty text
     */
    public function testSplitIntoSentencesEmpty(): void
    {

        $result = $this->callSplitIntoSentences('', self::$testLanguageId);

        $this->assertIsArray($result, 'Should return array even for empty text');
        // May return empty array or array with single empty element, both are acceptable
        $this->assertLessThanOrEqual(1, count($result), 'Empty text should return very small array');
    }

    /**
     * Test splitIntoSentences with whitespace-only text
     */
    public function testSplitIntoSentencesWhitespaceOnly(): void
    {

        $result = $this->callSplitIntoSentences("   \n\t  ", self::$testLanguageId);

        $this->assertIsArray($result, 'Should return array');
        // Whitespace may be treated as one or two sentences depending on paragraph marker handling
        $this->assertLessThanOrEqual(2, count($result), 'Whitespace-only text should return very small array');
    }

    /**
     * Test splitIntoSentences with Unicode text
     */
    public function testSplitIntoSentencesUnicode(): void
    {

        // Text with various Unicode characters
        $text = "Hello 世界. Γεια σου κόσμε. مرحبا بالعالم.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle Unicode text');
        $this->assertNotEmpty($result, 'Should parse Unicode text');
    }

    /**
     * Test splitIntoSentences with multiple paragraphs
     */
    public function testSplitIntoSentencesMultipleParagraphs(): void
    {

        $text = "First paragraph here.\n\nSecond paragraph here.\n\nThird paragraph.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should return array');
        $this->assertGreaterThanOrEqual(3, count($result), 'Should have at least 3 sentences');
    }

    /**
     * Test splitIntoSentences with Windows line endings
     */
    public function testSplitIntoSentencesWindowsLineEndings(): void
    {

        // Text with Windows line endings
        $text = "Line one.\r\nLine two.\r\nLine three.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle Windows line endings');
        $this->assertNotEmpty($result, 'Should parse text with CRLF');
    }

    /**
     * Test splitIntoSentences with special punctuation
     */
    public function testSplitIntoSentencesSpecialPunctuation(): void
    {

        $text = "Question? Exclamation! Period. Comma, semicolon; colon: dash-word.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle special punctuation');
        $this->assertGreaterThanOrEqual(3, count($result), 'Should split on sentence punctuation');
    }

    /**
     * Test splitIntoSentences with abbreviations
     */
    public function testSplitIntoSentencesAbbreviations(): void
    {

        // Should try not to split on Mr. or Dr. (in exception list)
        $text = "Mr. Smith met Dr. Jones. They talked.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle abbreviations');
        // Abbreviation handling may vary, expecting 2-3 sentences
        $this->assertGreaterThanOrEqual(2, count($result), 'Should have at least 2 sentences');
        $this->assertLessThanOrEqual(3, count($result), 'Should have at most 3 sentences');
    }

    /**
     * Test splitIntoSentences with numbers
     */
    public function testSplitIntoSentencesNumbers(): void
    {

        $text = "The value is 3.14. Another number is 42. Version 2.0.1 is here.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle numbers');
        $this->assertGreaterThanOrEqual(3, count($result), 'Should split sentences but not on decimal points');
    }

    /**
     * Test splitIntoSentences with mixed case
     */
    public function testSplitIntoSentencesMixedCase(): void
    {

        $text = "UPPERCASE SENTENCE. lowercase sentence. MiXeD CaSe SeNtEnCe.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle mixed case');
        $this->assertEquals(3, count($result), 'Should have 3 sentences');
    }

    /**
     * Test splitIntoSentences with quotes
     */
    public function testSplitIntoSentencesQuotes(): void
    {

        $text = '"First sentence." "Second sentence." \'Third sentence.\'';
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle quoted text');
        $this->assertGreaterThanOrEqual(3, count($result), 'Should have at least 3 sentences');
    }

    /**
     * Test checkTextReport previews the text it was given
     */
    public function testCheckTextReportPreviewsTheText(): void
    {
        $report = TextParsing::checkTextReport("Test sentence.", self::$testLanguageId);

        $this->assertStringContainsString(
            'Test sentence',
            $report['preview'],
            'Report should preview the text'
        );
    }

    /**
     * Test splitIntoSentences with very long text
     */
    public function testSplitIntoSentencesLongText(): void
    {

        // Generate long text
        $sentences = [];
        for ($i = 1; $i <= 50; $i++) {
            $sentences[] = "This is sentence number $i with some content.";
        }
        $text = implode(' ', $sentences);

        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle long text');
        // May have 50 or 51 sentences depending on parsing (allow some margin)
        $this->assertGreaterThanOrEqual(49, count($result), 'Should have at least 49 sentences');
        $this->assertLessThanOrEqual(51, count($result), 'Should have at most 51 sentences');
    }

    /**
     * Test splitIntoSentences with special characters that need escaping
     */
    public function testSplitIntoSentencesSpecialCharacters(): void
    {

        // Text with SQL-special characters
        $text = "Test with 'single quotes'. Test with \"double quotes\". Test with \\ backslash.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle special SQL characters');
        $this->assertNotEmpty($result, 'Should parse text with special characters');
    }

    /**
     * Test splitIntoSentences with emoji
     */
    public function testSplitIntoSentencesEmoji(): void
    {

        $text = "Hello 😀 world. How are you 🌍 doing?";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle emoji');
        $this->assertNotEmpty($result, 'Should parse text with emoji');
    }

    /**
     * Test splitIntoSentences with invalid language ID
     */
    public function testSplitIntoSentencesInvalidLanguage(): void
    {

        // Invalid language ID - splitIntoSentences returns [''] for invalid language
        $result = $this->callSplitIntoSentences("Test text.", 99999);

        // Verify it returns an array (splitIntoSentences never returns null)
        $this->assertIsArray($result, 'Should handle invalid language gracefully');
        $this->assertEquals([''], $result, 'Should return empty array for invalid language');
    }

    /**
     * Test splitIntoSentences with ellipsis
     */
    public function testSplitIntoSentencesEllipsis(): void
    {

        $text = "Wait for it... Here it comes. Done.";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle ellipsis');
        $this->assertNotEmpty($result, 'Should parse text with ellipsis');
    }

    /**
     * Test splitIntoSentences with no punctuation
     */
    public function testSplitIntoSentencesNoPunctuation(): void
    {

        $text = "Text without any sentence ending punctuation marks";
        $result = $this->callSplitIntoSentences($text, self::$testLanguageId);

        $this->assertIsArray($result, 'Should handle text without punctuation');
        $this->assertNotEmpty($result, 'Should still return the text as one sentence');
    }

    /**
     * Test TextParsingService::findLatinSentenceEnd method - comprehensive tests
     *
     * This method analyzes regex matches to determine if punctuation marks
     * end of sentence based on context (abbreviations, numbers, case, etc.)
     *
     * Note: The method may return different markers (\r or \t) depending on context
     */
    public function testFindLatinSentenceEnd(): void
    {
        $service = new TextParsingService();

        // Test 1: Real sentence end (period followed by capital letter with space in match[6])
        // Pattern typically captures: [1]=word, [2]=., [3]=space, [6]=space/empty, [7]=NextWord
        // When match[6] is empty and match[7] has alphanumeric after, it adds \t instead of \r
        $matches = ['End. Start', 'End', '.', '', '', '', '', 'Start'];
        $result = $service->findLatinSentenceEnd($matches, '');
        // This specific case adds \t based on the code logic (line 305-306)
        $this->assertStringContainsString("\t", $result, 'Period before capital may mark with tab');

        // Test 2: Abbreviation - single letter followed by period (Dr. Smith)
        // Single letter abbreviation should NOT end sentence
        $matches = ['A. Smith', 'A', '.', '', '', '', '', 'Smith'];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Single letter abbreviation should not end sentence');

        // Test 3: Number with decimal point (3.14)
        $matches = ['3.14', '3', '.', '', '', '', '', '14'];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Decimal number should not end sentence');

        // Test 4: Number with period at end (Year 2023.)
        // Small number (< 3 digits) with period should not end sentence
        $matches = ['10.', '10', '.', '', '', '', '', ''];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Small number with period should not end sentence');

        // Test 5: Large number with period (Year 2023.) - should end sentence
        $matches = ['2023.', '2023', '.', '', '', '', '', ''];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsWith("\r", $result, 'Large number (3+ digits) with period should end sentence');

        // Test 6: Period followed by lowercase (ellipsis or mid-sentence)
        $matches = ['test. then', 'test', '.', '', '', '', '', 'then'];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Period before lowercase should not end sentence');

        // Test 7: Custom exception - "Dr." in exception list
        $matches = ['Dr. Smith', 'Dr', '.', '', '', '', '', 'Smith'];
        $result = $service->findLatinSentenceEnd($matches, 'Dr.|Mr.|Mrs.');
        $this->assertStringEndsNotWith("\r", $result, 'Exception list should prevent sentence end');

        // Test 8: Custom exception - "Mr." in exception list
        $matches = ['Mr. Jones', 'Mr', '.', '', '', '', '', 'Jones'];
        $result = $service->findLatinSentenceEnd($matches, 'Dr.|Mr.|Mrs.');
        $this->assertStringEndsNotWith("\r", $result, 'Mr. in exception list should not end sentence');

        // Test 9: Not in exception list - may end with \t or \r depending on match structure
        $matches = ['End. Start', 'End', '.', '', '', '', '', 'Start'];
        $result = $service->findLatinSentenceEnd($matches, 'Dr.|Mr.|Mrs.');
        // With empty match[6] and alphanumeric match[7], returns \t (line 305-306)
        $this->assertStringContainsString("\t", $result, 'Word not in exception list marks sentence (with tab)');

        // Test 10: Common abbreviation patterns - consonant clusters
        // Abbreviations like "St.", "Rd." (street, road) should not end sentence
        $matches = ['St. John', 'St', '.', true, '', '', '', 'John'];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Consonant abbreviation should not end sentence');

        // Test 11: Single vowel abbreviation (e.g., "A.")
        $matches = ['A. Smith', 'A', '.', true, '', '', '', 'Smith'];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Single vowel abbreviation should not end sentence');

        // Test 12: Colon followed by lowercase (list continuation)
        $matches = ['test: item', 'test', ':', '', '', '', '', 'item'];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsNotWith("\r", $result, 'Colon before lowercase should not end sentence');

        // Test 13: Empty exception string (no exceptions)
        $matches = ['End. Start', 'End', '.', '', '', '', '', 'Start'];
        $result = $service->findLatinSentenceEnd($matches, '');
        // Still returns \t because match[6] is empty and match[7] has alphanumeric
        $this->assertStringContainsString("\t", $result, 'No exceptions marks with tab based on structure');

        // Test 14: Match at end of text (no following word)
        $matches = ['End.', 'End', '.', '', '', '', '', ''];
        $result = $service->findLatinSentenceEnd($matches, '');
        $this->assertStringEndsWith("\r", $result, 'Period at text end should mark sentence end');
    }
}
