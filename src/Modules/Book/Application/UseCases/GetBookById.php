<?php

/**
 * Get Book By ID Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Book\Application\UseCases;

use Lwt\Modules\Book\Domain\Book;
use Lwt\Modules\Book\Domain\BookRepositoryInterface;

/**
 * Use case for retrieving a single book with its chapters.
 *
 * @since 3.0.0
 */
class GetBookById
{
    private BookRepositoryInterface $bookRepository;

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
     * Get a book by its ID with chapter list.
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
    public function execute(int $bookId): ?array
    {
        $book = $this->bookRepository->findById($bookId);

        if ($book === null) {
            return null;
        }

        $chapters = $this->bookRepository->getChapters($bookId);

        // Book retrieved from repository always has an ID
        $id = $book->id();
        assert($id !== null, 'Book retrieved by ID must have an ID');

        return [
            'book' => [
                'id' => $id,
                'title' => $book->title(),
                'author' => $book->author(),
                'description' => $book->description(),
                'languageId' => $book->languageId(),
                'sourceType' => $book->sourceType(),
                'totalChapters' => $book->totalChapters(),
                'currentChapter' => $book->currentChapter(),
                'progress' => $book->getProgressPercent(),
                'createdAt' => $book->createdAt(),
                'updatedAt' => $book->updatedAt(),
            ],
            'chapters' => $chapters,
        ];
    }

    /**
     * Get book context for a text that belongs to a book.
     *
     * This provides the navigation info needed for chapter nav in the reading view.
     *
     * @param int $textId Text ID
     *
     * @return array{
     *     bookId: int,
     *     bookTitle: string,
     *     chapterNum: int,
     *     chapterTitle: string|null,
     *     totalChapters: int,
     *     prevTextId: int|null,
     *     nextTextId: int|null,
     *     chapters: array<array{id: int, num: int, title: string}>
     * }|null
     */
    public function getBookContextForText(int $textId): ?array
    {
        $context = $this->bookRepository->getBookContextForText($textId);

        if ($context === null) {
            return null;
        }

        // Also get all chapters for the dropdown
        $chapters = $this->bookRepository->getChapters($context['bookId']);

        return array_merge($context, ['chapters' => $chapters]);
    }
}
