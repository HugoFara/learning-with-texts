<?php

/**
 * Repository Interface
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Repository
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Repository;

/**
 * Base interface for all repositories.
 *
 * Repositories abstract database access and provide a collection-like
 * interface for domain objects.
 *
 * @template T The entity type this repository manages
 *
 * @since 3.0.0
 */
interface RepositoryInterface
{
    /**
     * Find an entity by its primary key.
     *
     * @param int $id The entity ID
     *
     * @return object|null The entity or null if not found
     *
     * @psalm-return T|null
     */
    public function find(int $id): ?object;

    /**
     * Find all entities.
     *
     * @return array<int, object> Array of entities
     *
     * @psalm-return array<int, T>
     */
    public function findAll(): array;

    /**
     * Find entities by criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs
     * @param array<string, string>|null $orderBy Field => direction pairs
     * @param int|null $limit Maximum results
     * @param int|null $offset Offset for pagination
     *
     * @return array<int, object> Array of matching entities
     *
     * @psalm-return array<int, T>
     */
    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null
    ): array;

    /**
     * Find a single entity by criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs
     *
     * @return object|null The entity or null if not found
     *
     * @psalm-return T|null
     */
    public function findOneBy(array $criteria): ?object;

    /**
     * Save an entity (insert or update).
     *
     * @param object $entity The entity to save
     *
     * @return int The entity ID (useful for inserts)
     *
     * @psalm-param T $entity
     * @psalm-suppress PossiblyUnusedReturnValue - Return value is optional; useful for inserts
     */
    public function save(object $entity): int;

    /**
     * Delete an entity.
     *
     * @param object|int $entityOrId The entity or its ID
     *
     * @return bool True if deleted, false if not found
     *
     * @psalm-param T|int $entityOrId
     */
    public function delete(object|int $entityOrId): bool;

    /**
     * Count entities matching criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs
     *
     * @return int The count
     */
    public function count(array $criteria = []): int;

    /**
     * Check if an entity with the given ID exists.
     *
     * @param int $id The entity ID
     *
     * @return bool
     */
    public function exists(int $id): bool;
}
