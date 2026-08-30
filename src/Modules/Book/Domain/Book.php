<?php

/**
 * Book Entity
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

use InvalidArgumentException;

/**
 * Book entity representing a collection of related texts (chapters).
 *
 * Books group multiple texts together, typically from EPUB imports or
 * large text imports that were automatically split into chapters.
 *
 * @category Lwt
 * @package  Lwt\Modules\Book\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Book
{
    private ?int $id;
    private ?int $userId;
    private int $languageId;
    private string $title;
    private ?string $author;
    private ?string $description;
    private ?string $coverPath;
    private string $sourceType;
    private ?string $sourceHash;
    private int $totalChapters;
    private int $currentChapter;
    private ?string $createdAt;
    private ?string $updatedAt;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        ?int $id,
        ?int $userId,
        int $languageId,
        string $title,
        ?string $author,
        ?string $description,
        ?string $coverPath,
        string $sourceType,
        ?string $sourceHash,
        int $totalChapters,
        int $currentChapter,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->languageId = $languageId;
        $this->title = $title;
        $this->author = $author;
        $this->description = $description;
        $this->coverPath = $coverPath;
        $this->sourceType = $sourceType;
        $this->sourceHash = $sourceHash;
        $this->totalChapters = $totalChapters;
        $this->currentChapter = $currentChapter;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Create a new book.
     *
     * @param int         $languageId  Language ID for this book
     * @param string      $title       Book title
     * @param string|null $author      Author name
     * @param string|null $description Book description
     * @param string      $sourceType  Source type: 'text', 'epub', or 'pdf'
     * @param string|null $sourceHash  SHA-256 hash for duplicate detection
     * @param int|null    $userId      User ID (for multi-user mode)
     *
     * @return self
     *
     * @throws InvalidArgumentException If title is empty or sourceType is invalid
     */
    public static function create(
        int $languageId,
        string $title,
        ?string $author = null,
        ?string $description = null,
        string $sourceType = 'text',
        ?string $sourceHash = null,
        ?int $userId = null
    ): self {
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new InvalidArgumentException('Book title cannot be empty');
        }
        if (mb_strlen($trimmedTitle) > 200) {
            throw new InvalidArgumentException('Book title cannot exceed 200 characters');
        }

        if ($languageId <= 0) {
            throw new InvalidArgumentException('Language ID must be positive');
        }

        $validSourceTypes = ['text', 'epub', 'pdf'];
        if (!in_array($sourceType, $validSourceTypes, true)) {
            throw new InvalidArgumentException(
                'Source type must be one of: ' . implode(', ', $validSourceTypes)
            );
        }

        return new self(
            null,
            $userId,
            $languageId,
            $trimmedTitle,
            $author !== null ? trim($author) : null,
            $description !== null ? trim($description) : null,
            null,
            $sourceType,
            $sourceHash,
            0,
            1
        );
    }

    /**
     * Reconstitute a book from persistence.
     *
     * @param int         $id             Book ID
     * @param int|null    $userId         User ID
     * @param int         $languageId     Language ID
     * @param string      $title          Book title
     * @param string|null $author         Author name
     * @param string|null $description    Book description
     * @param string|null $coverPath      Cover image path
     * @param string      $sourceType     Source type
     * @param string|null $sourceHash     Source file hash
     * @param int         $totalChapters  Total number of chapters
     * @param int         $currentChapter Current reading position
     * @param string|null $createdAt      Creation timestamp
     * @param string|null $updatedAt      Update timestamp
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        ?int $userId,
        int $languageId,
        string $title,
        ?string $author,
        ?string $description,
        ?string $coverPath,
        string $sourceType,
        ?string $sourceHash,
        int $totalChapters,
        int $currentChapter,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ): self {
        return new self(
            $id,
            $userId,
            $languageId,
            $title,
            $author,
            $description,
            $coverPath,
            $sourceType,
            $sourceHash,
            $totalChapters,
            $currentChapter,
            $createdAt,
            $updatedAt
        );
    }

    /**
     * Load from a database record.
     *
     * @param array<string, mixed> $record Database record
     *
     * @return self
     */
    public static function fromDbRecord(array $record): self
    {
        return self::reconstitute(
            (int) $record['BkID'],
            isset($record['BkUsID']) ? (int) $record['BkUsID'] : null,
            (int) $record['BkLgID'],
            (string) $record['BkTitle'],
            isset($record['BkAuthor']) ? (string) $record['BkAuthor'] : null,
            isset($record['BkDescription']) ? (string) $record['BkDescription'] : null,
            isset($record['BkCoverPath']) ? (string) $record['BkCoverPath'] : null,
            (string) ($record['BkSourceType'] ?? 'text'),
            isset($record['BkSourceHash']) ? (string) $record['BkSourceHash'] : null,
            (int) ($record['BkTotalChapters'] ?? 0),
            (int) ($record['BkCurrentChapter'] ?? 1),
            isset($record['BkCreated']) ? (string) $record['BkCreated'] : null,
            isset($record['BkUpdated']) ? (string) $record['BkUpdated'] : null
        );
    }

    // Domain behavior methods

    /**
     * Update the book title.
     *
     * @param string $title The new title
     *
     * @return void
     *
     * @throws InvalidArgumentException If title is empty or too long
     */
    public function rename(string $title): void
    {
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new InvalidArgumentException('Book title cannot be empty');
        }
        if (mb_strlen($trimmedTitle) > 200) {
            throw new InvalidArgumentException('Book title cannot exceed 200 characters');
        }
        $this->title = $trimmedTitle;
    }

    /**
     * Update the book author.
     *
     * @param string|null $author The new author name
     *
     * @return void
     */
    public function setAuthor(?string $author): void
    {
        $this->author = $author !== null ? trim($author) : null;
    }

    /**
     * Update the book description.
     *
     * @param string|null $description The new description
     *
     * @return void
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
    }

    /**
     * Set the cover image path.
     *
     * @param string|null $path Path to the cover image
     *
     * @return void
     */
    public function setCoverPath(?string $path): void
    {
        $this->coverPath = $path;
    }

    /**
     * Update the total chapter count.
     *
     * @param int $count Number of chapters
     *
     * @return void
     *
     * @throws InvalidArgumentException If count is negative
     */
    public function setTotalChapters(int $count): void
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Chapter count cannot be negative');
        }
        $this->totalChapters = $count;
    }

    /**
     * Update the current reading position.
     *
     * @param int $chapterNum Chapter number (1-based)
     *
     * @return void
     *
     * @throws InvalidArgumentException If chapter number is invalid
     */
    public function setCurrentChapter(int $chapterNum): void
    {
        if ($chapterNum < 1) {
            throw new InvalidArgumentException('Chapter number must be at least 1');
        }
        if ($this->totalChapters > 0 && $chapterNum > $this->totalChapters) {
            throw new InvalidArgumentException(
                "Chapter number cannot exceed total chapters ({$this->totalChapters})"
            );
        }
        $this->currentChapter = $chapterNum;
    }

    /**
     * Change the language for this book.
     *
     * @param int $languageId New language ID
     *
     * @return void
     *
     * @throws InvalidArgumentException If language ID is invalid
     */
    public function changeLanguage(int $languageId): void
    {
        if ($languageId <= 0) {
            throw new InvalidArgumentException('Language ID must be positive');
        }
        $this->languageId = $languageId;
    }

    // Query methods

    /**
     * Check if this is a new (unsaved) book.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->id === null;
    }

    /**
     * Check if the book has been fully read.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->totalChapters > 0 && $this->currentChapter >= $this->totalChapters;
    }

    /**
     * Get the reading progress as a percentage.
     *
     * @return float Progress from 0.0 to 100.0
     */
    public function getProgressPercent(): float
    {
        if ($this->totalChapters === 0) {
            return 0.0;
        }
        return ($this->currentChapter / $this->totalChapters) * 100.0;
    }

    // Getters

    public function id(): ?int
    {
        return $this->id;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function author(): ?string
    {
        return $this->author;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function coverPath(): ?string
    {
        return $this->coverPath;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceHash(): ?string
    {
        return $this->sourceHash;
    }

    public function totalChapters(): int
    {
        return $this->totalChapters;
    }

    public function currentChapter(): int
    {
        return $this->currentChapter;
    }

    public function createdAt(): ?string
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param int $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Cannot change ID of a persisted book');
        }
        $this->id = $id;
    }
}
