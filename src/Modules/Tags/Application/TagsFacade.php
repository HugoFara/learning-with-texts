<?php

/**
 * Tags Facade
 *
 * Backward-compatible facade for tag operations.
 * Delegates to use case classes for actual implementation.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Application;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Tags\Application\Services\TermTagService;
use Lwt\Modules\Tags\Application\Services\TextTagService;
use Lwt\Modules\Tags\Application\UseCases\CreateTag;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Modules\Tags\Application\UseCases\DeleteTag;
use Lwt\Modules\Tags\Application\UseCases\GetAllTagNames;
use Lwt\Modules\Tags\Application\UseCases\GetTagById;
use Lwt\Modules\Tags\Application\UseCases\ListTags;
use Lwt\Modules\Tags\Application\UseCases\UpdateTag;
use Lwt\Modules\Tags\Domain\Tag;
use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;
use Lwt\Modules\Tags\Domain\TagType;
use Lwt\Modules\Tags\Infrastructure\MySqlArchivedTextTagAssociation;
use Lwt\Modules\Tags\Infrastructure\MySqlTermTagRepository;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagAssociation;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagRepository;
use Lwt\Modules\Tags\Infrastructure\MySqlWordTagAssociation;

/**
 * Facade for tag module operations.
 *
 * Provides a unified interface to all tag-related use cases.
 * Designed for backward compatibility with existing TagService callers.
 *
 * @since 3.0.0
 */
class TagsFacade
{
    private TagType $tagType;
    private TagRepositoryInterface $repository;
    private TagAssociationInterface $association;

    // Term tag instances (static for caching)
    private static ?TagRepositoryInterface $termRepository = null;
    private static ?TagRepositoryInterface $textRepository = null;
    private static ?TagAssociationInterface $wordAssociation = null;
    private static ?TagAssociationInterface $textAssociation = null;
    private static ?TagAssociationInterface $archivedTextAssociation = null;
    private static ?GetAllTagNames $getAllTagNames = null;

    /**
     * Constructor.
     *
     * @param TagType                      $tagType     Tag type (TERM or TEXT)
     * @param TagRepositoryInterface|null  $repository  Tag repository
     * @param TagAssociationInterface|null $association Tag association handler
     */
    public function __construct(
        TagType $tagType = TagType::TERM,
        ?TagRepositoryInterface $repository = null,
        ?TagAssociationInterface $association = null
    ) {
        $this->tagType = $tagType;

        // Initialize repositories based on type
        if ($tagType === TagType::TERM) {
            $this->repository = $repository ?? self::getTermRepository();
            $this->association = $association ?? self::getWordAssociation();
        } else {
            $this->repository = $repository ?? self::getTextRepository();
            $this->association = $association ?? self::getTextAssociation();
        }
    }

    // =====================
    // FACTORY METHODS
    // =====================

    /**
     * Create a facade for term tags.
     *
     * @return self
     */
    public static function forTermTags(): self
    {
        return new self(TagType::TERM);
    }

    /**
     * Create a facade for text tags.
     *
     * @return self
     */
    public static function forTextTags(): self
    {
        return new self(TagType::TEXT);
    }

    // =====================
    // SINGLETON GETTERS
    // =====================

    private static function getTermRepository(): TagRepositoryInterface
    {
        if (self::$termRepository === null) {
            self::$termRepository = new MySqlTermTagRepository();
        }
        return self::$termRepository;
    }

    private static function getTextRepository(): TagRepositoryInterface
    {
        if (self::$textRepository === null) {
            self::$textRepository = new MySqlTextTagRepository();
        }
        return self::$textRepository;
    }

    private static function getWordAssociation(): TagAssociationInterface
    {
        if (self::$wordAssociation === null) {
            self::$wordAssociation = new MySqlWordTagAssociation(self::getTermRepository());
        }
        return self::$wordAssociation;
    }

    private static function getTextAssociation(): TagAssociationInterface
    {
        if (self::$textAssociation === null) {
            self::$textAssociation = new MySqlTextTagAssociation(self::getTextRepository());
        }
        return self::$textAssociation;
    }

    private static function getArchivedTextAssociation(): TagAssociationInterface
    {
        if (self::$archivedTextAssociation === null) {
            self::$archivedTextAssociation = new MySqlArchivedTextTagAssociation(self::getTextRepository());
        }
        return self::$archivedTextAssociation;
    }

    private static function getGetAllTagNames(): GetAllTagNames
    {
        if (self::$getAllTagNames === null) {
            self::$getAllTagNames = new GetAllTagNames(
                self::getTermRepository(),
                self::getTextRepository()
            );
        }
        return self::$getAllTagNames;
    }

    // =====================
    // CRUD OPERATIONS
    // =====================

    /**
     * Create a new tag.
     *
     * @param string $text    Tag text
     * @param string $comment Tag comment
     *
     * @return array{success: bool, tag: ?Tag, error: ?string} Result
     */
    public function create(string $text, string $comment = ''): array
    {
        $useCase = new CreateTag($this->repository);
        return $useCase->executeWithResult($text, $comment);
    }

    /**
     * Update an existing tag.
     *
     * @param int    $id      Tag ID
     * @param string $text    New tag text
     * @param string $comment New tag comment
     *
     * @return array{success: bool, tag: ?Tag, error: ?string} Result
     */
    public function update(int $id, string $text, string $comment): array
    {
        $useCase = new UpdateTag($this->repository);
        return $useCase->executeWithResult($id, $text, $comment);
    }

    /**
     * Delete a single tag.
     *
     * @param int $id Tag ID
     *
     * @return array{success: bool, count: int} Result
     */
    public function delete(int $id): array
    {
        $useCase = new DeleteTag($this->repository, $this->association);
        return $useCase->executeWithResult($id);
    }

    /**
     * Delete multiple tags.
     *
     * @param int[] $ids Tag IDs
     *
     * @return array{success: bool, count: int} Result
     */
    public function deleteMultiple(array $ids): array
    {
        $useCase = new DeleteTag($this->repository, $this->association);
        return $useCase->executeMultipleWithResult($ids);
    }

    /**
     * Delete all tags matching filter.
     *
     * @param string $query Filter query
     *
     * @return array{success: bool, count: int} Result
     */
    public function deleteAll(string $query = ''): array
    {
        $useCase = new DeleteTag($this->repository, $this->association);
        return $useCase->executeAllWithResult($query);
    }

    /**
     * Get a tag by ID.
     *
     * @param int $id Tag ID
     *
     * @return array|null Tag data or null
     */
    public function getById(int $id): ?array
    {
        $useCase = new GetTagById($this->repository);
        return $useCase->executeAsArray($id);
    }

    /**
     * Get paginated list of tags.
     *
     * @param string $query   Filter query
     * @param string $orderBy Sort column
     * @param int    $page    Page number
     * @param int    $perPage Items per page
     *
     * @return array Tag list with usage counts
     */
    public function getList(
        string $query = '',
        string $orderBy = 'text',
        int $page = 1,
        int $perPage = 0
    ): array {
        $useCase = new ListTags($this->repository);
        $result = $useCase->execute($page, $perPage, $query, $orderBy);

        // Convert to backward-compatible format
        $tags = [];
        foreach ($result['tags'] as $tag) {
            $tagData = [
                'id' => $tag->id()->toInt(),
                'text' => $tag->text(),
                'comment' => $tag->comment(),
                'usageCount' => $result['usageCounts'][$tag->id()->toInt()] ?? 0,
            ];

            // Add archived count for text tags
            if ($this->tagType === TagType::TEXT) {
                $tagData['archivedUsageCount'] = $this->getArchivedUsageCount($tag->id()->toInt());
            }

            $tags[] = $tagData;
        }

        return $tags;
    }

    /**
     * Get total count of tags.
     *
     * @param string $query Filter query
     *
     * @return int
     */
    public function getCount(string $query = ''): int
    {
        $useCase = new ListTags($this->repository);
        return $useCase->count($query);
    }

    /**
     * Get usage count for a tag.
     *
     * @param int $tagId Tag ID
     *
     * @return int
     */
    public function getUsageCount(int $tagId): int
    {
        return $this->repository->getUsageCount($tagId);
    }

    /**
     * Get archived text usage count (text tags only).
     *
     * @param int $tagId Tag ID
     *
     * @return int
     */
    public function getArchivedUsageCount(int $tagId): int
    {
        if ($this->tagType !== TagType::TEXT) {
            return 0;
        }

        return self::getArchivedTextAssociation()->getItemCount($tagId);
    }

    // =====================
    // PAGINATION & SORTING
    // =====================

    /**
     * Get pagination info.
     *
     * @param int $totalCount   Total count
     * @param int $currentPage  Current page
     *
     * @return array{pages: int, currentPage: int, perPage: int}
     */
    public function getPagination(int $totalCount, int $currentPage): array
    {
        $useCase = new ListTags($this->repository);
        return $useCase->getPagination($totalCount, $currentPage, $useCase->getMaxPerPage());
    }

    /**
     * Get maximum items per page.
     *
     * @return int
     */
    public function getMaxPerPage(): int
    {
        $useCase = new ListTags($this->repository);
        return $useCase->getMaxPerPage();
    }

    /**
     * Get sort options for dropdown.
     *
     * @return array
     */
    public function getSortOptions(): array
    {
        $useCase = new ListTags($this->repository);
        return $useCase->getSortOptions();
    }

    /**
     * Get sort column from index.
     *
     * @param int $index Sort index
     *
     * @return string
     */
    public function getSortColumn(int $index): string
    {
        $useCase = new ListTags($this->repository);
        return $useCase->getSortColumn($index);
    }

    // =====================
    // TAG TYPE INFO
    // =====================

    /**
     * Get the current tag type.
     *
     * @return TagType
     */
    public function getTagType(): TagType
    {
        return $this->tagType;
    }

    /**
     * Get the tag type label.
     *
     * @return string
     */
    public function getTagTypeLabel(): string
    {
        return $this->tagType->label();
    }

    /**
     * Get the base URL for this tag type.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->tagType->baseUrl();
    }

    /**
     * Get URL to view items with a tag.
     *
     * @param int $tagId Tag ID
     *
     * @return string
     */
    public function getItemsUrl(int $tagId): string
    {
        return sprintf($this->tagType->itemsUrlPattern(), $tagId);
    }

    /**
     * Get URL to view archived texts with a tag.
     *
     * @param int $tagId Tag ID
     *
     * @return string
     */
    public function getArchivedItemsUrl(int $tagId): string
    {
        if ($this->tagType !== TagType::TEXT) {
            return '';
        }
        return sprintf('/archived?tag=%d', $tagId);
    }

    // =====================
    // STATIC TAG CACHE METHODS (backward compatibility)
    // =====================

    /**
     * Get all term tag names with session caching.
     *
     * @param bool $refresh Force refresh
     *
     * @return string[]
     */
    public static function getAllTermTags(bool $refresh = false): array
    {
        return self::getGetAllTagNames()->getTermTags($refresh);
    }

    /**
     * Get all text tag names with session caching.
     *
     * @param bool $refresh Force refresh
     *
     * @return string[]
     */
    public static function getAllTextTags(bool $refresh = false): array
    {
        return self::getGetAllTagNames()->getTextTags($refresh);
    }

    // =====================
    // ASSOCIATION METHODS
    // =====================

    /**
     * Save tags for a word.
     *
     * @param int      $wordId   Word ID
     * @param string[] $tagNames Tag names
     *
     * @return void
     */
    public static function saveWordTags(int $wordId, array $tagNames): void
    {
        TermTagService::saveWordTags($wordId, $tagNames);
        self::getAllTermTags(true); // Refresh cache
    }

    /**
     * Save tags for a text.
     *
     * @param int      $textId   Text ID
     * @param string[] $tagNames Tag names
     *
     * @return void
     */
    public static function saveTextTags(int $textId, array $tagNames): void
    {
        TextTagService::saveTextTags($textId, $tagNames);
        self::getAllTextTags(true); // Refresh cache
    }

    /**
     * Save tags for an archived text.
     *
     * @param int      $textId   Archived text ID
     * @param string[] $tagNames Tag names
     *
     * @return void
     */
    public static function saveArchivedTextTags(int $textId, array $tagNames): void
    {
        TextTagService::saveArchivedTextTags($textId, $tagNames);
        self::getAllTextTags(true); // Refresh cache
    }

    // =====================
    // HTML RENDERING (backward compatibility)
    // =====================

    /**
     * Get HTML list of tags for a word.
     *
     * @param int $wordId Word ID
     *
     * @return string HTML UL element
     */
    public static function getWordTagsHtml(int $wordId): string
    {
        return TermTagService::getWordTagsHtml($wordId);
    }

    /**
     * Get HTML list of tags for a text.
     *
     * @param int $textId Text ID
     *
     * @return string HTML UL element
     */
    public static function getTextTagsHtml(int $textId): string
    {
        return TextTagService::getTextTagsHtml($textId);
    }

    /**
     * Get HTML list of tags for an archived text.
     *
     * @param int $textId Archived text ID
     *
     * @return string HTML UL element
     */
    public static function getArchivedTextTagsHtml(int $textId): string
    {
        return TextTagService::getArchivedTextTagsHtml($textId);
    }

    /**
     * Get comma-separated tag list for a word.
     *
     * @param int  $wordId     Word ID
     * @param bool $escapeHtml Whether to escape HTML
     *
     * @return string
     */
    public static function getWordTagList(int $wordId, bool $escapeHtml = true): string
    {
        return TermTagService::getWordTagList($wordId, $escapeHtml);
    }

    /**
     * Get word tags as array.
     *
     * @param int $wordId Word ID
     *
     * @return string[]
     */
    public static function getWordTagsArray(int $wordId): array
    {
        return TermTagService::getWordTagsArray($wordId);
    }

    /**
     * Save tags for a word from an array of tag names.
     *
     * @param int      $wordId   Word ID
     * @param string[] $tagNames Array of tag name strings
     *
     * @return void
     */
    public static function saveWordTagsFromArray(int $wordId, array $tagNames): void
    {
        TermTagService::saveWordTagsFromArray($wordId, $tagNames);
        self::getAllTermTags(true);
    }

    /**
     * Cleanup orphaned tag links.
     *
     * @return void
     */
    public function cleanupOrphanedLinks(): void
    {
        $this->association->cleanupOrphanedLinks();
    }

    // =====================
    // FORM-READING SAVE METHODS (backward compatibility)
    // =====================

    /**
     * Save tags for a word from form input.
     *
     * Reads 'TermTags' from request and saves to word.
     *
     * @param int $wordId Word ID
     *
     * @return void
     */
    public static function saveWordTagsFromForm(int $wordId): void
    {
        TermTagService::saveWordTagsFromForm($wordId);
    }

    /**
     * Save tags for a text from form input.
     *
     * @param int        $textId   Text ID
     * @param array|null $textTags Optional tags array. If null, reads from request.
     *
     * @return void
     */
    public static function saveTextTagsFromForm(int $textId, ?array $textTags = null): void
    {
        TextTagService::saveTextTagsFromForm($textId, $textTags);
    }

    /**
     * Save tags for an archived text from form input.
     *
     * @param int $textId Archived text ID
     *
     * @return void
     */
    public static function saveArchivedTextTagsFromForm(int $textId): void
    {
        TextTagService::saveArchivedTextTagsFromForm($textId);
    }

    // =====================
    // BATCH OPERATIONS
    // =====================

    /**
     * Add a tag to multiple words.
     *
     * @param string $tagText Tag text to add
     * @param int[]  $ids     Array of word IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function addTagToWords(string $tagText, array $ids): array
    {
        $result = TermTagService::addTagToWords($tagText, $ids);
        self::getAllTermTags(true);
        return $result;
    }

    /**
     * Remove a tag from multiple words.
     *
     * @param string $tagText Tag text to remove
     * @param int[]  $ids     Array of word IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function removeTagFromWords(string $tagText, array $ids): array
    {
        return TermTagService::removeTagFromWords($tagText, $ids);
    }

    /**
     * Add a tag to multiple texts.
     *
     * @param string $tagText Tag text to add
     * @param int[]  $ids     Array of text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function addTagToTexts(string $tagText, array $ids): array
    {
        $result = TextTagService::addTagToTexts($tagText, $ids);
        self::getAllTextTags(true);
        return $result;
    }

    /**
     * Remove a tag from multiple texts.
     *
     * @param string $tagText Tag text to remove
     * @param int[]  $ids     Array of text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function removeTagFromTexts(string $tagText, array $ids): array
    {
        return TextTagService::removeTagFromTexts($tagText, $ids);
    }

    /**
     * Add a tag to multiple archived texts.
     *
     * @param string $tagText Tag text to add
     * @param int[]  $ids     Array of archived text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function addTagToArchivedTexts(string $tagText, array $ids): array
    {
        $result = TextTagService::addTagToArchivedTexts($tagText, $ids);
        self::getAllTextTags(true);
        return $result;
    }

    /**
     * Remove a tag from multiple archived texts.
     *
     * @param string $tagText Tag text to remove
     * @param int[]  $ids     Array of archived text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function removeTagFromArchivedTexts(
        string $tagText,
        array $ids
    ): array {
        return TextTagService::removeTagFromArchivedTexts($tagText, $ids);
    }

    // =====================
    // SELECT OPTIONS HELPERS
    // =====================

    /**
     * Get term tag select options HTML for filtering.
     *
     * @param int|string|null $selected Currently selected value
     * @param int|string      $langId   Language ID filter ('' for all)
     *
     * @return string HTML options
     */
    public static function getTermTagSelectOptions(
        int|string|null $selected,
        int|string $langId
    ): string {
        return TermTagService::getTermTagSelectOptions($selected, $langId);
    }

    /**
     * Get text tag select options HTML for filtering.
     *
     * @param int|string|null $selected Currently selected value
     * @param int|string      $langId   Language ID filter ('' for all)
     *
     * @return string HTML options
     */
    public static function getTextTagSelectOptions(
        int|string|null $selected,
        int|string $langId
    ): string {
        return TextTagService::getTextTagSelectOptions($selected, $langId);
    }

    /**
     * Get text tag select options with text IDs for word list filtering.
     *
     * @param int|string      $langId   Language ID filter
     * @param int|string|null $selected Currently selected value
     *
     * @return string HTML options
     */
    public static function getTextTagSelectOptionsWithTextIds(
        int|string $langId,
        int|string|null $selected
    ): string {
        return TextTagService::getTextTagSelectOptionsWithTextIds($langId, $selected);
    }

    /**
     * Get archived text tag select options HTML for filtering.
     *
     * @param int|string|null $selected Currently selected value
     * @param int|string      $langId   Language ID filter ('' for all)
     *
     * @return string HTML options
     */
    public static function getArchivedTextTagSelectOptions(
        int|string|null $selected,
        int|string $langId
    ): string {
        return TextTagService::getArchivedTextTagSelectOptions($selected, $langId);
    }

    // =====================
    // HELPER METHODS
    // =====================

    /**
     * Get formatted tag list as Bulma tag components for a word.
     *
     * @param int    $wordId  Word ID
     * @param string $size    Bulma size class (e.g., 'is-small', 'is-normal')
     * @param string $color   Bulma color class (e.g., 'is-info', 'is-primary')
     * @param bool   $isLight Whether to use light variant
     *
     * @return string HTML for Bulma tags
     */
    public static function getWordTagListHtml(
        int $wordId,
        string $size = 'is-small',
        string $color = 'is-info',
        bool $isLight = true
    ): string {
        return TermTagService::getWordTagListHtml($wordId, $size, $color, $isLight);
    }

    /**
     * Build WHERE clause for query filtering.
     *
     * @param string $query Filter query string
     *
     * @return array{clause: string, params: array} Array with SQL clause and parameters
     */
    public function buildWhereClause(string $query): array
    {
        if ($query === '') {
            return ['clause' => '', 'params' => []];
        }

        $prefix = $this->tagType === TagType::TEXT ? 'T2' : 'Tg';
        $searchValue = str_replace("*", "%", $query);
        $clause = ' AND (' . $prefix . 'Text LIKE ? OR ' .
                  $prefix . 'Comment LIKE ?)';

        return ['clause' => $clause, 'params' => [$searchValue, $searchValue]];
    }

    /**
     * Parse duplicate entry error and extract tag details.
     *
     * @param string $message Original error message
     *
     * @return array{isDuplicate: bool, tagName: string, tagType: string}|null
     *         Returns tag details if duplicate error, null otherwise
     */
    public function parseDuplicateError(string $message): ?array
    {
        $keyName = $this->tagType === TagType::TEXT ? 'T2Text' : 'TgText';

        if (
            substr($message, 0, 24) == "Error: Duplicate entry '"
            && substr($message, -strlen("' for key '$keyName'")) == "' for key '$keyName'"
        ) {
            $tagName = substr($message, 24);
            $tagName = substr($tagName, 0, strlen($tagName) - strlen("' for key '$keyName'"));
            $tagTypeLabel = $this->tagType === TagType::TEXT ? 'Text Tag' : 'Term Tag';
            return [
                'isDuplicate' => true,
                'tagName' => $tagName,
                'tagType' => $tagTypeLabel
            ];
        }

        return null;
    }

    /**
     * Format duplicate entry error message for display.
     *
     * @param string $message Original error message
     *
     * @return string Formatted error message
     */
    public function formatDuplicateError(string $message): string
    {
        $result = $this->parseDuplicateError($message);
        if ($result !== null) {
            return "Error: {$result['tagType']} '{$result['tagName']}' already exists. " .
                   "Please go back and correct this!";
        }
        return $message;
    }
}
