<?php

/**
 * Tag Association Interface
 *
 * Domain port for tag-entity association operations.
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
 * Interface for managing tag associations with entities.
 *
 * Implementations handle specific entity types:
 * - Words (word_tag_map table)
 * - Texts (text_tag_map table)
 * - Archived texts (archived_text_tag_map table)
 *
 * @since 3.0.0
 */
interface TagAssociationInterface
{
    /**
     * Get all tag IDs associated with an item.
     *
     * @param int $itemId The item ID (word, text, or archived text)
     *
     * @return int[] Array of tag IDs
     */
    public function getTagIdsForItem(int $itemId): array;

    /**
     * Get all tag texts associated with an item.
     *
     * @param int $itemId The item ID
     *
     * @return string[] Array of tag texts
     */
    public function getTagTextsForItem(int $itemId): array;

    /**
     * Set tags for an item (replaces existing associations).
     *
     * @param int   $itemId   The item ID
     * @param int[] $tagIds   Tag IDs to associate
     *
     * @return void
     */
    public function setTagsForItem(int $itemId, array $tagIds): void;

    /**
     * Set tags for an item by tag names.
     *
     * Creates tags if they don't exist.
     *
     * @param int      $itemId   The item ID
     * @param string[] $tagNames Tag names to associate
     *
     * @return void
     */
    public function setTagsByName(int $itemId, array $tagNames): void;

    /**
     * Add a tag to an item.
     *
     * @param int $itemId The item ID
     * @param int $tagId  The tag ID
     *
     * @return bool True if added, false if already exists
     */
    public function addTag(int $itemId, int $tagId): bool;

    /**
     * Remove a tag from an item.
     *
     * @param int $itemId The item ID
     * @param int $tagId  The tag ID
     *
     * @return bool True if removed, false if not found
     */
    public function removeTag(int $itemId, int $tagId): bool;

    /**
     * Add a tag to multiple items.
     *
     * @param int   $tagId   The tag ID
     * @param int[] $itemIds Item IDs to add the tag to
     *
     * @return int Number of items the tag was added to
     */
    public function addTagToItems(int $tagId, array $itemIds): int;

    /**
     * Remove a tag from multiple items.
     *
     * @param int   $tagId   The tag ID
     * @param int[] $itemIds Item IDs to remove the tag from
     *
     * @return int Number of items the tag was removed from
     */
    public function removeTagFromItems(int $tagId, array $itemIds): int;

    /**
     * Remove all associations for an item.
     *
     * @param int $itemId The item ID
     *
     * @return int Number of removed associations
     */
    public function clearTagsForItem(int $itemId): int;

    /**
     * Remove all associations for a tag.
     *
     * Called when a tag is deleted.
     *
     * @param int $tagId The tag ID
     *
     * @return int Number of removed associations
     */
    public function clearItemsForTag(int $tagId): int;

    /**
     * Clean up orphaned tag links.
     *
     * Removes associations where the tag no longer exists.
     *
     * @return int Number of removed orphaned links
     */
    public function cleanupOrphanedLinks(): int;

    /**
     * Get count of items associated with a tag.
     *
     * @param int $tagId The tag ID
     *
     * @return int
     */
    public function getItemCount(int $tagId): int;

    /**
     * Check if a tag is associated with an item.
     *
     * @param int $itemId The item ID
     * @param int $tagId  The tag ID
     *
     * @return bool
     */
    public function hasTag(int $itemId, int $tagId): bool;
}
