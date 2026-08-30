<?php

/**
 * Article Repository Interface
 *
 * Domain port for article persistence operations.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Domain;

/**
 * Repository interface for Article entities (feed_links).
 *
 * This is a domain port defining the contract for article persistence.
 * Infrastructure implementations (e.g., MySqlArticleRepository) provide
 * the actual database access.
 *
 * @since 3.0.0
 */
interface ArticleRepositoryInterface
{
    /**
     * Find an article by its ID.
     *
     * @param int $id Article ID
     *
     * @return Article|null The article entity or null if not found
     */
    public function find(int $id): ?Article;

    /**
     * Find articles by feed ID with pagination.
     *
     * @param int    $feedId    Feed ID
     * @param int    $offset    Pagination offset
     * @param int    $limit     Page size
     * @param string $orderBy   Column to order by
     * @param string $direction Sort direction
     *
     * @return Article[]
     */
    public function findByFeed(
        int $feedId,
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'FlDate',
        string $direction = 'DESC'
    ): array;

    /**
     * Find articles by multiple IDs.
     *
     * @param int[] $ids Article IDs
     *
     * @return Article[]
     */
    public function findByIds(array $ids): array;

    /**
     * Find articles by feed IDs with status information.
     *
     * Returns articles with additional status info from texts/archived_texts tables.
     *
     * @param int[]  $feedIds   Feed IDs
     * @param int    $offset    Pagination offset
     * @param int    $limit     Page size
     * @param string $orderBy   Column to order by
     * @param string $direction Sort direction
     * @param string $search    Search query for title/description
     *
     * @return array<array{
     *     article: Article,
     *     text_id: int|null,
     *     archived_id: int|null,
     *     status: string
     * }>
     */
    public function findByFeedsWithStatus(
        array $feedIds,
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'FlDate',
        string $direction = 'DESC',
        string $search = ''
    ): array;

    /**
     * Count articles by feed ID.
     *
     * @param int    $feedId Feed ID
     * @param string $search Search query for title/description
     *
     * @return int
     */
    public function countByFeed(int $feedId, string $search = ''): int;

    /**
     * Count articles by multiple feed IDs.
     *
     * @param int[]  $feedIds Feed IDs
     * @param string $search  Search query for title/description
     *
     * @return int
     */
    public function countByFeeds(array $feedIds, string $search = ''): int;

    /**
     * Save an article entity.
     *
     * Inserts if new, updates if existing.
     *
     * @param Article|object $entity The article entity to save
     *
     * @return int The article ID (newly generated for inserts)
     */
    public function save(object $entity): int;

    /**
     * Insert multiple articles in batch.
     *
     * Ignores duplicates based on (FlNfID, FlTitle) unique key.
     *
     * @param Article[] $articles Articles to insert
     * @param int       $feedId   Feed ID for all articles
     *
     * @return array{inserted: int, duplicates: int}
     */
    public function insertBatch(array $articles, int $feedId): array;

    /**
     * Delete an article by its ID or entity.
     *
     * @param Article|int $entityOrId Article entity or ID
     *
     * @return bool True if deleted, false if not found
     */
    public function delete(object|int $entityOrId): bool;

    /**
     * Delete all articles for a feed.
     *
     * @param int $feedId Feed ID
     *
     * @return int Number of deleted articles
     */
    public function deleteByFeed(int $feedId): int;

    /**
     * Delete all articles for multiple feeds.
     *
     * @param int[] $feedIds Feed IDs
     *
     * @return int Number of deleted articles
     */
    public function deleteByFeeds(array $feedIds): int;

    /**
     * Delete articles by IDs.
     *
     * @param int[] $ids Article IDs
     *
     * @return int Number of deleted articles
     */
    public function deleteByIds(array $ids): int;

    /**
     * Reset error status for articles in feeds.
     *
     * Removes the leading space from article links that were marked as errors.
     *
     * @param int[] $feedIds Feed IDs
     *
     * @return int Number of reset articles
     */
    public function resetErrorsByFeeds(array $feedIds): int;

    /**
     * Mark an article as error by its link.
     *
     * Adds a leading space to the link to mark it as unloadable.
     *
     * @param string $link Article link
     *
     * @return void
     */
    public function markAsError(string $link): void;

    /**
     * Check if an article with the given title exists for a feed.
     *
     * @param int    $feedId Feed ID
     * @param string $title  Article title
     *
     * @return bool
     */
    public function titleExistsForFeed(int $feedId, string $title): bool;

    /**
     * Get article count per feed.
     *
     * @param int[] $feedIds Feed IDs (empty for all feeds)
     *
     * @return array<int, int> Map of feed ID to article count
     */
    public function getCountPerFeed(array $feedIds = []): array;
}
