<?php

/**
 * Unit tests for AnnotationService.
 *
 * Tests annotation processing, term formatting, JSON conversion,
 * and edge cases. Only tests methods that can run without database access.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Modules\Text\Application\Services;

use Lwt\Modules\Text\Application\Services\AnnotationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for AnnotationService.
 *
 * @since  3.0.0
 */
#[CoversClass(AnnotationService::class)]
class AnnotationServiceTest extends TestCase
{
    private AnnotationService $service;

    protected function setUp(): void
    {
        $this->service = new AnnotationService();
    }

    // =========================================================================
    // processTerm() - Non-term text (punctuation, spaces)
    // =========================================================================

    #[Test]
    public function processTermWithNontermOnly(): void
    {
        $result = $this->service->processTerm('. ', '', '', '', 1);

        $this->assertSame("-1\t. \n", $result);
    }

    #[Test]
    public function processTermWithEmptyNontermAndEmptyTerm(): void
    {
        $result = $this->service->processTerm('', '', '', '', 1);

        $this->assertSame('', $result);
    }

    #[Test]
    public function processTermWithNontermSpace(): void
    {
        $result = $this->service->processTerm(' ', '', '', '', 5);

        $this->assertSame("-1\t \n", $result);
    }

    #[Test]
    public function processTermWithNontermPunctuation(): void
    {
        $result = $this->service->processTerm('!', '', '', '', 10);

        $this->assertSame("-1\t!\n", $result);
    }

    // =========================================================================
    // processTerm() - Term text with translations
    // =========================================================================

    #[Test]
    public function processTermWithTermOnly(): void
    {
        $result = $this->service->processTerm('', 'hello', '', '', 3);

        // Format: line\tterm\twordid\ttranslation\n
        $this->assertStringStartsWith("3\thello\t\t", $result);
    }

    #[Test]
    public function processTermWithTermAndWordId(): void
    {
        $result = $this->service->processTerm('', 'hello', '', '42', 3);

        $this->assertSame("3\thello\t42\t\n", $result);
    }

    #[Test]
    public function processTermWithTermAndTranslation(): void
    {
        $result = $this->service->processTerm('', 'bonjour', 'hello', '42', 3);

        // getFirstTranslation may split on separators, but "hello" has none
        $this->assertStringContainsString("3\tbonjour\t42\t", $result);
    }

    #[Test]
    public function processTermWithNontermAndTerm(): void
    {
        $result = $this->service->processTerm(', ', 'world', '', '10', 5);

        // Should have both nonterm and term lines
        $this->assertStringStartsWith("-1\t, \n", $result);
        $this->assertStringContainsString("5\tworld\t10\t", $result);
    }

    #[Test]
    public function processTermLineNumberIsPreserved(): void
    {
        $result = $this->service->processTerm('', 'test', '', '1', 999);

        $this->assertStringStartsWith("999\ttest\t", $result);
    }

    #[Test]
    public function processTermLineZero(): void
    {
        $result = $this->service->processTerm('', 'word', '', '', 0);

        $this->assertStringStartsWith("0\tword\t", $result);
    }

    #[Test]
    public function processTermTrimsWordId(): void
    {
        $result = $this->service->processTerm('', 'test', '', '  42  ', 1);

        $this->assertStringContainsString("\t42\t", $result);
    }

    #[Test]
    public function processTermWithUnicodeText(): void
    {
        $result = $this->service->processTerm('', '日本語', '', '5', 1);

        $this->assertStringContainsString("1\t日本語\t5\t", $result);
    }

    #[Test]
    public function processTermWithUnicodeNonterm(): void
    {
        $result = $this->service->processTerm('。', '', '', '', 1);

        $this->assertSame("-1\t。\n", $result);
    }

    // =========================================================================
    // getFirstTranslation() - Translation string splitting
    // =========================================================================

    #[Test]
    public function getFirstTranslationWithEmptyString(): void
    {
        $result = $this->service->getFirstTranslation('');

        $this->assertSame('', $result);
    }

    #[Test]
    public function getFirstTranslationWithAsterisk(): void
    {
        $result = $this->service->getFirstTranslation('*');

        $this->assertSame('', $result);
    }

    #[Test]
    public function getFirstTranslationWithSingleWord(): void
    {
        $result = $this->service->getFirstTranslation('hello');

        $this->assertSame('hello', $result);
    }

    #[Test]
    public function getFirstTranslationTrimsWhitespace(): void
    {
        $result = $this->service->getFirstTranslation('  hello  ');

        $this->assertSame('hello', $result);
    }

    // =========================================================================
    // annotationToJson()
    // =========================================================================

    #[Test]
    public function annotationToJsonWithEmptyString(): void
    {
        $result = $this->service->annotationToJson('');

        $this->assertSame('{}', $result);
    }

    #[Test]
    public function annotationToJsonWithSingleAnnotation(): void
    {
        // Format: position\ttext\twordid\ttranslation
        $ann = "5\thello\t42\tbonjour\n";

        $result = $this->service->annotationToJson($ann);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(5, $decoded);
        $this->assertSame('hello', $decoded[5][0]);
        $this->assertSame('42', $decoded[5][1]);
        $this->assertSame('bonjour', $decoded[5][2]);
    }

    #[Test]
    public function annotationToJsonWithMultipleAnnotations(): void
    {
        $ann = "1\tword1\t10\ttrans1\n" .
               "5\tword2\t20\ttrans2\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey(1, $decoded);
        $this->assertArrayHasKey(5, $decoded);
    }

    #[Test]
    public function annotationToJsonSkipsNontermLines(): void
    {
        $ann = "-1\t, \n" .
               "3\tword\t5\ttranslation\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        // Only the term line (position 3) should be present
        $this->assertArrayHasKey(3, $decoded);
        $this->assertCount(1, $decoded);
    }

    #[Test]
    public function annotationToJsonSkipsLinesWithZeroWordId(): void
    {
        // vals[2] must be > 0 to be included
        $ann = "3\tword\t0\ttranslation\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    #[Test]
    public function annotationToJsonSkipsIncompleteLinesLessThan4Fields(): void
    {
        // Only 2 fields, needs > 3
        $ann = "3\tword\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    #[Test]
    public function annotationToJsonSkipsThreeFieldLines(): void
    {
        // Exactly 3 fields - still needs > 3
        $ann = "3\tword\t5\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    #[Test]
    public function annotationToJsonWithMixedContent(): void
    {
        $ann = "-1\t \n" .
               "1\thello\t10\tbonjour\n" .
               "-1\t, \n" .
               "3\tworld\t20\tmonde\n" .
               "-1\t.\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('hello', $decoded[1][0]);
        $this->assertSame('world', $decoded[3][0]);
    }

    #[Test]
    public function annotationToJsonWithUnicodeContent(): void
    {
        $ann = "1\t日本語\t5\tJapanese\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(1, $decoded);
        $this->assertSame('日本語', $decoded[1][0]);
    }

    #[Test]
    public function annotationToJsonReturnsValidJsonString(): void
    {
        $ann = "1\tword\t5\ttrans\n";

        $result = $this->service->annotationToJson($ann);

        $this->assertIsString($result);
        $this->assertNotFalse(json_decode($result));
    }

    #[Test]
    public function annotationToJsonPreservesPositionAsIntegerKey(): void
    {
        $ann = "10\tword\t5\ttrans\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey(10, $decoded);
    }

    #[Test]
    public function annotationToJsonHandlesEmptyTranslation(): void
    {
        $ann = "1\tword\t5\t\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(1, $decoded);
        $this->assertSame('', $decoded[1][2]);
    }

    #[Test]
    public function annotationToJsonHandlesTrailingNewlines(): void
    {
        $ann = "1\tword\t5\ttrans\n\n\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    #[Test]
    public function annotationToJsonLastAnnotationOverwritesDuplicate(): void
    {
        // Two annotations at same position - last one wins (array assignment)
        $ann = "1\tfirst\t5\ttrans1\n" .
               "1\tsecond\t10\ttrans2\n";

        $result = $this->service->annotationToJson($ann);

        $decoded = json_decode($result, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('second', $decoded[1][0]);
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    #[Test]
    public function canBeInstantiated(): void
    {
        $service = new AnnotationService();

        $this->assertInstanceOf(AnnotationService::class, $service);
    }
}
