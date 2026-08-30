<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Text;

use Lwt\Shared\Infrastructure\Database\Connection;
use PHPUnit\Framework\TestCase;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Modules\Vocabulary\Application\Services\ExportService;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Modules\Text\Application\Services\TextStatisticsService;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

/**
 * Unit tests for text processing functions.
 *
 * Tests word counting, statistics, language utilities, and text operations.
 */
class TextProcessingTest extends TestCase
{
    private static bool $dbConnected = false;
    private static ?LanguageFacade $languageService = null;

    public static function setUpBeforeClass(): void
    {
        self::$dbConnected = defined('LWT_TEST_DB_AVAILABLE') && LWT_TEST_DB_AVAILABLE;
        if (self::$dbConnected) {
            self::$languageService = new LanguageFacade();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
    }

    protected function tearDown(): void
    {
        if (!self::$dbConnected) {
            return;
        }

        // Clean up test data
        Connection::query("DELETE FROM languages WHERE LgName LIKE 'test_proc_%'");
    }

    // ===== getAllLanguages() tests =====

    public function testGetLanguagesReturnsArray(): void
    {

        $languages = self::$languageService->getAllLanguages();
        $this->assertIsArray($languages);
    }

    public function testGetLanguagesContainsNameIdPairs(): void
    {

        // Insert test language
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI)
                         VALUES ('test_proc_lang', 'http://test', 'http://test')");
        $lgId = (int)Connection::lastInsertId();

        $languages = self::$languageService->getAllLanguages();

        $this->assertArrayHasKey('test_proc_lang', $languages);
        $this->assertEquals($lgId, $languages['test_proc_lang']);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    public function testGetLanguagesExcludesEmptyNames(): void
    {

        // Clean up any existing empty-name language first
        Connection::query("DELETE FROM languages WHERE LgName = ''");

        // Insert language with empty name
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI)
                         VALUES ('', 'http://test', 'http://test')");
        $lgId = (int)Connection::lastInsertId();

        $languages = self::$languageService->getAllLanguages();

        // Empty name should not be in array
        $this->assertNotContains($lgId, $languages);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    // ===== getLanguageName() tests =====

    public function testGetLanguageWithIntId(): void
    {

        // Insert test language
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI)
                         VALUES ('test_proc_getlang', 'http://test', 'http://test')");
        $lgId = (int)Connection::lastInsertId();

        $name = self::$languageService->getLanguageName($lgId);

        $this->assertEquals('test_proc_getlang', $name);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    public function testGetLanguageWithStringId(): void
    {

        // Insert test language
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI)
                         VALUES ('test_proc_string', 'http://test', 'http://test')");
        $lgId = (int)Connection::lastInsertId();

        $name = self::$languageService->getLanguageName((string)$lgId);

        $this->assertEquals('test_proc_string', $name);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    public function testGetLanguageWithInvalidId(): void
    {

        $name = self::$languageService->getLanguageName(999999);
        $this->assertEquals('', $name);
    }

    public function testGetLanguageWithEmptyString(): void
    {

        $name = self::$languageService->getLanguageName('');
        $this->assertEquals('', $name);
    }

    public function testGetLanguageWithNonNumericString(): void
    {

        $name = self::$languageService->getLanguageName('invalid');
        $this->assertEquals('', $name);
    }

    // ===== getScriptDirectionTag() tests =====

    public function testGetScriptDirectionTagWithLTRLanguage(): void
    {

        // Insert test language (LTR)
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI, LgRightToLeft)
                         VALUES ('test_proc_ltr', 'http://test', 'http://test', 0)");
        $lgId = (int)Connection::lastInsertId();

        $tag = self::$languageService->getScriptDirectionTag($lgId);

        $this->assertEquals('', $tag);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    public function testGetScriptDirectionTagWithRTLLanguage(): void
    {

        // Insert test language (RTL)
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI, LgRightToLeft)
                         VALUES ('test_proc_rtl', 'http://test', 'http://test', 1)");
        $lgId = (int)Connection::lastInsertId();

        $tag = self::$languageService->getScriptDirectionTag($lgId);

        $this->assertEquals(' dir="rtl" ', $tag);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    public function testGetScriptDirectionTagWithStringId(): void
    {

        // Insert test language (RTL)
        Connection::query("INSERT INTO languages (LgName, LgDict1URI, LgGoogleTranslateURI, LgRightToLeft)
                         VALUES ('test_proc_rtl_str', 'http://test', 'http://test', 1)");
        $lgId = (int)Connection::lastInsertId();

        $tag = self::$languageService->getScriptDirectionTag((string)$lgId);

        $this->assertEquals(' dir="rtl" ', $tag);

        // Clean up
        Connection::query("DELETE FROM languages WHERE LgID = $lgId");
    }

    public function testGetScriptDirectionTagWithNull(): void
    {

        $tag = self::$languageService->getScriptDirectionTag(null);
        $this->assertEquals('', $tag);
    }

    public function testGetScriptDirectionTagWithEmptyString(): void
    {

        $tag = self::$languageService->getScriptDirectionTag('');
        $this->assertEquals('', $tag);
    }

    public function testGetScriptDirectionTagWithNonNumericString(): void
    {

        $tag = self::$languageService->getScriptDirectionTag('invalid');
        $this->assertEquals('', $tag);
    }

    // ===== todoWordsCount() tests =====

    public function testTodoWordsCountWithNoUnknownWords(): void
    {

        // Use a non-existent text ID
        $statsService = new TextStatisticsService();
        $count = $statsService->getTodoWordsCount(999999);
        $this->assertEquals(0, $count);
    }

    public function testTodoWordsCountReturnsInteger(): void
    {

        $statsService = new TextStatisticsService();
        $count = $statsService->getTodoWordsCount(1);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // ===== returnTextWordCount() tests =====

    public function testReturnTextWordCountReturnsArray(): void
    {

        $statsService = new TextStatisticsService();
        $result = $statsService->getTextWordCount([1]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('expr', $result);
        $this->assertArrayHasKey('stat', $result);
        $this->assertArrayHasKey('totalu', $result);
        $this->assertArrayHasKey('expru', $result);
        $this->assertArrayHasKey('statu', $result);
    }

    public function testReturnTextWordCountWithMultipleTexts(): void
    {

        $statsService = new TextStatisticsService();
        $result = $statsService->getTextWordCount([1, 2]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testReturnTextWordCountWithNonExistentText(): void
    {

        $statsService = new TextStatisticsService();
        $result = $statsService->getTextWordCount([999999]);

        $this->assertIsArray($result);
        // Should return empty arrays for each key
        $this->assertIsArray($result['total']);
    }

    // ===== Additional helper function tests =====

    public function testGetFirstSepaReturnsString(): void
    {
        $sepa = StringUtils::getFirstSeparator();
        $this->assertIsString($sepa);
        $this->assertNotEmpty($sepa);
        $this->assertEquals(1, mb_strlen($sepa, 'UTF-8'));
    }

    public function testGetSepassReturnsString(): void
    {
        $sepas = StringUtils::getSeparators();
        $this->assertIsString($sepas);
        $this->assertNotEmpty($sepas);
    }

    public function testGetStatusNameReturnsStrings(): void
    {
        // Test all valid statuses
        for ($i = 1; $i <= 5; $i++) {
            $name = StatusHelper::getName($i);
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }

        $ignored = StatusHelper::getName(98);
        $this->assertEquals('Ignored', $ignored);

        $wellKnown = StatusHelper::getName(99);
        $this->assertEquals('Well Known', $wellKnown);
    }

    public function testGetStatusAbbrReturnsStrings(): void
    {
        // Numeric statuses 1-5 have language-neutral digit abbreviations.
        for ($i = 1; $i <= 5; $i++) {
            $abbr = StatusHelper::getAbbr($i);
            $this->assertIsString($abbr);
            $this->assertNotEmpty($abbr);
        }

        // 98/99 no longer carry hand-invented abbreviations; display code
        // falls back to the localized full status name when abbr is empty.
        $this->assertSame('', StatusHelper::getAbbr(98));
        $this->assertSame('', StatusHelper::getAbbr(99));
    }

    public function testCheckStatusRangeWithValidRanges(): void
    {
        // Status 1 within range 15 (1-5)
        $this->assertTrue(StatusHelper::checkRange(1, 15));
        $this->assertTrue(StatusHelper::checkRange(3, 15));
        $this->assertTrue(StatusHelper::checkRange(5, 15));

        // Status 5 or 99 within range 599
        $this->assertTrue(StatusHelper::checkRange(5, 599));
        $this->assertTrue(StatusHelper::checkRange(99, 599));
    }

    public function testCheckStatusRangeWithInvalidRanges(): void
    {
        $this->assertFalse(StatusHelper::checkRange(1, 23)); // 1 < 2
        $this->assertFalse(StatusHelper::checkRange(4, 599)); // 4 != 5 and 4 != 99
        $this->assertFalse(StatusHelper::checkRange(1, 0)); // Invalid range
    }

    public function testGetColoredStatusMsgReturnsHTML(): void
    {
        $msg = StatusHelper::buildColoredMessage(1, StatusHelper::getName(1), StatusHelper::getAbbr(1));
        $this->assertStringContainsString('Learning', $msg);
        $this->assertStringContainsString('status', $msg);

        $msg = StatusHelper::buildColoredMessage(98, StatusHelper::getName(98), StatusHelper::getAbbr(98));
        $this->assertStringContainsString('Ignored', $msg);
    }

    public function testRemoveSoftHyphens(): void
    {
        $this->assertEquals('hello', StringUtils::removeSoftHyphens('hel­lo'));
        $this->assertEquals('world', StringUtils::removeSoftHyphens('world'));
        $this->assertEquals('', StringUtils::removeSoftHyphens(''));
        $this->assertEquals('testing', StringUtils::removeSoftHyphens('test­­ing'));
    }

    public function testMakeCounterWithTotal(): void
    {
        // Single item - should return empty
        $this->assertEquals('', StringUtils::makeCounterWithTotal(1, 1));

        // Less than 10 items
        $this->assertEquals('3/5', StringUtils::makeCounterWithTotal(5, 3));
        $this->assertEquals('1/9', StringUtils::makeCounterWithTotal(9, 1));

        // 10 or more items - should pad with zeros
        $this->assertEquals('03/10', StringUtils::makeCounterWithTotal(10, 3));
        $this->assertEquals('025/100', StringUtils::makeCounterWithTotal(100, 25));
        $this->assertEquals('0005/1000', StringUtils::makeCounterWithTotal(1000, 5));
    }

    public function testEncodeURI(): void
    {
        $this->assertEquals('hello%20world', StringUtils::encodeURI('hello world'));
        $this->assertEquals('test-file_name.txt', StringUtils::encodeURI('test-file_name.txt'));
        $this->assertEquals('path/to/file', StringUtils::encodeURI('path/to/file'));
        $this->assertEquals('query?param=value&other=2', StringUtils::encodeURI('query?param=value&other=2'));
        $this->assertEquals('#anchor', StringUtils::encodeURI('#anchor'));
    }

    public function testGetChecked(): void
    {
        $this->assertEquals(' checked="checked" ', FormHelper::getChecked(true));
        $this->assertEquals(' checked="checked" ', FormHelper::getChecked(1));
        $this->assertEquals(' checked="checked" ', FormHelper::getChecked('yes'));
        $this->assertEquals('', FormHelper::getChecked(false));
        $this->assertEquals('', FormHelper::getChecked(0));
        $this->assertEquals('', FormHelper::getChecked(''));
        $this->assertEquals('', FormHelper::getChecked(null));
    }

    public function testGetSelected(): void
    {
        $this->assertEquals(' selected="selected" ', FormHelper::getSelected('apple', 'apple'));
        $this->assertEquals(' selected="selected" ', FormHelper::getSelected(5, 5));
        $this->assertEquals('', FormHelper::getSelected('apple', 'orange'));
        $this->assertEquals('', FormHelper::getSelected(5, 10));
        $this->assertEquals(' selected="selected" ', FormHelper::getSelected('0', 0));
    }

    public function testStrToHex(): void
    {
        // strToHex returns UPPERCASE hex
        $this->assertEquals('68656C6C6F', StringUtils::toHex('hello'));
        $this->assertEquals('776F726C64', StringUtils::toHex('world'));
        $this->assertEquals('', StringUtils::toHex(''));

        // Test with UTF-8
        $hex = StringUtils::toHex('你好');
        $this->assertIsString($hex);
        $this->assertNotEmpty($hex);
    }

    public function testReplTabNl(): void
    {
        $this->assertEquals('hello world', ExportService::replaceTabNewline("hello\tworld"));
        $this->assertEquals('line one line two', ExportService::replaceTabNewline("line one\nline two"));
        // Multiple whitespace is collapsed to single space
        $this->assertEquals('test spaces', ExportService::replaceTabNewline("test\t\nspaces"));
    }
}
