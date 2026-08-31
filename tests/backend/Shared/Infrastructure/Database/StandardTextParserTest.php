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

use Lwt\Shared\Infrastructure\Database\StandardTextParser;
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

    #[Test]
    public function getLanguageSettingsRequiresDatabase(): void
    {
        $this->markTestSkipped('Database connection required');
    }
}
