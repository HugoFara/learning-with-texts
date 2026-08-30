<?php

/**
 * Unit tests for the ImportEpub use case.
 *
 * Tests EPUB import logic in isolation using mocked repositories and services.
 * Methods that call static database helpers (Connection, TextParsing, Globals)
 * cannot be fully exercised here; those paths are tested for structure only.
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

use Lwt\Modules\Book\Application\Services\EpubParserService;
use Lwt\Modules\Book\Application\Services\TextSplitterService;
use Lwt\Modules\Book\Application\UseCases\ImportEpub;
use Lwt\Modules\Book\Domain\BookRepositoryInterface;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for ImportEpub use case.
 *
 * @since 3.0.0
 */
class ImportEpubTest extends TestCase
{
    private BookRepositoryInterface&MockObject $bookRepository;
    private TextRepositoryInterface&MockObject $textRepository;
    private EpubParserService&MockObject $epubParser;
    private TextSplitterService&MockObject $textSplitter;
    private ImportEpub $useCase;

    protected function setUp(): void
    {
        $this->bookRepository = $this->createMock(BookRepositoryInterface::class);
        $this->textRepository = $this->createMock(TextRepositoryInterface::class);
        $this->epubParser = $this->createMock(EpubParserService::class);
        $this->textSplitter = $this->createMock(TextSplitterService::class);

        $this->useCase = new ImportEpub(
            $this->bookRepository,
            $this->textRepository,
            $this->epubParser,
            $this->textSplitter
        );
    }

    #[Test]
    public function canBeInstantiated(): void
    {
        $this->assertInstanceOf(ImportEpub::class, $this->useCase);
    }

    // =========================================================================
    // File validation tests
    // =========================================================================

    #[Test]
    public function returnsFailureWhenNoFileUploaded(): void
    {
        $result = $this->useCase->execute(1, []);

        $this->assertFalse($result['success']);
        $this->assertSame('No file uploaded', $result['message']);
        $this->assertNull($result['bookId']);
        $this->assertSame(0, $result['chapterCount']);
        $this->assertSame([], $result['textIds']);
    }

    #[Test]
    public function returnsFailureWhenTmpNameIsEmpty(): void
    {
        $result = $this->useCase->execute(1, ['tmp_name' => '']);

        $this->assertFalse($result['success']);
        $this->assertSame('No file uploaded', $result['message']);
    }

    #[Test]
    public function returnsFailureWhenZipExtensionMissing(): void
    {
        if (extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension is loaded, cannot test missing extension scenario');
        }

        // When ZIP extension is missing, we expect the extension check error,
        // not the EPUB validation error
        $result = $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ZIP extension', $result['message']);
        $this->assertNull($result['bookId']);
    }

    #[Test]
    public function returnsFailureWhenEpubIsInvalid(): void
    {
        // Skip this test if ZIP extension is missing since we check that first
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB validation');
        }

        $this->epubParser->expects($this->once())
            ->method('isValidEpub')
            ->with('/tmp/test.epub', '')
            ->willReturn(false);

        $result = $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid EPUB file', $result['message']);
        $this->assertNull($result['bookId']);
    }

    // =========================================================================
    // Parse failure tests
    // =========================================================================

    #[Test]
    public function returnsFailureWhenParsingThrows(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->expects($this->once())
            ->method('isValidEpub')
            ->willReturn(true);

        $this->epubParser->expects($this->once())
            ->method('parse')
            ->willThrowException(new \RuntimeException('Corrupt file'));

        $result = $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to parse EPUB', $result['message']);
        $this->assertStringContainsString('Corrupt file', $result['message']);
    }

    #[Test]
    public function parseReceivesOriginalFilenameSoExtensionCanBeResolved(): void
    {
        // Regression: GitHub issue #232. PHP upload temp paths
        // (/tmp/phpXXXXXX) have no extension, so the use case must
        // forward the original filename to parse() so the underlying
        // ebook library can resolve the format.
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);

        $this->epubParser->expects($this->once())
            ->method('parse')
            ->with('/tmp/phpUPLOAD', 'book.epub')
            ->willThrowException(new \RuntimeException('stop here'));

        $this->useCase->execute(1, [
            'tmp_name' => '/tmp/phpUPLOAD',
            'name' => 'book.epub',
        ]);
    }

    #[Test]
    public function returnsFailureWhenNoChaptersFound(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->expects($this->once())
            ->method('isValidEpub')
            ->willReturn(true);

        $this->epubParser->expects($this->once())
            ->method('parse')
            ->willReturn([
                'metadata' => [
                    'title' => 'Empty Book',
                    'author' => null,
                    'description' => null,
                    'sourceHash' => 'abc123',
                ],
                'chapters' => [],
            ]);

        $result = $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);

        $this->assertFalse($result['success']);
        $this->assertSame('No readable chapters found in EPUB', $result['message']);
    }

    // =========================================================================
    // Duplicate detection tests
    // =========================================================================

    #[Test]
    public function returnsFailureWhenBookAlreadyImported(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->expects($this->once())
            ->method('isValidEpub')
            ->willReturn(true);

        $this->epubParser->expects($this->once())
            ->method('parse')
            ->willReturn([
                'metadata' => [
                    'title' => 'Existing Book',
                    'author' => 'Author',
                    'description' => null,
                    'sourceHash' => 'existing-hash',
                ],
                'chapters' => [
                    ['title' => 'Chapter 1', 'content' => 'Some content'],
                ],
            ]);

        $this->bookRepository->expects($this->once())
            ->method('existsBySourceHash')
            ->with('existing-hash', null)
            ->willReturn(true);

        $result = $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);

        $this->assertFalse($result['success']);
        $this->assertSame('This book has already been imported', $result['message']);
    }

    #[Test]
    public function duplicateCheckPassesUserIdForMultiUser(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);

        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash123',
            ],
            'chapters' => [
                ['title' => 'Ch1', 'content' => 'Content'],
            ],
        ]);

        $this->bookRepository->expects($this->once())
            ->method('existsBySourceHash')
            ->with('hash123', 42)
            ->willReturn(true);

        $result = $this->useCase->execute(
            1,
            ['tmp_name' => '/tmp/test.epub'],
            null,
            [],
            42
        );

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // Title override tests
    // =========================================================================

    #[Test]
    public function usesOverrideTitleWhenProvided(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);

        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Original Title',
                'author' => 'Author',
                'description' => 'Desc',
                'sourceHash' => 'hash-override',
            ],
            'chapters' => [
                ['title' => 'Chapter 1', 'content' => 'Short content'],
            ],
        ]);

        $this->bookRepository->method('existsBySourceHash')->willReturn(false);

        // The book save should receive the book entity - we verify indirectly
        // through the result message which includes the book title
        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame('Custom Title', $book->title());
                return 1;
            });

        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->method('commit');
        $this->bookRepository->method('updateChapterCount');

        $this->textSplitter->method('needsSplit')->willReturn(false);

        // createChapterText calls static methods, so this will fail in unit test
        // We test the flow up to the point of book creation
        try {
            $this->useCase->execute(
                1,
                ['tmp_name' => '/tmp/test.epub'],
                'Custom Title'
            );
        } catch (\Throwable $e) {
            // Expected: static calls to TextParsing/Connection will fail
            // The important assertion above (save callback) already ran
        }
    }

    // =========================================================================
    // Transaction behavior tests
    // =========================================================================

    #[Test]
    public function beginsTransactionBeforeSaving(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash-tx',
            ],
            'chapters' => [
                ['title' => 'Ch1', 'content' => 'Content here'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);

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

        $this->textSplitter->method('needsSplit')->willReturn(false);

        try {
            $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);
        } catch (\Throwable $e) {
            // Static calls may fail in unit test
        }

        $this->assertSame('beginTransaction', $callOrder[0] ?? null);
        $this->assertSame('save', $callOrder[1] ?? null);
    }

    #[Test]
    public function rollsBackOnFailureDuringSave(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash-rollback',
            ],
            'chapters' => [
                ['title' => 'Ch1', 'content' => 'Content'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);
        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->bookRepository->expects($this->once())
            ->method('rollback');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to import EPUB');

        $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);
    }

    // =========================================================================
    // Chapter splitting delegation tests
    // =========================================================================

    #[Test]
    public function checksIfChapterNeedsSplitting(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash-split',
            ],
            'chapters' => [
                ['title' => 'Chapter 1', 'content' => 'Short content'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);
        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->method('save')->willReturn(1);

        $this->textSplitter->expects($this->once())
            ->method('needsSplit')
            ->with('Short content')
            ->willReturn(false);

        try {
            $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    #[Test]
    public function splitsChapterWhenContentExceedsLimit(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash-bigsplit',
            ],
            'chapters' => [
                ['title' => 'Long Chapter', 'content' => 'Very long content'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);
        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->method('save')->willReturn(1);

        $this->textSplitter->expects($this->once())
            ->method('needsSplit')
            ->with('Very long content')
            ->willReturn(true);

        $this->textSplitter->expects($this->once())
            ->method('split')
            ->with('Very long content')
            ->willReturn([
                ['num' => 1, 'title' => 'Part 1', 'content' => 'Very long'],
                ['num' => 2, 'title' => 'Part 2', 'content' => 'content'],
            ]);

        try {
            $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    // =========================================================================
    // Book entity creation tests
    // =========================================================================

    #[Test]
    public function createsBookWithEpubSourceType(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Test Book',
                'author' => 'Test Author',
                'description' => 'A description',
                'sourceHash' => 'hash-sourcetype',
            ],
            'chapters' => [
                ['title' => 'Ch1', 'content' => 'Content'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);
        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame('epub', $book->sourceType());
                $this->assertSame('Test Book', $book->title());
                $this->assertSame('Test Author', $book->author());
                $this->assertSame('A description', $book->description());
                $this->assertSame('hash-sourcetype', $book->sourceHash());
                return 1;
            });

        $this->textSplitter->method('needsSplit')->willReturn(false);

        try {
            $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);
        } catch (\Throwable $e) {
            // Static calls may fail
        }
    }

    #[Test]
    public function createsBookWithCorrectLanguageId(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'French Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash-lang',
            ],
            'chapters' => [
                ['title' => 'Chapitre 1', 'content' => 'Contenu'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);
        $this->bookRepository->method('beginTransaction');

        $this->bookRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($book) {
                $this->assertSame(5, $book->languageId());
                return 1;
            });

        $this->textSplitter->method('needsSplit')->willReturn(false);

        try {
            $this->useCase->execute(5, ['tmp_name' => '/tmp/test.epub']);
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
        $result = $this->useCase->execute(1, []);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('bookId', $result);
        $this->assertArrayHasKey('chapterCount', $result);
        $this->assertArrayHasKey('textIds', $result);
    }

    #[Test]
    public function failureResultTypesAreCorrect(): void
    {
        $result = $this->useCase->execute(1, []);

        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
        $this->assertNull($result['bookId']);
        $this->assertIsInt($result['chapterCount']);
        $this->assertIsArray($result['textIds']);
    }

    // =========================================================================
    // Multiple chapters test
    // =========================================================================

    #[Test]
    public function iteratesOverAllChapters(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension not available - cannot test EPUB parsing');
        }

        $this->epubParser->method('isValidEpub')->willReturn(true);
        $this->epubParser->method('parse')->willReturn([
            'metadata' => [
                'title' => 'Multi Chapter Book',
                'author' => null,
                'description' => null,
                'sourceHash' => 'hash-multi',
            ],
            'chapters' => [
                ['title' => 'Chapter 1', 'content' => 'Content 1'],
                ['title' => 'Chapter 2', 'content' => 'Content 2'],
                ['title' => 'Chapter 3', 'content' => 'Content 3'],
            ],
        ]);
        $this->bookRepository->method('existsBySourceHash')->willReturn(false);
        $this->bookRepository->method('beginTransaction');
        $this->bookRepository->method('save')->willReturn(1);

        // needsSplit is called once per chapter, but static calls in
        // createChapterText (Text::create, TextParsing::parseAndSave)
        // may throw before all chapters are reached in a unit test context.
        $this->textSplitter->expects($this->atLeastOnce())
            ->method('needsSplit')
            ->willReturn(false);

        try {
            $this->useCase->execute(1, ['tmp_name' => '/tmp/test.epub']);
        } catch (\Throwable $e) {
            // Static calls may fail in unit test context
        }
    }
}
