<?php

/**
 * Tag Repository Interface
 *
 * Domain port for tag persistence operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Domain;

/**
 * Repository interface for Tag entity.
 *
 * Defines the contract for tag persistence operations.
 * Implementations handle specific tag types (term or text).
 *
 * @since 3.0.0
 */
interface TagRepositoryInterface
{
    /**
     * Get the tag type this repository handles.
     *
     * @return TagType
     */
    public function getTagType(): TagType;

    /**
     * Find a tag by its ID.
     *
     * @param int $id Tag ID
     *
     * @return Tag|null Tag entity or null if not found
     */
    public function find(int $id): ?Tag;

    /**
     * Find a tag by its text.
     *
     * @param string $text Tag text (case-sensitive)
     *
     * @return Tag|null Tag entity or null if not found
     */
    public function findByText(string $text): ?Tag;

    /**
     * Save a tag entity (create or update).
     *
     * @param Tag $tag The tag to save
     *
     * @return void
     */
    public function save(Tag $tag): void;

    /**
     * Delete a tag by ID.
     *
     * @param int $id Tag ID
     *
     * @return bool True if deleted, false if not found
     */
    public function delete(int $id): bool;

    /**
     * Delete multiple tags by IDs.
     *
     * @param int[] $ids Tag IDs to delete
     *
     * @return int Number of deleted tags
     */
    public function deleteMultiple(array $ids): int;

    /**
     * Delete all tags matching a filter.
     *
     * @param string $query Filter query (supports * wildcard)
     *
     * @return int Number of deleted tags
     */
    public function deleteAll(string $query = ''): int;

    /**
     * Check if a tag exists by ID.
     *
     * @param int $id Tag ID
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Check if a tag text exists.
     *
     * @param string   $text      Tag text to check
     * @param int|null $excludeId Tag ID to exclude (for updates)
     *
     * @return bool
     */
    public function textExists(string $text, ?int $excludeId = null): bool;

    /**
     * Find all tags ordered by specified column.
     *
     * @param string $orderBy   Column to order by ('text', 'comment', 'usage')
     * @param string $direction Sort direction ('ASC' or 'DESC')
     *
     * @return Tag[]
     */
    public function findAll(string $orderBy = 'text', string $direction = 'ASC'): array;

    /**
     * Get paginated list of tags with usage counts.
     *
     * @param int    $page    Page number (1-based)
     * @param int    $perPage Items per page
     * @param string $query   Filter query (supports * wildcard)
     * @param string $orderBy Column to order by
     *
     * @return array{tags: Tag[], usageCounts: array<int, int>, totalCount: int}
     */
    public function paginate(
        int $page,
        int $perPage,
        string $query = '',
        string $orderBy = 'text'
    ): array;

    /**
     * Get total count of tags matching a filter.
     *
     * @param string $query Filter query (supports * wildcard)
     *
     * @return int
     */
    public function count(string $query = ''): int;

    /**
     * Get all tag texts as an array.
     *
     * Used for caching and autocomplete.
     *
     * @return string[]
     */
    public function getAllTexts(): array;

    /**
     * Get usage count for a specific tag.
     *
     * For term tags: count of words with this tag.
     * For text tags: count of texts with this tag.
     *
     * @param int $tagId Tag ID
     *
     * @return int
     */
    public function getUsageCount(int $tagId): int;

    /**
     * Get or create a tag by text.
     *
     * If tag exists, returns its ID. Otherwise creates it and returns the new ID.
     *
     * @param string $text Tag text
     *
     * @return int Tag ID
     */
    public function getOrCreate(string $text): int;
}
