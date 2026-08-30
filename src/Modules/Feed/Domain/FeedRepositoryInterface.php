<?php

/**
 * Feed Repository Interface
 *
 * Domain port for feed persistence operations.
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
 * Repository interface for Feed entities.
 *
 * This is a domain port defining the contract for feed persistence.
 * Infrastructure implementations (e.g., MySqlFeedRepository) provide
 * the actual database access.
 *
 * @since 3.0.0
 */
interface FeedRepositoryInterface
{
    /**
     * Find a feed by its ID.
     *
     * @param int $id Feed ID
     *
     * @return Feed|null The feed entity or null if not found
     */
    public function find(int $id): ?Feed;

    /**
     * Find all feeds.
     *
     * @param string $orderBy    Column to order by (default: NfUpdate)
     * @param string $direction  Sort direction (ASC/DESC)
     *
     * @return Feed[]
     */
    public function findAll(string $orderBy = 'NfUpdate', string $direction = 'DESC'): array;

    /**
     * Find feeds by language ID.
     *
     * @param int    $languageId Language ID
     * @param string $orderBy    Column to order by
     * @param string $direction  Sort direction
     *
     * @return Feed[]
     */
    public function findByLanguage(
        int $languageId,
        string $orderBy = 'NfUpdate',
        string $direction = 'DESC'
    ): array;

    /**
     * Save a feed entity.
     *
     * Inserts if new, updates if existing.
     *
     * @param Feed|object $entity The feed entity to save
     *
     * @return int The feed ID (newly generated for inserts)
     */
    public function save(object $entity): int;

    /**
     * Delete a feed by its ID.
     *
     * @param Feed|int $entityOrId Feed entity or ID
     *
     * @return bool True if deleted, false if not found
     */
    public function delete(object|int $entityOrId): bool;

    /**
     * Delete multiple feeds by IDs.
     *
     * @param int[] $ids Feed IDs
     *
     * @return int Number of feeds deleted
     */
    public function deleteMultiple(array $ids): int;

    /**
     * Check if a feed exists.
     *
     * @param int $id Feed ID
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Count feeds with optional filtering.
     *
     * @param int|null    $languageId   Language ID filter (null for all)
     * @param string|null $queryPattern LIKE pattern for name filter
     *
     * @return int
     */
    public function countFeeds(?int $languageId = null, ?string $queryPattern = null): int;

    /**
     * Update the last update timestamp for a feed.
     *
     * @param int $feedId    Feed ID
     * @param int $timestamp Unix timestamp
     *
     * @return void
     */
    public function updateTimestamp(int $feedId, int $timestamp): void;

    /**
     * Find feeds that need auto-update.
     *
     * Returns feeds where:
     * - autoupdate option is set
     * - current time > last update + interval
     *
     * @param int $currentTime Current Unix timestamp
     *
     * @return Feed[]
     */
    public function findNeedingAutoUpdate(int $currentTime): array;

    /**
     * Get feeds formatted for select dropdown options.
     *
     * @param int $languageId    Language ID (0 for all languages)
     * @param int $maxNameLength Maximum name length before truncation
     *
     * @return array<int, array{id: int, name: string, language_id: int}>
     */
    public function getForSelect(int $languageId = 0, int $maxNameLength = 40): array;

    /**
     * Find feeds with pagination and filtering.
     *
     * @param int         $offset       Pagination offset
     * @param int         $limit        Page size
     * @param int|null    $languageId   Language filter
     * @param string|null $queryPattern Name filter (LIKE pattern)
     * @param string      $orderBy      Column to order by
     * @param string      $direction    Sort direction
     *
     * @return Feed[]
     */
    public function findPaginated(
        int $offset,
        int $limit,
        ?int $languageId = null,
        ?string $queryPattern = null,
        string $orderBy = 'NfUpdate',
        string $direction = 'DESC'
    ): array;
}
