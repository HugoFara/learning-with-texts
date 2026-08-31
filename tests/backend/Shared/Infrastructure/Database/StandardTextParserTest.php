<?php

/**
 * Unit tests for StandardTextParser.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Shared\Infrastructure\Database
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Backend\Shared\Infrastructure\Database;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\StandardTextParser;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for StandardTextParser static methods.
 *
 * @since  3.0.0
 */
#[CoversClass(StandardTextParser::class)]
class StandardTextParserTest extends TestCase
{
    // =========================================================================
    // applyInitialTransformations
    // =========================================================================

    #[Test]
    public function applyInitialTransformationsReplacesNewlinesWithPilcrow(): void
    {
        $result = StandardTextParser::applyInitialTransformations(
            "Line one\nLine two",
            false
        );

        $this->assertStringNotContainsString("\n", $result);
        $this->assertStringContainsString("\xC2\xB6", $result); // ¶ in UTF-8
    }

    #[Test]
    public function applyInitialTransformationsTrimsText(): void
    {
        $result = StandardTextParser::applyInitialTransformations(
            '  Hello world  ',
            false
        );

        $this->assertSame('Hello world', $result);
    }

    #[Test]
    public function applyInitialTransformationsCollapsesMultipleSpaces(): void
    {
        $result = StandardTextParser::applyInitialTransformations(
            "Hello   \t  world",
            false
        );

        $this->assertSame('Hello world', $result);
    }

    #[Test]
    public function applyInitialTransformationsWithSplitEachCharInsertsSpaces(): void
    {
        $result = StandardTextParser::applyInitialTransformations('abc', true);

        // Each non-ws char gets a tab appended, then whitespace collapses
        // 'abc' -> "a\tb\tc\t" -> "a b c " (trailing space from last tab)
        $this->assertSame('a b c', trim($result));
    }

    #[Test]
    public function applyInitialTransformationsWithSplitEachCharPreservesExistingSpaces(): void
    {
        $result = StandardTextParser::applyInitialTransformations('a b', true);

        // 'a b' -> "a\t b\t" -> "a b " (whitespace collapses)
        $this->assertSame('a b', trim($result));
    }

    #[Test]
    public function applyInitialTransformationsWithEmptyString(): void
    {
        $result = StandardTextParser::applyInitialTransformations('', false);

        $this->assertSame('', $result);
    }

    #[Test]
    public function applyInitialTransformationsWithOnlyWhitespace(): void
    {
        $result = StandardTextParser::applyInitialTransformations('   ', false);

        $this->assertSame('', $result);
    }

    #[Test]
    public function applyInitialTransformationsWithMultipleNewlines(): void
    {
        $result = StandardTextParser::applyInitialTransformations(
            "A\n\nB",
            false
        );

        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
        $this->assertStringContainsString("\xC2\xB6", $result);
    }

    #[Test]
    public function applyInitialTransformationsPreservesUnicode(): void
    {
        $result = StandardTextParser::applyInitialTransformations(
            'Bonjour le monde',
            false
        );

        $this->assertSame('Bonjour le monde', $result);
    }

    #[Test]
    public function applyInitialTransformationsWithSplitEachCharHandlesUnicode(): void
    {
        $result = StandardTextParser::applyInitialTransformations('日本', true);

        // '日本' -> "日\t本\t" -> "日 本 " (trailing space)
        $this->assertSame('日 本', trim($result));
    }

    // =========================================================================
    // splitStandardSentences
    // =========================================================================

    #[Test]
    public function splitStandardSentencesBasicSplit(): void
    {
        // Input is preprocessed text with \r as sentence delimiters
        $text = "Hello world.\rThis is a test.";

        $result = StandardTextParser::splitStandardSentences($text, '0');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('Hello world.', $result[0]);
        $this->assertSame('This is a test.', $result[1]);
    }

    #[Test]
    public function splitStandardSentencesWithNoDelimiters(): void
    {
        $result = StandardTextParser::splitStandardSentences(
            'No delimiters here',
            '0'
        );

        $this->assertCount(1, $result);
        $this->assertSame('No delimiters here', $result[0]);
    }

    #[Test]
    public function splitStandardSentencesRemovesTabs(): void
    {
        $result = StandardTextParser::splitStandardSentences(
            "Word1\tWord2",
            '0'
        );

        $this->assertCount(1, $result);
        $this->assertStringNotContainsString("\t", $result[0]);
    }

    #[Test]
    public function splitStandardSentencesRemovesNewlines(): void
    {
        $result = StandardTextParser::splitStandardSentences(
            "Word1\nWord2",
            '0'
        );

        $this->assertCount(1, $result);
        $this->assertStringNotContainsString("\n", $result[0]);
    }

    #[Test]
    public function splitStandardSentencesCollapsesDoubleCarriageReturns(): void
    {
        // \r\r collapses to \r => only one split
        $result = StandardTextParser::splitStandardSentences(
            "Sentence one.\r\rSentence two.",
            '0'
        );

        $this->assertCount(2, $result);
    }

    #[Test]
    public function splitStandardSentencesWithRemoveSpacesStripsSpaces(): void
    {
        $result = StandardTextParser::splitStandardSentences(
            "Hello world.\rNext sentence.",
            '1'
        );

        // When removeSpaces is truthy, spaces are removed
        $this->assertCount(2, $result);
        $this->assertStringNotContainsString(' ', $result[0]);
    }

    #[Test]
    public function splitStandardSentencesWithEmptyInput(): void
    {
        $result = StandardTextParser::splitStandardSentences('', '0');

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]);
    }

    // =========================================================================
    // getLanguageSettings (requires DB)
    // =========================================================================

    /**
     * Create a language and return its ID.
     *
     * @param int $removeSpaces  LgRemoveSpaces
     * @param int $splitEachChar LgSplitEachChar
     *
     * @return int The new language's ID
     */
    private function makeLanguage(int $removeSpaces, int $splitEachChar): int
    {
        Connection::preparedExecute(
            "INSERT INTO languages (LgName, LgDict1URI, LgRegexpWordCharacters,
                LgRegexpSplitSentences, LgExceptionsSplitSentences,
                LgCharacterSubstitutions, LgRemoveSpaces, LgSplitEachChar,
                LgParserType, LgRightToLeft, LgTextSize)
             VALUES (?, 'https://example.org/???', ?, '.!?:;。！？：；', '', '', ?, ?,
                NULL, 0, 150)",
            [
                'SPTest' . substr(md5(uniqid('', true)), 0, 8),
                '\\x{4E00}-\\x{9FFF}',
                $removeSpaces,
                $splitEachChar,
            ]
        );
        return (int) Connection::lastInsertId();
    }

    /**
     * Remove a language created for a test.
     *
     * @param int $lid Language ID
     *
     * @return void
     */
    private function dropLanguage(int $lid): void
    {
        Connection::preparedExecute("DELETE FROM languages WHERE LgID = ?", [$lid]);
    }

    #[Test]
    public function aSpacelessLanguageWithoutATokenizerSplitsEachCharacter(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }

        // #278: reaching this parser means no tokenizer ran, and a language
        // written without spaces gives a regex no gaps to match on — it takes
        // the longest run of word characters, which is the whole sentence. The
        // reader then sees one unclickable "word" per sentence.
        $lid = $this->makeLanguage(removeSpaces: 1, splitEachChar: 0);
        try {
            $settings = StandardTextParser::getLanguageSettings($lid);
            $this->assertNotNull($settings);
            $this->assertTrue(
                $settings['splitEachChar'],
                'a spaceless language must fall back to character splitting'
            );
        } finally {
            $this->dropLanguage($lid);
        }
    }

    #[Test]
    public function aSpaceSeparatedLanguageIsLeftAlone(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }

        // The fallback must not reach a language whose words really are
        // separated by spaces: splitting French into characters would be worse
        // than anything #278 describes.
        $lid = $this->makeLanguage(removeSpaces: 0, splitEachChar: 0);
        try {
            $settings = StandardTextParser::getLanguageSettings($lid);
            $this->assertNotNull($settings);
            $this->assertFalse($settings['splitEachChar']);
        } finally {
            $this->dropLanguage($lid);
        }
    }

    #[Test]
    public function anExplicitSplitFlagIsStillHonoured(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }

        $lid = $this->makeLanguage(removeSpaces: 0, splitEachChar: 1);
        try {
            $settings = StandardTextParser::getLanguageSettings($lid);
            $this->assertNotNull($settings);
            $this->assertTrue($settings['splitEachChar']);
        } finally {
            $this->dropLanguage($lid);
        }
    }

    #[Test]
    public function aSpacelessTextParsesIntoCharactersRatherThanSentences(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }

        // The reported symptom, end to end: every sentence came back as one
        // long term that could not be clicked, looked up or learned (#278).
        $lid = $this->makeLanguage(removeSpaces: 1, splitEachChar: 0);
        try {
            $report = TextParsing::checkTextReport('我昨天去了图书馆。今天天气很好。', $lid);
            $terms = array_map(static fn (array $w): string => $w[0], $report['words']);
            $this->assertNotEmpty($terms);
            foreach ($terms as $term) {
                $this->assertSame(
                    1,
                    mb_strlen($term, 'UTF-8'),
                    "term '$term' spans more than one character"
                );
            }
        } finally {
            $this->dropLanguage($lid);
        }
    }
}
