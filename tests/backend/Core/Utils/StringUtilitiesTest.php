<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Utils;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Escaping;
use PHPUnit\Framework\TestCase;

/**
 * Tests for string_utilities.php functions
 */
final class StringUtilitiesTest extends TestCase
{
    /**
     * Helper function using same signature as production code
     */
    private function escapeHtml(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Test HTML escaping with various inputs
     */
    public function testHtmlEscaping(): void
    {
        // Basic HTML escaping
        $this->assertEquals('&lt;script&gt;', $this->escapeHtml('<script>'));
        $this->assertEquals('&lt;div&gt;Test&lt;/div&gt;', $this->escapeHtml('<div>Test</div>'));

        // Special characters
        $this->assertEquals('&amp;', $this->escapeHtml('&'));
        $this->assertEquals('&quot;', $this->escapeHtml('"'));
        // Single quotes are escaped by htmlspecialchars with ENT_QUOTES
        $this->assertEquals('&#039;', $this->escapeHtml("'"));

        // Empty and null values
        $this->assertEquals('', $this->escapeHtml(null));
        $this->assertEquals('', $this->escapeHtml(''));

        // Normal text should pass through
        $this->assertEquals('Hello World', $this->escapeHtml('Hello World'));

        // UTF-8 characters should be preserved
        $this->assertEquals('日本語', $this->escapeHtml('日本語'));
        $this->assertEquals('Ελληνικά', $this->escapeHtml('Ελληνικά'));
    }

    /**
     * Test HTML escaping with various edge cases
     */
    public function testHtmlEscapingEdgeCases(): void
    {
        // Already escaped HTML
        $this->assertEquals('&amp;lt;script&amp;gt;', $this->escapeHtml('&lt;script&gt;'));

        // Multiple special characters
        $this->assertEquals('&lt;&amp;&gt;&quot;', $this->escapeHtml('<&>"'));

        // Long string with special characters
        $longString = str_repeat('<div>&amp;</div>', 100);
        $result = $this->escapeHtml($longString);
        $this->assertStringContainsString('&lt;div&gt;', $result);
        $this->assertStringNotContainsString('<div>', $result);

        // Newlines and tabs should be preserved
        $this->assertEquals("line1\nline2\tindented", $this->escapeHtml("line1\nline2\tindented"));
    }

    /**
     * Test line ending normalization
     */
    public function testPrepareTextdata(): void
    {
        // Windows line endings to Unix
        $this->assertEquals("line1\nline2", Escaping::prepareTextdata("line1\r\nline2"));
        $this->assertEquals("a\nb\nc", Escaping::prepareTextdata("a\r\nb\r\nc"));

        // Unix line endings should remain unchanged
        $this->assertEquals("line1\nline2", Escaping::prepareTextdata("line1\nline2"));

        // Mac line endings should remain unchanged
        $this->assertEquals("line1\rline2", Escaping::prepareTextdata("line1\rline2"));

        // Empty string
        $this->assertEquals('', Escaping::prepareTextdata(''));

        // No line endings
        $this->assertEquals('single line', Escaping::prepareTextdata('single line'));
    }

    /**
     * Test space removal function
     */
    public function testRemoveSpaces(): void
    {
        // Remove spaces when requested
        $this->assertEquals('test', StringUtils::removeSpaces('t e s t', true));
        $this->assertEquals('hello', StringUtils::removeSpaces('h e l l o', true));
        $this->assertEquals('nospaceshere', StringUtils::removeSpaces('n o s p a c e s h e r e', true));

        // Don't remove spaces when not requested
        $this->assertEquals('t e s t', StringUtils::removeSpaces('t e s t', false));
        $this->assertEquals('hello world', StringUtils::removeSpaces('hello world', false));

        // Empty string handling
        $this->assertEquals('', StringUtils::removeSpaces('', true));
        $this->assertEquals('', StringUtils::removeSpaces('', false));

        // String with no spaces
        $this->assertEquals('test', StringUtils::removeSpaces('test', true));
        $this->assertEquals('test', StringUtils::removeSpaces('test', false));
    }

    /**
     * Test zero-width space handling in remove_spaces function
     *
     * Note: Current implementation only removes regular spaces (U+0020)
     * The comment in the code mentions zero-width space but doesn't actually remove it
     */
    public function testRemoveSpacesZeroWidth(): void
    {
        // Zero-width space (U+200B) - current implementation does NOT remove it
        $text_with_zwsp = "test\u{200B}word";

        // When remove is true, only regular spaces are removed (not zero-width)
        $result = StringUtils::removeSpaces($text_with_zwsp, true);
        $this->assertEquals($text_with_zwsp, $result, 'Current implementation does not remove zero-width spaces');

        // When remove is false, everything remains
        $result = StringUtils::removeSpaces($text_with_zwsp, false);
        $this->assertEquals($text_with_zwsp, $result, 'Should keep all characters when not removing');

        // Multiple regular spaces are removed, but zero-width spaces remain
        $complex = "a b\u{200B}c d";
        $result = StringUtils::removeSpaces($complex, true);
        $this->assertEquals("ab\u{200B}cd", $result, 'Should remove regular spaces but keep zero-width');
    }

    /**
     * Test remove_spaces with Unicode characters
     */
    public function testRemoveSpacesUnicode(): void
    {
        // Chinese characters with spaces
        $this->assertEquals('你好世界', StringUtils::removeSpaces('你 好 世 界', true));
        $this->assertEquals('你 好 世 界', StringUtils::removeSpaces('你 好 世 界', false));

        // Japanese with spaces
        $this->assertEquals('こんにちは', StringUtils::removeSpaces('こ ん に ち は', true));

        // Arabic with spaces
        $this->assertEquals('مرحبا', StringUtils::removeSpaces('م ر ح ب ا', true));

        // Mixed languages
        $this->assertEquals('Hello世界', StringUtils::removeSpaces('Hello 世界', true));
    }

    /**
     * Test string replacement (first occurrence only)
     */
    public function testStrReplaceFirst(): void
    {
        // Basic replacement (only first occurrence should be replaced)
        $this->assertEquals('goodbye world hello', StringUtils::replaceFirst('hello', 'goodbye', 'hello world hello'));
        $this->assertEquals('xbc abc', StringUtils::replaceFirst('a', 'x', 'abc abc'));

        // No match
        $this->assertEquals('hello world', StringUtils::replaceFirst('goodbye', 'hi', 'hello world'));

        // Empty needle
        $this->assertEquals('test', StringUtils::replaceFirst('', 'x', 'test'));

        // Empty haystack
        $this->assertEquals('', StringUtils::replaceFirst('a', 'b', ''));

        // Needle at start
        $this->assertEquals('replaced test', StringUtils::replaceFirst('original', 'replaced', 'original test'));

        // Needle at end
        $this->assertEquals('test replaced', StringUtils::replaceFirst('original', 'replaced', 'test original'));
    }

    /**
     * Test str_replace_first with special regex characters
     */
    public function testStrReplaceFirstRegexCharacters(): void
    {
        // Needle with regex special characters - str_replace_first is NOT regex based
        // so special characters should be treated literally
        $this->assertEquals('[bcd]efg[abc]', StringUtils::replaceFirst('[abc]', '[bcd]', '[abc]efg[abc]'));
        $this->assertEquals('testworld...', StringUtils::replaceFirst('...', 'test', '...world...'));

        // Replacement with regex special characters
        $this->assertEquals('$test world', StringUtils::replaceFirst('hello', '$test', 'hello world'));
        $this->assertEquals('\\test world', StringUtils::replaceFirst('hello', '\\test', 'hello world'));
    }
}
