<?php

/**
 * Unit tests for GetNextTerm use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Review\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Application\UseCases;

use Lwt\Modules\Review\Application\UseCases\GetNextTerm;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewWord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetNextTerm use case.
 *
 * Tests word retrieval, sentence formatting, and solution generation
 * for the spaced repetition review interface.
 *
 * @since 3.0.0
 */
class GetNextTermTest extends TestCase
{
    private ReviewRepositoryInterface&MockObject $repository;

    protected function setUp(): void
    {
        if (!defined('LWT_TEST_DB_AVAILABLE') || !LWT_TEST_DB_AVAILABLE) {
            $this->markTestSkipped('Database connection required');
        }
        $this->repository = $this->createMock(ReviewRepositoryInterface::class);
    }

    /**
     * Helper to create a ReviewWord instance.
     */
    private function makeWord(
        int $id = 1,
        string $text = 'hello',
        string $textLc = 'hello',
        string $translation = 'bonjour',
        ?string $romanization = null,
        ?string $sentence = null,
        int $langId = 1,
        int $status = 2,
        int $score = 50,
        int $daysOld = 3
    ): ReviewWord {
        return new ReviewWord(
            $id,
            $text,
            $textLc,
            $translation,
            $romanization,
            $sentence,
            $langId,
            $status,
            $score,
            $daysOld
        );
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    #[Test]
    public function canBeInstantiated(): void
    {
        $useCase = new GetNextTerm($this->repository);
        $this->assertInstanceOf(GetNextTerm::class, $useCase);
    }

    // =========================================================================
    // No word available
    // =========================================================================

    #[Test]
    public function executeReturnsEmptyResultWhenNoWordAvailable(): void
    {
        $config = ReviewConfiguration::fromLanguage(1, 1);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->with($config)
            ->willReturn(null);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame(0, $result['word_id']);
        $this->assertSame('', $result['word_text']);
        $this->assertSame('', $result['solution']);
        $this->assertSame('', $result['group']);
        $this->assertArrayNotHasKey('word', $result);
    }

    // =========================================================================
    // Word mode (no sentence lookup)
    // =========================================================================

    #[Test]
    public function executeInWordModeSkipsSentenceLookup(): void
    {
        $word = $this->makeWord(id: 42, text: 'gato', textLc: 'gato', translation: 'cat');
        // reviewType 4 => wordMode = true, baseType = 1
        $config = ReviewConfiguration::fromLanguage(1, 4, true);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        // Should NOT call getSentenceForWord since wordMode is true
        $this->repository->expects($this->never())
            ->method('getSentenceForWord');

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame(42, $result['word_id']);
        $this->assertSame('gato', $result['word_text']);
        $this->assertInstanceOf(ReviewWord::class, $result['word']);
    }

    // =========================================================================
    // Sentence mode: repository returns sentence
    // =========================================================================

    #[Test]
    public function executeInSentenceModeCallsRepositoryForSentence(): void
    {
        $word = $this->makeWord(
            id: 10,
            text: 'Katze',
            textLc: 'katze',
            translation: 'cat'
        );
        $config = ReviewConfiguration::fromLanguage(1, 1, false);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository->expects($this->once())
            ->method('getSentenceForWord')
            ->with(10, 'katze')
            ->willReturn(['sentence' => 'Die {Katze} ist klein.', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame(10, $result['word_id']);
        $this->assertSame('Katze', $result['word_text']);
    }

    // =========================================================================
    // Sentence mode: no sentence found falls back to word
    // =========================================================================

    #[Test]
    public function executeWithNoSentenceFoundFallsBackToWordBraces(): void
    {
        $word = $this->makeWord(
            id: 5,
            text: 'perro',
            textLc: 'perro',
            translation: 'dog'
        );
        $config = ReviewConfiguration::fromLanguage(1, 1, false);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository->expects($this->once())
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => null, 'found' => false]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame('perro', $result['word_text']);
    }

    // =========================================================================
    // Test type 1: term visible, translation as solution
    // =========================================================================

    #[Test]
    public function executeType1ShowsTermAndTranslationSolution(): void
    {
        $word = $this->makeWord(
            id: 7,
            text: 'chat',
            textLc: 'chat',
            translation: 'cat'
        );
        // Type 1: term -> translation
        $config = ReviewConfiguration::fromLanguage(1, 1, false);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository->expects($this->once())
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => 'Le {chat} dort.', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Type 1 should show the term in a span
        $this->assertStringContainsString('word-test', $result['group']);
        $this->assertStringContainsString('chat', $result['group']);
        // Solution should contain translation (in brackets for sentence mode)
        $this->assertStringContainsString('cat', $result['solution']);
    }

    // =========================================================================
    // Test type 2: term hidden
    // =========================================================================

    #[Test]
    public function executeType2HidesTermWithPlaceholder(): void
    {
        $word = $this->makeWord(
            id: 8,
            text: 'Hund',
            textLc: 'hund',
            translation: 'dog'
        );
        // Type 2: translation -> term
        $config = ReviewConfiguration::fromLanguage(1, 2, false);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository->expects($this->once())
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => 'Der {Hund} bellt.', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Type 2 hides the word
        $this->assertStringContainsString('[...]', $result['group']);
        // Solution is the word itself
        $this->assertSame('Hund', $result['solution']);
    }

    // =========================================================================
    // Test type 3: sentence -> term
    // =========================================================================

    #[Test]
    public function executeType3HidesTermAndShowsWordAsSolution(): void
    {
        $word = $this->makeWord(
            id: 9,
            text: 'libro',
            textLc: 'libro',
            translation: 'book'
        );
        $config = ReviewConfiguration::fromLanguage(1, 3, false);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository->expects($this->once())
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => 'El {libro} es bueno.', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertStringContainsString('[...]', $result['group']);
        $this->assertSame('libro', $result['solution']);
    }

    // =========================================================================
    // Test type > 3 maps to base type
    // =========================================================================

    #[Test]
    public function executeType4MapsToBaseType1(): void
    {
        $word = $this->makeWord(
            id: 11,
            text: 'agua',
            textLc: 'agua',
            translation: 'water'
        );
        // Type 4 = word mode, base type 1
        $config = ReviewConfiguration::fromLanguage(1, 4);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Base type 1 shows term
        $this->assertStringContainsString('word-test', $result['group']);
        $this->assertSame('agua', $result['word_text']);
    }

    #[Test]
    public function executeType5MapsToBaseType2(): void
    {
        $word = $this->makeWord(
            id: 12,
            text: 'fuego',
            textLc: 'fuego',
            translation: 'fire'
        );
        // Type 5 = word mode, base type 2
        $config = ReviewConfiguration::fromLanguage(1, 5);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Base type 2 hides the word
        $this->assertStringContainsString('[...]', $result['group']);
        $this->assertSame('fuego', $result['solution']);
    }

    // =========================================================================
    // Result structure
    // =========================================================================

    #[Test]
    public function executeReturnsWordEntityInResult(): void
    {
        $word = $this->makeWord(id: 20);
        $config = ReviewConfiguration::fromLanguage(1, 1, true);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('word', $result);
        $this->assertSame($word, $result['word']);
    }

    #[Test]
    public function executeResultContainsAllRequiredKeys(): void
    {
        $word = $this->makeWord(id: 30);
        $config = ReviewConfiguration::fromLanguage(1, 1, true);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('word_id', $result);
        $this->assertArrayHasKey('word_text', $result);
        $this->assertArrayHasKey('solution', $result);
        $this->assertArrayHasKey('group', $result);
    }

    // =========================================================================
    // HTML escaping in type 1
    // =========================================================================

    #[Test]
    public function executeType1EscapesHtmlInWordText(): void
    {
        $word = $this->makeWord(
            id: 50,
            text: '<script>',
            textLc: '<script>',
            translation: 'injection'
        );
        $config = ReviewConfiguration::fromLanguage(1, 1, true);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // The HTML should be escaped in the display
        $this->assertStringNotContainsString('<script>', $result['group']);
        $this->assertStringContainsString('&lt;script&gt;', $result['group']);
    }

    // =========================================================================
    // Configuration with text selection
    // =========================================================================

    #[Test]
    public function executeWithTextConfigPassesConfigToRepository(): void
    {
        $config = ReviewConfiguration::fromText(42, 2);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->with($config)
            ->willReturn(null);

        $useCase = new GetNextTerm($this->repository);
        $useCase->execute($config);
    }

    // =========================================================================
    // Braces cleanup
    // =========================================================================

    #[Test]
    public function executeCleansBracesFromDisplayHtml(): void
    {
        $word = $this->makeWord(
            id: 60,
            text: 'test',
            textLc: 'test',
            translation: 'prueba'
        );
        $config = ReviewConfiguration::fromLanguage(1, 1, false);

        $this->repository->expects($this->once())
            ->method('findNextWordForReview')
            ->willReturn($word);

        $this->repository->expects($this->once())
            ->method('getSentenceForWord')
            ->willReturn(['sentence' => 'A {test} case.', 'found' => true]);

        $useCase = new GetNextTerm($this->repository);
        $result = $useCase->execute($config);

        // Braces should be cleaned from final output
        $this->assertStringNotContainsString('{', $result['group']);
        $this->assertStringNotContainsString('}', $result['group']);
    }
}
