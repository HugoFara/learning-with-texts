<?php

/**
 * Get Book List Use Case
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
 * Use case for retrieving a list of books.
 *
 * @since 3.0.0
 */
class GetBookList
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
     * Get a paginated list of books.
     *
     * @param int|null $userId     User ID for filtering
     * @param int|null $languageId Language ID for filtering
     * @param int      $page       Page number (1-based)
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
    public function execute(
        ?int $userId = null,
        ?int $languageId = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $books = $this->bookRepository->findAll($userId, $languageId, $perPage, $offset);
        $total = $this->bookRepository->count($userId, $languageId);
        $totalPages = (int) ceil($total / $perPage);

        $bookData = array_map(
            function (Book $book): array {
                // Books from repository always have IDs
                $id = $book->id();
                assert($id !== null, 'Book from repository must have an ID');

                return [
                    'id' => $id,
                    'title' => $book->title(),
                    'author' => $book->author(),
                    'languageId' => $book->languageId(),
                    'sourceType' => $book->sourceType(),
                    'totalChapters' => $book->totalChapters(),
                    'currentChapter' => $book->currentChapter(),
                    'progress' => $book->getProgressPercent(),
                    'createdAt' => $book->createdAt(),
                    'updatedAt' => $book->updatedAt(),
                ];
            },
            $books
        );

        return [
            'books' => $bookData,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }
}
