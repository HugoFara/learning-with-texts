<?php

/**
 * Book Repository Interface
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Book\Domain;

/**
 * Repository interface for Book persistence operations.
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
interface BookRepositoryInterface
{
    /**
     * Begin a database transaction.
     *
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commit the current database transaction.
     *
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback the current database transaction.
     *
     * @return void
     */
    public function rollback(): void;

    /**
     * Find a book by its ID.
     *
     * @param int $id Book ID
     *
     * @return Book|null The book or null if not found
     */
    public function findById(int $id): ?Book;

    /**
     * Find a book by its source hash (for duplicate detection).
     *
     * @param string   $hash   SHA-256 hash of the source file
     * @param int|null $userId User ID for multi-user filtering
     *
     * @return Book|null The book or null if not found
     */
    public function findBySourceHash(string $hash, ?int $userId = null): ?Book;

    /**
     * Check if a book with the given source hash exists.
     *
     * @param string   $hash   SHA-256 hash of the source file
     * @param int|null $userId User ID for multi-user filtering
     *
     * @return bool True if exists
     */
    public function existsBySourceHash(string $hash, ?int $userId = null): bool;

    /**
     * Get all books for a user.
     *
     * @param int|null $userId     User ID (null for all users)
     * @param int|null $languageId Filter by language
     * @param int      $limit      Maximum number of results
     * @param int      $offset     Offset for pagination
     *
     * @return Book[] Array of books
     */
    public function findAll(
        ?int $userId = null,
        ?int $languageId = null,
        int $limit = 50,
        int $offset = 0
    ): array;

    /**
     * Count books for a user.
     *
     * @param int|null $userId     User ID (null for all users)
     * @param int|null $languageId Filter by language
     *
     * @return int Number of books
     */
    public function count(?int $userId = null, ?int $languageId = null): int;

    /**
     * Save a book (insert or update).
     *
     * @param Book $book The book to save
     *
     * @return int The book ID
     */
    public function save(Book $book): int;

    /**
     * Delete a book by its ID.
     *
     * Cascades to delete all associated text chapters.
     *
     * @param int $id Book ID
     *
     * @return bool True if deleted
     */
    public function delete(int $id): bool;

    /**
     * Update the total chapter count for a book.
     *
     * @param int $bookId Book ID
     * @param int $count  Number of chapters
     *
     * @return void
     */
    public function updateChapterCount(int $bookId, int $count): void;

    /**
     * Update the current reading position for a book.
     *
     * @param int $bookId     Book ID
     * @param int $chapterNum Chapter number (1-based)
     *
     * @return void
     */
    public function updateCurrentChapter(int $bookId, int $chapterNum): void;

    /**
     * Get chapters (texts) for a book.
     *
     * @param int $bookId Book ID
     *
     * @return array<array{id: int, num: int, title: string}> Array of chapter info
     */
    public function getChapters(int $bookId): array;

    /**
     * Get the text ID for a specific chapter of a book.
     *
     * @param int $bookId     Book ID
     * @param int $chapterNum Chapter number (1-based)
     *
     * @return int|null Text ID or null if not found
     */
    public function getChapterTextId(int $bookId, int $chapterNum): ?int;

    /**
     * Get book info for a text that belongs to a book.
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
     *     nextTextId: int|null
     * }|null Book context or null if text doesn't belong to a book
     */
    public function getBookContextForText(int $textId): ?array;
}
