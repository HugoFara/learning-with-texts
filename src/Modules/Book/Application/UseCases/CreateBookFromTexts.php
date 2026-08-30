<?php

/**
 * Create Book From Texts Use Case
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
use Lwt\Modules\Book\Application\Services\TextSplitterService;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Modules\Text\Domain\Text;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use RuntimeException;

/**
 * Use case for creating a book from a large text that needs splitting.
 *
 * Takes a long text, splits it into chapters, and creates a book with
 * linked text records for each chapter.
 *
 * @since 3.0.0
 */
class CreateBookFromTexts
{
    private BookRepositoryInterface $bookRepository;
    private TextRepositoryInterface $textRepository;
    private TextSplitterService $textSplitter;

    /**
     * Constructor.
     *
     * @param BookRepositoryInterface $bookRepository Book repository
     * @param TextRepositoryInterface $textRepository Text repository
     * @param TextSplitterService     $textSplitter   Text splitter service
     */
    public function __construct(
        BookRepositoryInterface $bookRepository,
        TextRepositoryInterface $textRepository,
        TextSplitterService $textSplitter
    ) {
        $this->bookRepository = $bookRepository;
        $this->textRepository = $textRepository;
        $this->textSplitter = $textSplitter;
    }

    /**
     * Create a book from a long text.
     *
     * The text will be split at paragraph boundaries into chapters,
     * each stored as a separate text record linked to the book.
     *
     * @param int         $languageId  Language ID
     * @param string      $title       Book title
     * @param string      $text        Text content to split
     * @param string|null $author      Author name (optional)
     * @param string      $audioUri    Audio URI (applied to first chapter)
     * @param string      $sourceUri   Source URI (applied to first chapter)
     * @param int[]       $tagIds      Tag IDs to apply to all chapters
     * @param int|null    $userId      User ID (for multi-user mode)
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     bookId: int|null,
     *     chapterCount: int,
     *     textIds: int[]
     * }
     */
    public function execute(
        int $languageId,
        string $title,
        string $text,
        ?string $author = null,
        string $audioUri = '',
        string $sourceUri = '',
        array $tagIds = [],
        ?int $userId = null
    ): array {
        // Clean and validate text
        $text = $this->cleanText($text);

        if (trim($text) === '') {
            return [
                'success' => false,
                'message' => __('book.flash.text_empty'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        // Split text into chapters
        $chapters = $this->textSplitter->split($text);

        // If only one chapter and it fits in a single text, don't create book
        if (count($chapters) === 1 && !$this->textSplitter->needsSplit($text)) {
            return [
                'success' => false,
                'message' => __('book.flash.no_split_needed'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        // Create book entity
        $book = Book::create(
            $languageId,
            $title,
            $author,
            null, // No description for text imports
            'text',
            hash('sha256', $text), // Hash the text for duplicate detection
            $userId
        );

        // Begin transaction
        $this->bookRepository->beginTransaction();

        try {
            // Save book
            $bookId = $this->bookRepository->save($book);

            // Create text records for each chapter
            $textIds = [];

            foreach ($chapters as $index => $chapter) {
                $chapterNum = $chapter['num'];
                $chapterTitle = $chapter['title'];

                // For first chapter, use book title; others get part numbers
                $textTitle = $index === 0 && count($chapters) > 1
                    ? "{$title} ({$chapterTitle})"
                    : ($index === 0 ? $title : "{$title} ({$chapterTitle})");

                // Create text
                $textEntity = Text::create(
                    LanguageId::fromInt($languageId),
                    $textTitle,
                    $chapter['content']
                );

                // Only first chapter gets audio/source URIs
                if ($index === 0) {
                    $textEntity->setMediaUri($audioUri);
                    $textEntity->setSourceUri($sourceUri);
                }

                // Save text
                $textId = $this->textRepository->save($textEntity);
                $textIds[] = $textId;

                // Link to book
                $this->linkTextToBook($textId, $bookId, $chapterNum, $chapterTitle);

                // Parse text
                TextParsing::parseAndSave($chapter['content'], $languageId, $textId);

                // Apply tags
                if (!empty($tagIds)) {
                    $this->applyTags($textId, $tagIds);
                }
            }

            // Update book with chapter count
            $this->bookRepository->updateChapterCount($bookId, count($textIds));

            // Commit transaction
            $this->bookRepository->commit();

            return [
                'success' => true,
                'message' => __('book.flash.created_from_text', ['title' => $title, 'count' => count($textIds)]),
                'bookId' => $bookId,
                'chapterCount' => count($textIds),
                'textIds' => $textIds,
            ];
        } catch (\Throwable $e) {
            $this->bookRepository->rollback();
            throw new RuntimeException('Failed to create book: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clean text content.
     *
     * @param string $text Raw text
     *
     * @return string Cleaned text
     */
    private function cleanText(string $text): string
    {
        // Remove soft hyphens
        $text = str_replace("\xC2\xAD", "", $text);

        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Normalize multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        if ($text === null) {
            return '';
        }

        return trim($text);
    }

    /**
     * Link a text record to a book.
     *
     * @param int    $textId       Text ID
     * @param int    $bookId       Book ID
     * @param int    $chapterNum   Chapter number
     * @param string $chapterTitle Chapter title
     */
    private function linkTextToBook(
        int $textId,
        int $bookId,
        int $chapterNum,
        string $chapterTitle
    ): void {
        $bindings = [$bookId, $chapterNum, $chapterTitle, $textId];
        Connection::preparedExecute(
            "UPDATE texts" .
            " SET TxBkID = ?, TxChapterNum = ?, TxChapterTitle = ? WHERE TxID = ?",
            $bindings
        );
    }

    /**
     * Apply tags to a text.
     *
     * @param int   $textId Text ID
     * @param int[] $tagIds Array of tag IDs
     */
    private function applyTags(int $textId, array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $bindings = [$textId, $tagId];
            Connection::preparedExecute(
                "INSERT IGNORE INTO text_tag_map" .
                " (TtTxID, TtT2ID) VALUES (?, ?)",
                $bindings
            );
        }
    }
}
