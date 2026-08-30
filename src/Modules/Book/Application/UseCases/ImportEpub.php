<?php

/**
 * Import EPUB Use Case
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
use Lwt\Modules\Book\Application\Services\EpubParserService;
use Lwt\Modules\Book\Application\Services\TextSplitterService;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\TextParsing;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Text\Domain\Text;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Use case for importing EPUB files as books.
 *
 * Parses the EPUB, creates a book record, and imports each chapter
 * as a text record linked to the book.
 *
 * @since 3.0.0
 */
class ImportEpub
{
    private BookRepositoryInterface $bookRepository;
    private TextRepositoryInterface $textRepository;
    private EpubParserService $epubParser;
    private TextSplitterService $textSplitter;

    /**
     * Constructor.
     *
     * @param BookRepositoryInterface $bookRepository Book repository
     * @param TextRepositoryInterface $textRepository Text repository
     * @param EpubParserService       $epubParser     EPUB parser service
     * @param TextSplitterService     $textSplitter   Text splitter service
     */
    public function __construct(
        BookRepositoryInterface $bookRepository,
        TextRepositoryInterface $textRepository,
        EpubParserService $epubParser,
        TextSplitterService $textSplitter
    ) {
        $this->bookRepository = $bookRepository;
        $this->textRepository = $textRepository;
        $this->epubParser = $epubParser;
        $this->textSplitter = $textSplitter;
    }

    /**
     * Import an EPUB file as a book.
     *
     * @param int                   $languageId    Language ID for the book
     * @param array<string, mixed>  $uploadedFile  Uploaded file data from $_FILES
     * @param string|null           $overrideTitle Optional title override
     * @param int[]                 $tagIds        Tag IDs to apply to all chapters
     * @param int|null              $userId        User ID (for multi-user mode)
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     bookId: int|null,
     *     chapterCount: int,
     *     textIds: int[]
     * }
     *
     * @throws InvalidArgumentException If file is invalid
     * @throws RuntimeException If import fails
     */
    public function execute(
        int $languageId,
        array $uploadedFile,
        ?string $overrideTitle = null,
        array $tagIds = [],
        ?int $userId = null
    ): array {
        // Validate uploaded file
        if (!isset($uploadedFile['tmp_name']) || $uploadedFile['tmp_name'] === '') {
            return [
                'success' => false,
                'message' => __('book.flash.no_file_uploaded'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        /** @var string $filePath */
        $filePath = $uploadedFile['tmp_name'];

        // Check if ZIP extension is available
        if (!extension_loaded('zip')) {
            return [
                'success' => false,
                'message' => __('book.flash.zip_required'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        // Validate EPUB file
        $originalName = isset($uploadedFile['name']) ? (string) $uploadedFile['name'] : '';
        if (!$this->epubParser->isValidEpub($filePath, $originalName)) {
            return [
                'success' => false,
                'message' => __('book.flash.invalid_epub'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        // Parse EPUB
        try {
            $parsed = $this->epubParser->parse($filePath, $originalName);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('book.flash.parse_failed', ['error' => $e->getMessage()]),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        $metadata = $parsed['metadata'];
        $chapters = $parsed['chapters'];

        if (empty($chapters)) {
            return [
                'success' => false,
                'message' => __('book.flash.no_chapters'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        // Check for duplicate
        if ($this->bookRepository->existsBySourceHash($metadata['sourceHash'], $userId)) {
            return [
                'success' => false,
                'message' => __('book.flash.already_imported'),
                'bookId' => null,
                'chapterCount' => 0,
                'textIds' => [],
            ];
        }

        // Create book entity
        $book = Book::create(
            $languageId,
            $overrideTitle ?? $metadata['title'],
            $metadata['author'],
            $metadata['description'],
            'epub',
            $metadata['sourceHash'],
            $userId
        );

        // Begin transaction
        $this->bookRepository->beginTransaction();

        try {
            // Save book
            $bookId = $this->bookRepository->save($book);

            // Import chapters as texts
            $textIds = [];
            $chapterNumber = 0;

            foreach ($chapters as $chapter) {
                $chapterNumber++;
                $chapterTexts = $this->importChapter(
                    $bookId,
                    $languageId,
                    $chapterNumber,
                    $chapter['title'],
                    $chapter['content'],
                    $tagIds,
                    $userId
                );
                $textIds = array_merge($textIds, $chapterTexts);
            }

            // Update book with chapter count
            $this->bookRepository->updateChapterCount($bookId, count($textIds));

            // Commit transaction
            $this->bookRepository->commit();

            return [
                'success' => true,
                'message' => __('book.flash.imported', ['title' => $book->title(), 'count' => count($textIds)]),
                'bookId' => $bookId,
                'chapterCount' => count($textIds),
                'textIds' => $textIds,
            ];
        } catch (\Throwable $e) {
            $this->bookRepository->rollback();
            throw new RuntimeException('Failed to import EPUB: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Import a single chapter as one or more text records.
     *
     * If the chapter content exceeds 60KB, it will be split into multiple
     * text records, each linked to the book.
     *
     * @param int      $bookId        Book ID
     * @param int      $languageId    Language ID
     * @param int      $chapterNum    Chapter number
     * @param string   $chapterTitle  Chapter title
     * @param string   $content       Chapter content
     * @param int[]    $tagIds        Tag IDs to apply
     * @param int|null $userId        User ID
     *
     * @return int[] Array of created text IDs
     */
    private function importChapter(
        int $bookId,
        int $languageId,
        int $chapterNum,
        string $chapterTitle,
        string $content,
        array $tagIds,
        ?int $userId
    ): array {
        $textIds = [];

        // Check if chapter needs splitting
        if ($this->textSplitter->needsSplit($content)) {
            $chunks = $this->textSplitter->split($content);
            $subNum = 0;

            foreach ($chunks as $chunk) {
                $subNum++;
                $subTitle = count($chunks) > 1
                    ? "{$chapterTitle} (Part {$subNum})"
                    : $chapterTitle;

                $textId = $this->createChapterText(
                    $bookId,
                    $languageId,
                    $chapterNum,
                    $subTitle,
                    $chunk['content'],
                    $tagIds,
                    $userId
                );

                if ($textId !== null) {
                    $textIds[] = $textId;
                    // Increment chapter num for subsequent parts
                    $chapterNum++;
                }
            }
        } else {
            $textId = $this->createChapterText(
                $bookId,
                $languageId,
                $chapterNum,
                $chapterTitle,
                $content,
                $tagIds,
                $userId
            );

            if ($textId !== null) {
                $textIds[] = $textId;
            }
        }

        return $textIds;
    }

    /**
     * Create a text record for a chapter.
     *
     * @param int      $bookId       Book ID
     * @param int      $languageId   Language ID
     * @param int      $chapterNum   Chapter number
     * @param string   $chapterTitle Chapter title
     * @param string   $content      Chapter content
     * @param int[]    $tagIds       Tag IDs to apply
     * @param int|null $userId       User ID
     *
     * @return int Text ID
     */
    private function createChapterText(
        int $bookId,
        int $languageId,
        int $chapterNum,
        string $chapterTitle,
        string $content,
        array $tagIds,
        ?int $userId
    ): int {
        // Validate content length
        if (strlen($content) > 65000) {
            // Truncate if still too long (shouldn't happen with proper splitting)
            $content = mb_substr($content, 0, 64000) . '...';
        }

        // Create text entity
        $text = Text::create(
            LanguageId::fromInt($languageId),
            $chapterTitle,
            $content
        );

        // Save text
        $textId = $this->textRepository->save($text);

        // Link to book
        $this->linkTextToBook($textId, $bookId, $chapterNum, $chapterTitle);

        // Parse text for sentences and word items
        TextParsing::parseAndSave($content, $languageId, $textId);

        // Apply tags
        if (!empty($tagIds)) {
            $this->applyTags($textId, $tagIds);
        }

        return $textId;
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
