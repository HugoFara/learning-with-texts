<?php

/**
 * Book Facade
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Book\Application;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Modules\Book\Domain\BookRepositoryInterface;
use Lwt\Modules\Book\Application\UseCases\ImportEpub;
use Lwt\Modules\Book\Application\UseCases\CreateBookFromTexts;
use Lwt\Modules\Book\Application\UseCases\GetBookList;
use Lwt\Modules\Book\Application\UseCases\GetBookById;
use Lwt\Modules\Book\Application\UseCases\DeleteBook;
use Lwt\Modules\Book\Application\Services\TextSplitterService;

/**
 * Facade providing a unified interface to book operations.
 *
 * This class coordinates between use cases and provides a simple API
 * for controllers and other consumers.
 *
 * @since 3.0.0
 */
class BookFacade
{
    private BookRepositoryInterface $bookRepository;

    // Lazy-loaded use cases
    private ?ImportEpub $importEpub = null;
    private ?CreateBookFromTexts $createBookFromTexts = null;
    private ?GetBookList $getBookList = null;
    private ?GetBookById $getBookById = null;
    private ?DeleteBook $deleteBook = null;
    private ?TextSplitterService $textSplitter = null;

    /**
     * Constructor.
     *
     * @param BookRepositoryInterface $bookRepository Book repository
     */
    public function __construct(BookRepositoryInterface $bookRepository)
    {
        $this->bookRepository = $bookRepository;
    }

    /**
     * Import an EPUB file as a book.
     *
     * @param int                     $languageId    Language ID
     * @param array<string, mixed>    $uploadedFile  Uploaded file data from $_FILES
     * @param string|null             $overrideTitle Optional title override
     * @param int[]                   $tagIds        Tag IDs to apply
     * @param int|null                $userId        User ID
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     bookId: int|null,
     *     chapterCount: int,
     *     textIds: int[]
     * }
     */
    public function importEpub(
        int $languageId,
        array $uploadedFile,
        ?string $overrideTitle = null,
        array $tagIds = [],
        ?int $userId = null
    ): array {
        return $this->getImportEpub()->execute(
            $languageId,
            $uploadedFile,
            $overrideTitle,
            $tagIds,
            $userId
        );
    }

    /**
     * Create a book from a long text (auto-split).
     *
     * @param int         $languageId Language ID
     * @param string      $title      Book title
     * @param string      $text       Text content
     * @param string|null $author     Author name
     * @param string      $audioUri   Audio URI
     * @param string      $sourceUri  Source URI
     * @param int[]       $tagIds     Tag IDs to apply
     * @param int|null    $userId     User ID
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     bookId: int|null,
     *     chapterCount: int,
     *     textIds: int[]
     * }
     */
    public function createBookFromText(
        int $languageId,
        string $title,
        string $text,
        ?string $author = null,
        string $audioUri = '',
        string $sourceUri = '',
        array $tagIds = [],
        ?int $userId = null
    ): array {
        return $this->getCreateBookFromTexts()->execute(
            $languageId,
            $title,
            $text,
            $author,
            $audioUri,
            $sourceUri,
            $tagIds,
            $userId
        );
    }

    /**
     * Get a paginated list of books.
     *
     * @param int|null $userId     User ID filter
     * @param int|null $languageId Language ID filter
     * @param int      $page       Page number
     * @param int      $perPage    Items per page
     *
     * @return array{
     *     books: array<array{
     *         id: int,
     *         title: string,
     *         author: string|null,
     *         languageId: int,
     *         sourceType: string,
     *         totalChapters: int,
     *         currentChapter: int,
     *         progress: float,
     *         createdAt: string|null,
     *         updatedAt: string|null
     *     }>,
     *     total: int,
     *     page: int,
     *     perPage: int,
     *     totalPages: int
     * }
     */
    public function getBooks(
        ?int $userId = null,
        ?int $languageId = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        return $this->getGetBookList()->execute($userId, $languageId, $page, $perPage);
    }

    /**
     * Get a book by ID with chapters.
     *
     * @param int $bookId Book ID
     *
     * @return array{
     *     book: array{
     *         id: int,
     *         title: string,
     *         author: string|null,
     *         description: string|null,
     *         languageId: int,
     *         sourceType: string,
     *         totalChapters: int,
     *         currentChapter: int,
     *         progress: float,
     *         createdAt: string|null,
     *         updatedAt: string|null
     *     },
     *     chapters: array<array{id: int, num: int, title: string}>
     * }|null
     */
    public function getBook(int $bookId): ?array
    {
        return $this->getGetBookById()->execute($bookId);
    }

    /**
     * Get book context for a text (for chapter navigation).
     *
     * @param int $textId Text ID
     *
     * @return array|null Book context or null if text doesn't belong to a book
     */
    public function getBookContextForText(int $textId): ?array
    {
        return $this->getGetBookById()->getBookContextForText($textId);
    }

    /**
     * Delete a book and all its chapters.
     *
     * @param int $bookId Book ID
     *
     * @return array{success: bool, message: string}
     */
    public function deleteBook(int $bookId): array
    {
        return $this->getDeleteBook()->execute($bookId);
    }

    /**
     * Check if text needs to be split.
     *
     * @param string $text     Text content
     * @param int    $maxBytes Maximum bytes threshold
     *
     * @return bool True if text exceeds threshold
     */
    public function needsSplit(string $text, int $maxBytes = 60000): bool
    {
        return $this->getTextSplitter()->needsSplit($text, $maxBytes);
    }

    /**
     * Get the byte size of text.
     *
     * @param string $text Text content
     *
     * @return int Size in bytes
     */
    public function getTextByteSize(string $text): int
    {
        return $this->getTextSplitter()->getByteSize($text);
    }

    /**
     * Estimate how many chapters a text will be split into.
     *
     * @param string $text     Text content
     * @param int    $maxBytes Maximum bytes per chunk
     *
     * @return int Estimated chapter count
     */
    public function estimateChapterCount(string $text, int $maxBytes = 60000): int
    {
        return $this->getTextSplitter()->estimateChunkCount($text, $maxBytes);
    }

    /**
     * Update reading progress for a book.
     *
     * @param int $bookId     Book ID
     * @param int $chapterNum Current chapter number
     *
     * @return void
     */
    public function updateReadingProgress(int $bookId, int $chapterNum): void
    {
        $this->bookRepository->updateCurrentChapter($bookId, $chapterNum);
    }

    /**
     * Get chapters for a book.
     *
     * @param int $bookId Book ID
     *
     * @return array<array{id: int, num: int, title: string}>
     */
    public function getChapters(int $bookId): array
    {
        return $this->bookRepository->getChapters($bookId);
    }

    // Lazy loading helpers

    private function getImportEpub(): ImportEpub
    {
        if ($this->importEpub === null) {
            $this->importEpub = Container::getInstance()->getTyped(ImportEpub::class);
        }
        return $this->importEpub;
    }

    private function getCreateBookFromTexts(): CreateBookFromTexts
    {
        if ($this->createBookFromTexts === null) {
            $this->createBookFromTexts = Container::getInstance()->getTyped(CreateBookFromTexts::class);
        }
        return $this->createBookFromTexts;
    }

    private function getGetBookList(): GetBookList
    {
        if ($this->getBookList === null) {
            $this->getBookList = Container::getInstance()->getTyped(GetBookList::class);
        }
        return $this->getBookList;
    }

    private function getGetBookById(): GetBookById
    {
        if ($this->getBookById === null) {
            $this->getBookById = Container::getInstance()->getTyped(GetBookById::class);
        }
        return $this->getBookById;
    }

    private function getDeleteBook(): DeleteBook
    {
        if ($this->deleteBook === null) {
            $this->deleteBook = Container::getInstance()->getTyped(DeleteBook::class);
        }
        return $this->deleteBook;
    }

    private function getTextSplitter(): TextSplitterService
    {
        if ($this->textSplitter === null) {
            $this->textSplitter = Container::getInstance()->getTyped(TextSplitterService::class);
        }
        return $this->textSplitter;
    }
}
