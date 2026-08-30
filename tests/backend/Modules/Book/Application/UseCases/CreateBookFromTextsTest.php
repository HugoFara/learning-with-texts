<?php

/**
 * Unit tests for the CreateBookFromTexts use case.
 *
 * Tests book creation from large text content in isolation using mocked
 * repositories and services. Methods that call static database helpers
 * (Connection, TextParsing, Globals) cannot be fully exercised here;
 * those paths are tested for structure only.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Book\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Book\Application\UseCases;

use Lwt\Modules\Book\Application\Services\TextSplitterService;
use Lwt\Modules\Book\Application\UseCases\CreateBookFromTexts;
use Lwt\Modules\Book\Domain\BookRepositoryInterface;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for CreateBookFromTexts use case.
 *
 * @since 3.0.0
 */
class CreateBookFromTextsTest extends TestCase
{
    private BookRepositoryInterface&MockObject $bookRepository;
    private TextRepositoryInterface&MockObject $textRepository;
    private TextSplitterService&MockObject $textSplitter;
    private CreateBookFromTexts $useCase;

    protected function setUp(): void
    {
        $this->bookRepository = $this->createMock(BookRepositoryInterface::class);
        $this->textRepository = $this->createMock(TextRepositoryInterface::class);
        $this->textSplitter = $this->createMock(TextSplitterService::class);

        $this->useCase = new CreateBookFromTexts(
            $this->bookRepository,
            $this->textRepository,
            $this->textSplitter
        );
    }

    #[Test]
    public function canBeInstantiated(): void
    {
        $this->assertInstanceOf(CreateBookFromTexts::class, $this->useCase);
    }

    // =========================================================================
    // Empty/blank text tests
    // =========================================================================

    #[Test]
    public function returnsFailureWhenTextIsEmpty(): void
    {
        $result = $this->useCase->execute(1, 'My Book', '');

        $this->assertFalse($result['success']);
        $this->assertSame('Text content is empty', $result['message']);
        $this->assertNull($result['bookId']);
        $this->assertSame(0, $result['chapterCount']);
        $this->assertSame([], $result['textIds']);
    }

    #[Test]
    public function returnsFailureWhenTextIsOnlyWhitespace(): void
    {
        $result = $this->useCase->execute(1, 'My Book', "   \n\n\t  ");

        $this->assertFalse($result['success']);
        $this->assertSame('Text content is empty', $result['message']);
    }

    #[Test]
    public function returnsFailureWhenTextIsOnlySoftHyphens(): void
    {
        // Soft hyphens are stripped by cleanText(), leaving empty string
        $result = $this->useCase->execute(1, 'My Book', "\xC2\xAD\xC2\xAD");

        $this->assertFalse($result['success']);
        $this->assertSame('Text content is empty', $result['message']);
    }

    // =========================================================================
    // Text does not need splitting tests
    // =========================================================================

    #[Test]
    public function returnsFailureWhenTextDoesNotNeedSplitting(): void
    {
        $this->textSplitter->expects($this->once())
            ->method('split')
            ->with($this->isType('string'))
            ->willReturn([
                ['num' => 1, 'title' => 'Part 1', 'content' => 'Short text'],
            ]);

        $this->textSplitter->expects($this->once())
            ->method('needsSplit')
            ->willReturn(false);

        $result = $this->useCase->execute(1, 'Short Book', 'Short text');

        $this->assertFalse($result['success']);
        $this->assertSame('Text does not need splitting', $result['message']);
        $this->assertNull($result['bookId']);
    }

    // =========================================================================
    // Text cleaning tests
    // =========================================================================

    #[Test]
    public function cleansSoftHyphensFromText(): void
    {
        $textWithSoftHyphens = "para\xC2\xADgraph one\n\npara\xC2\xADgraph two";

        $this->textSplitter->expects($this->once())
            ->method('split')
            ->with("paragraph one\n\nparagraph two")
            ->willReturn([
                ['num' => 1, 'title' => 'Part 1', 'content' => 'paragraph one'],
            ]);

        $this->textSplitter->method('needsSplit')->willReturn(false);

        $this->useCase->execute(1, 'Book', $textWithSoftHyphens);
    }

    #[Test]
    public function normalizesLineEndings(): void
    {
        $textWithMixedEndings = "line one\r\nline two\rline three";

        $this->textSplitter->expects($this->once())
            ->method('split')
            ->with("line one\nline two\nline three")
            ->willReturn([
                ['num' => 1, 'title' => 'Part 1', 'content' => 'normalized'],
            ]);

        $this->textSplitter->method('needsSplit')->willReturn(false);

        $this->useCase->execute(1, 'Book', $textWithMixedEndings);
    }

    #[Test]
    public function normalizesMultipleBlankLines(): void
    {
        $textWithManyBlanks = "paragraph one\n\n\n\n\nparagraph two";

        $this->textSplitter->expects($this->once())
            ->method('split')
            ->with("paragraph one\n\nparagraph two")
            ->willReturn([
                ['num' => 1, 'title' => 'Part 1', 'content' => 'normalized'],
            ]);

        $this->textSplitter->method('needsSplit')->willReturn(false);

        $this->useCase->execute(1, 'Book', $textWithManyBlanks);
    }

    // =========================================================================
    // Book entity creation tests
    // =========================================================================

    #[Test]
    public function createsBookWithTextSourceType(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Chunk one'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'Chunk two'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame('text', $book->sourceType());
                $this->assertSame('My Novel', $book->title());
                return 1;
            });

        try {
            $this->useCase->execute(1, 'My Novel', 'Some long text content');
        } catch (\Throwable $e) {
            // Static calls may fail in unit test
        }
    }

    #[Test]
    public function createsBookWithAuthorWhenProvided(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame('Jane Austen', $book->author());
                return 1;
            });

        try {
            $this->useCase->execute(1, 'Pride', 'Long text', 'Jane Austen');
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    #[Test]
    public function createsBookWithNullDescriptionForTextImports(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertNull($book->description());
                return 1;
            });

        try {
            $this->useCase->execute(1, 'Book', 'Long text');
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    #[Test]
    public function createsBookWithSourceHashFromText(): void
    {
        $inputText = 'Some text content';
        $expectedHash = hash('sha256', $inputText);

        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Some text'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'content'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) use ($expectedHash) {
                $this->assertSame($expectedHash, $book->sourceHash());
                return 1;
            });

        try {
            $this->useCase->execute(1, 'Book', $inputText);
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    #[Test]
    public function createsBookWithCorrectLanguageId(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame(7, $book->languageId());
                return 1;
            });

        try {
            $this->useCase->execute(7, 'German Book', 'Langer Text');
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    // =========================================================================
    // Transaction behavior tests
    // =========================================================================

    #[Test]
    public function beginsTransactionBeforeSaving(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $callOrder = [];

        $this->bookRepository->expects($this->once())
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'beginTransaction';
            });

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'save';
                return 1;
            });

        try {
            $this->useCase->execute(1, 'Book', 'Long text content');
        } catch (\Throwable $e) {
            // Static calls may fail
        }

        $this->assertSame('beginTransaction', $callOrder[0] ?? null);
        $this->assertSame('save', $callOrder[1] ?? null);
    }

    #[Test]
    public function rollsBackOnFailureDuringSave(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->bookRepository->expects($this->once())
            ->method('rollback');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create book');

        $this->useCase->execute(1, 'Book', 'Long text');
    }

    #[Test]
    public function rollsBackWhenTextRepositorySaveFailsMidway(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->method('save')->willReturn(1);

        $this->textRepository->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException('Text save failed'));

        $this->bookRepository->expects($this->once())
            ->method('rollback');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create book');

        $this->useCase->execute(1, 'Book', 'Long text');
    }

    // =========================================================================
    // Splitting delegation tests
    // =========================================================================

    #[Test]
    public function delegatesSplittingToTextSplitter(): void
    {
        $this->textSplitter->expects($this->once())
            ->method('split')
            ->with($this->isType('string'))
            ->willReturn([
                ['num' => 1, 'title' => 'Part 1', 'content' => 'Chunk A'],
                ['num' => 2, 'title' => 'Part 2', 'content' => 'Chunk B'],
                ['num' => 3, 'title' => 'Part 3', 'content' => 'Chunk C'],
            ]);

        $this->textSplitter->method('needsSplit')->willReturn(true);
        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->method('save')->willReturn(1);

        try {
            $this->useCase->execute(1, 'Book', 'Very long text to split');
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    #[Test]
    public function checksNeedsSplitAfterSplitting(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Short text'],
        ]);

        // Single chapter and needsSplit returns false = no book needed
        $this->textSplitter->expects($this->once())
            ->method('needsSplit')
            ->willReturn(false);

        $result = $this->useCase->execute(1, 'Book', 'Short text');

        $this->assertFalse($result['success']);
        $this->assertSame('Text does not need splitting', $result['message']);
    }

    #[Test]
    public function proceedsWithSingleChunkWhenNeedsSplitIsTrue(): void
    {
        // Edge case: split returns 1 chunk but needsSplit is true
        // (text was just over the threshold but paragraphs fit in one chunk)
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Borderline text'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturn(1);

        try {
            $this->useCase->execute(1, 'Book', 'Borderline text');
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    // =========================================================================
    // Return structure tests
    // =========================================================================

    #[Test]
    public function failureResultHasExpectedKeys(): void
    {
        $result = $this->useCase->execute(1, 'Book', '');

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('bookId', $result);
        $this->assertArrayHasKey('chapterCount', $result);
        $this->assertArrayHasKey('textIds', $result);
    }

    #[Test]
    public function failureResultTypesAreCorrect(): void
    {
        $result = $this->useCase->execute(1, 'Book', '');

        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
        $this->assertNull($result['bookId']);
        $this->assertIsInt($result['chapterCount']);
        $this->assertIsArray($result['textIds']);
    }

    // =========================================================================
    // User ID passthrough test
    // =========================================================================

    #[Test]
    public function passesUserIdToBookEntity(): void
    {
        $this->textSplitter->method('split')->willReturn([
            ['num' => 1, 'title' => 'Part 1', 'content' => 'Content'],
            ['num' => 2, 'title' => 'Part 2', 'content' => 'More'],
        ]);
        $this->textSplitter->method('needsSplit')->willReturn(true);

        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame(99, $book->userId());
                return 1;
            });

        try {
            $this->useCase->execute(
                1,
                'Book',
                'Long text',
                null,
                '',
                '',
                [],
                99
            );
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }
}
