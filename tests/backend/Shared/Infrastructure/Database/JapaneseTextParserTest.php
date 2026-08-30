<?php

/**
 * Unit tests for JapaneseTextParser.
 *
 * PHP version 8.1
 *
 * @category Testing
 * @package  Lwt\Tests\Shared\Infrastructure\Database
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Backend\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Database\JapaneseTextParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for JapaneseTextParser static methods.
 *
 * @since  3.0.0
 */
#[CoversClass(JapaneseTextParser::class)]
class JapaneseTextParserTest extends TestCase
{
    // =========================================================================
    // splitJapaneseSentences
    // =========================================================================

    #[Test]
    public function splitJapaneseSentencesWithSimpleTextReturnsSingleElement(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences('Hello world');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('Hello world', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesWithEmptyStringReturnsSingleEmptyElement(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences('');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesWithNewlinesSplitsIntoMultiple(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences("Line one\nLine two");

        $this->assertCount(2, $result);
        $this->assertSame('Line one', $result[0]);
        $this->assertStringContainsString('Line two', $result[1]);
    }

    #[Test]
    public function splitJapaneseSentencesInsertsPilcrowAtNewlines(): void
    {
        // preg_replace("/[\n]+/u", "\n¶", $text) then explode("\n")
        $result = JapaneseTextParser::splitJapaneseSentences("First\nSecond");

        $this->assertSame('First', $result[0]);
        // Second element starts with pilcrow (¶ = U+00B6)
        $this->assertStringStartsWith("\xC2\xB6", $result[1]);
    }

    #[Test]
    public function splitJapaneseSentencesCollapsesConsecutiveNewlines(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences("A\n\n\nB");

        // Multiple newlines collapse to single \n¶ via regex
        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesCollapsesSpacesAndTabs(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences("Hello   \t  world");

        $this->assertCount(1, $result);
        $this->assertSame('Hello world', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesTrimsLeadingAndTrailingWhitespace(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences('  Hello  ');

        $this->assertSame('Hello', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesHandlesJapaneseCharacters(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences('これはテストです');

        $this->assertCount(1, $result);
        $this->assertSame('これはテストです', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesHandlesMultipleJapaneseParagraphs(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences("最初の文。\n二番目の文。");

        $this->assertCount(2, $result);
        $this->assertSame('最初の文。', $result[0]);
    }

    #[Test]
    public function splitJapaneseSentencesWithWhitespaceOnlyReturnsEmptyString(): void
    {
        $result = JapaneseTextParser::splitJapaneseSentences("   \t  ");

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]);
    }

    // =========================================================================
    // buildTokensFromMecab (pure MeCab-output -> tokens, no binary needed)
    // =========================================================================

    #[Test]
    public function buildTokensFromMecabProducesExpectedTokens(): void
    {
        // Captured MeCab output for "東京は大きいです。" in the parser's
        // "-F %m\t%t\t%h" format, terminated with the EOP sentence marker.
        $mecabed = "東京\t2\t46\nは\t6\t16\n大きい\t2\t10\n"
            . "です\t6\t25\n。\t3\t7\nEOP\t3\t7\n";

        $tokens = JapaneseTextParser::buildTokensFromMecab($mecabed);

        $this->assertCount(5, $tokens);
        $this->assertSame(
            ['東京', 'は', '大きい', 'です', '。'],
            array_map(fn($t) => $t->text, $tokens)
        );
        // Punctuation is a non-word; the rest are words.
        $this->assertSame([1, 1, 1, 1, 0], array_map(fn($t) => $t->wordCount, $tokens));
        // All in the first sentence (the trailing EOP order group is dropped).
        foreach ($tokens as $t) {
            $this->assertSame(1, $t->sentence);
        }
        // Global order is strictly increasing.
        $orders = array_map(fn($t) => $t->order, $tokens);
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders);
    }

    /**
     * MeCab's line terminator comes from MeCab, not from the host PHP runs on,
     * so splitting on PHP_EOL made this method host-dependent. Whenever the
     * output's terminator is not the one being split on, the whole output stays
     * a single line and every token collapses — on Windows
     * (PHP_EOL === "\r\n") an LF-terminated output produced zero tokens, which
     * is what failed the Windows CI jobs. Every terminator must now yield the
     * same tokens on every platform.
     *
     * The CR-only case reproduces that failure mode on a Linux host, where
     * PHP_EOL is "\n": before the fix it yielded 0 tokens instead of 5.
     */
    #[Test]
    public function buildTokensFromMecabIsIndependentOfLineEndings(): void
    {
        $lines = [
            "東京\t2\t46",
            "は\t6\t16",
            "大きい\t2\t10",
            "です\t6\t25",
            "。\t3\t7",
            "EOP\t3\t7",
        ];

        $flatten = fn(array $tokens) => array_map(
            fn($t) => [$t->text, $t->wordCount, $t->sentence, $t->order],
            $tokens
        );

        $lf = JapaneseTextParser::buildTokensFromMecab(implode("\n", $lines) . "\n");
        $this->assertCount(5, $lf);

        foreach (["\r\n" => 'CRLF', "\r" => 'CR-only'] as $eol => $label) {
            $tokens = JapaneseTextParser::buildTokensFromMecab(implode($eol, $lines) . $eol);

            $this->assertCount(5, $tokens, "$label output should tokenize");
            $this->assertSame(
                $flatten($lf),
                $flatten($tokens),
                "$label output should tokenize identically to LF"
            );
        }
    }

    #[Test]
    public function buildTokensFromMecabHandlesEmptyOutput(): void
    {
        $this->assertSame([], JapaneseTextParser::buildTokensFromMecab(''));
    }

    // =========================================================================
    // previewText
    // =========================================================================

    #[Test]
    public function previewTextCollapsesWhitespace(): void
    {
        $this->assertSame('Word1 Word2', JapaneseTextParser::previewText("Word1   \t   Word2"));
    }

    #[Test]
    public function previewTextTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Text', JapaneseTextParser::previewText("  \t Text \t "));
    }

    #[Test]
    public function previewTextKeepsNewlinesForTheCallerToRender(): void
    {
        $this->assertSame("Line one\nLine two", JapaneseTextParser::previewText("Line one\nLine two"));
    }
}
